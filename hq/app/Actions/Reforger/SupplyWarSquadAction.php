<?php

namespace App\Actions\Reforger;

use App\Enums\WarSupplyReason;
use App\Models\User;
use App\Models\WarEnlistment;
use App\Models\WarOrder;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Models\WarSupplyEntry;
use App\Models\WarSupport;
use App\Models\WarVehicle;
use App\Support\WarCondition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Logistics on the ground, nothing flown in: a squad gets what it needs where it is.
 *
 *   home      your side's home is the field hospital and the arsenal. A squad there (within
 *             logistics.home_radius) hands its wounded to the hospital (they go to the reserve and
 *             recover) and rearms for free. A Go home order that the game completes does both.
 *   truck     a troop truck or a carrier of yours carries ammunition: a squad within
 *             logistics.truck_radius of one rearms there for logistics.truck_cost.
 *
 * Wounded men in the field are the medic's (the game's heal command); getting them home is a
 * drive. HQ detachments walk home to rotate the same way (RunWarHqAction).
 */
final class SupplyWarSquadAction
{
    public function __construct(
        private readonly ResolveWarSessionAction $sessions,
        private readonly AccrueWarSuppliesAction $accrue,
    ) {}

    /** Top the squad's magazines up, at home or beside one of the commander's ammo vehicles. */
    public function rearm(User $player, int $squadId): WarSupport
    {
        $this->sessions->closeDue();

        return DB::transaction(function () use ($player, $squadId) {
            $squad = $this->own($player, $squadId);
            if ($this->field($squad)->isEmpty()) {
                throw ValidationException::withMessages(['squad_id' => ['Nobody from that squad is on the island.']]);
            }
            if ($this->rearming($squad)) {
                throw ValidationException::withMessages(['squad_id' => ['That squad is rearming already.']]);
            }

            $cost = 0;
            if (! self::atHome($squad)) {
                $truck = self::ammoVehicleNear($squad);
                if ($truck === null) {
                    throw ValidationException::withMessages(['squad_id' => [sprintf(
                        'Rearm at home or within %d m of one of your trucks.',
                        (int) config('reforger.war.logistics.truck_radius', 40),
                    )]]);
                }
                $cost = (int) config('reforger.war.logistics.truck_cost', 10);
                $this->debit($squad, $cost);
            }

            return $this->issue($squad, $cost);
        }, 3);
    }

    /**
     * Refuel and repair one of the commander's vehicles: free at home (the depot), or beside
     * another of his trucks or carriers for logistics.service_cost. The mod does it the Game
     * Master's way and acks it.
     */
    public function service(User $player, int $vehicleId): WarSupport
    {
        $this->sessions->closeDue();

        return DB::transaction(function () use ($player, $vehicleId) {
            $session = $this->sessions->current();
            $vehicle = $session === null ? null : WarVehicle::query()
                ->whereKey($vehicleId)
                ->where('session_id', $session->id)
                ->where('commander_id', $player->id)
                ->where('status', 'fielded')
                ->lockForUpdate()
                ->first();
            if ($vehicle === null) {
                throw ValidationException::withMessages(['vehicle' => ['That vehicle is not yours on the island.']]);
            }
            $open = WarSupport::query()->where('vehicle_id', $vehicle->id)->where('kind', 'service')->whereIn('status', WarSupport::OPEN)->exists();
            if ($open) {
                throw ValidationException::withMessages(['vehicle' => ['That vehicle is being serviced already.']]);
            }

            [$hx, $hz] = $vehicle->side->muster();
            $home = hypot((float) $vehicle->pos_x - $hx, (float) $vehicle->pos_z - $hz) <= (float) config('reforger.war.logistics.home_radius', 200);
            $cost = 0;
            if (! $home) {
                $radius = (float) config('reforger.war.logistics.truck_radius', 40);
                $truck = WarVehicle::query()
                    ->where('session_id', $vehicle->session_id)
                    ->where('commander_id', $player->id)
                    ->where('status', 'fielded')
                    ->whereKeyNot($vehicle->id)
                    ->whereIn('kind', (array) config('reforger.war.logistics.ammo_vehicles', ['truck', 'apc']))
                    ->get()
                    ->first(fn (WarVehicle $v) => hypot((float) $v->pos_x - (float) $vehicle->pos_x, (float) $v->pos_z - (float) $vehicle->pos_z) <= $radius);
                if ($truck === null) {
                    throw ValidationException::withMessages(['vehicle' => [sprintf('Refuel and repair at home, or within %d m of another of your trucks.', (int) $radius)]]);
                }
                $cost = (int) config('reforger.war.logistics.service_cost', 15);
                $enlistment = WarEnlistment::query()->where('session_id', $vehicle->session_id)->where('commander_id', $player->id)->lockForUpdate()->first();
                if ($enlistment === null) {
                    throw ValidationException::withMessages(['vehicle' => ['Enlist a side first.']]);
                }
                $this->accrue->accrue($enlistment);
                if ($enlistment->supplies < $cost) {
                    throw ValidationException::withMessages(['supplies' => ['Not enough supplies to service it.']]);
                }
                $enlistment->supplies -= $cost;
                $enlistment->save();
                WarSupplyEntry::query()->create([
                    'session_id' => $enlistment->session_id,
                    'commander_id' => $enlistment->commander_id,
                    'amount' => -$cost,
                    'reason' => WarSupplyReason::Support,
                    'balance' => $enlistment->supplies,
                ]);
            }

            return WarSupport::query()->create([
                'session_id' => $vehicle->session_id,
                'commander_id' => $player->id,
                'vehicle_id' => $vehicle->id,
                'kind' => 'service',
                'status' => 'requested',
                'pos_x' => $vehicle->pos_x,
                'pos_z' => $vehicle->pos_z,
                'cost' => $cost,
                'ready_at' => now(),
                'note' => $home ? 'At the depot.' : 'From the truck.',
            ]);
        }, 3);
    }

