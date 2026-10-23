<?php

namespace App\Actions\Reforger;

use App\Enums\WarOrderKind;
use App\Enums\WarOrderStatus;
use App\Enums\WarSide;
use App\Models\WarAiPlan;
use App\Models\WarContact;
use App\Models\WarControl;
use App\Models\WarEnlistment;
use App\Models\WarOrder;
use App\Models\WarSession;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Models\WarVehicle;
use App\Support\WarCondition;
use App\Support\WarEconomy;
use App\Support\WarLog;
use App\Support\WarTerritory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A living island: the sides no commander plays this week are run by an AI command, with the
 * same squads, men and vanilla orders a commander has (they belong to no player). Only while
 * at least one commander is in the war, and never one that a commander plays.
 *
 *   garrison   a squad defends each of its bases and held points (war.pve.garrisons of them)
 *   patrol     a squad walks a beat between two of its places; when its side sights the enemy
 *              near it, it goes and searches there, then takes up its beat again
 *   qrf        a garrison under fire: the patrol runs over if it is near, else a squad comes
 *              out from home, holds the post a while and goes home
 *   counter    a commander took a point near its ground: a squad rushes to a line short of it,
 *              then searches and destroys there, and holds it if it took it back
 *   raid       now and then it goes for the nearest point it does not hold, the same way
 *
 * An attack or a relief far from home (war.pve.drive_from) goes out by truck: the squad boards
 * one of the AI command's own trucks at home, drives to war.pve.drop_short short of the point,
 * gets out and goes in on foot; the truck goes back to the pool when the plan ends.
 *
 * A squad down to war.pve.retreat_share of its men falls back home (the hospital takes it) and
 * a fresh one replaces it after war.pve.raise_cooldown, as after one wiped out. The AI command
 * keeps to war.pve.budget men and always leaves war.pve.headroom of the server's AI cap to
 * commanders. When a commander enlists a side, its AI squads go home.
 */
final class RunWarAiAction
{
    /** A plan that holds its squad: reviewed every tick. */
    private const LIVE = ['active', 'retreat'];

    public function run(): void
    {
        if (! (bool) config('reforger.war.pve.enabled', false)) {
            return;
        }
        DB::transaction(function () {
            $session = WarSession::query()->whereNull('closed_at')->orderByDesc('id')->first();
            if ($session === null || ! $session->isOpen()) {
                return;
            }
            $played = WarEnlistment::query()->where('session_id', $session->id)->pluck('side')->map(fn ($s) => $s instanceof WarSide ? $s : WarSide::from($s))->unique();
            if ($played->isEmpty()) {
                return;
            }
            foreach (WarSide::cases() as $side) {
                if ($played->contains($side)) {
                    $this->handOver($session, $side);
                } else {
                    $this->tend($session, $side, $played);
                }
            }
        });
    }

