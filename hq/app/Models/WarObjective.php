<?php

namespace App\Models;

use App\Enums\WarSide;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An HQ objective for one side. Any commander on that side may complete it; the reward goes to
 * whoever does. Kinds and checks: RunWarObjectivesAction.
 *
 * @property int $id
 * @property int $session_id
 * @property WarSide $side
 * @property string $kind capture|supply_run|interdict|sabotage|raid
 * @property string|null $stage supply run: loaded
 * @property int|null $vehicle_id
 * @property string $target place id
 * @property string $title
 * @property float|numeric-string $pos_x
 * @property float|numeric-string $pos_z
 * @property int $reward
 * @property string $status open|done|expired
 * @property int|null $squad_id
 * @property Carbon|null $progress_at
 * @property int|null $commander_id
 * @property Carbon $expires_at
 * @property Carbon|null $done_at
 */
class WarObjective extends Model
{
    protected $table = 'cbpnk_war_objectives';

    protected $fillable = [
        'session_id',
        'side',
        'kind',
        'target',
        'title',
        'pos_x',
        'pos_z',
        'reward',
        'status',
        'stage',
        'vehicle_id',
        'squad_id',
        'progress_at',
        'commander_id',
        'expires_at',
        'done_at',
    ];

    protected $casts = [
        'side' => WarSide::class,
        'pos_x' => 'decimal:2',
        'pos_z' => 'decimal:2',
        'reward' => 'integer',
        'progress_at' => 'datetime',
        'expires_at' => 'datetime',
        'done_at' => 'datetime',
    ];
}
