<?php

namespace App\Actions\Reforger;

use App\Enums\WarSupplyReason;
use App\Models\WarEnlistment;
use App\Models\WarSupplyEntry;
use App\Support\WarEconomy;
use App\Support\WarTerritory;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Add supplies earned since the last collection. Sitting at the cap does not bank extra time. */
final class AccrueWarSuppliesAction
{
    public function accrue(WarEnlistment $enlistment, ?DateTimeInterface $until = null): void
    {
        $until = Carbon::parse($until ?? now());
        if ($until->greaterThan(now())) {
            $until = now();
        }

        $cap = WarEconomy::supplyCap();
        // Base income plus whatever the side's held control points pay right now.
        $rate = WarEconomy::incomePerHour() + WarTerritory::incomeFor($enlistment->session_id, $enlistment->side);

        if ($enlistment->supplied_at === null) {
            $enlistment->supplied_at = $until;
            $enlistment->save();

            return;
        }

        if ($enlistment->supplies >= $cap || $rate <= 0) {
            $enlistment->supplied_at = $until;
            $enlistment->save();

            return;
        }

        $elapsed = max(0, (int) $enlistment->supplied_at->diffInSeconds($until, false));
        $gain = intdiv($elapsed * $rate, 3600);
        if ($gain < 1) {
            return;
        }

        $applied = min($gain, $cap - $enlistment->supplies);
        $consumed = intdiv($applied * 3600, $rate);
        $enlistment->supplies += $applied;
        $enlistment->supplied_at = $enlistment->supplied_at->copy()->addSeconds(max($consumed, 1));
        $enlistment->save();

        WarSupplyEntry::query()->create([
            'session_id' => $enlistment->session_id,
            'commander_id' => $enlistment->commander_id,
            'amount' => $applied,
            'reason' => WarSupplyReason::Income,
            'balance' => $enlistment->supplies,
        ]);
    }
}
