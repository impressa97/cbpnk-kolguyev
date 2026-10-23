<?php

namespace App\Support;

use App\Enums\WarOrderStatus;
use Illuminate\Validation\ValidationException;

/**
 * Tab-separated bundle the Reforger mod reads and writes. No JSON parser in Enforce.
 *
 * orders.tsv, in this order (vehicles before orders, so a boarding order finds its vehicle):
 *   WEEK id · HOME side x z · SQUAD id side x z strength tier · VEHICLE id side kind prefab ·
 *   ORDER id squad side x z kind target|0 [x2 z2] (target: the soldier to treat, the vehicle to board,
 *   the squad to follow) · LEG order x z (a move's further points, in order) ·
 *   FORM squad auto|wedge|line|column|staggered auto|walk|run|sprint ·
 *   CHARGE order plant_s fuze_s (a sabotage's planting time and fuze) · COMBAT squad hold|return|free ·
 *   ALERT squad normal|alert|ready ·
 *   SOLDIER id squad role aim grit fieldcraft service stance pace endurance ·
 *   FIRE id kind x z · SUPPLY id squad magazines · SERVICE id vehicle (refuel and repair)
 * state.tsv:
 *   SQUAD id x z alive [doing combat] · SOLDIER id dead ·
 *   SOLDIER id ok health bleed down stamina threat mags in_vehicle [x z [seat [stance action [heading [target]]]]] ·
 *   ORDER id status · CONTACT squad side x z inf|veh seen|heard age_s [heading [soldier]] ·
 *   SABOTAGE order approach|planting|armed|detonated|disarmed seconds_to_go ·
 *   VEHICLE id x z health fuel seats crew alive · FIRE id status · SUPPLY id status · SERVICE id status ·
 *   HEAL squad n ·
 *   KILL run seq soldier victim_side (one enemy killed by one of ours) ·
 *   DEATH run seq soldier killer|0 enemy|friendly|self|fire|vehicle|other weapon|- metres|-1 x z killer_x|- killer_z|- ·
 *   WORLD hour weather rain fog wind_ms wind_deg sunrise sunset moon · END
 */
final class WarBridgeCodec
{
    private const THREATS = ['safe', 'vigilant', 'alerted', 'threatened'];

    private const ORDER_KINDS = ['move', 'move_forced', 'defend', 'search', 'suppress', 'heal', 'mount', 'dismount', 'halt', 'patrol', 'rest', 'recon', 'sabotage', 'raid', 'smoke', 'follow', 'attack'];

