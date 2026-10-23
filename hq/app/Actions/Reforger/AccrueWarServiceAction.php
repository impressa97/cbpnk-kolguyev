<?php

namespace App\Actions\Reforger;

use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Support\WarEconomy;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Add hours served since the last visit. Time at the cap is not banked. */
final class AccrueWarServiceAction
{
    public function accrue(WarSquad $squad, ?DateTimeInterface $until = null): void
    {
        $until = Carbon::parse($until ?? now());
        if ($until->greaterThan(now())) {
            $until = now();
        }

        $cap = WarEconomy::serviceCap();

        $soldiers = WarSoldier::query()
            ->where('squad_id', $squad->id)
            ->whereNull('fallen_at')
            ->lockForUpdate()
            ->get();

        foreach ($soldiers as $soldier) {
            $this->accrueSoldier($soldier, $cap, $until);
        }
    }

    private function accrueSoldier(WarSoldier $soldier, int $cap, Carbon $until): void
    {
        if ($soldier->service_at === null) {
            $soldier->service_at = $soldier->joined_at ?? $until;
        }

        if ($soldier->service >= $cap) {
            $soldier->service_at = $until;
            $soldier->save();

            return;
        }

        $elapsed = max(0, (int) $soldier->service_at->diffInSeconds($until, false));
        $gain = intdiv($elapsed, 3600);
        if ($gain < 1) {
            return;
        }

        $applied = min($gain, $cap - $soldier->service);
        $soldier->service += $applied;
        $soldier->service_at = $soldier->service_at->copy()->addSeconds($applied * 3600);
        $soldier->save();
    }
}
