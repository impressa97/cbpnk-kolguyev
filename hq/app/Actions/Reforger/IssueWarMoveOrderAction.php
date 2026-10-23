<?php

namespace App\Actions\Reforger;

use App\Enums\WarOrderKind;
use App\Enums\WarOrderStatus;
use App\Models\User;
use App\Models\WarContact;
use App\Models\WarOrder;
use App\Models\WarRun;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Models\WarVehicle;
use App\Support\WarOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Queue an order for one of the player's own squads: one of the game's commanding-menu
 * commands for the whole group (see WarOrderKind). A newer order replaces one that is pending
 * or under way, as a vanilla command overrides the previous ones. Squads HQ runs for a task
 * take orders from HQ only.
 *
 *   rest      goes home; the point is ignored
 *   mount     the game's "get in" on one of the commander's vehicles on the island (named,
 *             or the nearest to the squad); vehicles belong to no squad
 *   dismount, halt   where they are
 *   heal      names one soldier of the squad; the squad's medic treats him, so it needs one
 *   patrol    needs a second point
 *   recon, sabotage, raid   aimed and staffed as war.operations says (WarOperations)
 *   move, move_forced   may go on along a route of up to MAX_LEGS more points
 *   smoke     on top of the order under way, which goes on (it is not replaced)
 *   follow    names another of the commander's squads in the field
 *   attack    at an enemy contact the side knows, near the mark
 *
 * Queued (a chain, as the game's own waypoint list): the order waits behind the squad's open
 * ones and starts when the game completes the one before it (AdvanceWarOrdersAction). Nothing
 * can wait behind a defend, a patrol or a follow: those go on until the next order.
 */
final class IssueWarMoveOrderAction
{
    /** An order this close to the same order under way is that order, clicked again. */
    private const SAME_METRES = 25.0;

    /** Points after the first a route may take. */
    public const MAX_LEGS = 6;

    /** Orders that may wait in one squad's chain behind the one under way. */
    public const MAX_QUEUE = 6;

    public function __construct(
        private readonly ResolveWarSessionAction $sessions,
    ) {}

    /**
     * @param  array{0: float, 1: float}|null  $second  a patrol's other end
     */
    public function move(User $player, float $x, float $z, ?int $squadId = null, WarOrderKind $kind = WarOrderKind::Move, ?int $soldierId = null, ?array $second = null, ?int $vehicleId = null, ?int $followSquadId = null, array $route = [], bool $queue = false): WarOrder
    {
        if ($kind === WarOrderKind::Heal && $soldierId === null) {
            throw ValidationException::withMessages(['soldier_id' => ['Pick the soldier the medic should treat.']]);
        }
        if ($kind !== WarOrderKind::Heal && $soldierId !== null) {
            throw ValidationException::withMessages(['kind' => ['Orders go to the whole squad; only treatment names one soldier.']]);
        }
        if ($route !== [] && ! in_array($kind, [WarOrderKind::Move, WarOrderKind::MoveForced], true)) {
            throw ValidationException::withMessages(['route' => ['Only a move goes along a route.']]);
        }
        if (count($route) > self::MAX_LEGS) {
            throw ValidationException::withMessages(['route' => ['A route takes at most '.(self::MAX_LEGS + 1).' points.']]);
        }
        if ($kind === WarOrderKind::Follow && $followSquadId === null) {
            throw ValidationException::withMessages(['follow_squad_id' => ['Pick the squad to follow.']]);
        }
        if ($kind === WarOrderKind::Patrol && $second === null) {
            throw ValidationException::withMessages(['x' => ['A patrol needs both ends of its beat.']]);
        }

        $size = (float) config('reforger.war.world_size', 12800);
        if ($x < 0 || $z < 0 || $x > $size || $z > $size) {
            throw ValidationException::withMessages(['x' => ['That point is outside Kolguyev.']]);
        }

        $this->sessions->closeDue();

        return DB::transaction(function () use ($player, $x, $z, $squadId, $kind, $soldierId, $second, $vehicleId, $followSquadId, $route, $queue) {
            $session = $this->sessions->current();
            if ($session === null) {
                throw ValidationException::withMessages(['side' => ['This week is over.']]);
            }

            $query = WarSquad::query()
                ->where('session_id', $session->id)
                ->where('commander_id', $player->id)
                ->orderBy('id')
                ->lockForUpdate();
            $squadId !== null ? $query->whereKey($squadId) : $query->where('managed', false);
            $squad = $query->first();

            if ($squad === null) {
                throw ValidationException::withMessages(['side' => ['Enlist a side before giving orders.']]);
            }
            if ($squad->managed) {
                throw ValidationException::withMessages(['squad_id' => ['HQ runs that squad for a task. Change the task instead.']]);
            }
            if (! WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->where('location', 'field')->exists()) {
                throw ValidationException::withMessages(['squad_id' => ['Nobody from that squad is on the island.']]);
            }
            // Riding in an HQ truck: the driver has the wheel until they are dropped.
            if (WarRun::query()->where('squad_id', $squad->id)->where('status', 'active')->where('kind', 'transport')->whereIn('stage', ['loading', 'carrying'])->exists()) {
                throw ValidationException::withMessages(['squad_id' => ['They are riding an HQ truck: give the order once they are dropped off.']]);
            }

            // In a chain behind the squad's open orders, or (smoke aside) in place of them.
            $open = WarOrder::query()
                ->where('squad_id', $squad->id)
                ->whereIn('status', [WarOrderStatus::Queued, WarOrderStatus::Pending, WarOrderStatus::Acked])
                ->orderBy('id')
                ->get();
            $queued = $queue && ! $kind->keepsOrder() && $open->isNotEmpty();
            if ($queued) {
                $last = WarOrderKind::from($open->last()->kind);
                if (! $last->endsByItself()) {
                    throw ValidationException::withMessages(['kind' => [sprintf('%s goes on until the next order: nothing after it would ever start.', $last->label())]]);
                }
                if ($open->where('status', WarOrderStatus::Queued)->count() >= self::MAX_QUEUE) {
                    throw ValidationException::withMessages(['kind' => ['That chain is long enough: at most '.self::MAX_QUEUE.' orders wait in it.']]);
                }
            }

            $vehicle = null;
            $enemy = null;
            if ($kind === WarOrderKind::Heal) {
                [$x, $z] = $this->patient($squad, (int) $soldierId);
                $target = null;
            } elseif ($kind === WarOrderKind::Follow) {
                [$x, $z] = $this->leader($player, $squad, (int) $followSquadId);
                $target = null;
            } elseif ($kind === WarOrderKind::Attack) {
                [$x, $z, $target, $enemy] = $this->contact($squad, $x, $z);
            } elseif ($kind === WarOrderKind::Smoke) {
                [$x, $z, $target] = [(float) $squad->pos_x, (float) $squad->pos_z, null];
            } elseif ($kind === WarOrderKind::Mount) {
                $vehicle = $this->vehicle($player, $squad, $vehicleId);
                [$x, $z, $target] = [(float) $vehicle->pos_x, (float) $vehicle->pos_z, null];
            } elseif ($queued && $kind === WarOrderKind::Dismount) {
                // They get out wherever the order before leaves them aboard.
                [$x, $z, $target] = [(float) $open->last()->pos_x, (float) $open->last()->pos_z, null];
            } else {
                [$x, $z, $target] = $this->aim($squad, $kind, $x, $z);
            }

            // The same order to the same spot while it is under way (a second click): it stands,
            // and the squad is not sent back to the start of it.
            if (! $queued && ! $kind->keepsOrder() && $route === [] && $second === null && $soldierId === null && $open->where('status', WarOrderStatus::Queued)->isEmpty()) {
                $same = $open->first(fn (WarOrder $o) => $o->kind === $kind->value && $o->vehicle_id === $vehicle?->id && $o->route === null
                    && hypot((float) $o->pos_x - $x, (float) $o->pos_z - $z) <= self::SAME_METRES);
                if ($same !== null) {
                    return $same;
                }
            }

            // Smoke is thrown on top of the order under way; anything else replaces it and its
            // chain. A queued order replaces nothing.
            WarOrder::query()
                ->where('squad_id', $squad->id)
                ->when($queued, fn ($query) => $query->whereRaw('false'))
                ->when($kind->keepsOrder(), fn ($query) => $query->where('kind', $kind->value))
                ->where(fn ($query) => $query
                    ->whereIn('status', [WarOrderStatus::Queued, WarOrderStatus::Pending, WarOrderStatus::Acked])
                    // An operation that reached its target but has not paid off is abandoned too.
                    ->orWhere(fn ($q) => $q
                        ->where('status', WarOrderStatus::Done)
                        ->whereIn('kind', [WarOrderKind::Recon->value, WarOrderKind::Sabotage->value, WarOrderKind::Raid->value])
                        ->whereNull('effect_until')))
                ->update(['status' => WarOrderStatus::Superseded]);

            return WarOrder::query()->create([
                'session_id' => $session->id,
                'squad_id' => $squad->id,
                'commander_id' => $player->id,
                'kind' => $kind->value,
                'status' => $queued ? WarOrderStatus::Queued : WarOrderStatus::Pending,
                'pos_x' => round($x, 2),
                'pos_z' => round($z, 2),
                'pos2_x' => $second !== null ? round($second[0], 2) : null,
                'pos2_z' => $second !== null ? round($second[1], 2) : null,
                'soldier_id' => $soldierId,
                'vehicle_id' => $vehicle?->id,
                'follow_squad_id' => $kind === WarOrderKind::Follow ? $followSquadId : null,
                'route' => $route === [] ? null : array_map(fn (array $p) => [round($p[0], 2), round($p[1], 2)], $route),
                'target' => $target,
                'enemy_id' => $enemy,
            ]);
        }, 3);
    }

    /**
     * A chain in one go: the first order in place of the squad's (or behind them, appended), the
     * rest waiting behind it in turn. All or nothing.
     *
     * @param  list<array{kind: WarOrderKind, x: float, z: float, second?: array{0: float, 1: float}|null, vehicle_id?: int|null, follow_squad_id?: int|null, route?: list<array{0: float, 1: float}>}>  $steps
     * @return list<WarOrder>
     */
    public function chain(User $player, int $squadId, array $steps, bool $append = false): array
    {
        if ($steps === []) {
            throw ValidationException::withMessages(['kind' => ['Give the squad an order.']]);
        }
        if (count($steps) > self::MAX_QUEUE + 1) {
            throw ValidationException::withMessages(['kind' => ['That chain is too long.']]);
        }

        return DB::transaction(fn () => array_map(fn (array $step, int $i) => $this->move(
            $player,
            $step['x'],
            $step['z'],
            $squadId,
            $step['kind'],
            null,
            $step['second'] ?? null,
            $step['vehicle_id'] ?? null,
            $step['follow_squad_id'] ?? null,
            $step['route'] ?? [],
            $append || $i > 0,
        ), $steps, array_keys($steps)), 3);
    }

    /** Take an order that is still waiting out of the squad's chain. */
    public function cancel(User $player, int $orderId): void
    {
        DB::transaction(function () use ($player, $orderId) {
            $order = WarOrder::query()->whereKey($orderId)->where('commander_id', $player->id)->lockForUpdate()->first();
            if ($order === null || $order->status !== WarOrderStatus::Queued) {
                throw ValidationException::withMessages(['kind' => ['That order is not waiting any more.']]);
            }
            $order->status = WarOrderStatus::Superseded;
            $order->note = 'Taken out of the chain.';
            $order->save();
        }, 3);
    }

    /**
     * Where the wounded man lies, checking the squad can treat him: in the game the heal command
     * sends the group's medic, so a squad without one has nobody to send.
     *
     * @return array{0: float, 1: float}
     */
    private function patient(WarSquad $squad, int $soldierId): array
    {
        $soldier = WarSoldier::query()
            ->whereKey($soldierId)
            ->where('squad_id', $squad->id)
            ->whereNull('fallen_at')
            ->where('location', 'field')
            ->first();
        if ($soldier === null) {
            throw ValidationException::withMessages(['soldier_id' => ['That soldier is not with this squad on the island.']]);
        }

        $medic = WarSoldier::query()
            ->where('squad_id', $squad->id)
            ->whereNull('fallen_at')
            ->where('location', 'field')
            ->where('role', 'like', '%medic%')
            ->where('down', false)
            ->exists();
        if (! $medic) {
            throw ValidationException::withMessages(['kind' => ['The squad has no medic on his feet to send.']]);
        }

        return [(float) ($soldier->pos_x ?? $squad->pos_x), (float) ($soldier->pos_z ?? $squad->pos_z)];
    }

    /**
     * Where the order really points, and the place an operation is aimed at.
     *
     * @return array{0: float, 1: float, 2: string|null}
     */
    private function aim(WarSquad $squad, WarOrderKind $kind, float $x, float $z): array
    {
        if ($kind->isOperation()) {
            $field = WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->where('location', 'field')->get();
            if (! WarOperations::staffed($kind, $field)) {
                throw ValidationException::withMessages(['kind' => [sprintf('%s needs a %s in the squad.', $kind->label(), WarOperations::spec($kind)['needs'])]]);
            }

            return WarOperations::aim($kind, $x, $z);
        }

        return match ($kind) {
            WarOrderKind::Rest => [...$squad->side->muster(), null],
            WarOrderKind::Dismount => $this->aboard($squad),
            WarOrderKind::Halt => [(float) $squad->pos_x, (float) $squad->pos_z, null],
            default => [$x, $z, null],
        };
    }

    /**
     * The vehicle to get into: the one named, or the commander's nearest to the squad. Any of
     * his vehicles on the island will do; none is tied to a squad.
     */
    private function vehicle(User $player, WarSquad $squad, ?int $vehicleId): WarVehicle
    {
        $vehicle = WarVehicle::query()
            ->where('session_id', $squad->session_id)
            ->where('commander_id', $player->id)
            ->where('status', 'fielded')
            ->where('hq', false)
            ->when($vehicleId !== null, fn ($query) => $query->whereKey($vehicleId))
            ->get()
            ->sortBy(fn (WarVehicle $v) => hypot((float) $v->pos_x - (float) $squad->pos_x, (float) $v->pos_z - (float) $squad->pos_z))
            ->first();
        if ($vehicle === null) {
            throw ValidationException::withMessages(['vehicle_id' => [$vehicleId === null ? 'You have no vehicle on the island. Draw one from the motor pool.' : 'That vehicle is not yours on the island.']]);
        }

        return $vehicle;
    }

    /**
     * Where the squad to follow is: another of the commander's squads with men in the field.
     *
     * @return array{0: float, 1: float}
     */
    private function leader(User $player, WarSquad $squad, int $followSquadId): array
    {
        $other = WarSquad::query()
            ->whereKey($followSquadId)
            ->where('session_id', $squad->session_id)
            ->where('commander_id', $player->id)
            ->whereKeyNot($squad->id)
            ->first();
        if ($other === null || ! WarSoldier::query()->where('squad_id', $other->id)->whereNull('fallen_at')->where('location', 'field')->exists()) {
            throw ValidationException::withMessages(['follow_squad_id' => ['Follow another of your squads on the island.']]);
        }

        return [(float) $other->pos_x, (float) $other->pos_z];
    }

    /**
     * The enemy contact the side knows nearest the mark (its squads' reports), within 150 m,
     * and the war soldier it is when the game named him: the attack goes for that man, as the
     * game's attack waypoint takes one entity. A group that cannot reach him hunts there.
     *
     * @return array{0: float, 1: float, 2: string, 3: int|null}
     */
    private function contact(WarSquad $squad, float $x, float $z): array
    {
        $contact = WarContact::query()
            ->where('session_id', $squad->session_id)
            ->where('side', '!=', $squad->side->value)
            ->where('seen_at', '>=', now()->subMinutes((int) config('reforger.war.contact_minutes', 15)))
            ->whereHas('squad', fn ($q) => $q->where('side', $squad->side->value))
            ->get()
            ->sortBy(fn (WarContact $c) => hypot((float) $c->pos_x - $x, (float) $c->pos_z - $z))
            ->first();
        if ($contact === null || hypot((float) $contact->pos_x - $x, (float) $contact->pos_z - $z) > 150) {
            throw ValidationException::withMessages(['kind' => ['Attack a known enemy: tap a contact on the map.']]);
        }

        return [(float) $contact->pos_x, (float) $contact->pos_z, $contact->side->value, $contact->target_id];
    }

    /** @return array{0: float, 1: float, 2: null} */
    private function aboard(WarSquad $squad): array
    {
        if (! WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->where('location', 'field')->where('in_vehicle', true)->exists()) {
            throw ValidationException::withMessages(['kind' => ['Nobody from that squad is aboard.']]);
        }

        return [(float) $squad->pos_x, (float) $squad->pos_z, null];
    }
}
