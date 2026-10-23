<?php

namespace App\Models;

use App\Enums\WarSide;
use App\Observers\WarLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An enemy one Kolguyev squad knows about: seen (identified) or heard (detected) in game.
 *
 * @property int|null $heading where the enemy we see looks
 * @property int|null $target_id which enemy soldier it is, when the game names him
 * @property int $id
 * @property int $session_id
 * @property int $squad_id
 * @property WarSide $side
 * @property float|numeric-string $pos_x
 * @property float|numeric-string $pos_z
 * @property string $kind inf|veh
 * @property string $how seen|heard
 * @property Carbon $seen_at
 */
#[ObservedBy(WarLogObserver::class)]
class WarContact extends Model
{
    protected $table = 'cbpnk_war_contacts';

    protected $fillable = [
        'session_id',
        'squad_id',
        'side',
        'pos_x',
        'pos_z',
        'kind',
        'how',
        'seen_at',
        'trail',
        'heading',
        'target_id',
    ];

    protected $casts = [
        'trail' => 'array',
        'side' => WarSide::class,
        'pos_x' => 'decimal:2',
        'pos_z' => 'decimal:2',
        'seen_at' => 'datetime',
    ];

    /** @return BelongsTo<WarSquad, $this> */
    public function squad(): BelongsTo
    {
        return $this->belongsTo(WarSquad::class, 'squad_id');
    }
}
