<?php

namespace App\Models;

use App\Enums\WarSupplyReason;
use App\Models\Concerns\BelongsToCommander;
use Illuminate\Database\Eloquent\Model;

/**
 * One line in a commander's Kolguyev supply ledger.
 *
 * @property int $id
 * @property int $commander_id
 * @property int $amount
 * @property WarSupplyReason $reason
 * @property int $balance
 */
class WarSupplyEntry extends Model
{
    use BelongsToCommander;

    protected $table = 'cbpnk_war_supply_entries';

    protected $fillable = [
        'commander_id',
        'session_id',
        'amount',
        'reason',
        'balance',
    ];

    protected $casts = [
        'amount' => 'integer',
        'reason' => WarSupplyReason::class,
        'balance' => 'integer',
    ];
}
