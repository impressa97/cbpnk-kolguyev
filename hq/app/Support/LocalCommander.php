<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/** The one commander this headquarters runs as. There is no account to register. */
final class LocalCommander
{
    public static function resolve(): User
    {
        return User::query()->first() ?? User::query()->create([
            'name' => 'Commander',
            'email' => 'commander@localhost',
            'password' => Str::random(40),
        ]);
    }
}
