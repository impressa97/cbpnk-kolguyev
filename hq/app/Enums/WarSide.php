<?php

namespace App\Enums;

/** Kolguyev war side. Sticky for the continuous war. */
enum WarSide: string
{
    case Us = 'us';
    case Ussr = 'ussr';
    case Fia = 'fia';

    public function label(): string
    {
        return (string) config('reforger.war.sides.'.$this->value.'.name', $this->value);
    }

    public function detail(): string
    {
        return (string) config('reforger.war.sides.'.$this->value.'.detail', '');
    }

    /** Reforger faction catalog key. */
    public function factionKey(): string
    {
        return match ($this) {
            self::Us => 'US',
            self::Ussr => 'USSR',
            self::Fia => 'FIA',
        };
    }

    /**
     * Muster point in terrain metres, south-west origin, X east, Z north. Each extra squad
     * stands 200 m further from home toward the island centre, which keeps coastal homes
     * (Kamensk sits on the north-east shore) off the water.
     *
     * @return array{0: float, 1: float}
     */
    public function muster(int $index = 0): array
    {
        $places = config('reforger.war.places', []);
        if (! is_array($places)) {
            return [0.0, 0.0];
        }

        foreach ($places as $place) {
            if (($place['side'] ?? null) !== $this->value || ($place['kind'] ?? null) !== 'home') {
                continue;
            }

            $x = (float) $place['x'];
            $z = (float) $place['z'];
            if ($index <= 0) {
                return [$x, $z];
            }

            $centre = (float) config('reforger.war.world_size', 12800) / 2;
            $dx = $centre - $x;
            $dz = $centre - $z;
            $length = sqrt($dx * $dx + $dz * $dz);
            if ($length < 1.0) {
                return [$x, $z];
            }

            $step = 200.0 * $index;

            return [round($x + $dx / $length * $step, 2), round($z + $dz / $length * $step, 2)];
        }

        return [0.0, 0.0];
    }
}
