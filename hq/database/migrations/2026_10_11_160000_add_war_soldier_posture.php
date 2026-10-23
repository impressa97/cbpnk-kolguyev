<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One man's orders the game has (SCR_AIOrder_Stance, SCR_AIOrder_MovementType) and what he is
 * actually doing: his stance and the AI action he is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->string('stance_order', 8)->default('auto');   // auto|stand|crouch|prone
            $table->string('pace_order', 8)->default('auto');     // auto|walk|run|sprint
            $table->string('stance', 8)->nullable();               // from the game
            $table->string('action', 40)->nullable();              // from the game
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->dropColumn(['stance_order', 'pace_order', 'stance', 'action']);
        });
    }
};
