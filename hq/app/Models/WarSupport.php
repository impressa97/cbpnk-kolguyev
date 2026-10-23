<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCommander;
use App\Observers\WarLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request to HQ: evacuation, resupply, or a fire mission.
 *
 * Status: requested (waiting for ready_at) → waiting (evac at a hot LZ) → done | aborted | failed | cancelled.
 *
 * @property int $id
 * @property int $session_id
 * @property int $commander_id
 * @property int|null $squad_id
 * @property int|null $vehicle_id the vehicle a service refuels and repairs
 * @property string $kind key of reforger.war.support
 * @property string $status
 * @property float|numeric-string|null $pos_x
 * @property float|numeric-string|null $pos_z
 * @property int $cost
 * @property Carbon $ready_at
 * @property Carbon|null $done_at
 * @property string $note
 */
#[ObservedBy(WarLogObserver::class)]
class WarSupport extends Model
{
    use BelongsToCommander;

    public const OPEN = ['requested', 'waiting'];

    protected $table = 'cbpnk_war_support';

    protected $fillable = [
        'session_id',
        'commander_id',
        'squad_id',
        'vehicle_id',
        'kind',
        'status',
        'pos_x',
        'pos_z',
        'cost',
        'ready_at',
        'done_at',
        'note',
    ];

    protected $casts = [
        'pos_x' => 'decimal:2',
        'pos_z' => 'decimal:2',
        'cost' => 'integer',
        'ready_at' => 'datetime',
        'done_at' => 'datetime',
    ];

    /** @return BelongsTo<WarSquad, $this> */
    public function squad(): BelongsTo
    {
        return $this->belongsTo(WarSquad::class, 'squad_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isFire(): bool
    {
        return in_array($this->kind, ['smoke', 'illum', 'fire_he_small', 'fire_he_medium'], true);
    }
}