    /** @param  Collection<int, WarSide>  $played */
    private function tend(WarSession $session, WarSide $side, Collection $played): void
    {
        $plans = WarAiPlan::query()->where('session_id', $session->id)->where('side', $side->value)->lockForUpdate()->get();
        foreach ($plans->whereIn('status', self::LIVE) as $plan) {
            $this->review($plan);
        }
        $active = $plans->where('status', 'active');

        $own = $this->ownPlaces($session, $side);
        $budget = $this->budget($session, $side);

        // Garrisons: bases and held points, nearest to home first.
        [$hx, $hz] = $side->muster();
        $posts = $own->filter(fn (array $p) => $p['kind'] !== 'home')
            ->sortBy(fn (array $p) => hypot($p['x'] - $hx, $p['z'] - $hz))
            ->take((int) config('reforger.war.pve.garrisons', 3));
        foreach ($posts as $post) {
            if ($active->where('kind', 'garrison')->where('place', $post['id'])->isNotEmpty() || $this->cooling($plans, 'garrison', $post['id'])) {
                continue;
            }
            $size = (int) config('reforger.war.pve.garrison_size', 4);
            if ($budget < $size) {
                break;
            }
            $squad = $this->raise($session, $side, $size);
            $this->order($squad, WarOrderKind::Defend, $post['x'], $post['z']);
            $this->plan($session, $side, 'garrison', $squad, $post);
            $budget -= $size;
        }

        // A patrol between two of its places.
        $patrol = $active->firstWhere('kind', 'patrol');
        if ($own->count() >= 2 && $patrol === null && ! $this->cooling($plans, 'patrol', null)) {
            $size = (int) config('reforger.war.pve.patrol_size', 4);
            if ($budget >= $size) {
                [$a, $b] = $own->shuffle()->take(2)->values()->all();
                $squad = $this->raise($session, $side, $size);
                $this->order($squad, WarOrderKind::Patrol, $a['x'], $a['z'], [$b['x'], $b['z']]);
                $patrol = $this->plan($session, $side, 'patrol', $squad, $a);
                $budget -= $size;
            }
        }

        // A post under fire gets help; otherwise the patrol hunts what the side has sighted.
        $budget -= $this->relieve($session, $side, $active, $plans, $patrol, $budget);
        if ($patrol !== null && $this->ready($patrol, (int) config('reforger.war.pve.hunt_cooldown', 300))) {
            $this->hunt($session, $side, $patrol, $played);
        }

        // An attack: a counterattack on a commander's point near its ground first, else a point
        // it does not hold.
        if ($active->whereIn('kind', ['counter', 'raid'])->isNotEmpty()) {
            return;
        }
        $size = (int) config('reforger.war.pve.counter_size', 6);
        if ($budget < $size) {
            return;
        }
        $target = $this->rested($plans, 'counter', (int) config('reforger.war.pve.counter_cooldown', 1800))
            ? $this->counterTarget($session, $own, $played)
            : null;
        $kind = 'counter';
        if ($target === null && $this->rested($plans, 'raid', (int) config('reforger.war.pve.raid_cooldown', 1500))) {
            $target = $this->raidTarget($own);
            $kind = 'raid';
        }
        if ($target !== null) {
            $squad = $this->raise($session, $side, $size);
            $squad->update(['alert' => 'ready']);
            $truck = $this->strike($squad, $target['x'], $target['z']);
            $this->plan($session, $side, $kind, $squad, $target, $truck);
        }
    }

    /**
     * Every tick, for each plan that holds a squad:
     *  - nobody left: lost (or done, for one that made it home)
     *  - too few left: it falls back home
     *  - an attack whose sweep is over holds the point; a relief that held long enough goes home
     *  - one falling back that cannot get home, with no enemy near, is taken off the island
     */
    private function review(WarAiPlan $plan): void
    {
        $squad = $plan->squad_id === null ? null : WarSquad::query()->whereKey($plan->squad_id)->first();
        $field = $squad === null ? collect() : WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->where('location', 'field')->get();
        if ($squad === null || $field->isEmpty()) {
            $home = $squad !== null && WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->exists();
            $this->settle($plan, $plan->status === 'retreat' && $home ? 'done' : 'lost');

            return;
        }
        $open = WarOrder::query()->where('squad_id', $squad->id)->whereIn('status', [WarOrderStatus::Queued, WarOrderStatus::Pending, WarOrderStatus::Acked])->latest('id')->first();

        if ($plan->status === 'retreat') {
            $failed = $open === null && WarOrder::query()->where('squad_id', $squad->id)->latest('id')->value('status') === WarOrderStatus::Failed;
            $stale = $plan->updated_at !== null && $plan->updated_at->lt(now()->subMinutes(40));
            if (($failed || $stale) && ! $this->enemyNear($squad, (float) config('reforger.war.pve.clear_radius', 800))) {
                // Stuck where the game cannot take them on: off the island, the way the game's own
                // AI groups are cleared away from players.
                WarSoldier::query()->whereKey($field->pluck('id'))->update(['fallen_at' => now(), 'loss' => 'discharged', 'location' => 'reserve']);
                WarLog::write('squad.discharged', 'sq '.$squad->id.' stuck on its retreat: '.$field->count().' men taken off the island', WarLog::squad($squad), ['soldiers' => $field->pluck('id')->all()]);
                $squad->alive = 0;
                $squad->save();
                RequestWarSupportAction::standDownOrders($squad->id);
                $this->settle($plan, 'done');
            } elseif ($failed) {
                $this->goHome($squad);
            }

            return;
        }

        $raised = WarSoldier::query()->where('squad_id', $squad->id)->count();
        if ($raised > 1 && $field->count() <= max(1, (int) floor($raised * (float) config('reforger.war.pve.retreat_share', 0.34)))) {
            $this->goHome($squad);
            $this->settle($plan, 'retreat');

            return;
        }

        if (in_array($plan->kind, ['counter', 'raid'], true) && $open === null) {
            // The sweep is over: they hold the point (the game's defend is given again whenever
            // it lets it go).
            $this->order($squad, WarOrderKind::Defend, (float) $plan->pos_x, (float) $plan->pos_z);
        }
        if ($plan->kind === 'qrf') {
            if ($plan->created_at->lt(now()->subSeconds((int) config('reforger.war.pve.qrf_hold', 900)))) {
                $this->goHome($squad);
                $this->settle($plan, 'retreat');
            } elseif ($open === null) {
                $this->order($squad, WarOrderKind::Defend, (float) $plan->pos_x, (float) $plan->pos_z);
            }
        }
    }

