<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** commander_id → users.id. player() stays so existing eager loads keep working. */
trait BelongsToCommander
{
    /** @return BelongsTo<User, $this> */
    public function commander(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commander_id');
    }

    /** @return BelongsTo<User, $this> */
    public function player(): BelongsTo
    {
        return $this->commander();
    }
}
