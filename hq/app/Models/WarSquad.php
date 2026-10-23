<?php

namespace App\Models;

use App\Enums\WarSide;
use App\Models\Concerns\BelongsToCommander;
use App\Observers\WarLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One AI squad on Kolguyev, owned by the player who enlisted.
 *
 * @property int $id
 * @property int $session_id
 * @property int|null $commander_id null: the AI command's
 * @property bool $ai run by the AI command of a side no commander plays
 * @property WarSide $side
 * @property int $strength
 * @property int $alive
 * @property int $tier
 * @property float|numeric-string $pos_x
 * @property float|numeric-string $pos_z
 * @property int|null $task_id HQ task this squad carries out
 * @property bool $managed raised and run by HQ, not ordered by hand
 * @property string $combat combat mode the commander set: hold|return|free
 * @property string|null $combat_actual the mode the AI is actually in, from the game
 * @property string|null $doing the order kind whose waypoint the group is on, idle, or own
 * @property Carbon|null $engaged_at when its current fight began (a man first shot at), for the log
 * @property Carbon|null $fired_at when one of its men was last shot at, in the current fight
 */
#[ObservedBy(WarLogObserver::class)]
class WarSquad extends Model
{
    use BelongsToCommander;

    protected $table = 'cbpnk_war_squads';

    protected $fillable = [
        'commander_id',
        'side',
        'strength',
        'alive',
        'tier',
        'pos_x',
        'pos_z',
        'session_id',
        'task_id',
        'managed',
        'ai',
        'alert',
        'combat',
        'combat_actual',
        'formation',
        'pace_order',
        'name',
        'doing',
    ];

    protected $casts = [
        'side' => WarSide::class,
        'strength' => 'integer',
        'alive' => 'integer',
        'tier' => 'integer',
        'pos_x' => 'decimal:2',
        'pos_z' => 'decimal:2',
        'managed' => 'boolean',
        'ai' => 'boolean',
        'engaged_at' => 'datetime',
        'fired_at' => 'datetime',
    ];

    /** @return HasMany<WarOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(WarOrder::class, 'squad_id');
    }

    /** @return HasMany<WarVehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(WarVehicle::class, 'squad_id');
    }

    /** @return HasMany<WarSoldier, $this> */
    public function soldiers(): HasMany
    {
        return $this->hasMany(WarSoldier::class, 'squad_id');
    }
}
