<?php

namespace App\Support;

use App\Models\WarEnlistment;
use App\Models\WarSquad;

/**
 * A commander's squads. He has all of them (war.max_squads) from the day he enlists; they cost
 * nothing until he puts men in them. Each has a callsign until he names it.
 */
final class WarSquads
{
    public const CALLSIGNS = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel', 'India', 'Juliet'];

    public static function callsign(int $index): string
    {
        return self::CALLSIGNS[$index] ?? 'Squad '.($index + 1);
    }

    /** Raise the empty squads this commander is still missing, at his side's home. */
    public static function ensure(WarEnlistment $enlistment): void
    {
        $held = WarSquad::query()
            ->where('session_id', $enlistment->session_id)
            ->where('commander_id', $enlistment->commander_id)
            ->where('managed', false)
            ->count();

        [$x, $z] = $enlistment->side->muster();
        for ($i = $held; $i < WarEconomy::maxSquads(); $i++) {
            WarSquad::query()->create([
                'session_id' => $enlistment->session_id,
                'commander_id' => $enlistment->commander_id,
                'side' => $enlistment->side,
                'strength' => 0,
                'alive' => 0,
                'tier' => 0,
                'pos_x' => $x,
                'pos_z' => $z,
            ]);
        }
    }

    /**
     * What each squad is called: its name, or the callsign its place in the commander's own
     * list gives it (HQ detachments count apart).
     *
     * @param  iterable<WarSquad>  $squads
     * @return array<int, string> squad id => label
     */
    public static function labels(iterable $squads): array
    {
        $seen = [];
        $labels = [];
        foreach ($squads as $squad) {
            // The AI command's squads count per side and wear the side's name.
            $key = $squad->ai ? 'ai-'.$squad->side->value : $squad->commander_id.'-'.(int) $squad->managed;
            $index = $seen[$key] ?? 0;
            $seen[$key] = $index + 1;
            $call = self::callsign($index);
            $labels[$squad->id] = $squad->ai
                ? strtoupper($squad->side->value).' '.$call
                : ($squad->managed ? 'HQ '.$call : (trim((string) $squad->name) !== '' ? trim((string) $squad->name) : $call));
        }

        return $labels;
    }
}
