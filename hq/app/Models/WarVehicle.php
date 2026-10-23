<?php

namespace App\Models;

use App\Enums\WarSide;
use App\Models\Concerns\BelongsToCommander;
use App\Observers\WarLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A vanilla vehicle drawn from the motor pool: the commander's, delivered at the side's spawn;
 * any of his squads boards it. squad_id is only the squad an old row was drawn for.
 *
 * @property int $id
 * @property int $session_id
 * @property int|null $squad_id
 * @property int|null $commander_id none for the AI command's own trucks
 * @property string $kind key of reforger.war.vehicles
 * @property WarSide $side
 * @property string $status ordered|fielded|destroyed|scrapped
 * @property float|numeric-string|null $pos_x
 * @property float|numeric-string|null $pos_z
 * @property int $health
 * @property int $fuel
 * @property int|null $seats
 * @property int $crew
 * @property int $cost
 * @property Carbon|null $lost_at
 * @property bool $hq lent by HQ for a run: not one of the commander's
 */
#[ObservedBy(WarLogObserver::class)]
class WarVehicle extends Model
{
    use BelongsToCommander;

    public const ACTIVE = ['ordered', 'fielded'];

    protected $table = 'cbpnk_war_vehicles';

    protected $fillable = [
        'session_id',
        'squad_id',
        'commander_id',
        'kind',
        'side',
        'status',
        'pos_x',
        'pos_z',
        'health',
        'fuel',
        'seats',
        'crew',
        'cost',
        'lost_at',
        'hq',
    ];

    protected $casts = [
        'side' => WarSide::class,
        'hq' => 'boolean',
        'pos_x' => 'decimal:2',
        'pos_z' => 'decimal:2',
        'health' => 'integer',
        'fuel' => 'integer',
        'seats' => 'integer',
        'crew' => 'integer',
        'cost' => 'integer',
        'lost_at' => 'datetime',
    ];

    /** @return BelongsTo<WarSquad, $this> */
    public function squad(): BelongsTo
    {
        return $this->belongsTo(WarSquad::class, 'squad_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
