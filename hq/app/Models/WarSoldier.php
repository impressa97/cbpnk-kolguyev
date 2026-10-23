<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCommander;
use App\Observers\WarLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One ordered soldier in a Kolguyev squad.
 *
 * @property int $id
 * @property int $session_id
 * @property int $squad_id
 * @property int|null $commander_id null: the AI command's
 * @property string $role
 * @property string $name
 * @property string $note
 * @property int $aim
 * @property int $grit
 * @property int $fieldcraft
 * @property int $endurance drilled: breath on the move, magazines carried
 * @property Carbon|null $under_fire_at last shot at, until the calm after it counts a battle
 * @property int $spent
 * @property int $service
 * @property Carbon|null $joined_at
 * @property Carbon|null $service_at
 * @property Carbon|null $fallen_at
 * @property string|null $loss
 * @property int $health 0-100, from the game
 * @property int $stamina 0-100, from the game
 * @property bool $bleeding
 * @property bool $down unconscious
 * @property string $threat safe|vigilant|alerted|threatened
 * @property int|null $mags spare magazines for the weapon in hand
 * @property bool $in_vehicle
 * @property float|numeric-string|null $pos_x where the game last saw him
 * @property float|numeric-string|null $pos_z
 * @property string|null $seat driver|gunner|passenger when aboard, from the game
 * @property string $stance_order auto|stand|crouch|prone, his squad leader's order
 * @property string $pace_order auto|walk|run|sprint, his squad leader's order
 * @property string|null $stance stand|crouch|prone, from the game
 * @property string|null $action the AI action he is on, from the game
 * @property numeric-string $morale 0-100, kept by the site
 * @property int $battles fights survived (under fire or alerted, then calm again)
 * @property int|null $heading where he looks, a compass bearing from the game
 * @property int|null $target_id the enemy soldier he is fighting (the game's current target)
 * @property int $kills enemies he killed, as the game reported them
 * @property int|null $killed_by_id the war soldier who killed him
 * @property string|null $death_cause enemy|friendly|self|fire|vehicle|wounds|other, from the game
 * @property string|null $death_weapon the prefab that killed him
 * @property int|null $death_metres how far away his killer was
 * @property Carbon|null $condition_at
 * @property string $location field|reserve
 * @property Carbon|null $ready_at reserve: back on duty from
 */
#[ObservedBy(WarLogObserver::class)]
class WarSoldier extends Model
{
    use BelongsToCommander;

    protected $table = 'cbpnk_war_soldiers';

    protected $fillable = [
        'squad_id',
        'commander_id',
        'session_id',
        'role',
        'name',
        'note',
        'aim',
        'grit',
        'fieldcraft',
        'endurance',
        'under_fire_at',
        'spent',
        'service',
        'joined_at',
        'service_at',
        'fallen_at',
        'loss',
        'health',
        'stamina',
        'bleeding',
        'down',
        'threat',
        'mags',
        'pos_x',
        'pos_z',
        'seat',
        'stance_order',
        'pace_order',
        'stance',
        'action',
        'in_vehicle',
        'morale',
        'battles',
        'kills',
        'heading',
        'target_id',
        'condition_at',
        'location',
        'ready_at',
    ];

    protected $casts = [
        'aim' => 'integer',
        'grit' => 'integer',
        'fieldcraft' => 'integer',
        'endurance' => 'integer',
        'under_fire_at' => 'datetime',
        'spent' => 'integer',
        'service' => 'integer',
        'joined_at' => 'datetime',
        'service_at' => 'datetime',
        'fallen_at' => 'datetime',
        'health' => 'integer',
        'stamina' => 'integer',
        'bleeding' => 'boolean',
        'down' => 'boolean',
        'mags' => 'integer',
        'pos_x' => 'decimal:2',
        'pos_z' => 'decimal:2',
        'in_vehicle' => 'boolean',
        'morale' => 'decimal:2',
        'battles' => 'integer',
        'kills' => 'integer',
        'killed_by_id' => 'integer',
        'death_metres' => 'integer',
        'condition_at' => 'datetime',
        'ready_at' => 'datetime',
    ];

    /** @return BelongsTo<WarSquad, $this> */
    public function squad(): BelongsTo
    {
        return $this->belongsTo(WarSquad::class, 'squad_id');
    }
}