    /**
     * @param  array{session_id: int, homes?: list<array{side: string, x: float, z: float}>, squads: list<array<string, mixed>>, vehicles?: list<array<string, mixed>>, orders: list<array<string, mixed>>, soldiers: list<array<string, mixed>>, fire?: list<array<string, mixed>>, supply?: list<array<string, mixed>>, service?: list<array<string, mixed>>}  $bundle
     */
    public static function encodeOrders(array $bundle): string
    {
        $lines = ['WEEK'."\t".$bundle['session_id']];
        foreach ($bundle['homes'] ?? [] as $home) {
            $lines[] = self::row(['HOME', $home['side'], self::coord($home['x']), self::coord($home['z'])]);
        }
        foreach ($bundle['squads'] as $squad) {
            $lines[] = self::row(['SQUAD', $squad['id'], $squad['side'], self::coord($squad['x']), self::coord($squad['z']), $squad['strength'], $squad['tier']]);
        }
        foreach ($bundle['vehicles'] ?? [] as $vehicle) {
            $lines[] = self::row(['VEHICLE', $vehicle['id'], $vehicle['side'], $vehicle['kind'], $vehicle['prefab']]);
        }
        foreach ($bundle['squads'] as $squad) {
            $lines[] = self::row(['COMBAT', $squad['id'], $squad['combat'] ?? 'free']);
            $lines[] = self::row(['ALERT', $squad['id'], $squad['alert'] ?? 'normal']);
            $lines[] = self::row(['FORM', $squad['id'], $squad['formation'] ?? 'auto', $squad['pace'] ?? 'auto']);
        }
        foreach ($bundle['orders'] as $order) {
            $row = ['ORDER', $order['id'], $order['squad_id'], $order['side'], self::coord($order['x']), self::coord($order['z']), $order['kind'] ?? 'move', $order['target_id'] ?? 0];
            if (isset($order['x2'], $order['z2'])) {
                $row[] = self::coord($order['x2']);
                $row[] = self::coord($order['z2']);
            }
            $lines[] = self::row($row);
            if (isset($order['charge'])) {
                $lines[] = self::row(['CHARGE', $order['id'], $order['charge'][0], $order['charge'][1]]);
            }
            foreach ($order['route'] ?? [] as $leg) {
                $lines[] = self::row(['LEG', $order['id'], self::coord($leg[0]), self::coord($leg[1])]);
            }
        }
        foreach ($bundle['soldiers'] as $soldier) {
            $lines[] = self::row(['SOLDIER', $soldier['id'], $soldier['squad_id'], $soldier['role'], $soldier['aim'], $soldier['grit'], $soldier['fieldcraft'], $soldier['service'] ?? 0, $soldier['stance'] ?? 'auto', $soldier['pace'] ?? 'auto', $soldier['endurance'] ?? 40]);
        }
        foreach ($bundle['fire'] ?? [] as $fire) {
            $lines[] = self::row(['FIRE', $fire['id'], $fire['kind'], self::coord($fire['x']), self::coord($fire['z'])]);
        }
        foreach ($bundle['supply'] ?? [] as $supply) {
            $lines[] = self::row(['SUPPLY', $supply['id'], $supply['squad_id'], $supply['magazines']]);
        }
        foreach ($bundle['service'] ?? [] as $service) {
            $lines[] = self::row(['SERVICE', $service['id'], $service['vehicle_id']]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * State file written by the mod.
     *
     * @return array{
     *     squads: list<array{id: int, x: float, z: float, alive: int, doing: string|null, combat: string|null}>,
     *     orders: list<array{id: int, status: string}>,
     *     dead: list<int>,
     *     condition: list<array{id: int, health: int, bleeding: bool, down: bool, stamina: int, threat: string, mags: int|null, in_vehicle: bool, x: float|null, z: float|null, seat: string|null, stance: string|null, action: string|null, heading?: int|null, target?: int|null}>,
     *     contacts: list<array{squad_id: int, side: string, x: float, z: float, kind: string, how: string, age: int, heading?: int|null, target?: int|null}>,
     *     vehicles: list<array{id: int, x: float, z: float, health: int, fuel: int, seats: int, crew: int, alive: bool}>,
     *     support: list<array{id: int, status: string}>,
     *     heal: list<array{squad_id: int, count: int}>,
     *     sabotage: list<array{id: int, phase: string, left: int}>,
     *     kills: list<array{run: int, seq: int, soldier: int, side: string}>,
     *     deaths: list<array{run: int, seq: int, soldier: int, killer: int|null, cause: string, weapon: string|null, metres: int|null, x: float, z: float, killer_x: float|null, killer_z: float|null}>,
     *     world: array{hour: float, weather: string, rain: int, fog: int, wind: float, wind_dir: int, sunrise: float, sunset: float, moon: int}|null
     * }
     */
    public static function decodeState(string $body): array
    {
        $out = ['squads' => [], 'orders' => [], 'dead' => [], 'condition' => [], 'contacts' => [], 'vehicles' => [], 'support' => [], 'heal' => [], 'sabotage' => [], 'kills' => [], 'deaths' => [], 'world' => null];

        foreach (preg_split("/\r\n|\n|\r/", trim($body)) ?: [] as $line) {
            if ($line === '' || $line === 'END') {
                continue;
            }
            $p = explode("\t", $line);
            $kind = $p[0];
            $n = count($p);

            match (true) {
                $kind === 'SQUAD' && ($n === 5 || $n === 7) => $out['squads'][] = [
                    'id' => self::int($p[1], 'squads'),
                    'x' => self::float($p[2], 'squads'),
                    'z' => self::float($p[3], 'squads'),
                    'alive' => self::int($p[4], 'squads'),
                    // What the game has the group doing, and the combat mode it actually fights in.
                    'doing' => $n === 7 ? self::oneOf($p[5], [...self::ORDER_KINDS, 'idle', 'own'], 'squads') : null,
                    'combat' => $n === 7 ? self::oneOf($p[6], ['hold', 'return', 'free'], 'squads') : null,
                ],
                $kind === 'ORDER' && $n === 3 => $out['orders'][] = [
                    'id' => self::int($p[1], 'orders'),
                    'status' => self::oneOf($p[2], [WarOrderStatus::Acked->value, WarOrderStatus::Done->value, WarOrderStatus::Failed->value], 'orders'),
                ],
                $kind === 'SOLDIER' && $n === 3 && $p[2] === 'dead' => $out['dead'][] = self::int($p[1], 'dead'),
                $kind === 'SOLDIER' && in_array($n, [10, 12, 13, 15, 16, 17], true) && $p[2] === 'ok' => $out['condition'][] = [
                    'id' => self::int($p[1], 'condition'),
                    'health' => max(0, min(100, self::int($p[3], 'condition'))),
                    'bleeding' => $p[4] === '1',
                    'down' => $p[5] === '1',
                    'stamina' => max(0, min(100, self::int($p[6], 'condition'))),
                    'threat' => self::oneOf($p[7], self::THREATS, 'condition'),
                    'mags' => self::int($p[8], 'condition') < 0 ? null : self::int($p[8], 'condition'),
                    'in_vehicle' => $p[9] === '1',
                    // Older mods report no position; the site then keeps the squad's formation.
                    'x' => $n >= 12 ? self::float($p[10], 'condition') : null,
                    'z' => $n >= 12 ? self::float($p[11], 'condition') : null,
                    'seat' => $n >= 13 && $p[12] !== '-' ? self::oneOf($p[12], ['driver', 'gunner', 'passenger'], 'condition') : null,
                    'stance' => $n >= 15 ? self::oneOf($p[13], ['stand', 'crouch', 'prone'], 'condition') : null,
                    // The AI action's class, trimmed (Attack, MoveInFormation, Heal, ...): a word, never markup.
                    'action' => $n >= 15 && $p[14] !== '-' ? substr((string) preg_replace('/[^A-Za-z0-9_]/', '', $p[14]), 0, 40) : null,
                    // Where he looks, a compass bearing; -1: unknown.
                    'heading' => $n >= 16 && self::int($p[15], 'condition') >= 0 ? self::int($p[15], 'condition') % 360 : null,
                    // The war soldier he is fighting (the game's current target); null: nobody.
                    'target' => $n === 17 && self::int($p[16], 'condition') > 0 ? self::int($p[16], 'condition') : null,
                ],
                $kind === 'CONTACT' && $n >= 8 && $n <= 10 => $out['contacts'][] = [
                    'squad_id' => self::int($p[1], 'contacts'),
                    'side' => self::oneOf($p[2], ['us', 'ussr', 'fia'], 'contacts'),
                    'x' => self::float($p[3], 'contacts'),
                    'z' => self::float($p[4], 'contacts'),
                    'kind' => self::oneOf($p[5], ['inf', 'veh'], 'contacts'),
                    'how' => self::oneOf($p[6], ['seen', 'heard'], 'contacts'),
                    'age' => max(0, self::int($p[7], 'contacts')),
                    'heading' => $n >= 9 && self::int($p[8], 'contacts') >= 0 ? self::int($p[8], 'contacts') % 360 : null,
                    // Which war soldier it is; null: a vehicle, or a mod too old to say.
                    'target' => $n === 10 && self::int($p[9], 'contacts') > 0 ? self::int($p[9], 'contacts') : null,
                ],
                $kind === 'VEHICLE' && $n === 9 => $out['vehicles'][] = [
                    'id' => self::int($p[1], 'vehicles'),
                    'x' => self::float($p[2], 'vehicles'),
                    'z' => self::float($p[3], 'vehicles'),
                    'health' => max(0, min(100, self::int($p[4], 'vehicles'))),
                    'fuel' => max(0, min(100, self::int($p[5], 'vehicles'))),
                    'seats' => max(0, self::int($p[6], 'vehicles')),
                    'crew' => max(0, self::int($p[7], 'vehicles')),
                    'alive' => $p[8] === '1',
                ],
                ($kind === 'FIRE' || $kind === 'SUPPLY' || $kind === 'SERVICE') && $n === 3 => $out['support'][] = [
                    'id' => self::int($p[1], 'support'),
                    'status' => self::oneOf($p[2], ['done', 'failed'], 'support'),
                ],
                $kind === 'KILL' && $n === 5 => $out['kills'][] = [
                    'run' => self::int($p[1], 'kills'),
                    'seq' => self::int($p[2], 'kills'),
                    'soldier' => self::int($p[3], 'kills'),
                    'side' => self::oneOf($p[4], ['us', 'ussr', 'fia'], 'kills'),
                ],
                $kind === 'DEATH' && $n === 12 => $out['deaths'][] = [
                    'run' => self::int($p[1], 'deaths'),
                    'seq' => self::int($p[2], 'deaths'),
                    'soldier' => self::int($p[3], 'deaths'),
                    'killer' => self::int($p[4], 'deaths') > 0 ? self::int($p[4], 'deaths') : null,
                    'cause' => self::oneOf($p[5], ['enemy', 'friendly', 'self', 'fire', 'vehicle', 'other'], 'deaths'),
                    // The prefab's file name: a word, never markup.
                    'weapon' => $p[6] === '-' ? null : (substr((string) preg_replace('/[^A-Za-z0-9_\-]/', '', $p[6]), 0, 64) ?: null),
                    'metres' => self::int($p[7], 'deaths') >= 0 ? self::int($p[7], 'deaths') : null,
                    'x' => self::float($p[8], 'deaths'),
                    'z' => self::float($p[9], 'deaths'),
                    'killer_x' => $p[10] === '-' ? null : self::float($p[10], 'deaths'),
                    'killer_z' => $p[11] === '-' ? null : self::float($p[11], 'deaths'),
                ],
                $kind === 'SABOTAGE' && $n === 4 => $out['sabotage'][] = [
                    'id' => self::int($p[1], 'sabotage'),
                    'phase' => self::oneOf($p[2], ['approach', 'planting', 'armed', 'detonated', 'disarmed'], 'sabotage'),
                    'left' => max(0, self::int($p[3], 'sabotage')),
                ],
                $kind === 'HEAL' && $n === 3 => $out['heal'][] = [
                    'squad_id' => self::int($p[1], 'heal'),
                    'count' => max(0, self::int($p[2], 'heal')),
                ],
                $kind === 'WORLD' && $n === 10 => $out['world'] = [
                    'hour' => self::float($p[1], 'world'),
                    'weather' => mb_substr(preg_replace('/[^\w \-]/u', '', $p[2]) ?? '', 0, 32),
                    'rain' => max(0, min(100, self::int($p[3], 'world'))),
                    'fog' => max(0, min(100, self::int($p[4], 'world'))),
                    'wind' => self::float($p[5], 'world'),
                    'wind_dir' => (int) round(self::float($p[6], 'world')) % 360,
                    'sunrise' => self::float($p[7], 'world'),
                    'sunset' => self::float($p[8], 'world'),
                    'moon' => max(0, min(100, self::int($p[9], 'world'))),
                ],
                default => throw ValidationException::withMessages(['state' => ['Unrecognized war state line.']]),
            };
        }

        return $out;
    }

    /** @param  list<string|int|float>  $parts */
    private static function row(array $parts): string
    {
        return implode("\t", $parts);
    }

    /** @param  list<string>  $allowed */
    private static function oneOf(string $value, array $allowed, string $field): string
    {
        if (! in_array($value, $allowed, true)) {
            throw ValidationException::withMessages([$field => ['Value is not one of '.implode(', ', $allowed).'.']]);
        }

        return $value;
    }

    private static function coord(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private static function int(string $value, string $field): int
    {
        if (! preg_match('/^-?\d+$/', $value)) {
            throw ValidationException::withMessages([$field => ['Expected an integer.']]);
        }

        return (int) $value;
    }

    private static function float(string $value, string $field): float
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([$field => ['Expected a number.']]);
        }

        return (float) $value;
    }
}
