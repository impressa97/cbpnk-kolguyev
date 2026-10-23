<?php

namespace App\Actions\Reforger;

use App\Enums\WarOrderKind;
use App\Enums\WarOrderStatus;
use App\Enums\WarSide;
use App\Enums\WarSupplyReason;
use App\Models\User;
use App\Models\WarAiPlan;
use App\Models\WarContact;
use App\Models\WarControl;
use App\Models\WarEnlistment;
use App\Models\WarObjective;
use App\Models\WarOrder;
use App\Models\WarRun;
use App\Models\WarSession;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Models\WarSupplyEntry;
use App\Models\WarSupport;
use App\Models\WarTask;
use App\Models\WarVehicle;
use App\Support\WarCondition;
use App\Support\WarEconomy;
use App\Support\WarOperations;
use App\Support\WarSquads;
use App\Support\WarTerritory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Inertia slice for the Kolguyev command panel on /reforger.
 *
 * Fog of war: a commander sees their own side's squads and soldiers, and enemies only as
 * contacts their side's squads report from the game. Other sides show as counts, and the
 * island log keeps their moves, losses, and supplies private.
 */
final class BuildWarCommandDataAction
{
    /** A seen vehicle within this of one of the enemy's known vehicles is that vehicle. */
    private const VEHICLE_MATCH_METRES = 40.0;

    public function __construct(
        private readonly AccrueWarSuppliesAction $accrue,
        private readonly AccrueWarServiceAction $service,
        private readonly ResolveWarSessionAction $sessions,
        private readonly ResolveWarSupportAction $support,
        private readonly RunWarHqAction $hq,
        private readonly RunWarObjectivesAction $objectives,
        private readonly ControlWarTerritoryAction $territory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $player): array
    {
        $this->advance();

        return $this->view($player);
    }

    /**
     * Let the week's systems catch up: support, HQ tasks, territory income, objectives. Every
     * bridge tick does this; a page does it only while the game server is not reporting, so a
     * commander's page and the tick do not fight over the same rows.
     */
    public function advance(): void
    {
        $this->sessions->closeDue();
        $display = $this->sessions->display();
        if ($display !== null && $display->isOpen() && ! IngestWarStateAction::reporting()) {
            $this->support->resolve($display->id);
            $this->hq->run();
            $this->territory->run();
            $this->objectives->run();
        }
    }

    /** Rows HQ no longer offers, or never offered on the menu: rearming is logistics. */
    private const SUPPORT_NAMES = [
        'resupply' => 'Rearm',
        'service' => 'Refuel and repair',
        'medevac' => 'Casualty evacuation',
        'extract' => 'Squad extraction',
    ];

