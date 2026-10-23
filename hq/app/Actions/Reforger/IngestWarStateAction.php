<?php

namespace App\Actions\Reforger;

use App\Enums\WarOrderKind;
use App\Enums\WarOrderStatus;
use App\Models\WarContact;
use App\Models\WarEnlistment;
use App\Models\WarOrder;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Models\WarSupport;
use App\Models\WarVehicle;
use App\Support\WarCondition;
use App\Support\WarEconomy;
use App\Support\WarLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Apply what the Reforger mod reports each tick: squad ground, deaths, how every soldier is
 * doing, what the squads know about the enemy, vehicles, HQ missions carried out, and the
 * island's time and weather. Then let HQ act on it: evacuations, operations, its own squads.
 */
final class IngestWarStateAction
{
    /** A new report this close to a known contact of the same squad is that contact, moved. */
    private const MERGE_METRES = 60.0;

    /** Quiet this long after being shot at, a man has come through a battle. */
    private const BATTLE_CALM_SECONDS = 120;

    /** A defend the game let go of this many times within the window is not given again. */
    private const HOLD_RETRIES = 4;

    private const HOLD_RETRY_WINDOW = 180;

    /** A contact that moved further than this leaves a point on its trail; it keeps this many. */
    private const TRAIL_STEP_METRES = 25.0;

    private const TRAIL_POINTS = 6;

    public const WORLD_CACHE = 'reforger.war.world';

    /** When the mod on the game server last reported. */
    public const FEED_CACHE = 'reforger.war.feed';

    /** The game server is running the war mod: it reported within the last two minutes. */
    public static function reporting(): bool
    {
        $feed = Cache::get(self::FEED_CACHE);

        return is_array($feed) && isset($feed['at']) && Carbon::parse($feed['at'])->greaterThan(now()->subMinutes(2));
    }

    public function __construct(
        private readonly ResolveWarSupportAction $support,
        private readonly ResolveWarOperationsAction $operations,
        private readonly RunWarHqAction $hq,
        private readonly RunWarObjectivesAction $objectives,
        private readonly ControlWarTerritoryAction $territory,
        private readonly SupplyWarSquadAction $logistics,
        private readonly AdvanceWarOrdersAction $chains,
        private readonly RunWarAiAction $ai,
        private readonly RunWarRunsAction $runs,
    ) {}

    /**
     * @param  array<string, mixed>  $state  WarBridgeCodec::decodeState()
     */
    public function ingest(array $state): void
    {
        $size = (float) config('reforger.war.world_size', 12800);
        Cache::put(self::FEED_CACHE, ['source' => 'game', 'at' => now()->toIso8601String()], now()->addMinutes(10));

        if (is_array($state['world'] ?? null)) {
            Cache::put(self::WORLD_CACHE, [...$state['world'], 'at' => now()->toIso8601String()], now()->addMinutes(10));
        }

        // Rows in id order, so this tick and a commander's page lock them in the same order; a
        // deadlock with another writer all the same is retried (Laravel re-runs the transaction).
        foreach (['squads', 'condition', 'vehicles', 'orders'] as $key) {
            if (is_array($state[$key] ?? null)) {
                usort($state[$key], fn (array $a, array $b) => $a['id'] <=> $b['id']);
            }
        }
        if (is_array($state['dead'] ?? null)) {
            sort($state['dead']);
        }

        DB::transaction(function () use ($state, $size) {
            $this->deaths($state['deaths'] ?? []);
            $this->bury($state['dead'] ?? []);
            $this->place($state['squads'] ?? [], $size);
            $this->fights($this->condition($state['condition'] ?? []));
            $this->sight(array_map(fn (array $row) => $row['id'], $state['squads'] ?? []), $state['contacts'] ?? [], $size);
            $this->vehicles($state['vehicles'] ?? []);
            $this->orders($state['orders'] ?? []);
            $this->standDownEmpty();
            $this->kills($state['kills'] ?? []);
            $this->missions($state['support'] ?? []);
            foreach ($state['sabotage'] ?? [] as $row) {
                $this->operations->sabotageReport($row['id'], $row['phase'], $row['left']);
            }
        }, 3);

        // The next order in a chain goes out once the one before is done.
        $this->chains->advance();
        $this->support->resolve();
        $this->operations->resolve();
        $this->hq->run();
        $this->ai->run();
        $this->runs->tend();
        $this->territory->run();
        $this->objectives->run();
    }

