<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One Kolguyev war. The clock is real time and the length is one week.
 *
 * @property int $id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $closed_at
 */
class WarSession extends Model
{
    protected $table = 'cbpnk_war_sessions';

    protected $fillable = [
        'starts_at',
        'ends_at',
        'closed_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->closed_at === null && $this->ends_at->greaterThan(now());
    }
}