    /**
     * A garrison whose men are under fire gets help: the patrol, if it is near and not on
     * another errand, runs over and searches the post, then takes up its beat again; else a
     * fresh squad comes out from home. Once per post per war.pve.qrf_cooldown.
     *
     * @param  Collection<int, WarAiPlan>  $active
     * @param  Collection<int, WarAiPlan>  $plans
     * @return int men raised
     */
    private function relieve(WarSession $session, WarSide $side, Collection $active, Collection $plans, ?WarAiPlan $patrol, int $budget): int
    {
        $cooldown = (int) config('reforger.war.pve.qrf_cooldown', 600);
        foreach ($active->where('kind', 'garrison') as $garrison) {
            $underFire = WarSoldier::query()->where('squad_id', $garrison->squad_id)->whereNull('fallen_at')->where('location', 'field')->where('threat', 'threatened')->exists();
            if (! $underFire || $garrison->acted_at?->gt(now()->subSeconds($cooldown))) {
                continue;
            }
            $garrison->acted_at = now();
            $garrison->save();
            $post = ['id' => (string) $garrison->place, 'kind' => 'post', 'x' => (float) $garrison->pos_x, 'z' => (float) $garrison->pos_z];

            $patrolSquad = $patrol?->squad_id === null ? null : WarSquad::query()->find($patrol->squad_id);
            if ($patrolSquad !== null && $this->ready($patrol, $cooldown)
                && hypot((float) $patrolSquad->pos_x - $post['x'], (float) $patrolSquad->pos_z - $post['z']) <= (float) config('reforger.war.pve.hunt_radius', 1500)) {
                $this->divert($patrol, $patrolSquad, $post['x'], $post['z'], true);

                return 0;
            }
            $size = (int) config('reforger.war.pve.qrf_size', 4);
            if ($budget < $size || $active->where('kind', 'qrf')->where('place', $post['id'])->isNotEmpty()) {
                return 0;
            }
            $squad = $this->raise($session, $side, $size);
            $squad->update(['alert' => 'ready']);
            $truck = $this->strike($squad, $post['x'], $post['z']);
            $this->plan($session, $side, 'qrf', $squad, $post, $truck);

            return $size;
        }

        return 0;
    }

    /**
     * The patrol goes after the freshest sighting its side has of a commander's men within
     * war.pve.hunt_radius of it, searches there, and takes up its beat again.
     *
     * @param  Collection<int, WarSide>  $played
     */
    private function hunt(WarSession $session, WarSide $side, WarAiPlan $patrol, Collection $played): void
    {
        $squad = $patrol->squad_id === null ? null : WarSquad::query()->find($patrol->squad_id);
        if ($squad === null) {
            return;
        }
        $radius = (float) config('reforger.war.pve.hunt_radius', 1500);
        $sighting = WarContact::query()
            ->where('session_id', $session->id)
            ->whereIn('side', $played->map(fn (WarSide $s) => $s->value)->all())
            ->where('seen_at', '>=', now()->subSeconds((int) config('reforger.war.pve.sighting_age', 120)))
            ->whereHas('squad', fn ($q) => $q->where('side', $side->value))
            ->orderByDesc('seen_at')
            ->get()
            ->first(fn (WarContact $c) => hypot((float) $c->pos_x - (float) $squad->pos_x, (float) $c->pos_z - (float) $squad->pos_z) <= $radius);
        if ($sighting !== null) {
            $this->divert($patrol, $squad, (float) $sighting->pos_x, (float) $sighting->pos_z, false);
        }
    }

    /** The patrol off its beat: (rush to a line short of it and) search there, then the beat again. */
    private function divert(WarAiPlan $patrol, WarSquad $squad, float $x, float $z, bool $rush): void
    {
        $beat = WarOrder::query()->where('squad_id', $squad->id)->where('kind', WarOrderKind::Patrol->value)->latest('id')->first();
        if ($rush) {
            $this->assault($squad, $x, $z);
        } else {
            $this->order($squad, WarOrderKind::Search, $x, $z);
        }
        if ($beat !== null) {
            $this->order($squad, WarOrderKind::Patrol, (float) $beat->pos_x, (float) $beat->pos_z, [(float) $beat->pos2_x, (float) $beat->pos2_z], WarOrderStatus::Queued);
        }
        $patrol->acted_at = now();
        $patrol->save();
    }

