<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An attack names the enemy soldier the commander picked (the contact's man), the way the
 * game's attack waypoint takes one entity, instead of whoever the squad perceives near the mark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('enemy_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->dropColumn('enemy_id');
        });
    }
};