    /**
     * JSON debug route: the same data with the older field set.
     *
     * @param  list<array{id: int, x: float, z: float, alive: int}>  $squads
     * @param  list<array{id: int, status: string}>  $orders
     * @param  list<int>  $dead
     * @param  list<array<string, mixed>>  $contacts
     */
    public function apply(array $squads, array $orders, array $dead = [], array $contacts = []): void
    {
        $this->ingest(['squads' => $squads, 'orders' => $orders, 'dead' => $dead, 'contacts' => $contacts]);
    }

    /** @param  list<array{id: int, x: float, z: float, alive: int, doing?: string|null, combat?: string|null}>  $squads */
    private function place(array $squads, float $size): void
    {
        foreach ($squads as $row) {
            $squad = WarSquad::query()->whereKey($row['id'])->lockForUpdate()->first();
            if ($squad === null) {
                throw ValidationException::withMessages(['squads' => ['Unknown squad '.$row['id'].'.']]);
            }
            if ($row['x'] < 0 || $row['z'] < 0 || $row['x'] > $size || $row['z'] > $size) {
                throw ValidationException::withMessages(['squads' => ['Squad '.$row['id'].' is outside Kolguyev.']]);
            }

            $squad->pos_x = round($row['x'], 2);
            $squad->pos_z = round($row['z'], 2);
            if (($row['doing'] ?? null) !== null) {
                $squad->doing = $row['doing'];
                $squad->combat_actual = $row['combat'];
            }
            $living = $squad->soldiers()->whereNull('fallen_at')->where('location', 'field')->count();
            $reported = max(0, $row['alive']);
            $squad->alive = $living > 0 ? min($reported, $living) : $reported;
            $squad->save();
        }
    }

    /**
     * The mod keeps naming a dead soldier until the export drops them, so a repeat is a no-op.
     * Everyone left in that squad takes the loss hard.
     *
     * @param  list<int>  $dead
     */
    private function bury(array $dead): void
    {
        if ($dead === []) {
            return;
        }

        $soldiers = WarSoldier::query()
            ->whereIn('id', array_values(array_unique($dead)))
            ->whereNull('fallen_at')
            ->lockForUpdate()
            ->get();

        foreach ($soldiers->groupBy('squad_id') as $squadId => $fallen) {
            foreach ($fallen as $soldier) {
                $soldier->fallen_at = now();
                $soldier->loss = 'killed';
                $soldier->health = 0;
                $soldier->save();
                WarEconomy::refundLoss($soldier, 'killed');
            }

            $shock = WarCondition::lossShock() * $fallen->count();
            WarSoldier::query()
                ->where('squad_id', $squadId)
                ->whereNull('fallen_at')
                ->lockForUpdate()
                ->get()
                ->each(function (WarSoldier $soldier) use ($shock) {
                    $soldier->morale = max(0, (float) $soldier->morale - $shock);
                    $soldier->save();
                });

            $squad = WarSquad::query()->whereKey($squadId)->lockForUpdate()->first();
            if ($squad === null) {
                continue;
            }

            $squad->strength = $squad->soldiers()->whereNull('fallen_at')->count();
            $squad->alive = min($squad->alive, $squad->strength);
            $squad->save();
        }
    }

