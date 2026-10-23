<?php

namespace App\Support;

use App\Models\WarSoldier;

/**
 * How a Kolguyev soldier holds up. The game reports the body (health, bleeding, unconscious,
 * stamina, threat, magazines); morale and battles are kept here. Numbers live in
 * config/reforger.php war.condition.
 */
final class WarCondition
{
    public const THREATS = ['safe', 'vigilant', 'alerted', 'threatened'];

    private static function rate(string $key, float $default): float
    {
        return (float) config('reforger.war.condition.'.$key, $default);
    }

    /** Morale a rested soldier settles at: base plus 10 per training tier. */
    public static function baseMorale(int $tier): float
    {
        return min(100.0, self::rate('morale_base', 60) + 10 * $tier);
    }

    /**
     * Advance morale by the minutes since the last report. A calm squad drifts back to its base,
     * a squad resting at home climbs past it, fire and wounds wear it down.
     */
    public static function nextMorale(float $morale, int $tier, string $threat, int $health, bool $resting, float $minutes): float
    {
        $minutes = max(0.0, min(1.0, $minutes));
        $base = self::baseMorale($tier);

        if (in_array($threat, ['alerted', 'threatened'], true)) {
            $morale += self::rate('morale_fire_per_min', -1) * $minutes;
        } elseif ($resting) {
            $morale = min(100.0, $morale + self::rate('morale_rest_per_min', 3) * $minutes);
        } elseif ($morale < $base) {
            $morale = min($base, $morale + self::rate('morale_safe_per_min', 0.5) * $minutes);
        }

        if ($health < 60) {
            $morale += self::rate('morale_wound_per_min', -0.5) * $minutes;
        }

        return round(max(0.0, min(100.0, $morale)), 2);
    }

    public static function lossShock(): float
    {
        return self::rate('morale_loss', 12);
    }

    /** shaken fights a skill level worse, steady a little better. */
    public static function mood(float $morale): string
    {
        if ($morale < self::rate('shaken_below', 30)) {
            return 'shaken';
        }
        if ($morale >= self::rate('steady_from', 80)) {
            return 'steady';
        }

        return 'fair';
    }

    public static function isWounded(WarSoldier $soldier): bool
    {
        return $soldier->down || $soldier->bleeding || $soldier->health < 60;
    }

    /**
     * Short tags the roster shows: what needs the commander's attention first.
     *
     * @return list<string>
     */
    public static function flags(WarSoldier $soldier): array
    {
        $flags = [];
        if ($soldier->location === 'reserve') {
            return ['reserve'];
        }
        if ($soldier->down) {
            $flags[] = 'down';
        }
        if ($soldier->bleeding) {
            $flags[] = 'bleeding';
        }
        if (! $soldier->down && $soldier->health < 60) {
            $flags[] = 'wounded';
        }
        if ($soldier->threat === 'threatened') {
            $flags[] = 'under_fire';
        }
        if ($soldier->mags !== null && $soldier->mags >= 0 && $soldier->mags <= (int) config('reforger.war.condition.low_mags', 1)) {
            $flags[] = 'low_ammo';
        }
        if ($soldier->stamina < 25) {
            $flags[] = 'exhausted';
        }
        if (self::mood((float) $soldier->morale) === 'shaken') {
            $flags[] = 'shaken';
        }
        if ($soldier->in_vehicle) {
            $flags[] = 'mounted';
        }

        return $flags;
    }

    /** Minutes at base before an evacuated soldier can go back. */
    public static function recoveryMinutes(WarSoldier $soldier): int
    {
        $table = config('reforger.war.reserve.recover_minutes', []);
        $key = match (true) {
            $soldier->down => 'down',
            $soldier->health < 30 => 'critical',
            $soldier->health < 60 || $soldier->bleeding => 'wounded',
            default => 'fit',
        };

        return (int) ($table[$key] ?? 10);
    }
}
