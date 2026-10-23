<?php

namespace Database\Seeders;

use App\Support\LocalCommander;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        LocalCommander::resolve();
    }
}