    /**
     * Body state from the game, morale and battles kept here.
     *
     * @param  list<array{id: int, health: int, bleeding: bool, down: bool, stamina: int, threat: string, mags: int|null, in_vehicle: bool, x?: float|null, z?: float|null, seat?: string|null, stance?: string|null, action?: string|null, heading?: int|null, target?: int|null}>  $rows
     * @return array<int, list<int>> squads with men under fire now => the enemy soldiers they fight
     */
    private function condition(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $fire = [];

        $soldiers = WarSoldier::query()
            ->with('squad')
            ->whereIn('id', array_map(fn (array $row) => $row['id'], $rows))
            ->whereNull('fallen_at')
            ->where('location', 'field')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $radius = (float) config('reforger.war.condition.rest_radius', 150);

        foreach ($rows as $row) {
            $soldier = $soldiers->get($row['id']);
            if ($soldier === null || $soldier->squad === null) {
                continue;
            }

            $squad = $soldier->squad;
            [$hx, $hz] = $squad->side->muster();
            $calm = in_array($row['threat'], ['safe', 'vigilant'], true);
            $resting = $calm && hypot((float) $squad->pos_x - $hx, (float) $squad->pos_z - $hz) <= $radius;
            $minutes = $soldier->condition_at === null ? 0.0 : $soldier->condition_at->diffInSeconds(now(), true) / 60;

            // A fight survived: shot at (the game's threatened), then BATTLE_CALM_SECONDS without it,
            // on his feet. Stepping down from alerted alone is no fight: the game does it on a march.
            if ($row['threat'] === 'threatened') {
                $soldier->under_fire_at = now();
                $fire[$squad->id] ??= [];
                if (($row['target'] ?? null) !== null) {
                    $fire[$squad->id][] = $row['target'];
                }
            } elseif ($soldier->under_fire_at !== null && $soldier->under_fire_at->lt(now()->subSeconds(self::BATTLE_CALM_SECONDS)) && ! $row['down']) {
                $soldier->battles += 1;
                $soldier->under_fire_at = null;
            }

            $soldier->morale = (string) WarCondition::nextMorale((float) $soldier->morale, $squad->tier, $row['threat'], $row['health'], $resting, $minutes);
            $soldier->health = $row['health'];
            $soldier->bleeding = $row['bleeding'];
            $soldier->down = $row['down'];
            $soldier->stamina = $row['stamina'];
            $soldier->threat = $row['threat'];
            $soldier->mags = $row['mags'];
            $soldier->in_vehicle = $row['in_vehicle'];
            $soldier->seat = $row['in_vehicle'] ? ($row['seat'] ?? null) : null;
            $soldier->stance = $row['stance'] ?? $soldier->stance;
            $soldier->action = $row['action'] ?? $soldier->action;
            $soldier->heading = $row['heading'] ?? null;
            $soldier->target_id = $row['target'] ?? null;
            if (isset($row['x'], $row['z'])) {
                $size = (float) config('reforger.war.world_size', 12800);
                $soldier->pos_x = (string) round(max(0.0, min($size, $row['x'])), 2);
                $soldier->pos_z = (string) round(max(0.0, min($size, $row['z'])), 2);
            }
            $soldier->condition_at = now();
            $soldier->save();
        }

        return $fire;
    }

