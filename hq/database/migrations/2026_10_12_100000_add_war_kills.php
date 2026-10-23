<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kills by the war's soldiers as the game reports them: one row per kill, keyed by the server
 * run and the mod's sequence so a line posted twice counts once; each soldier's tally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbpnk_war_kills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('soldier_id');
            $table->string('victim_side', 8);
            $table->unsignedBigInteger('run');
            $table->unsignedInteger('seq');
            $table->timestamps();

            $table->unique(['run', 'seq']);
            $table->index(['session_id', 'soldier_id']);
        });
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->unsignedInteger('kills')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbpnk_war_kills');
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->dropColumn('kills');
        });
    }
};
