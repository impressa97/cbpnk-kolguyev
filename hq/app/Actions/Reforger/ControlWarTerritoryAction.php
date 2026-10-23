<?php

namespace App\Actions\Reforger;

use App\Models\WarContact;
use App\Models\WarControl;
use App\Models\WarSession;
use App\Models\WarSquad;
use App\Support\WarTerritory;
use Illuminate\Support\Facades\DB;

/**
 * Who holds what. A side takes a control point by keeping a squad on it for war.territory.capture
 * seconds while no other side has a squad there and its own squads see no enemy nearby. The
 * holder keeps it until someone else does the same; leaving does not lose it.
 */
final class ControlWarTerritoryAction
{
    public function run(): void
    {
        $session = WarSession::query()->whereNull('closed_at')->orderByDesc('id')->first();
        if ($session === null || ! $session->isOpen()) {
            return;
        }

        $radius = (float) config('reforger.war.territory.radius', 150);
        $clear = (float) config('reforger.war.territory.clear', 300);
        $capture = (int) config('reforger.war.territory.capture', 300);

        DB::transaction(function () use ($session, $radius, $clear, $capture) {
            $squads = WarSquad::query()
                ->where('session_id', $session->id)
                ->whereHas('soldiers', fn ($q) => $q->whereNull('fallen_at')->where('location', 'field'))
                ->get();
            $contacts = WarContact::query()
                ->with('squad')
                ->where('session_id', $session->id)
                // What the squads perceive now: a contact the game still reports is refreshed on
                // every tick; the map keeps older sightings, but they do not hold a point.
                ->where('updated_at', '>=', now()->subSeconds(30))
                ->get();

            foreach (WarTerritory::points() as $place) {
                $present = $squads
                    ->filter(fn (WarSquad $s) => hypot((float) $s->pos_x - $place['x'], (float) $s->pos_z - $place['z']) <= $radius)
                    ->map(fn (WarSquad $s) => $s->side->value)
                    ->unique()
                    ->values();

                $control = WarControl::query()->firstOrCreate(
                    ['session_id' => $session->id, 'place' => $place['id']],
                );

                $claimant = $present->count() === 1 ? $present->first() : null;
                if ($claimant !== null) {
                    $threatened = $contacts->contains(fn (WarContact $c) => $c->squad?->side->value === $claimant
                        && $c->side->value !== $claimant
                        && hypot((float) $c->pos_x - $place['x'], (float) $c->pos_z - $place['z']) <= $clear);
                    if ($threatened) {
                        $claimant = null;
                    }
                }

                if ($claimant === null || $claimant === $control->side) {
                    if ($control->claim_side !== null) {
                        $control->claim_side = null;
                        $control->claim_since = null;
                        $control->save();
                    }

                    continue;
                }

                if ($control->claim_side !== $claimant) {
                    $control->claim_side = $claimant;
                    $control->claim_since = now();
                    $control->save();

                    continue;
                }

                if ($control->claim_since->diffInSeconds(now(), true) >= $capture) {
                    $control->side = $claimant;
                    $control->held_since = now();
                    $control->claim_side = null;
                    $control->claim_since = null;
                    $control->interdicted_until = null;
                    $control->save();
                }
            }
        });
    }
}