    /**
     * A squad's fight, for the log: it begins when one of its men is first shot at and ends
     * BATTLE_CALM_SECONDS after the last shot, with what it cost and what it took. A squad wiped
     * out in it is closed the same way.
     *
     * @param  array<int, list<int>>  $fire  condition()
     */
    private function fights(array $fire): void
    {
        foreach (WarSquad::query()->whereKey(array_keys($fire))->orderBy('id')->get() as $squad) {
            $begun = $squad->engaged_at === null;
            $squad->engaged_at ??= now();
            $squad->fired_at = now();
            $squad->saveQuietly();
            if ($begun) {
                $enemies = array_values(array_unique($fire[$squad->id]));
                $seen = WarContact::query()->where('squad_id', $squad->id)->where('seen_at', '>=', now()->subMinute())->count();
                WarLog::write('squad.engaged', 'sq '.$squad->id.' under fire ('.$squad->alive.' up, '.$seen.' contacts'.($enemies !== [] ? ', fighting #'.implode(' #', $enemies) : '').', doing '.($squad->doing ?? '?').')',
                    WarLog::squad($squad), ['enemies' => $enemies, 'contacts' => $seen, 'doing' => $squad->doing, 'alert' => $squad->alert]);
            }
        }

        $calm = WarSquad::query()->whereNotNull('engaged_at')->whereNotIn('id', array_keys($fire))
            ->where('fired_at', '<', now()->subSeconds(self::BATTLE_CALM_SECONDS))->orderBy('id')->get();
        foreach ($calm as $squad) {
            $since = $squad->engaged_at;
            $killed = $squad->soldiers()->where('loss', 'killed')->where('fallen_at', '>=', $since)->count();
            $kills = DB::table('cbpnk_war_kills')->whereIn('soldier_id', $squad->soldiers()->select('id'))->where('created_at', '>=', $since)->count();
            $minutes = (int) round($since->diffInSeconds($squad->fired_at, true) / 60);
            WarLog::write('squad.disengaged', 'sq '.$squad->id.' out of the fight after '.$minutes.' min: '.$killed.' killed, '.$kills.' kills, '.$squad->alive.' up',
                WarLog::squad($squad), ['since' => $since->toIso8601String(), 'minutes' => $minutes, 'killed' => $killed, 'kills' => $kills]);
            $squad->engaged_at = null;
            $squad->fired_at = null;
            $squad->saveQuietly();
        }
    }

    /**
     * How each of ours died, from the mod: by whom, how, with what, from how far. Written once
     * per soldier (the mod repeats its last deaths in every file); the log row of his death gets
     * the detail whether the site buried him before or after.
     *
     * @param  list<array{run: int, seq: int, soldier: int, killer: int|null, cause: string, weapon: string|null, metres: int|null, x: float, z: float, killer_x: float|null, killer_z: float|null}>  $rows
     */
    private function deaths(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $victims = WarSoldier::query()->with('squad')->whereKey(array_column($rows, 'soldier'))->whereNull('death_cause')->orderBy('id')->get()->keyBy('id');
        if ($victims->isEmpty()) {
            return;
        }
        $killers = WarSoldier::query()->with('squad')->whereKey(array_filter(array_column($rows, 'killer')))->get()->keyBy('id');

        foreach ($rows as $row) {
            $victim = $victims->pull($row['soldier']);
            if ($victim === null) {
                continue;
            }
            // An older mod names HQ's barrage by its Game Master module, as a weapon.
            if ($row['weapon'] !== null && str_starts_with($row['weapon'], 'EffectModule')) {
                $row = [...$row, 'cause' => 'fire', 'weapon' => null, 'metres' => null, 'killer_x' => null, 'killer_z' => null];
            }
            // Nobody's hand and last seen bleeding: he bled out (the game names no killer for it).
            if ($row['cause'] === 'other' && $row['killer'] === null && $victim->bleeding) {
                $row['cause'] = 'wounds';
            }
            $barrage = $row['cause'] === 'fire' ? $this->barrageAt($victim->session_id, $row['x'], $row['z']) : null;
            $killer = $row['killer'] === null ? null : $killers->get($row['killer']);
            $victim->killed_by_id = $killer?->id;
            $victim->death_cause = $row['cause'];
            $victim->death_weapon = $row['weapon'];
            $victim->death_metres = $row['metres'];
            $victim->saveQuietly();

            $how = match ($row['cause']) {
                'enemy', 'friendly' => ($row['cause'] === 'friendly' ? 'friendly fire from ' : 'by ').WarLog::who($killer).($killer?->squad ? ' ['.$killer->squad->side->value.']' : ''),
                'fire' => $barrage === null ? 'by artillery'
                    : 'under HQ '.$barrage['support']->kind.' #'.$barrage['support']->id.' called by commander '.$barrage['support']->commander_id
                        .($barrage['side'] === $victim->squad?->side->value ? ' (his own side)' : ''),
                'vehicle' => 'by a vehicle',
                'self' => 'by his own hand (fall, own grenade)',
                'wounds' => 'of his wounds (bled out)',
                default => 'by something else',
            };
            $with = ($row['weapon'] !== null ? ' with '.$row['weapon'] : '').($row['metres'] !== null ? ' from '.$row['metres'].' m' : '');
            WarLog::put('death:'.$victim->id, 'soldier.killed', WarLog::who($victim).' ['.($victim->squad?->side->value ?? '?').'] killed '.$how.$with.WarLog::at($row['x'], $row['z']),
                [...WarLog::soldier($victim), 'other_id' => $killer?->id, 'x' => $row['x'], 'z' => $row['z'],
                    'ref' => $barrage === null ? null : 'support:'.$barrage['support']->id],
                ['cause' => $row['cause'], 'weapon' => $row['weapon'], 'metres' => $row['metres'], 'killer' => $killer?->id, 'killer_squad' => $killer?->squad_id,
                    'killer_x' => $row['killer_x'], 'killer_z' => $row['killer_z'], 'run' => $row['run'], 'seq' => $row['seq'],
                    'support' => $barrage['support']->id ?? null, 'called_by' => $barrage['support']->commander_id ?? null, 'caller_side' => $barrage['side'] ?? null]);
        }
    }

