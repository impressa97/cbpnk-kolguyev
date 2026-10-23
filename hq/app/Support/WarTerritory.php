<?php

namespace App\Support;

use App\Enums\WarSide;
use App\Models\WarControl;

/** Control points and what they pay. Config: war.territory, places: war.places. */
final class WarTerritory
{
    /**
     * Places that can be held: every place except the sides' homes.
     *
     * @return list<array{id: string, name: string, side: string|null, kind: string, x: int, z: int}>
     */
    public static function points(): array
    {
        return array_values(array_filter(
            config('reforger.war.places', []),
            fn (array $place) => ($place['kind'] ?? '') !== 'home' && self::income((string) $place['kind']) > 0,
        ));
    }

    /** @return array{id: string, name: string, side: string|null, kind: string, x: int, z: int}|null */
    public static function point(string $id): ?array
    {
        foreach (self::points() as $place) {
            if ($place['id'] === $id) {
                return $place;
            }
        }

        return null;
    }

    public static function income(string $kind): int
    {
        return (int) config('reforger.war.territory.income.'.$kind, 0);
    }

    /** Extra supplies per hour every commander of this side earns from the points it holds. */
    public static function incomeFor(int $sessionId, WarSide $side): int
    {
        return (int) WarControl::query()
            ->where('session_id', $sessionId)
            ->where('side', $side->value)
            ->get()
            ->filter(fn (WarControl $control) => $control->pays())
            ->sum(fn (WarControl $control) => self::income((string) (self::point($control->place)['kind'] ?? '')));
    }

    /** The point pays nobody for a while (recon marked it for interdiction, or its relay is down). */
    public static function interdict(int $sessionId, string $place): ?WarControl
    {
        $control = WarControl::query()->where('session_id', $sessionId)->where('place', $place)->lockForUpdate()->first();
        if ($control === null || $control->side === null) {
            return null;
        }

        $control->interdicted_until = now()->addHours((int) config('reforger.war.territory.interdict_hours', 2));
        $control->save();

        return $control;
    }
}