    /** Hand the squad's wounded to the hospital at home. */
    public function hospital(User $player, int $squadId): int
    {
        return DB::transaction(function () use ($player, $squadId) {
            $squad = $this->own($player, $squadId);
            if (! self::atHome($squad)) {
                throw ValidationException::withMessages(['squad_id' => ['The hospital is at home: bring them there first.']]);
            }
            $admitted = $this->admit($squad, $this->field($squad)->filter(fn (WarSoldier $s) => WarCondition::isWounded($s)));
            if ($admitted === []) {
                throw ValidationException::withMessages(['squad_id' => ['Nobody in that squad needs the hospital.']]);
            }

            return count($admitted);
        }, 3);
    }

    /**
     * A Go home the game completed: the wounded go to the hospital, the rest rearm. An HQ
     * detachment going home is rotating: all of it goes to the reserve, and HQ sends it back
     * once it is fit.
     */
    public function arrivedHome(WarOrder $order): void
    {
        $squad = WarSquad::query()->whereKey($order->squad_id)->lockForUpdate()->first();
        if ($squad === null) {
            return;
        }
        $field = $this->field($squad);
        if ($field->isEmpty()) {
            return;
        }

        if ($squad->managed) {
            $this->admit($squad, $field);
            $order->note = 'Home. Resting before going back out.';
            $order->save();

            return;
        }

        $admitted = $this->admit($squad, $field->filter(fn (WarSoldier $s) => WarCondition::isWounded($s)));
        $rearm = count($admitted) < $field->count() && ! $this->rearming($squad);
        if ($rearm) {
            $this->issue($squad, 0);
        }
        $order->note = trim(
            ($admitted === [] ? '' : 'Hospital: '.implode(', ', array_slice($admitted, 0, 4)).(count($admitted) > 4 ? ' and '.(count($admitted) - 4).' more' : '').'. ')
            .($rearm ? 'Rearming.' : ''),
        ) ?: 'Home.';
        $order->save();
    }

    public static function atHome(WarSquad $squad): bool
    {
        [$hx, $hz] = $squad->side->muster();

        return hypot((float) $squad->pos_x - $hx, (float) $squad->pos_z - $hz) <= (float) config('reforger.war.logistics.home_radius', 200);
    }

