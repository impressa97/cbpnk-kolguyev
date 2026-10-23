<?php

namespace App\Actions\Reforger;

/**
 * The desk is read by GET /desk. A bridge tick does not push a socket.
 */
final class PushWarDeskAction
{
    public function push(): int
    {
        return 0;
    }
}