    /** Within this of where it was aimed, in the last BARRAGE_MINUTES, a death under fire is that barrage's. */
    private const BARRAGE_METRES = 300.0;

    private const BARRAGE_MINUTES = 10;

    /**
     * The HQ fire mission that came down on this spot lately (the nearest), and the side of the
     * commander who called it.
     *
     * @return array{support: WarSupport, side: string|null}|null
     */
    private function barrageAt(int $sessionId, float $x, float $z): ?array
    {
        $support = WarSupport::query()
            ->where('session_id', $sessionId)
            ->where('kind', 'like', 'fire_%')
            ->where('status', 'done')
            ->where('done_at', '>=', now()->subMinutes(self::BARRAGE_MINUTES))
            ->get()
            ->filter(fn (WarSupport $s) => $s->pos_x !== null && hypot((float) $s->pos_x - $x, (float) $s->pos_z - $z) <= self::BARRAGE_METRES)
            ->sortBy(fn (WarSupport $s) => hypot((float) $s->pos_x - $x, (float) $s->pos_z - $z))
            ->first();
        if ($support === null) {
            return null;
        }
        $side = WarEnlistment::query()->where('session_id', $sessionId)->where('commander_id', $support->commander_id)->value('side');

        return ['support' => $support, 'side' => $side instanceof \BackedEnum ? (string) $side->value : $side];
    }

