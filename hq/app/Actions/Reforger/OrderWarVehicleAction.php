<?php

namespace App\Actions\Reforger;

use App\Support\WarLock;

use App\Enums\WarSupplyReason;
use App\Models\User;
use App\Models\WarEnlistment;
use App\Models\WarSupplyEntry;
use App\Models\WarVehicle;
use App\Support\WarEconomy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draw a vehicle from the motor pool, or hand it back. As at Conflict's vehicle depot it is
 * delivered at the side's spawn on free ground and belongs to no squad: the commander's squads
 * board it by name.
 */
final class OrderWarVehicleAction
{
    public function __construct(
        private readonly ResolveWarSessionAction $sessions,
        private readonly AccrueWarSuppliesAction $accrue,
    ) {}

    public function order(User $player, string $kind): WarVehicle
    {
        $this->sessions->closeDue();

        return WarLock::run(fn () => DB::transaction(function () use ($player, $kind) {

            $session = $this->sessions->current();
            if ($session === null) {
                throw ValidationException::withMessages(['vehicle' => ['This week is over.']]);
            }

            $enlistment = WarEnlistment::query()
                ->where('session_id', $session->id)
                ->where('commander_id', $player->id)
                ->lockForUpdate()
                ->first();
            if ($enlistment === null) {
                throw ValidationException::withMessages(['vehicle' => ['Enlist a side first.']]);
            }

            $limit = (int) config('reforger.war.vehicle_limit', 4);
            $held = WarVehicle::query()
                ->where('session_id', $session->id)
                ->where('commander_id', $player->id)
                ->where('hq', false)
                ->whereIn('status', WarVehicle::ACTIVE)
                ->count();
            if ($held >= $limit) {
                throw ValidationException::withMessages(['vehicle' => ["You already run {$limit} vehicles. Send one back first."]]);
            }

            $spec = WarEconomy::vehicle($kind, $enlistment->side);
            $this->accrue->accrue($enlistment);
            if ($enlistment->supplies < $spec['cost']) {
                throw ValidationException::withMessages(['supplies' => ['Not enough supplies for the '.$spec['model'].'.']]);
            }

            $enlistment->supplies -= $spec['cost'];
            $enlistment->save();
            WarSupplyEntry::query()->create([
                'session_id' => $session->id,
                'commander_id' => $player->id,
                'amount' => -$spec['cost'],
                'reason' => WarSupplyReason::Vehicle,
                'balance' => $enlistment->supplies,
            ]);

            // Delivered at the side's spawn; the game finds free ground there and reports where.
            [$x, $z] = $enlistment->side->muster();

            return WarVehicle::query()->create([
                'session_id' => $session->id,
                'squad_id' => null,
                'commander_id' => $player->id,
                'kind' => $kind,
                'side' => $enlistment->side,
                'status' => 'ordered',
                'pos_x' => $x,
                'pos_z' => $z,
                'cost' => $spec['cost'],
            ]);
        }, 3));
    }

    /** Send the vehicle back to the pool. Nothing is refunded: the supplies went into running it. */
    public function scrap(User $player, int $vehicleId): void
    {
        DB::transaction(function () use ($player, $vehicleId) {
            $vehicle = WarVehicle::query()
                ->whereKey($vehicleId)
                ->where('commander_id', $player->id)
                ->whereIn('status', WarVehicle::ACTIVE)
                ->lockForUpdate()
                ->first();
            if ($vehicle === null) {
                throw ValidationException::withMessages(['vehicle' => ['That vehicle is not yours to return.']]);
            }

            $vehicle->status = 'scrapped';
            $vehicle->lost_at = now();
            $vehicle->save();
        }, 3);
    }
}
