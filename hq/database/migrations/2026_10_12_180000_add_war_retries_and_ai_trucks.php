<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A move the game stopped on the way goes again once (attempt); the AI command drives its
 * attacks out in trucks of its own, which belong to no player.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempt')->default(0);
        });
        Schema::table('cbpnk_war_vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('commander_id')->nullable()->change();
        });
        Schema::table('cbpnk_war_ai_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicle_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_ai_plans', function (Blueprint $table) {
            $table->dropColumn('vehicle_id');
        });
        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->dropColumn('attempt');
        });
    }
};
