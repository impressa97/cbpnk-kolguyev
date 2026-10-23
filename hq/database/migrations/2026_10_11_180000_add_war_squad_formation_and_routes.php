<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More of the game's commanding: a squad's formation and pace (group AI settings), a move's
 * further points (a route of waypoints), and the squad a follow order follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->string('formation', 12)->default('auto');   // auto|wedge|line|column|staggered
            $table->string('pace_order', 8)->default('auto');   // auto|walk|run|sprint
        });
        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->json('route')->nullable();                   // [[x, z], ...] after the first point
            $table->unsignedBigInteger('follow_squad_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->dropColumn(['route', 'follow_squad_id']);
        });
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->dropColumn(['formation', 'pace_order']);
        });
    }
};
