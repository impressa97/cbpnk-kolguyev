<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders speak the game's commanding menu; the game says back what each squad is doing.
 *
 *   squads   combat         the combat mode the commander set (hold|return|free)
 *            combat_actual  the mode the AI is actually in (SCR_AIGroupUtilityComponent)
 *            doing          the order kind whose waypoint the group is on, idle, or own
 *   soldiers seat           driver|gunner|passenger when aboard (the game's compartment type)
 *   orders   pos2_x/z       a patrol's second point (Patrol waypoints in a Cycle)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->string('combat', 8)->default('free');
            $table->string('combat_actual', 8)->nullable();
            $table->string('doing', 12)->nullable();
        });

        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->string('seat', 12)->nullable();
        });

        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->decimal('pos2_x', 8, 2)->nullable();
            $table->decimal('pos2_z', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->dropColumn(['pos2_x', 'pos2_z']);
        });
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->dropColumn('seat');
        });
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->dropColumn(['combat', 'combat_actual', 'doing']);
        });
    }
};
