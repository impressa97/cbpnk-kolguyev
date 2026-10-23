<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where a soldier, and an enemy we see, is looking: a compass bearing from the game. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cbpnk_war_soldiers', 'cbpnk_war_contacts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->smallInteger('heading')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['cbpnk_war_soldiers', 'cbpnk_war_contacts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('heading');
            });
        }
    }
};