    /**
     * Enemies the reporting squads know about now. The game forgets a target the moment it is
     * lost or dead, but the side's map keeps the last sighting: a contact still reported is
     * refreshed where it is (one row per squad, side and kind within MERGE_METRES), the rest
     * stay as they were last seen until they age out (contact_minutes, or a recon's
     * intel_minutes, whichever is longer; the desk decides who sees what for how long).
     *
     * @param  list<int>  $reporting
     * @param  list<array{squad_id: int, side: string, x: float, z: float, kind: string, how: string, age: int, heading?: int|null, target?: int|null}>  $contacts
     */
    private function sight(array $reporting, array $contacts, float $size): void
    {
        $keep = max((int) config('reforger.war.contact_minutes', 15), (int) config('reforger.war.operations.recon.intel_minutes', 30));
        WarContact::query()->where('seen_at', '<', now()->subMinutes($keep))->delete();
        if ($reporting === []) {
            return;
        }

        $sessions = WarSquad::query()->whereIn('id', $reporting)->pluck('session_id', 'id');
        // Earlier sightings only: two men reported in the same tick are two contacts.
        $known = WarContact::query()->whereIn('squad_id', $reporting)->get();
        $touched = [];
        foreach ($contacts as $row) {
            $session = $sessions->get($row['squad_id']);
            if ($session === null || $row['x'] < 0 || $row['z'] < 0 || $row['x'] > $size || $row['z'] > $size) {
                continue;
            }

            $seen = now()->subSeconds(min($row['age'], 3600));
            $target = $row['target'] ?? null;
            // A man the mod names is that man wherever he went; one it cannot name is the nearest
            // unnamed contact within MERGE_METRES, moved.
            $same = $target !== null
                ? $known->first(fn (WarContact $c) => ! isset($touched[$c->id]) && $c->squad_id === $row['squad_id'] && $c->target_id === $target)
                : $known
                    ->filter(fn (WarContact $c) => ! isset($touched[$c->id]) && $c->target_id === null && $c->squad_id === $row['squad_id'] && $c->side->value === $row['side'] && $c->kind === $row['kind']
                        && hypot((float) $c->pos_x - $row['x'], (float) $c->pos_z - $row['z']) <= self::MERGE_METRES)
                    ->sortBy(fn (WarContact $c) => hypot((float) $c->pos_x - $row['x'], (float) $c->pos_z - $row['z']))
                    ->first();

            $contact = $same ?? new WarContact(['session_id' => $session, 'squad_id' => $row['squad_id'], 'side' => $row['side'], 'kind' => $row['kind'], 'target_id' => $target]);
            if ($same !== null) {
                $touched[$same->id] = true;
                if ($same->seen_at->greaterThan($seen)) {
                    continue;
                }
            }
            // Moved since: where it was joins its trail (the last few spots).
            if ($same !== null && hypot((float) $same->pos_x - $row['x'], (float) $same->pos_z - $row['z']) > self::TRAIL_STEP_METRES) {
                $trail = $same->trail ?? [];
                $trail[] = [round((float) $same->pos_x, 1), round((float) $same->pos_z, 1)];
                $contact->trail = array_slice($trail, -self::TRAIL_POINTS);
            }
            $contact->pos_x = round($row['x'], 2);
            $contact->pos_z = round($row['z'], 2);
            $contact->how = $row['how'];
            $contact->heading = $row['heading'] ?? null;
            $contact->seen_at = $seen;
            $contact->save();
        }
    }

    /** @param  list<array{id: int, x: float, z: float, health: int, fuel: int, seats: int, crew: int, alive: bool}>  $rows */
    private function vehicles(array $rows): void
    {
        foreach ($rows as $row) {
            $vehicle = WarVehicle::query()->whereKey($row['id'])->lockForUpdate()->first();
            if ($vehicle === null || ! $vehicle->isActive()) {
                continue;
            }

            $vehicle->pos_x = round($row['x'], 2);
            $vehicle->pos_z = round($row['z'], 2);
            $vehicle->health = $row['health'];
            $vehicle->fuel = $row['fuel'];
            $vehicle->seats = $row['seats'] > 0 ? $row['seats'] : $vehicle->seats;
            $vehicle->crew = $row['crew'];
            if (! $row['alive']) {
                // A wreck is a loss. Nothing comes back.
                $vehicle->status = 'destroyed';
                $vehicle->lost_at = now();
            } elseif ($vehicle->status === 'ordered') {
                $vehicle->status = 'fielded';
            }
            $vehicle->save();
        }
    }

