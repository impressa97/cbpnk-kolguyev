<?php

namespace App\Models;

use App\Enums\WarSide;
use App\Observers\WarLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What the AI command of a side no commander plays is doing with one of its squads.
 *
 * Kind: garrison (defend a place it holds) | patrol (walk between two of its places, and hunt
 * what the side sights) | counter (retake a place a commander took near its ground) | raid (take
 * a point it does not hold) | qrf (run to a post under fire).
 * Status: active | retreat (too few left: going home) | lost (wiped out) | done | ended (a
 * commander took the side over).
 *
 * @property int $id
 * @property int $session_id
 * @property WarSide $side
 * @property string $kind
 * @property int|null $squad_id
 * @property string|null $place config war.places id
 * @property float|numeric-string $pos_x
 * @property float|numeric-string $pos_z
 * @property string $status
 * @property Carbon|null $acted_at when it last reacted
 * @property int|null $vehicle_id the truck that drives the squad out, for an attack far from home
 */
#[ObservedBy(WarLogObserver::class)]
class WarAiPlan extends Model
{
    protected $table = 'cbpnk_war_ai_plans';

    protected $fillable = ['session_id', 'side', 'kind', 'squad_id', 'place', 'pos_x', 'pos_z', 'status', 'acted_at', 'vehicle_id'];

    protected $casts = [
        'side' => WarSide::class,
        'pos_x' => 'decimal:2',
        'pos_z' => 'decimal:2',
        'acted_at' => 'datetime',
    ];

    /** @return BelongsTo<WarSquad, $this> */
    public function squad(): BelongsTo
    {
        return $this->belongsTo(WarSquad::class, 'squad_id');
    }
}