    /** A side a commander enlisted on: its AI plans end and their squads go home. */
    private function handOver(WarSession $session, WarSide $side): void
    {
        $plans = WarAiPlan::query()->where('session_id', $session->id)->where('side', $side->value)->whereIn('status', self::LIVE)->lockForUpdate()->get();
        foreach ($plans as $plan) {
            $squad = $plan->squad_id === null ? null : WarSquad::query()->find($plan->squad_id);
            if ($squad !== null && WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->where('location', 'field')->exists()) {
                $this->goHome($squad);
            }
            $this->settle($plan, 'ended');
        }
    }

    private function goHome(WarSquad $squad): void
    {
        [$hx, $hz] = $squad->side->muster();
        $this->order($squad, WarOrderKind::Rest, $hx, $hz);
    }

    private function settle(WarAiPlan $plan, string $status): void
    {
        $plan->status = $status;
        $plan->save();
        // Its truck goes back to the pool (the mod takes it off the island once nobody is in it).
        if ($status !== 'retreat' && $plan->vehicle_id !== null) {
            WarVehicle::query()->whereKey($plan->vehicle_id)->whereIn('status', WarVehicle::ACTIVE)
                ->update(['status' => 'scrapped', 'lost_at' => now()]);
        }
    }

    /**
     * Send the squad at the point: far from home it boards a truck of the AI command's own,
     * drives to war.pve.drop_short short of it and gets out, then goes in as an assault; near
     * home it goes on foot. The truck's id, if it got one.
     */
    private function strike(WarSquad $squad, float $x, float $z): ?int
    {
        [$hx, $hz] = $squad->side->muster();
        $metres = hypot($x - $hx, $z - $hz);
        $short = (float) config('reforger.war.pve.drop_short', 500);
        if ($metres < (float) config('reforger.war.pve.drive_from', 1500)) {
            $this->assault($squad, $x, $z);

            return null;
        }
        [$vx, $vz] = RunWarRunsAction::bay($hx, $hz, WarVehicle::query()->where('session_id', $squad->session_id)->count());
        $truck = WarVehicle::query()->create([
            'session_id' => $squad->session_id,
            'squad_id' => $squad->id,
            'commander_id' => null,
            'kind' => 'truck',
            'side' => $squad->side,
            'status' => 'ordered',
            'pos_x' => $vx,
            'pos_z' => $vz,
            'cost' => 0,
        ]);
        $k = ($metres - $short) / $metres;
        $dx = $hx + ($x - $hx) * $k;
        $dz = $hz + ($z - $hz) * $k;
        $this->order($squad, WarOrderKind::Mount, $vx, $vz, null, WarOrderStatus::Pending, $truck->id);
        $this->order($squad, WarOrderKind::Move, $dx, $dz, null, WarOrderStatus::Queued);
        $this->order($squad, WarOrderKind::Dismount, $dx, $dz, null, WarOrderStatus::Queued);
        $this->order($squad, WarOrderKind::MoveForced, $hx + ($x - $hx) * (($metres - 150) / $metres), $hz + ($z - $hz) * (($metres - 150) / $metres), null, WarOrderStatus::Queued);
        $this->order($squad, WarOrderKind::Search, $x, $z, null, WarOrderStatus::Queued);

        return $truck->id;
    }

    /** Whether a squad of a side other than this one is within $radius of it. */
    private function enemyNear(WarSquad $squad, float $radius): bool
    {
        return WarSquad::query()
            ->where('session_id', $squad->session_id)
            ->where('side', '!=', $squad->side->value)
            ->where('alive', '>', 0)
            ->get(['pos_x', 'pos_z'])
            ->contains(fn (WarSquad $s) => hypot((float) $s->pos_x - (float) $squad->pos_x, (float) $s->pos_z - (float) $squad->pos_z) <= $radius);
    }

    /** Whether the plan's squad may take on a new errand. */
    private function ready(?WarAiPlan $plan, int $cooldown): bool
    {
        return $plan !== null && ($plan->acted_at === null || $plan->acted_at->lt(now()->subSeconds($cooldown)));
    }

