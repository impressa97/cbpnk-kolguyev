<?php

namespace App\Support;

use App\Models\WarSoldier;
use App\Models\WarSquad;
use Illuminate\Support\Facades\DB;

/**
 * The war's log (cbpnk_war_log): what happened, when, where, and the ids it touches, so a fight
 * can be put back together afterwards (`php artisan war:timeline`). Rows are only added; a row
 * with a key is written once, however often the mod repeats the report.
 *
 * Kinds are dotted: order.given, order.done, squad.engaged, soldier.killed, run.stage, ...
 */
final class WarLog
{
    public const TABLE = 'cbpnk_war_log';

    /**
     * @param  array{session_id?: int|null, side?: string|null, commander_id?: int|null, squad_id?: int|null, soldier_id?: int|null, other_id?: int|null, order_id?: int|null, ref?: string|null, x?: float|string|null, z?: float|string|null}  $ids
     * @param  array<string, mixed>  $data
     */
    public static function write(string $kind, string $text, array $ids = [], array $data = [], ?string $key = null): void
    {
        $row = self::row($kind, $text, $ids, $data, $key);
        if ($key === null) {
            DB::table(self::TABLE)->insert($row);
        } else {
            DB::table(self::TABLE)->insertOrIgnore($row);
        }
    }

    /**
     * The row under this key, written now or rewritten with what is known better (a death the
     * site saw before the mod said who did it).
     *
     * @param  array{session_id?: int|null, side?: string|null, commander_id?: int|null, squad_id?: int|null, soldier_id?: int|null, other_id?: int|null, order_id?: int|null, ref?: string|null, x?: float|string|null, z?: float|string|null}  $ids
     * @param  array<string, mixed>  $data
     */
    public static function put(string $key, string $kind, string $text, array $ids = [], array $data = []): void
    {
        $row = self::row($kind, $text, $ids, $data, $key);
        $known = DB::table(self::TABLE)->where('key', $key)->first(['id', 'at']);
        if ($known === null) {
            DB::table(self::TABLE)->insertOrIgnore($row);

            return;
        }
        // It happened when it was first written.
        unset($row['at'], $row['key']);
        DB::table(self::TABLE)->where('id', $known->id)->update($row);
    }

    /**
     * Who a squad is, for a row.
     *
     * @return array{session_id: int, side: string, commander_id: int|null, squad_id: int, x: float|string, z: float|string}
     */
    public static function squad(WarSquad $squad): array
    {
        return [
            'session_id' => $squad->session_id,
            'side' => $squad->side->value,
            'commander_id' => $squad->commander_id,
            'squad_id' => $squad->id,
            'x' => $squad->pos_x,
            'z' => $squad->pos_z,
        ];
    }

    /**
     * Who a soldier is, for a row: his squad, and where he was last seen.
     *
     * @return array{session_id: int, side: string|null, commander_id: int|null, squad_id: int, soldier_id: int, x: float|string|null, z: float|string|null}
     */
    public static function soldier(WarSoldier $soldier): array
    {
        return [
            'session_id' => $soldier->session_id,
            'side' => $soldier->squad?->side->value,
            'commander_id' => $soldier->commander_id,
            'squad_id' => $soldier->squad_id,
            'soldier_id' => $soldier->id,
            'x' => $soldier->pos_x,
            'z' => $soldier->pos_z,
        ];
    }

    /** "Pte Ivanov #312 (sq 12)" */
    public static function who(?WarSoldier $soldier): string
    {
        if ($soldier === null) {
            return 'someone';
        }

        return trim($soldier->name).' #'.$soldier->id.' ('.$soldier->role.', sq '.$soldier->squad_id.')';
    }

    public static function at(float|string|null $x, float|string|null $z): string
    {
        if ($x === null || $z === null) {
            return '';
        }

        return ' at '.round((float) $x).','.round((float) $z);
    }

    /**
     * @param  array<string, mixed>  $ids
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function row(string $kind, string $text, array $ids, array $data, ?string $key): array
    {
        $x = $ids['x'] ?? null;
        $z = $ids['z'] ?? null;

        return [
            'session_id' => $ids['session_id'] ?? null,
            'at' => now(),
            'kind' => substr($kind, 0, 32),
            'side' => $ids['side'] ?? null,
            'commander_id' => $ids['commander_id'] ?? null,
            'squad_id' => $ids['squad_id'] ?? null,
            'soldier_id' => $ids['soldier_id'] ?? null,
            'other_id' => $ids['other_id'] ?? null,
            'order_id' => $ids['order_id'] ?? null,
            'ref' => isset($ids['ref']) ? substr($ids['ref'], 0, 32) : null,
            'pos_x' => $x === null ? null : round((float) $x, 2),
            'pos_z' => $z === null ? null : round((float) $z, 2),
            'text' => mb_substr($text, 0, 255),
            'data' => $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'key' => $key,
        ];
    }
}
