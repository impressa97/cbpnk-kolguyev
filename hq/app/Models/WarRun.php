<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCommander;
use App\Observers\WarLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An HQ run for one of a commander's squads: an HQ driver brings an HQ vehicle out.
 *
 * Kind: supply (an ammunition truck: the squad rearms beside it) | ambulance (the wounded
 * board and are driven to the hospital at home) | transport (the squad is driven to a point).
 * Stage: out → arrived → loading → carrying → unloading → home. Status: active | done | aborted.
 *
 * @property int $id
 * @property int $session_id
 * @property int $commander_id
 * @property string $kind
 * @property int $squad_id the commander's squad served
 * @property int|null $crew_squad_id HQ's driver (and, on an ambulance, the wounded aboard)
 * @property int|null $vehicle_id
 * @property string $stage
 * @property Carbon|null $stage_at
 * @property string $status
 * @property float|numeric-string $pick_x
 * @property float|numeric-string $pick_z
 * @property float|numeric-string|null $drop_x
 * @property float|numeric-string|null $drop_z
 * @property array<int|string, int>|null $riders wounded taken aboard: soldier id => his own squad
 * @property int $cost
 * @property string $note
 * @property Carbon $ends_at
 */
#[ObservedBy(WarLogObserver::class)]
class WarRun extends Model
{
    use BelongsToCommander;

    protected $table = 'cbpnk_war_runs';

    protected $fillable = [
        'session_id', 'commander_id', 'kind', 'squad_id', 'crew_squad_id', 'vehicle_id', 'stage', 'stage_at', 'status',
        'pick_x', 'pick_z', 'drop_x', 'drop_z', 'riders', 'cost', 'note', 'ends_at', 'tries',
    ];

    protected $casts = [
        'stage_at' => 'datetime',
        'tries' => 'integer',
        'ends_at' => 'datetime',
        'pick_x' => 'decimal:2',
        'pick_z' => 'decimal:2',
        'drop_x' => 'decimal:2',
        'drop_z' => 'decimal:2',
        'riders' => 'array',
        'cost' => 'integer',
    ];

    /** @return BelongsTo<WarSquad, $this> */
    public function squad(): BelongsTo
    {
        return $this->belongsTo(WarSquad::class, 'squad_id');
    }
}
