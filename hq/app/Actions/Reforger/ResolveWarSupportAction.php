<?php

namespace App\Actions\Reforger;

use App\Models\WarSoldier;
use Illuminate\Support\Facades\DB;

/**
 * Let the reserve at base recover. (HQ no longer flies anyone out: the wounded are driven or
 * walked home to the hospital, see SupplyWarSquadAction.)
 */
final class ResolveWarSupportAction
{
    public function resolve(?int $sessionId = null): void
    {
        DB::transaction(fn () => $this->recover($sessionId));
    }

    /** Rest at base: morale climbs, and anyone whose time is up is fit again. */
    private function recover(?int $sessionId): void
    {
        $perHour = (float) config('reforger.war.reserve.morale_per_hour', 15);
        $reserve = WarSoldier::query()
            ->whereNull('fallen_at')
            ->where('location', 'reserve')
            ->when($sessionId !== null, fn ($query) => $query->where('session_id', $sessionId))
            ->lockForUpdate()
            ->get();

        foreach ($reserve as $soldier) {
            $since = $soldier->condition_at ?? now();
            $hours = max(0, $since->diffInSeconds(now(), true)) / 3600;
            if ($hours < 1 / 60) {
                continue;
            }

            $soldier->morale = (string) min(100, round((float) $soldier->morale + $perHour * $hours, 2));
            if ($soldier->ready_at === null || $soldier->ready_at->lessThanOrEqualTo(now())) {
                $soldier->health = 100;
                $soldier->stamina = 100;
                $soldier->bleeding = false;
                $soldier->down = false;
            }
            $soldier->condition_at = now();
            $soldier->save();
        }
    }
}