    /** @param  list<array{id: int, status: string}>  $rows */
    private function orders(array $rows): void
    {
        foreach ($rows as $row) {
            $order = WarOrder::query()->whereKey($row['id'])->lockForUpdate()->first();
            if ($order === null) {
                throw ValidationException::withMessages(['orders' => ['Unknown order '.$row['id'].'.']]);
            }

            $next = WarOrderStatus::from($row['status']);
            if ($order->status === $next) {
                continue;
            }

            $allowed = match ($order->status) {
                WarOrderStatus::Pending => [WarOrderStatus::Acked, WarOrderStatus::Done, WarOrderStatus::Failed],
                WarOrderStatus::Acked => [WarOrderStatus::Done, WarOrderStatus::Failed],
                default => [],
            };
            if (in_array($next, $allowed, true)) {
                $order->status = $next;
                $order->save();
                // Home: the hospital takes the wounded, the rest rearm.
                if ($next === WarOrderStatus::Done && $order->kind === WarOrderKind::Rest->value) {
                    $this->logistics->arrivedHome($order);
                }
                // The game gave up on the way (no path, stuck, stopped by a fight): once, they go
                // on from where they are; the second time it is said, and the chain stops there.
                if ($next === WarOrderStatus::Failed && in_array($order->kind, [WarOrderKind::Move->value, WarOrderKind::MoveForced->value, WarOrderKind::Rest->value], true)) {
                    $this->goOn($order);
                }
                // The game lets a defend waypoint go after a while; the order is to hold until
                // told otherwise, so it goes to the game again.
                if ($next === WarOrderStatus::Done && $order->kind === WarOrderKind::Defend->value) {
                    $this->holdOn($order);
                }
            }
        }
    }

    /**
     * A move the game gave up on. The first time it goes to the game again from where the squad
     * stands (the failed one is replaced, so the chain behind it carries on); the second time the
     * order says why and what waited behind it is closed.
     */
    private function goOn(WarOrder $order): void
    {
        $manned = WarSoldier::query()->where('squad_id', $order->squad_id)->whereNull('fallen_at')->where('location', 'field')->exists();
        $newer = WarOrder::query()->where('squad_id', $order->squad_id)->where('id', '>', $order->id)->where('status', '!=', WarOrderStatus::Queued)->exists();
        if ($order->attempt === 0 && $manned && ! $newer) {
            $order->status = WarOrderStatus::Superseded;
            $order->note = 'Stopped on the way: sent on again.';
            $order->save();
            $again = $order->replicate(['status', 'note', 'arrived_at', 'effect_until', 'attempt']);
            $again->status = WarOrderStatus::Pending;
            $again->note = 'Going on after a stop.';
            $again->attempt = 1;
            $again->save();

            return;
        }
        if ($order->note === '' || $order->attempt > 0) {
            $order->note = 'The game could not take them there: no way through, or they were stopped.';
            $order->save();
        }
        WarOrder::query()->where('squad_id', $order->squad_id)->where('status', WarOrderStatus::Queued)
            ->update(['status' => WarOrderStatus::Superseded, 'note' => 'The move before it failed: the chain stopped.']);
    }