    /**
     * A plan of this kind (at this place) ended a short while ago: no new squad yet.
     *
     * @param  Collection<int, WarAiPlan>  $plans
     */
    private function cooling(Collection $plans, string $kind, ?string $place): bool
    {
        $cooldown = (int) config('reforger.war.pve.raise_cooldown', 900);

        return $plans->contains(fn (WarAiPlan $p) => in_array($p->status, ['lost', 'retreat', 'done'], true) && $p->kind === $kind && ($place === null || $p->place === $place)
            && $p->updated_at !== null && $p->updated_at->gt(now()->subSeconds($cooldown)));
    }

    /**
     * No attack of this kind for the cooldown since the last one began.
     *
     * @param  Collection<int, WarAiPlan>  $plans
     */
    private function rested(Collection $plans, string $kind, int $cooldown): bool
    {
        $last = $plans->where('kind', $kind)->max('created_at');
        // The first raid only once the side has settled in: it holds its own before it goes out.
        if ($last === null && $kind === 'raid') {
            $last = $plans->min('created_at');
            if ($last === null) {
                return false;
            }
        }

        return $last === null || now()->diffInSeconds($last, true) >= $cooldown;
    }

    /**
     * The side's own places as config gives them (home, bases, its towns) and the points it holds.
     *
     * @return Collection<int, array{id: string, kind: string, x: float, z: float}>
     */
    private function ownPlaces(WarSession $session, WarSide $side): Collection
    {
        $places = collect((array) config('reforger.war.places', []));
        $held = WarControl::query()->where('session_id', $session->id)->where('side', $side->value)->pluck('place')->all();

        return $places
            ->filter(fn (array $p) => ($p['side'] ?? null) === $side->value || in_array($p['id'], $held, true))
            ->map(fn (array $p) => ['id' => (string) $p['id'], 'kind' => (string) $p['kind'], 'x' => (float) $p['x'], 'z' => (float) $p['z']])
            ->values();
    }

    /**
     * The commanders' point nearest the side's ground, within war.pve.counter_radius of it.
     *
     * @param  Collection<int, array{id: string, kind: string, x: float, z: float}>  $own
     * @param  Collection<int, WarSide>  $played
     * @return array{id: string, kind: string, x: float, z: float}|null
     */
    private function counterTarget(WarSession $session, Collection $own, Collection $played): ?array
    {
        $held = WarControl::query()->where('session_id', $session->id)->whereIn('side', $played->map(fn (WarSide $s) => $s->value)->all())->pluck('place')->all();

        return $this->nearest($own, fn (array $p) => in_array($p['id'], $held, true), (float) config('reforger.war.pve.counter_radius', 2500));
    }

    /**
     * The point nearest the side's ground that is not its own (no one's, a commander's, the
     * other AI side's), within war.pve.raid_radius of it.
     *
     * @param  Collection<int, array{id: string, kind: string, x: float, z: float}>  $own
     * @return array{id: string, kind: string, x: float, z: float}|null
     */
    private function raidTarget(Collection $own): ?array
    {
        $mine = $own->pluck('id')->all();

        return $this->nearest($own, fn (array $p) => ! in_array($p['id'], $mine, true), (float) config('reforger.war.pve.raid_radius', 3000));
    }

    /**
     * @param  Collection<int, array{id: string, kind: string, x: float, z: float}>  $own
     * @param  callable(array<string, mixed>): bool  $wanted
     * @return array{id: string, kind: string, x: float, z: float}|null
     */
    private function nearest(Collection $own, callable $wanted, float $radius): ?array
    {
        $best = null;
        foreach (WarTerritory::points() as $point) {
            if (! $wanted($point)) {
                continue;
            }
            $near = $own->min(fn (array $p) => hypot($p['x'] - (float) $point['x'], $p['z'] - (float) $point['z']));
            if ($near !== null && $near <= $radius && ($best === null || $near < $best[0])) {
                $best = [$near, ['id' => (string) $point['id'], 'kind' => (string) $point['kind'], 'x' => (float) $point['x'], 'z' => (float) $point['z']]];
            }
        }

        return $best[1] ?? null;
    }

    /** Men the AI command may still put on the island for this side. */
    private function budget(WarSession $session, WarSide $side): int
    {
        $mine = WarSoldier::query()->whereNull('fallen_at')->where('location', 'field')
            ->whereHas('squad', fn ($q) => $q->where('session_id', $session->id)->where('side', $side->value)->where('ai', true))->count();
        $room = (int) config('reforger.ai_limit', 192) - (int) config('reforger.war.pve.headroom', 64) - WarEconomy::fieldStrength($session->id);

        return max(0, min((int) config('reforger.war.pve.budget', 32) - $mine, $room));
    }