    /** The commander's nearest truck or carrier on the island within reach of the squad. */
    public static function ammoVehicleNear(WarSquad $squad): ?WarVehicle
    {
        $radius = (float) config('reforger.war.logistics.truck_radius', 40);

        return WarVehicle::query()
            ->where('session_id', $squad->session_id)
            ->where('commander_id', $squad->commander_id)
            ->where('status', 'fielded')
            ->whereIn('kind', (array) config('reforger.war.logistics.ammo_vehicles', ['truck', 'apc']))
            ->get()
            ->map(fn (WarVehicle $v) => [$v, hypot((float) $v->pos_x - (float) $squad->pos_x, (float) $v->pos_z - (float) $squad->pos_z)])
            ->filter(fn (array $pair) => $pair[1] <= $radius)
            ->sortBy(fn (array $pair) => $pair[1])
            ->first()[0] ?? null;
    }

    /**
     * To the reserve at home, each to recover as long as his wounds take.
     *
     * @param  \Illuminate\Support\Collection<int, WarSoldier>  $soldiers
     * @return list<string> their names
     */
    private function admit(WarSquad $squad, \Illuminate\Support\Collection $soldiers): array
    {
        foreach ($soldiers as $soldier) {
            $soldier->ready_at = now()->addMinutes(WarCondition::recoveryMinutes($soldier));
            $soldier->location = 'reserve';
            $soldier->in_vehicle = false;
            $soldier->threat = 'safe';
            $soldier->condition_at = now();
            $soldier->save();
        }
        if ($soldiers->isNotEmpty()) {
            $left = $this->field($squad)->count();
            $squad->alive = $left;
            $squad->save();
            if ($left === 0) {
                RequestWarSupportAction::standDownOrders($squad->id);
            }
        }

        return $soldiers->pluck('name')->values()->all();
    }

    /** The mod hands out the magazines (a resupply it acks in the state file). */
    private function issue(WarSquad $squad, int $cost): WarSupport
    {
        return WarSupport::query()->create([
            'session_id' => $squad->session_id,
            'commander_id' => $squad->commander_id,
            'squad_id' => $squad->id,
            'kind' => 'resupply',
            'status' => 'requested',
            'pos_x' => $squad->pos_x,
            'pos_z' => $squad->pos_z,
            'cost' => $cost,
            'ready_at' => now(),
            'note' => $cost > 0 ? 'From the truck.' : 'At home.',
        ]);
    }

    private function rearming(WarSquad $squad): bool
    {
        return WarSupport::query()
            ->where('squad_id', $squad->id)
            ->where('kind', 'resupply')
            ->where(fn ($q) => $q
                ->whereIn('status', WarSupport::OPEN)
                ->orWhere('created_at', '>', now()->subSeconds((int) config('reforger.war.logistics.rearm_cooldown', 120))))
            ->exists();
    }

    private function debit(WarSquad $squad, int $cost): void
    {
        $enlistment = WarEnlistment::query()
            ->where('session_id', $squad->session_id)
            ->where('commander_id', $squad->commander_id)
            ->lockForUpdate()
            ->first();
        if ($enlistment === null) {
            throw ValidationException::withMessages(['squad_id' => ['Enlist a side first.']]);
        }
        $this->accrue->accrue($enlistment);
        if ($enlistment->supplies < $cost) {
            throw ValidationException::withMessages(['supplies' => ['Not enough supplies to rearm.']]);
        }
        $enlistment->supplies -= $cost;
        $enlistment->save();
        WarSupplyEntry::query()->create([
            'session_id' => $enlistment->session_id,
            'commander_id' => $enlistment->commander_id,
            'amount' => -$cost,
            'reason' => WarSupplyReason::Support,
            'balance' => $enlistment->supplies,
        ]);
    }

    private function own(User $player, int $squadId): WarSquad
    {
        $session = $this->sessions->current();
        $squad = $session === null ? null : WarSquad::query()
            ->whereKey($squadId)
            ->where('session_id', $session->id)
            ->where('commander_id', $player->id)
            ->where('managed', false)
            ->lockForUpdate()
            ->first();
        if ($squad === null) {
            throw ValidationException::withMessages(['squad_id' => ['Pick one of your squads.']]);
        }

        return $squad;
    }

    /** @return Collection<int, WarSoldier> */
    private function field(WarSquad $squad): Collection
    {
        return WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->where('location', 'field')->lockForUpdate()->get();
    }
}