    /**
     * Enemies killed by our soldiers, each counted once (the mod repeats its last kills in every
     * file): the soldier's tally goes up, and with it what he has learnt.
     *
     * @param  list<array{run: int, seq: int, soldier: int, side: string}>  $rows
     */
    private function kills(array $rows): void
    {
        foreach ($rows as $row) {
            $soldier = WarSoldier::query()->whereKey($row['soldier'])->lockForUpdate()->first();
            if ($soldier === null) {
                continue;
            }
            $new = DB::table('cbpnk_war_kills')->insertOrIgnore([
                'session_id' => $soldier->session_id,
                'soldier_id' => $soldier->id,
                'victim_side' => $row['side'],
                'run' => $row['run'],
                'seq' => $row['seq'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($new > 0) {
                $soldier->kills += 1;
                $soldier->save();
            }
        }
    }

    /**
     * A squad with nobody left on the island (all killed, or all in the hospital) is no longer in
     * the game, so its open orders and its chain will never be done: they are closed.
     */
    private function standDownEmpty(): void
    {
        $closed = WarOrder::query()
            ->whereIn('status', [WarOrderStatus::Queued, WarOrderStatus::Pending, WarOrderStatus::Acked])
            ->whereDoesntHave('squad.soldiers', fn ($q) => $q->whereNull('fallen_at')->where('location', 'field'))
            ->update(['status' => WarOrderStatus::Superseded, 'note' => 'Nobody left on the island to carry it out.']);
        if ($closed > 0) {
            WarLog::write('order.stood_down', $closed.' open orders closed: their squads have nobody left on the island');
        }
    }

    /** A squad this far from its defend point when the game lets the defend go never got there. */
    private const HOLD_REACH_METRES = 60.0;

    /**
     * A defend the game let go of. Holding is an order until the next one, so it goes to the game
     * again, as long as it is the squad's latest. A squad still far from the point never got
     * there (the game drops a defend it cannot walk to): once, it is sent there first (a rush,
     * then the defend behind it); the second time it stays where it is, and the order says why.
     * At the point, after HOLD_RETRIES quick drops in a row the same.
     */
    private function holdOn(WarOrder $order): void
    {
        $manned = WarSoldier::query()->where('squad_id', $order->squad_id)->whereNull('fallen_at')->where('location', 'field')->exists();
        // Smoke and look-at ride on top of the defend: they are no newer order.
        $newer = WarOrder::query()
            ->where('squad_id', $order->squad_id)
            ->where('id', '>', $order->id)
            ->whereNotIn('kind', [WarOrderKind::Smoke->value, WarOrderKind::Watch->value])
            ->exists();
        if (! $manned || $newer) {
            return;
        }
        $squad = WarSquad::query()->find($order->squad_id);
        $away = $squad !== null && hypot((float) $squad->pos_x - (float) $order->pos_x, (float) $squad->pos_z - (float) $order->pos_z) > self::HOLD_REACH_METRES;
        if ($away) {
            if ($order->attempt > 0) {
                $order->note = 'The game could not take them there: they stay where they are.';
                $order->save();

                return;
            }
            $order->note = 'Not there yet when the game let go: sent there first.';
            $order->save();
            $go = $order->replicate(['status', 'note', 'arrived_at', 'effect_until', 'attempt']);
            $go->kind = WarOrderKind::MoveForced->value;
            $go->status = WarOrderStatus::Pending;
            $go->note = 'On the way to hold.';
            $go->attempt = 1;
            $go->save();
            $hold = $order->replicate(['status', 'note', 'arrived_at', 'effect_until', 'attempt']);
            $hold->status = WarOrderStatus::Queued;
            $hold->note = 'Holding once they are there.';
            $hold->attempt = 1;
            $hold->save();

            return;
        }
        // The game lets a defend go at once when it cannot move the squad (stuck, no way
        // through): given again it lets go again, every tick. After a few in a row they stay
        // where they are, and the order says why.
        $quick = WarOrder::query()->where('squad_id', $order->squad_id)->where('kind', WarOrderKind::Defend->value)
            ->where('created_at', '>=', now()->subSeconds(self::HOLD_RETRY_WINDOW))->count();
        if ($quick >= self::HOLD_RETRIES) {
            $order->note = 'The game cannot move them to hold there: they stay where they are.';
            $order->save();

            return;
        }
        $again = $order->replicate(['status', 'note', 'arrived_at', 'effect_until', 'attempt']);
        $again->status = WarOrderStatus::Pending;
        $again->note = 'Still holding.';
        $again->save();
    }

    /**
     * Fire missions and ammunition drops the mod carried out.
     *
     * @param  list<array{id: int, status: string}>  $rows
     */
    private function missions(array $rows): void
    {
        foreach ($rows as $row) {
            $support = WarSupport::query()->whereKey($row['id'])->lockForUpdate()->first();
            if ($support === null || $support->status !== 'requested') {
                continue;
            }

            $support->status = $row['status'];
            $support->done_at = now();
            $support->note = $row['status'] === 'done'
                ? match ($support->kind) {
                    'resupply' => 'Ammunition delivered.',
                    'service' => 'Refuelled and repaired.',
                    default => 'Rounds on target.',
                }
            : 'HQ could not carry it out.';
            $support->save();
        }
    }
}