    /** A squad of the side's own soldier types, raised at its home: a leader, a gunner, a medic, riflemen. */
    private function raise(WarSession $session, WarSide $side, int $size): WarSquad
    {
        [$x, $z] = $side->muster();
        $squad = WarSquad::query()->create([
            'session_id' => $session->id,
            'commander_id' => null,
            'side' => $side,
            'strength' => $size,
            'alive' => $size,
            'tier' => 0,
            'pos_x' => $x,
            'pos_z' => $z,
            'managed' => true,
            'ai' => true,
            'combat' => 'free',
            'alert' => 'alert',
        ]);
        $pick = fn (array $wanted) => collect($wanted)->first(fn (string $id) => in_array($side->value, WarEconomy::role($id)['sides'], true));
        $roles = array_fill(0, $size, 'rifleman');
        $roles[0] = $pick(['squad_leader', 'team_leader']) ?? 'rifleman';
        if ($size >= 4) {
            $roles[1] = $pick(['autorifle', 'machinegun']) ?? 'rifleman';
            $roles[$size - 1] = $pick(['medic']) ?? 'rifleman';
        }
        $taken = [];
        foreach ($roles as $role) {
            $spec = WarEconomy::role($role);
            $name = WarEconomy::unusedName($side->value, $taken);
            $taken[] = $name;
            WarSoldier::query()->create([
                'session_id' => $session->id,
                'squad_id' => $squad->id,
                'commander_id' => null,
                'role' => $role,
                'name' => $name,
                'note' => '',
                'aim' => $spec['aim'],
                'grit' => $spec['grit'],
                'fieldcraft' => $spec['fieldcraft'],
                'spent' => 0,
                'service' => 0,
                'joined_at' => now(),
                'service_at' => now(),
                'morale' => WarCondition::baseMorale(0),
                'condition_at' => now(),
            ]);
        }

        return $squad;
    }

    /** @param  array{0: float, 1: float}|null  $second */
    private function order(WarSquad $squad, WarOrderKind $kind, float $x, float $z, ?array $second = null, WarOrderStatus $status = WarOrderStatus::Pending, ?int $vehicleId = null): WarOrder
    {
        if ($status === WarOrderStatus::Pending) {
            WarOrder::query()->where('squad_id', $squad->id)
                ->whereIn('status', [WarOrderStatus::Queued, WarOrderStatus::Pending, WarOrderStatus::Acked])
                ->update(['status' => WarOrderStatus::Superseded]);
        }

        return WarOrder::query()->create([
            'session_id' => $squad->session_id,
            'squad_id' => $squad->id,
            'commander_id' => null,
            'kind' => $kind->value,
            'status' => $status,
            'pos_x' => round($x, 2),
            'pos_z' => round($z, 2),
            'pos2_x' => $second !== null ? round($second[0], 2) : null,
            'pos2_z' => $second !== null ? round($second[1], 2) : null,
            'vehicle_id' => $vehicleId,
        ]);
    }

    /** As a commander's Assault: rush to a line short of the point, then search and destroy there. */
    private function assault(WarSquad $squad, float $x, float $z): void
    {
        $from = [(float) $squad->pos_x, (float) $squad->pos_z];
        $metres = hypot($x - $from[0], $z - $from[1]);
        $standOff = 150.0;
        if ($metres > $standOff + 50) {
            $k = ($metres - $standOff) / $metres;
            $this->order($squad, WarOrderKind::MoveForced, $from[0] + ($x - $from[0]) * $k, $from[1] + ($z - $from[1]) * $k);
            $this->order($squad, WarOrderKind::Search, $x, $z, null, WarOrderStatus::Queued);

            return;
        }
        $this->order($squad, WarOrderKind::Search, $x, $z);
    }

    /** @param  array{id: string, kind: string, x: float, z: float}  $place */
    private function plan(WarSession $session, WarSide $side, string $kind, WarSquad $squad, array $place, ?int $vehicleId = null): WarAiPlan
    {
        return WarAiPlan::query()->create([
            'session_id' => $session->id,
            'side' => $side,
            'kind' => $kind,
            'squad_id' => $squad->id,
            'place' => $place['id'],
            'pos_x' => round($place['x'], 2),
            'pos_z' => round($place['z'], 2),
            'vehicle_id' => $vehicleId,
        ]);
    }
}
