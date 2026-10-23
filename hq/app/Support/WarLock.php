<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/** Serializes war mutations on SQLite and Postgres. Re-entrant for the nested call. */
final class WarLock
{
    private static int $depth = 0;

    public static function run(callable $callback): mixed
    {
        if (self::$depth > 0) {
            return $callback();
        }

        $lock = Cache::lock('kolguyev-war', 15);
        $lock->block(10);
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
            $lock->release();
        }
    }
}
