<?php

namespace App\Models;

use App\Observers\WarLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Who holds one control point this week, who is taking it, and whether it is interdicted.
 * A held point pays every commander of its side config war.territory.income[kind] per hour.
 *
 * @property int $id
 * @property int $session_id
 * @property string $place config war.places id
 * @property string|null $side
 * @property Carbon|null $held_since
 * @property string|null $claim_side
 * @property Carbon|null $claim_since
 * @property Carbon|null $interdicted_until
 */
#[ObservedBy(WarLogObserver::class)]
class WarControl extends Model
{
    protected $table = 'cbpnk_war_control';

    protected $fillable = [
        'session_id',
        'place',
        'side',
        'held_since',
        'claim_side',
        'claim_since',
        'interdicted_until',
    ];

    protected $casts = [
        'held_since' => 'datetime',
        'claim_since' => 'datetime',
        'interdicted_until' => 'datetime',
    ];

    public function pays(): bool
    {
        return $this->side !== null && ($this->interdicted_until === null || $this->interdicted_until->isPast());
    }
}
