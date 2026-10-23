<?php

namespace App\Enums;

/**
 * Where an order stands. queued: waits in the squad's chain until the order before it is done
 * (the game completes it); then it goes to the game as pending.
 */
enum WarOrderStatus: string
{
    case Queued = 'queued';
    case Pending = 'pending';
    case Acked = 'acked';
    case Done = 'done';
    case Failed = 'failed';
    case Superseded = 'superseded';

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
