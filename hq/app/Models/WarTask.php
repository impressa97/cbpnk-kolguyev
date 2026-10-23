<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCommander;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A standing request to HQ: hold a point or patrol a route. HQ raises a squad from the
 * commander's HQ budget, sends it, treats and rotates it, and refills losses.
 *
 * @property int $id
 * @property int $session_id
 * @property int $commander_id
 * @property string $kind hold|patrol
 * @property string $status active|cancelled
 * @property int $size
 * @property string $label
 * @property float|numeric-string $a_x
 * @property float|numeric-string $a_z
 * @property float|numeric-string|null $b_x
 * @property float|numeric-string|null $b_z
 * @property string $note
 */
class WarTask extends Model
{
    use BelongsToCommander;

    protected $table = 'cbpnk_war_tasks';

    protected $fillable = [
        'session_id',
        'commander_id',
        'kind',
        'status',
        'size',
        'label',
        'a_x',
        'a_z',
        'b_x',
        'b_z',
        'note',
        'ends_at',
    ];

    protected $casts = [
        'ends_at' => 'datetime',
        'size' => 'integer',
        'a_x' => 'decimal:2',
        'a_z' => 'decimal:2',
        'b_x' => 'decimal:2',
        'b_z' => 'decimal:2',
    ];

    /** @return HasOne<WarSquad, $this> */
    public function squad(): HasOne
    {
        return $this->hasOne(WarSquad::class, 'task_id');
    }
}