    /**
     * The desk as this commander sees it, without advancing anything.
     *
     * @return array<string, mixed>
     */
    public function view(User $player): array
    {
        $display = $this->sessions->display();
        $open = $display?->isOpen() ?? false;
        $enlisted = $this->collect($player, $display, $open);
        $squads = $display === null
            ? collect()
            : WarSquad::query()->with(['player', 'soldiers'])->where('session_id', $display->id)->orderBy('id')->get();
        $orders = $display === null
            ? collect()
            : WarOrder::query()
                ->where('session_id', $display->id)
                ->where('commander_id', $player->id)
                ->latest('id')
                ->limit(24)
                ->get();
        $mineSquads = $squads->where('commander_id', $player->id)->values();
        $mine = $mineSquads->first();
        $viewerSide = $enlisted?->side;
        $visible = $viewerSide === null
            ? collect()
            : $squads->filter(fn (WarSquad $squad) => $squad->side === $viewerSide)->values();

        $labels = WarSquads::labels($squads);

        return [
            'world_size' => (int) config('reforger.war.world_size', 12800),
            'squad_strength' => (int) config('reforger.war.squad_strength', 4),
            // A control point is taken within this many metres of it.
            'capture_radius' => (int) config('reforger.war.territory.radius', 150),
            'max_squads' => WarEconomy::maxSquads(),
            'ai_limit' => (int) config('reforger.ai_limit', 192),
            'enlisted_side' => $enlisted?->side->value,
            'session' => [
                'open' => $open,
                'starts_at' => $display?->starts_at->toIso8601String(),
                'ends_at' => $display?->ends_at->toIso8601String(),
                'seconds_left' => $open ? max(0, (int) now()->diffInSeconds($display->ends_at, false)) : 0,
            ],
            'sides' => array_map(
                fn (WarSide $side) => [
                    'id' => $side->value,
                    'name' => $side->label(),
                    'detail' => $side->detail(),
                    // Squads with men in them: an empty one is only a name.
                    'squads' => $squads->filter(fn (WarSquad $squad) => $squad->side === $side && $squad->strength > 0)->count(),
                    'commanders' => $squads->filter(fn (WarSquad $squad) => $squad->side === $side)->unique('commander_id')->count(),
                    'home' => $this->home($side),
                ],
                WarSide::cases(),
            ),
            'squads' => $visible->map(function (WarSquad $squad) use ($player, $labels) {
                $name = $squad->player?->name ?? 'Squad '.$squad->id;

                return [
                    'id' => $squad->id,
                    'commander_id' => $squad->commander_id,
                    'name' => $name,
                    // The commander's name for it, or its callsign; HQ detachments are "HQ ...".
                    'label' => $labels[$squad->id] ?? '',
                    'side' => $squad->side->value,
                    'strength' => $squad->strength,
                    'alive' => $squad->alive,
                    'tier' => $squad->tier,
                    'tier_name' => WarEconomy::tierName($squad->tier),
                    'train_cost' => WarEconomy::trainCost($squad->tier),
                    'train_name' => WarEconomy::trainCost($squad->tier) === null
                        ? 'Veteran'
                        : WarEconomy::tierName($squad->tier + 1),
                    'x' => (float) $squad->pos_x,
                    'z' => (float) $squad->pos_z,
                    'mine' => $squad->commander_id === $player->id,
                    'managed' => $squad->managed,
                    'task_id' => $squad->task_id,
                    'condition' => $this->squadCondition($squad),
                    // The commander's combat mode, and what the game says the group is doing.
                    'combat' => $squad->combat,
                    'combat_actual' => $squad->combat_actual,
                    // Formation and pace for the whole squad (group AI settings; auto: the AI's own).
                    'formation' => $squad->formation,
                    'alert' => $squad->alert,
                    'pace' => $squad->pace_order,
                    'doing' => $squad->doing,
                ];
            })->values()->all(),
            'orders' => $orders->map(fn (WarOrder $order) => [
                'id' => $order->id,
                'squad_id' => $order->squad_id,
                'soldier_id' => $order->soldier_id,
                'vehicle_id' => $order->vehicle_id,
                'follow_squad_id' => $order->follow_squad_id,
                'route' => array_map(fn (array $p) => ['x' => (float) $p[0], 'z' => (float) $p[1]], $order->route ?? []),
                'kind' => $order->kind,
                'status' => $order->status->value,
                'x2' => $order->pos2_x !== null ? (float) $order->pos2_x : null,
                'z2' => $order->pos2_z !== null ? (float) $order->pos2_z : null,
                'x' => (float) $order->pos_x,
                'z' => (float) $order->pos_z,
                'target' => $order->target,
                'note' => $order->note,
                'effect_until' => $order->effect_until?->toIso8601String(),
            ])->values()->all(),
            'places' => $this->places(),
            // HQ detachments run themselves: the commander sees them down to the man, but they
            // are HQ's (hq) and take no orders from him.
            'markers' => $this->markers($visible, $player->id),
            'contacts' => $display === null || $viewerSide === null ? [] : $this->contacts($display, $viewerSide),
            'intel' => $display === null || $viewerSide === null ? ['blind_until' => null, 'operations' => []] : $this->intel($display, $viewerSide),
            'world' => Cache::get(IngestWarStateAction::WORLD_CACHE),
            // Where the island's state comes from; the page judges freshness from `at`.
            'feed' => Cache::get(IngestWarStateAction::FEED_CACHE),
            'vehicles' => $display === null ? [] : $this->vehicles($display, $player),
            'motor_pool' => $viewerSide === null ? [] : WarEconomy::vehicles($viewerSide),
            'vehicle_limit' => (int) config('reforger.war.vehicle_limit', 4),
            // HQ runs for this commander's squads, under way and lately finished.
            'runs' => $display === null ? [] : WarRun::query()
                ->where('session_id', $display->id)
                ->where('commander_id', $player->id)
                ->where(fn ($q) => $q->where('status', 'active')->orWhere('updated_at', '>', now()->subMinutes(10)))
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (WarRun $r) => [
                    'id' => $r->id,
                    'kind' => $r->kind,
                    'squad_id' => $r->squad_id,
                    'vehicle_id' => $r->vehicle_id,
                    'stage' => $r->stage,
                    'status' => $r->status,
                    'note' => $r->note,
                ])->values()->all(),
            'run_costs' => array_map('intval', (array) config('reforger.war.runs.cost', [])),
            // Where a squad gets its wounded seen to and its magazines (SupplyWarSquadAction).
            'logistics' => [
                'home_radius' => (int) config('reforger.war.logistics.home_radius', 200),
                'truck_radius' => (int) config('reforger.war.logistics.truck_radius', 40),
                'truck_cost' => (int) config('reforger.war.logistics.truck_cost', 10),
                'service_cost' => (int) config('reforger.war.logistics.service_cost', 15),
                'magazines' => (int) config('reforger.war.logistics.magazines', 6),
                'ammo_vehicles' => array_values((array) config('reforger.war.logistics.ammo_vehicles', ['truck', 'apc'])),
            ],
            'support' => $display === null ? [] : $this->supportRequests($display, $player),
            'support_catalog' => $this->supportCatalog($player),
            'tasks' => $display === null ? [] : $this->tasks($display, $player),
            'hq' => [...$this->hqBudget($display, $player), 'durations' => array_values(array_map('intval', config('reforger.war.hq.durations', [30, 60, 120])))],
            'operations' => WarOperations::catalog(),
            'objectives' => $display === null || $viewerSide === null ? [] : $this->objectiveList($display, $viewerSide, $player),
            'territory' => $display === null ? [] : $this->territoryList($display),
            'income' => [
                'base' => WarEconomy::incomePerHour(),
                'territory' => $display === null || $viewerSide === null ? 0 : WarTerritory::incomeFor($display->id, $viewerSide),
            ],
            'events' => $display === null ? [] : $this->events($display, $squads, $viewerSide),
            'soldiers' => $this->roster($mineSquads->where('managed', false), false),
            'fallen' => $this->roster($mineSquads->where('managed', false), true),
            'catalog' => WarEconomy::catalog(),
            'economy' => $this->economy($enlisted, $mine, $display === null ? 0 : WarEconomy::fieldStrength($display->id), $mineSquads->where('managed', false)->count()),
        ];
    }

    /**
     * @return list<array{id: string, name: string, side: string|null, kind: string, x: int, z: int}>
     */
    private function places(): array
    {
        $places = config('reforger.war.places', []);
        if (! is_array($places)) {
            return [];
        }

        return array_values(array_map(fn (array $place) => [
            'id' => (string) $place['id'],
            'name' => (string) $place['name'],
            'side' => $place['side'] ?? null,
            'kind' => (string) $place['kind'],
            'x' => (int) $place['x'],
            'z' => (int) $place['z'],
        ], $places));
    }

    /**
     * @param  Collection<int, WarSquad>  $squads
     * @return list<array<string, mixed>>
     */
    private function markers(Collection $squads, int $playerId): array
    {
        $size = (int) config('reforger.war.world_size', 12800);
        // Where the enemy each of our men is fighting stands: he shoots at him, so he sees him.
        $targets = WarSoldier::query()
            ->whereKey($squads->flatMap(fn (WarSquad $s) => $s->soldiers->pluck('target_id'))->filter()->unique()->values())
            ->whereNull('fallen_at')->where('location', 'field')->whereNotNull('pos_x')
            ->get(['id', 'pos_x', 'pos_z'])
            ->keyBy('id');

        return $squads->flatMap(function (WarSquad $squad) use ($playerId, $size, $targets) {
            return $squad->soldiers
                ->whereNull('fallen_at')
                ->where('location', 'field')
                ->sortBy('id')
                ->values()
                ->map(function (WarSoldier $soldier, int $index) use ($squad, $playerId, $size, $targets) {
                    [$east, $north] = WarEconomy::formationOffset($index);
                    $reported = $soldier->pos_x !== null && $soldier->pos_z !== null;
                    [$hx, $hz] = $squad->side->muster();

                    return [
                        'id' => $soldier->id,
                        'squad_id' => $squad->id,
                        'name' => $soldier->name,
                        'role_name' => WarEconomy::role($soldier->role)['name'],
                        // What he does in a fight, for his symbol.
                        'role' => WarEconomy::roleClass($soldier->role),
                        'side' => $squad->side->value,
                        // Until the mod reports him, a man stands at his side's spawn, where the
                        // game puts every new man and every return from the reserve.
                        'x' => $this->metres($reported ? (float) $soldier->pos_x : $hx + $east, $size),
                        'z' => $this->metres($reported ? (float) $soldier->pos_z : $hz + $north, $size),
                        'mine' => $squad->commander_id === $playerId,
                        'hq' => $squad->managed,
                        // How he is, for the label beside him on the map.
                        'health' => $soldier->health,
                        'bleeding' => $soldier->bleeding,
                        'down' => $soldier->down,
                        'threat' => $soldier->threat,
                        'in_vehicle' => $soldier->in_vehicle,
                        'seat' => $soldier->seat,
                        'stance' => $soldier->stance,
                        'action' => $soldier->action,
                        'heading' => $soldier->heading,
                        'target' => ($foe = $targets->get($soldier->target_id ?? 0)) === null
                            ? null
                            : ['x' => $this->metres((float) $foe->pos_x, $size), 'z' => $this->metres((float) $foe->pos_z, $size)],
                        'reported' => $reported,
                    ];
                });
        })->values()->all();
    }

    /**
     * @param  Collection<int, WarSquad>  $squads
     * @return list<array{id: string, at: string, kind: string, text: string}>
     */
    private function events(WarSession $session, Collection $squads, ?WarSide $viewerSide): array
    {
        /** @var Collection<int, array{id: string, at: string, sort: int, kind: string, text: string}> $rows */
        $rows = collect();

        foreach ($squads as $squad) {
            $side = $squad->side->label();

            // Another side: everyone hears a new squad land, nothing more.
            if ($squad->side !== $viewerSide) {
                $rows->push([
                    'id' => 'squad-'.$squad->id,
                    'at' => $squad->created_at->toIso8601String(),
                    'sort' => $squad->created_at->getTimestamp(),
                    'kind' => 'join',
                    'text' => "{$side} put a squad on the island",
                ]);

                continue;
            }

            $commander = $this->commanderName($squad->player);

            foreach ($squad->soldiers as $soldier) {
                $joined = $soldier->joined_at ?? $soldier->created_at;
                if ($joined !== null) {
                    $role = WarEconomy::role($soldier->role)['name'];
                    $rows->push([
                        'id' => 'join-'.$soldier->id,
                        'at' => $joined->toIso8601String(),
                        'sort' => $joined->getTimestamp(),
                        'kind' => 'join',
                        'text' => "{$commander} · {$soldier->name} joined {$side} as {$role}",
                    ]);
                }

                if ($soldier->fallen_at !== null) {
                    $how = $soldier->loss === 'killed' ? 'was killed' : 'was discharged';
                    $rows->push([
                        'id' => 'loss-'.$soldier->id,
                        'at' => $soldier->fallen_at->toIso8601String(),
                        'sort' => $soldier->fallen_at->getTimestamp(),
                        'kind' => 'loss',
                        'text' => "{$commander} · {$soldier->name} {$how}",
                    ]);
                }
            }
        }

        $ownSquads = $squads->filter(fn (WarSquad $squad) => $squad->side === $viewerSide)->pluck('id')->all();
        $labels = WarSquads::labels($squads);
        $ownPlayers = $squads->filter(fn (WarSquad $squad) => $squad->side === $viewerSide)->pluck('commander_id')->unique()->all();

        $orders = WarOrder::query()
            ->with(['player', 'squad', 'soldier'])
            ->where('session_id', $session->id)
            ->whereIn('squad_id', $ownSquads)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        foreach ($orders as $order) {
            $rows->push([
                'id' => 'move-'.$order->id,
                'at' => $order->created_at->toIso8601String(),
                'sort' => $order->created_at->getTimestamp(),
                'kind' => 'move',
                'text' => ($order->kind === 'heal' && $order->soldier !== null
                    ? sprintf('%s sent the medic to %s · %s', $this->commanderName($order->player), $order->soldier->name, $this->orderStatus($order))
                    : sprintf(
                        '%s · %s: %s at %s · %s',
                        $this->commanderName($order->player),
                        $labels[$order->squad_id] ?? 'Squad',
                        strtolower((WarOrderKind::tryFrom($order->kind) ?? WarOrderKind::Move)->label()),
                        $this->gridRef((float) $order->pos_x, (float) $order->pos_z),
                        $this->orderStatus($order),
                    )).($order->note !== '' ? ' · '.$order->note : ''),
            ]);
        }

        // Enemies our men killed, as the game reported them.
        $kills = DB::table('cbpnk_war_kills as k')
            ->join('cbpnk_war_soldiers as s', 's.id', '=', 'k.soldier_id')
            ->where('k.session_id', $session->id)
            ->whereIn('s.squad_id', $ownSquads)
            ->orderByDesc('k.id')
            ->limit(40)
            ->get(['k.id', 'k.victim_side', 'k.created_at', 's.name', 's.squad_id']);
        foreach ($kills as $kill) {
            $at = Carbon::parse($kill->created_at);
            $rows->push([
                'id' => 'kill-'.$kill->id,
                'at' => $at->toIso8601String(),
                'sort' => $at->getTimestamp(),
                'kind' => 'kill',
                'text' => sprintf('%s · %s killed a %s soldier', $labels[$kill->squad_id] ?? 'Squad', $kill->name, strtoupper($kill->victim_side)),
            ]);
        }

        // Your side's vehicles lost or sent back.
        foreach (WarVehicle::query()->where('session_id', $session->id)->whereIn('commander_id', $ownPlayers)->whereNotNull('lost_at')->with('player')->latest('lost_at')->limit(20)->get() as $vehicle) {
            $rows->push([
                'id' => 'vehicle-'.$vehicle->id,
                'at' => $vehicle->lost_at->toIso8601String(),
                'sort' => $vehicle->lost_at->getTimestamp(),
                'kind' => $vehicle->status === 'destroyed' ? 'loss' : 'vehicle',
                'text' => sprintf('%s · %s %s', $this->commanderName($vehicle->player), WarEconomy::vehicle($vehicle->kind, $vehicle->side)['model'], $vehicle->status === 'destroyed' ? 'was destroyed' : 'went back to the motor pool'),
            ]);
        }

        // HQ fire, rearming and servicing: how each went.
        foreach (WarSupport::query()->where('session_id', $session->id)->whereIn('commander_id', $ownPlayers)->whereNotNull('done_at')->with('player')->latest('done_at')->limit(30)->get() as $support) {
            $name = self::SUPPORT_NAMES[$support->kind] ?? (in_array($support->kind, WarEconomy::supportIds(), true) ? WarEconomy::support($support->kind)['name'] : $support->kind);
            $where = $support->pos_x !== null ? ' at '.$this->gridRef((float) $support->pos_x, (float) $support->pos_z) : '';
            $rows->push([
                'id' => 'support-'.$support->id,
                'at' => $support->done_at->toIso8601String(),
                'sort' => $support->done_at->getTimestamp(),
                'kind' => 'fire',
                'text' => sprintf('%s · %s%s · %s', $this->commanderName($support->player), $name, $where, $support->note !== '' ? $support->note : $support->status),
            ]);
        }

        // Ground changing hands: flags are public.
        foreach (WarControl::query()->where('session_id', $session->id)->whereNotNull('side')->whereNotNull('held_since')->get() as $control) {
            $place = collect((array) config('reforger.war.places', []))->firstWhere('id', $control->place);
            $rows->push([
                'id' => 'taken-'.$control->id.'-'.$control->held_since->getTimestamp(),
                'at' => $control->held_since->toIso8601String(),
                'sort' => $control->held_since->getTimestamp(),
                'kind' => 'territory',
                'text' => sprintf('%s took %s', strtoupper((string) $control->side), $place['name'] ?? $control->place),
            ]);
        }

        // HQ intelligence: enemy attacks and reinforcements on the move, and how they ended.
        if ($viewerSide !== null) {
            $names = collect((array) config('reforger.war.places', []))->pluck('name', 'id');
            $what = ['counter' => 'counterattack', 'raid' => 'attack', 'qrf' => 'reinforcements'];
            $plans = WarAiPlan::query()->where('session_id', $session->id)->where('side', '!=', $viewerSide->value)
                ->whereIn('kind', array_keys($what))->latest('id')->limit(20)->get();
            foreach ($plans as $plan) {
                $name = $names[$plan->place] ?? $plan->place;
                $who = sprintf('%s %s', strtoupper($plan->side->value), $what[$plan->kind]);
                $rows->push([
                    'id' => 'ai-'.$plan->id,
                    'at' => $plan->created_at->toIso8601String(),
                    'sort' => $plan->created_at->getTimestamp(),
                    'kind' => 'threat',
                    'text' => sprintf('HQ: %s heading for %s%s', $who, $name, $plan->vehicle_id !== null ? ' in trucks' : ''),
                ]);
                $end = ['lost' => 'was wiped out', 'retreat' => 'fell back', 'done' => 'fell back'][$plan->status] ?? null;
                if ($end !== null && $plan->kind !== 'qrf') {
                    $rows->push([
                        'id' => 'ai-end-'.$plan->id,
                        'at' => $plan->updated_at->toIso8601String(),
                        'sort' => $plan->updated_at->getTimestamp(),
                        'kind' => 'territory',
                        'text' => sprintf('The %s on %s %s', $who, $name, $end),
                    ]);
                }
            }
        }

        // Your side's HQ objectives: done or lapsed.
        if ($viewerSide !== null) {
            foreach (WarObjective::query()->where('session_id', $session->id)->where('side', $viewerSide->value)->whereIn('status', ['done', 'expired'])->latest('updated_at')->limit(20)->get() as $objective) {
                $at = $objective->done_at ?? $objective->updated_at;
                $rows->push([
                    'id' => 'objective-'.$objective->id,
                    'at' => $at->toIso8601String(),
                    'sort' => $at->getTimestamp(),
                    'kind' => 'objective',
                    'text' => $objective->status === 'done' ? "Objective done: {$objective->title} · +{$objective->reward}" : "Objective lapsed: {$objective->title}",
                ]);
            }
        }

        // HQ detachments raised and called in.
        foreach (WarTask::query()->where('session_id', $session->id)->whereIn('commander_id', $ownPlayers)->with('player')->latest('id')->limit(20)->get() as $task) {
            $what = ($task->kind === 'hold' ? 'secure ' : 'patrol ').($task->label !== '' ? $task->label : $this->gridRef((float) $task->a_x, (float) $task->a_z));
            $rows->push([
                'id' => 'task-'.$task->id,
                'at' => $task->created_at->toIso8601String(),
                'sort' => $task->created_at->getTimestamp(),
                'kind' => 'hq',
                'text' => sprintf('%s · HQ to %s with %d men', $this->commanderName($task->player), $what, $task->size),
            ]);
            if ($task->status === 'closed') {
                $rows->push([
                    'id' => 'task-end-'.$task->id,
                    'at' => $task->updated_at->toIso8601String(),
                    'sort' => $task->updated_at->getTimestamp(),
                    'kind' => 'hq',
                    'text' => sprintf('HQ called in the detachment to %s · %s', $what, $task->note),
                ]);
            }
        }

        $entries = WarSupplyEntry::query()
            ->with('player')
            ->where('session_id', $session->id)
            ->whereIn('commander_id', $ownPlayers)
            // Steady income trickles in a unit at a time; the HUD shows the rate, the feed the events.
            ->where('reason', '!=', WarSupplyReason::Income->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        foreach ($entries as $entry) {
            $rows->push([
                'id' => 'supply-'.$entry->id,
                'at' => $entry->created_at->toIso8601String(),
                'sort' => $entry->created_at->getTimestamp(),
                'kind' => 'supply',
                'text' => $this->commanderName($entry->player).' · '.$this->supplyText($entry),
            ]);
        }

        return $rows
            ->sortBy([['sort', 'desc'], ['id', 'desc']])
            ->take(60)
            ->map(fn (array $row) => [
                'id' => $row['id'],
                'at' => $row['at'],
                'kind' => $row['kind'],
                'text' => $row['text'],
            ])
            ->values()
            ->all();
    }

    /**
     * Enemies this side knows about, freshest first. Reports within 60 m of each other from
     * several squads are one contact, the way the in-game map would show one marker.
     *
     * @return list<array{id: int, side: string, x: int, z: int, kind: string, how: string, seen_at: string, age_s: int}>
     */
    private function contacts(WarSession $session, WarSide $viewerSide): array
    {
        if ($this->blindUntil($session, $viewerSide) !== null) {
            return [];
        }

        $observers = $this->activeOps($session)
            ->filter(fn (WarOrder $order) => $order->kind === WarOrderKind::Recon->value && $order->squad?->side === $viewerSide)
            ->pluck('squad_id')
            ->all();
        $intel = (int) config('reforger.war.operations.recon.intel_minutes', 30);

        $rows = WarContact::query()
            ->where('session_id', $session->id)
            ->where('side', '!=', $viewerSide->value)
            ->where(fn ($query) => $query
                ->where('seen_at', '>=', now()->subMinutes((int) config('reforger.war.contact_minutes', 15)))
                ->orWhere(fn ($q) => $q->whereIn('squad_id', $observers)->where('seen_at', '>=', now()->subMinutes($intel))))
            ->whereHas('squad', fn ($query) => $query->where('side', $viewerSide->value))
            ->orderByDesc('seen_at')
            ->limit(200)
            ->get();

        $kept = [];
        foreach ($rows as $row) {
            $x = (float) $row->pos_x;
            $z = (float) $row->pos_z;
            // Several squads seeing one man: one marker. A man the game cannot name: one per 60 m.
            foreach ($kept as $other) {
                $same = $row->target_id !== null
                    ? $other['target_id'] === $row->target_id
                    : $other['target_id'] === null && $other['side'] === $row->side->value && hypot($other['x'] - $x, $other['z'] - $z) < 60;
                if ($same) {
                    continue 2;
                }
            }
            $kept[] = [
                'id' => $row->id,
                'target_id' => $row->target_id,
                'side' => $row->side->value,
                'x' => (int) round($x),
                'z' => (int) round($z),
                'kind' => $row->kind,
                'how' => $row->how,
                'heading' => $row->how === 'seen' ? $row->heading : null,
                'seen_at' => $row->seen_at->toIso8601String(),
                'age_s' => max(0, (int) $row->seen_at->diffInSeconds(now())),
                // Where it was seen before, oldest first.
                'trail' => array_map(fn (array $p) => ['x' => (int) round($p[0]), 'z' => (int) round($p[1])], $row->trail ?? []),
            ];
        }

        // What a contact is, as far as the side can tell: a man seen (not only heard) is known
        // by his weapon, so by what he does; a vehicle seen is one of the enemy's known by kind,
        // the nearest within VEHICLE_MATCH_METRES of the sighting.
        $roles = WarSoldier::query()
            ->whereKey(array_values(array_filter(array_map(fn (array $r) => $r['kind'] === 'inf' && $r['how'] === 'seen' ? $r['target_id'] : null, $kept))))
            ->pluck('role', 'id');
        $vehicles = WarVehicle::query()
            ->where('session_id', $session->id)
            ->where('side', '!=', $viewerSide->value)
            ->whereIn('status', ['fielded', 'ordered'])
            ->whereNotNull('pos_x')
            ->get(['id', 'kind', 'pos_x', 'pos_z']);
        foreach ($kept as $i => $row) {
            $seen = $row['how'] === 'seen';
            $kept[$i]['role'] = $seen && $row['kind'] === 'inf' && $roles->has($row['target_id']) ? WarEconomy::roleClass((string) $roles->get($row['target_id'])) : null;
            $match = $seen && $row['kind'] === 'veh'
                ? $vehicles->filter(fn (WarVehicle $v) => hypot((float) $v->pos_x - $row['x'], (float) $v->pos_z - $row['z']) <= self::VEHICLE_MATCH_METRES)
                    ->sortBy(fn (WarVehicle $v) => hypot((float) $v->pos_x - $row['x'], (float) $v->pos_z - $row['z']))
                    ->first()
                : null;
            $kept[$i]['vehicle'] = $match?->kind;
        }

        return array_map(function (array $row) {
            unset($row['target_id']);

            return $row;
        }, $kept);
    }

    /** @return Collection<int, WarOrder> */
    private function activeOps(WarSession $session): Collection
    {
        return WarOrder::query()
            ->with('squad')
            ->where('session_id', $session->id)
            ->whereIn('kind', [WarOrderKind::Recon->value, WarOrderKind::Sabotage->value])
            ->where('effect_until', '>', now())
            ->get();
    }

    /** Someone from another side sabotaged a relay: your side's contact picture is dark. */
    private function blindUntil(WarSession $session, WarSide $viewerSide): ?string
    {
        $until = $this->activeOps($session)
            ->filter(fn (WarOrder $order) => $order->kind === WarOrderKind::Sabotage->value && $order->squad !== null && $order->squad->side !== $viewerSide)
            ->max('effect_until');

        return $until?->toIso8601String();
    }

    /**
     * @return array{blind_until: string|null, operations: list<array<string, mixed>>}
     */
    private function intel(WarSession $session, WarSide $viewerSide): array
    {
        return [
            'blind_until' => $this->blindUntil($session, $viewerSide),
            'operations' => $this->activeOps($session)
                ->filter(fn (WarOrder $order) => $order->squad?->side === $viewerSide)
                ->map(fn (WarOrder $order) => [
                    'id' => $order->id,
                    'kind' => $order->kind,
                    'until' => $order->effect_until?->toIso8601String(),
                    'note' => $order->note,
                ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function squadCondition(WarSquad $squad): array
    {
        $living = $squad->soldiers->whereNull('fallen_at');
        $field = $living->where('location', 'field');

        return [
            'field' => $field->count(),
            'reserve' => $living->where('location', 'reserve')->count(),
            'wounded' => $field->filter(fn (WarSoldier $s) => WarCondition::isWounded($s))->count(),
            'low_ammo' => $field->filter(fn (WarSoldier $s) => in_array('low_ammo', WarCondition::flags($s), true))->count(),
            'under_fire' => $field->filter(fn (WarSoldier $s) => in_array($s->threat, ['alerted', 'threatened'], true))->count(),
            'morale' => $field->isEmpty() ? null : (int) round((float) $field->avg(fn (WarSoldier $s) => (float) $s->morale)),
            'health' => $field->isEmpty() ? null : (int) round((float) $field->avg('health')),
        ];
    }

    /**
     * The commander's vehicles: his, not any squad's, as at Conflict's vehicle depot.
     *
     * @return list<array<string, mixed>>
     */
    private function vehicles(WarSession $session, User $player): array
    {
        return WarVehicle::query()
            ->where('session_id', $session->id)
            ->where('commander_id', $player->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (WarVehicle $v) => $v->isActive() || ($v->lost_at !== null && $v->lost_at->greaterThan(now()->subHours(6))))
            ->map(function (WarVehicle $v) {
                $spec = WarEconomy::vehicle($v->kind, $v->side);

                return [
                    'id' => $v->id,
                    'kind' => $v->kind,
                    'name' => $spec['name'],
                    'model' => $spec['model'],
                    'status' => $v->status,
                    'x' => $v->pos_x === null ? null : (float) $v->pos_x,
                    'z' => $v->pos_z === null ? null : (float) $v->pos_z,
                    'health' => $v->health,
                    'fuel' => $v->fuel,
                    'seats' => $v->seats,
                    'crew' => $v->crew,
                    'hq' => $v->hq,
                ];
            })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function supportRequests(WarSession $session, User $player): array
    {
        return WarSupport::query()
            ->where('session_id', $session->id)
            ->where('commander_id', $player->id)
            ->where(fn ($q) => $q->whereIn('status', WarSupport::OPEN)->orWhere('updated_at', '>', now()->subHours(2)))
            ->orderByDesc('id')
            ->limit(16)
            ->get()
            ->map(fn (WarSupport $row) => [
                'id' => $row->id,
                'kind' => $row->kind,
                'name' => self::SUPPORT_NAMES[$row->kind] ?? WarEconomy::support($row->kind)['name'],
                'status' => $row->status,
                'squad_id' => $row->squad_id,
                'vehicle_id' => $row->vehicle_id,
                'x' => $row->pos_x === null ? null : (float) $row->pos_x,
                'z' => $row->pos_z === null ? null : (float) $row->pos_z,
                'cost' => $row->cost,
                'ready_at' => $row->ready_at->toIso8601String(),
                'note' => $row->note,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function supportCatalog(User $player): array
    {
        return array_map(function (string $id) use ($player) {
            $spec = WarEconomy::support($id);
            $last = $spec['cooldown'] <= 0 ? null : WarSupport::query()
                ->where('commander_id', $player->id)
                ->where('kind', $id)
                ->where('status', '!=', 'cancelled')
                ->where('created_at', '>', now()->subSeconds($spec['cooldown']))
                ->where('cost', '>', 0)
                ->max('created_at');

            return [
                ...$spec,
                'ready_at' => $last === null ? null : Carbon::parse($last)->addSeconds($spec['cooldown'])->toIso8601String(),
            ];
        }, WarEconomy::supportIds());
    }

    /** @return list<array<string, mixed>> */
    private function tasks(WarSession $session, User $player): array
    {
        return WarTask::query()
            ->with('squad.soldiers')
            ->where('session_id', $session->id)
            ->where('commander_id', $player->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->map(fn (WarTask $task) => [
                'ends_at' => $task->ends_at?->toIso8601String(),
                // What the detachment is up to, as far as the commander needs to know.
                'men' => $task->squad?->soldiers->whereNull('fallen_at')->where('location', 'field')->count() ?? 0,
                'doing' => $task->squad?->doing,
                'at' => $task->squad === null ? null : ['x' => (float) $task->squad->pos_x, 'z' => (float) $task->squad->pos_z],
                'id' => $task->id,
                'kind' => $task->kind,
                'size' => $task->size,
                'label' => $task->label,
                'a' => ['x' => (float) $task->a_x, 'z' => (float) $task->a_z],
                'b' => $task->b_x === null ? null : ['x' => (float) $task->b_x, 'z' => (float) $task->b_z],
                'squad_id' => $task->squad?->id,
                'note' => $task->note,
            ])->values()->all();
    }

    /** @return array{budget: int, in_use: int, sizes: list<int>} */
    private function hqBudget(?WarSession $session, User $player): array
    {
        $inUse = $session === null ? 0 : WarSoldier::query()
            ->whereNull('fallen_at')
            ->whereHas('squad', fn ($q) => $q->where('session_id', $session->id)->where('commander_id', $player->id)->where('managed', true))
            ->count();

        return [
            'budget' => (int) config('reforger.war.hq.budget', 8),
            'in_use' => $inUse,
            'sizes' => array_values(array_map('intval', config('reforger.war.hq.sizes', [4, 6, 8]))),
        ];
    }

    /**
     * Who holds what is common knowledge: flags fly over every point.
     *
     * @return list<array<string, mixed>>
     */
    private function territoryList(WarSession $session): array
    {
        $controls = WarControl::query()->where('session_id', $session->id)->get()->keyBy('place');

        return array_map(function (array $place) use ($controls) {
            $control = $controls->get($place['id']);

            return [
                'place' => $place['id'],
                'income' => WarTerritory::income($place['kind']),
                'side' => $control?->side,
                'claim_side' => $control?->claim_side,
                'claim_since' => $control?->claim_since?->toIso8601String(),
                'interdicted_until' => $control?->interdicted_until?->isFuture() ? $control->interdicted_until->toIso8601String() : null,
            ];
        }, WarTerritory::points());
    }

    /** @return list<array<string, mixed>> */
    private function objectiveList(WarSession $session, WarSide $side, User $player): array
    {
        return WarObjective::query()
            ->where('session_id', $session->id)
            ->where('side', $side->value)
            ->where(fn ($q) => $q->where('status', 'open')->orWhere('done_at', '>', now()->subHours(3)))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (WarObjective $o) => [
                'id' => $o->id,
                'kind' => $o->kind,
                'title' => $o->title,
                'target' => $o->target,
                'x' => (float) $o->pos_x,
                'z' => (float) $o->pos_z,
                'reward' => $o->reward,
                'status' => $o->status,
                'progress_at' => $o->progress_at?->toIso8601String(),
                'progress_squad_id' => $o->squad_id,
                'stage' => $o->stage,
                'expires_at' => $o->expires_at->toIso8601String(),
                'mine' => $o->commander_id === $player->id,
            ])->values()->all();
    }

    /** @return array{x: int, z: int} */
    private function home(WarSide $side): array
    {
        [$x, $z] = $side->muster();

        return ['x' => (int) round($x), 'z' => (int) round($z)];
    }

    /** Reforger map grid: three digits east, three digits north, 100 m squares. */
    private function gridRef(float $x, float $z): string
    {
        return sprintf('%03d %03d', max(0, (int) floor($x / 100)), max(0, (int) floor($z / 100)));
    }

    private function orderStatus(WarOrder $order): string
    {
        return match ($order->status) {
            WarOrderStatus::Queued => 'waiting in the chain',
            WarOrderStatus::Pending => 'pending',
            WarOrderStatus::Acked => 'acknowledged',
            WarOrderStatus::Done => 'arrived',
            WarOrderStatus::Failed => 'failed',
            WarOrderStatus::Superseded => 'replaced',
        };
    }

    private function supplyText(WarSupplyEntry $entry): string
    {
        $amount = abs($entry->amount);

        return match ($entry->reason) {
            WarSupplyReason::Grant => "enlisted with {$amount} supplies",
            WarSupplyReason::Income => "received {$amount} supplies",
            WarSupplyReason::Train => "paid {$amount} for training",
            WarSupplyReason::Refund => "received {$amount} supplies back",
            WarSupplyReason::Support => "called HQ support, {$amount} supplies",
            WarSupplyReason::Vehicle => "drew a vehicle, {$amount} supplies",
            WarSupplyReason::Plunder => "raided a harbour for {$amount} supplies",
            WarSupplyReason::Raided => "lost {$amount} supplies to a raid",
            WarSupplyReason::Objective => "completed an HQ objective, +{$amount} supplies",
            WarSupplyReason::Recruit => $amount === WarEconomy::squadCost()
                ? "raised a squad, {$amount} supplies"
                : "fielded a soldier, {$amount} supplies",
        };
    }

    private function commanderName(?User $player): string
    {
        return $player?->name ?? 'A commander';
    }

    private function metres(float $value, int $size): int
    {
        return min($size, max(0, (int) round($value)));
    }

    /**
     * @param  Collection<int, WarSquad>  $squads
     * @return list<array<string, mixed>>
     */
    private function roster(Collection $squads, bool $fallen): array
    {
        // Who killed the fallen, by name: one query for all of them.
        $killers = $fallen
            ? WarSoldier::query()->whereKey($squads->flatMap(fn (WarSquad $s) => $s->soldiers->pluck('killed_by_id'))->filter()->unique()->values())->pluck('name', 'id')
            : collect();

        return $squads->flatMap(function (WarSquad $squad) use ($fallen, $killers) {
            $rows = $fallen
                ? $squad->soldiers
                    ->whereNotNull('fallen_at')
                    ->reject(fn (WarSoldier $s) => $squad->managed && $s->loss === 'discharged')
                    ->sortByDesc('fallen_at')
                    ->take(12)
                : $squad->soldiers->whereNull('fallen_at')->sortBy('id');

            return $rows->map(function (WarSoldier $soldier) use ($squad, $killers) {
                $role = WarEconomy::role($soldier->role);

                return [
                    'id' => $soldier->id,
                    'squad_id' => $squad->id,
                    'name' => $soldier->name,
                    'note' => $soldier->note,
                    'role' => $soldier->role,
                    'role_name' => $role['name'],
                    'aim' => $soldier->aim,
                    'grit' => $soldier->grit,
                    'fieldcraft' => $soldier->fieldcraft,
                    'endurance' => $soldier->endurance,
                    'endurance_effective' => WarEconomy::effectiveStat($soldier->endurance, WarEconomy::experience($soldier)),
                    'aim_effective' => WarEconomy::effectiveStat($soldier->aim, WarEconomy::experience($soldier)),
                    // What drilling each stat once more costs (null: he is at the top).
                    'drill' => [
                        'aim' => WarEconomy::drillCost($soldier, 'aim'),
                        'grit' => WarEconomy::drillCost($soldier, 'grit'),
                        'fieldcraft' => WarEconomy::drillCost($soldier, 'fieldcraft'),
                        'endurance' => WarEconomy::drillCost($soldier, 'endurance'),
                    ],
                    'grit_effective' => WarEconomy::effectiveStat($soldier->grit, WarEconomy::experience($soldier)),
                    'fieldcraft_effective' => WarEconomy::effectiveStat($soldier->fieldcraft, WarEconomy::experience($soldier)),
                    'service' => $soldier->service,
                    'spent' => $soldier->spent,
                    'loss' => $soldier->loss,
                    'cost' => WarEconomy::soldierCost($soldier->role, $soldier->aim, $soldier->grit, $soldier->fieldcraft),
                    'health' => $soldier->health,
                    'stamina' => $soldier->stamina,
                    'bleeding' => $soldier->bleeding,
                    'down' => $soldier->down,
                    'threat' => $soldier->threat,
                    'mags' => $soldier->mags,
                    'in_vehicle' => $soldier->in_vehicle,
                    'seat' => $soldier->seat,
                    'stance' => $soldier->stance,
                    'action' => $soldier->action,
                    'morale' => (int) round((float) $soldier->morale),
                    'mood' => WarCondition::mood((float) $soldier->morale),
                    'battles' => $soldier->battles,
                    'kills' => $soldier->kills,
                    'ai_skill' => WarEconomy::aiSkill($soldier, $soldier->squad->tier),
                    'flags' => WarCondition::flags($soldier),
                    'location' => $soldier->location,
                    // His squad leader's standing orders for him.
                    'stance_order' => $soldier->stance_order,
                    'pace_order' => $soldier->pace_order,
                    'ready_at' => $soldier->ready_at?->toIso8601String(),
                    // How he died, as the game reported it: null while alive, or when it did not say.
                    'death' => $soldier->death_cause === null ? null : [
                        'cause' => $soldier->death_cause,
                        'by' => $soldier->killed_by_id === null ? null : $killers->get($soldier->killed_by_id),
                        'weapon' => $soldier->death_weapon,
                        'metres' => $soldier->death_metres,
                    ],
                ];
            });
        })->values()->all();
    }

    private function collect(User $player, ?WarSession $session, bool $open): ?WarEnlistment
    {
        if ($session === null) {
            return null;
        }

        $enlisted = WarEnlistment::query()
            ->where('session_id', $session->id)
            ->where('commander_id', $player->id)
            ->first();
        if ($enlisted === null || ! $open) {
            return $enlisted;
        }

        DB::transaction(function () use ($enlisted) {
            $locked = WarEnlistment::query()->whereKey($enlisted->id)->lockForUpdate()->first();
            if ($locked !== null) {
                WarSquads::ensure($locked);
                $this->accrue->accrue($locked);
                $squads = WarSquad::query()
                    ->where('session_id', $locked->session_id)
                    ->where('commander_id', $locked->commander_id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($squads as $squad) {
                    $this->service->accrue($squad);
                }
            }
        }, 3);

        return $enlisted->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function economy(?WarEnlistment $enlistment, ?WarSquad $squad, int $usedStrength, int $squadCount): array
    {
        $income = WarEconomy::incomePerHour();
        $cap = WarEconomy::supplyCap();
        $emptyUpgrade = ['cost' => null, 'available' => false, 'label' => 'Enlist first'];

        if ($enlistment === null || $squad === null) {
            return [
                'supplies' => null,
                'income_per_hour' => $income,
                'supply_cap' => $cap,
                'service_cap' => WarEconomy::serviceCap(),
                'missing' => 0,
                'tier' => null,
                'tier_name' => null,
                'recruit' => $emptyUpgrade,
                'train' => $emptyUpgrade,
            ];
        }

        $limit = (int) config('reforger.ai_limit', 192);
        $recruitCost = WarEconomy::recruitCost($squad->strength);
        $trainCost = WarEconomy::trainCost($squad->tier);
        $raiseCost = $squadCount >= WarEconomy::maxSquads() ? null : WarEconomy::squadCost();
        $raiseRoom = $usedStrength + (int) config('reforger.war.squad_strength', 4) <= $limit;

        return [
            'supplies' => $enlistment->supplies,
            'income_per_hour' => $income,
            'supply_cap' => $cap,
            'service_cap' => WarEconomy::serviceCap(),
            'missing' => max(0, $squad->strength - $squad->alive),
            'tier' => $squad->tier,
            'tier_name' => WarEconomy::tierName($squad->tier),
            'recruit' => $this->upgradeOffer(
                $recruitCost,
                $enlistment->supplies,
                $recruitCost === null
                    ? 'Squad is full'
                    : ($usedStrength + 1 > $limit
                        ? 'AI budget is full'
                        : 'Recruit to '.($squad->strength + 1).' · '.$recruitCost),
                $recruitCost !== null && $usedStrength + 1 <= $limit,
            ),
            'train' => $this->upgradeOffer(
                $trainCost,
                $enlistment->supplies,
                $trainCost === null
                    ? 'Veteran'
                    : 'Train to '.WarEconomy::tierName($squad->tier + 1).' · '.$trainCost,
                $trainCost !== null,
            ),
        ];
    }

    /**
     * @return array{cost: int|null, available: bool, label: string}
     */
    private function upgradeOffer(?int $cost, int $supplies, string $label, bool $open): array
    {
        return [
            'cost' => $cost,
            'available' => $open && $cost !== null && $supplies >= $cost,
            'label' => $label,
        ];
    }
}
