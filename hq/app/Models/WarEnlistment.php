<?php

namespace App\Models;

use App\Enums\WarSide;
use App\Models\Concerns\BelongsToCommander;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Sticky side for the Kolguyev war.
 *
 * @property int $id
 * @property int $session_id
 * @property int $commander_id
 * @property WarSide $side
 * @property int $supplies
 * @property Carbon|null $supplied_at
 */
class WarEnlistment extends Model
{
    use BelongsToCommander;

    protected $table = 'cbpnk_war_enlistments';

    protected $fillable = [
        'commander_id',
        'side',
        'supplies',
        'supplied_at',
        'session_id',
    ];

    protected $casts = [
        'side' => WarSide::class,
        'supplies' => 'integer',
        'supplied_at' => 'datetime',
    ];
}
