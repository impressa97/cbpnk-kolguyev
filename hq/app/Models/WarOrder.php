<?php

namespace App\Models;

use App\Enums\WarOrderStatus;
use App\Models\Concerns\BelongsToCommander;
use App\Observers\WarLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A move order from the website to one Kolguyev squad.
 *
 * @property int $id
 * @property int $session_id
 * @property int $squad_id
 * @property int|null $soldier_id the soldier the squad's medic treats; null otherwise
 * @property int|null $vehicle_id the vehicle a board order gets into
 * @property list<array{0: float, 1: float}>|null $route a move's further points, in order
 * @property int|null $follow_squad_id the squad a follow order follows
 * @property int|null $commander_id null: the AI command's
 * @property string $kind
 * @property WarOrderStatus $status
 * @property float|numeric-string $pos_x
 * @property float|numeric-string $pos_z
 * @property float|numeric-string|null $pos2_x a patrol's other end
 * @property float|numeric-string|null $pos2_z
 * @property string|null $target place id an operation is aimed at
 * @property int|null $enemy_id the enemy soldier an attack goes for (the contact the commander picked)
 * @property Carbon|null $arrived_at
 * @property Carbon|null $effect_until
 * @property string $note
 */
#[ObservedBy(WarLogObserver::class)]
class WarOrder extends Model
{
    use BelongsToCommander;

    protected $table = 'cbpnk_war_orders';

    protected $fillable = [
        'attempt',
        'squad_id',
        'soldier_id',
        'vehicle_id',
        'route',
        'follow_squad_id',
        'commander_id',
        'session_id',
        'kind',
        'status',
        'pos_x',
        'pos_z',
        'pos2_x',
        'pos2_z',
        'target',
        'arrived_at',
        'effect_until',
        'note',
        'enemy_id',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'status' => WarOrderStatus::class,
        'route' => 'array',
        'pos_x' => 'decimal:2',
        'pos_z' => 'decimal:2',
        'pos2_x' => 'decimal:2',
        'pos2_z' => 'decimal:2',
        'arrived_at' => 'datetime',
        'effect_until' => 'datetime',
    ];

    /** @return BelongsTo<WarSquad, $this> */
    public function squad(): BelongsTo
    {
        return $this->belongsTo(WarSquad::class, 'squad_id');
    }

    /** @return BelongsTo<WarSoldier, $this> */
    public function soldier(): BelongsTo
    {
        return $this->belongsTo(WarSoldier::class, 'soldier_id');
    }
}
