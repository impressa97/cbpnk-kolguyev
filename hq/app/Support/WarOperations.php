<?php

namespace App\Support;

use App\Enums\WarOrderKind;
use App\Models\WarSoldier;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Special operations (recon, sabotage, raid) as config/reforger.php war.operations has them:
 * what they are aimed at, who the squad needs, and the help the page shows, built from the same
 * numbers the site judges them by.
 */
final class WarOperations
{
    /** @return array<string, mixed> */
    public static function spec(WarOrderKind|string $kind): array
    {
        $id = $kind instanceof WarOrderKind ? $kind->value : $kind;
        $spec = config('reforger.war.operations.'.$id, []);

        return is_array($spec) ? $spec : [];
    }

    /**
     * Where an operation really points: the nearest place of its target kinds within `snap` m of
     * the mark, or the mark itself when the operation may go anywhere.
     *
     * @return array{0: float, 1: float, 2: string|null}
     */
    public static function aim(WarOrderKind $kind, float $x, float $z): array
    {
        $spec = self::spec($kind);
        $kinds = (array) ($spec['targets'] ?? []);
        $snap = (float) ($spec['snap'] ?? 0);

        $best = null;
        foreach (config('reforger.war.places', []) as $place) {
            if (! in_array($place['kind'] ?? null, $kinds, true)) {
                continue;
            }
            $d = hypot((float) $place['x'] - $x, (float) $place['z'] - $z);
            if ($d <= $snap && ($best === null || $d < $best[0])) {
                $best = [$d, $place];
            }
        }

        if ($best !== null) {
            return [(float) $best[1]['x'], (float) $best[1]['z'], (string) $best[1]['id']];
        }
        if ($spec['required'] ?? false) {
            throw ValidationException::withMessages(['kind' => [sprintf('%s must be aimed at a %s: mark one within %d m.', $spec['label'] ?? $kind->label(), self::kindsText($kinds), (int) $snap)]]);
        }

        return [$x, $z, null];
    }

    /**
     * Whether the squad's men in the field include the role the operation needs.
     *
     * @param  Collection<int, WarSoldier>  $field
     */
    public static function staffed(WarOrderKind|string $kind, Collection $field): bool
    {
        $needs = self::spec($kind)['needs'] ?? null;

        return $needs === null || $field->contains(fn ($soldier) => str_contains((string) $soldier->role, (string) $needs));
    }

    /**
     * The operations for the page: their targets and rules, and a line of help from the numbers.
     *
     * @return list<array{id: string, label: string, radius: int, hold: int, targets: list<string>, snap: int, required: bool, needs: string|null, help: string}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (config('reforger.war.operations', []) as $id => $spec) {
            $out[] = [
                'id' => (string) $id,
                'label' => (string) ($spec['label'] ?? ucfirst((string) $id)),
                'radius' => (int) ($spec['radius'] ?? 75),
                'hold' => (int) ($spec['hold'] ?? 120),
                'targets' => array_values(array_map('strval', (array) ($spec['targets'] ?? []))),
                'snap' => (int) ($spec['snap'] ?? 0),
                'required' => (bool) ($spec['required'] ?? false),
                'needs' => isset($spec['needs']) ? (string) $spec['needs'] : null,
                'help' => self::help((string) $id, $spec),
            ];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $spec */
    private static function help(string $id, array $spec): string
    {
        $interdict = (int) config('reforger.war.territory.interdict_hours', 2);
        if ($id === 'recon') {
            return sprintf(
                'The game\'s Scout: the squad goes to the mark holding fire and watches the area for %s. Your side keeps what it sees on the map for %d min instead of %d; a scouted enemy-held point stops paying its holder for %d h.',
                self::minutes((int) ($spec['hold'] ?? 600)),
                (int) ($spec['intel_minutes'] ?? 30),
                (int) config('reforger.war.contact_minutes', 15),
                $interdict,
            );
        }
        if ($id === 'sabotage') {
            return sprintf(
                'Needs a sapper. The squad goes to a relay tower; the sapper plants a real demolition charge (%s) with a %d s fuze and the squad falls back. Anyone can disarm it in time. If it goes off, the other sides get no contact reports for %d min and the relay\'s holder loses its income for %d h.',
                self::minutes((int) ($spec['plant'] ?? 20)).' to plant',
                (int) ($spec['fuze'] ?? 45),
                (int) ($spec['blackout_minutes'] ?? 60),
                $interdict,
            );
        }

        $hold = self::minutes((int) ($spec['hold'] ?? 120));
        $where = ($spec['required'] ?? false) ? 'a '.self::kindsText((array) ($spec['targets'] ?? [])) : 'the mark';
        $needs = isset($spec['needs']) ? 'Needs a '.$spec['needs'].' in the squad. ' : '';
        $stay = sprintf('%sWalks in holding fire; must stay within %d m of %s unseen for %s (being spotted restarts the clock).', $needs, (int) ($spec['radius'] ?? 75), $where, $hold);

        $effect = match ($id) {
            'recon' => sprintf(
                'Your side keeps this squad\'s contacts for %d min instead of %d. Aimed at an enemy-held point, it also stops the point paying its holder for %d h.',
                (int) ($spec['intel_minutes'] ?? 30),
                (int) config('reforger.war.contact_minutes', 15),
                (int) config('reforger.war.territory.interdict_hours', 2),
            ),
            'sabotage' => sprintf(
                'The other sides get no contact reports for %d min, and the relay\'s holder loses its income for %d h.',
                (int) ($spec['blackout_minutes'] ?? 60),
                (int) config('reforger.war.territory.interdict_hours', 2),
            ),
            'raid' => sprintf(
                '+%d supplies for you; every enemy commander loses %d. Your side can raid a harbour once every %d h.',
                (int) ($spec['plunder'] ?? 60),
                (int) ($spec['enemy_loss'] ?? 20),
                (int) ($spec['cooldown_hours'] ?? 6),
            ),
            default => '',
        };

        return trim($stay.' '.$effect);
    }

    private static function minutes(int $seconds): string
    {
        return $seconds >= 60 && $seconds % 60 === 0 ? ($seconds / 60).' min' : $seconds.' s';
    }

    /** @param  array<int, mixed>  $kinds */
    private static function kindsText(array $kinds): string
    {
        $words = ['relay' => 'relay tower', 'harbour' => 'harbour', 'base' => 'base', 'objective' => 'objective', 'town' => 'town'];

        return implode(' or ', array_map(fn ($kind) => $words[$kind] ?? (string) $kind, $kinds));
    }
}
