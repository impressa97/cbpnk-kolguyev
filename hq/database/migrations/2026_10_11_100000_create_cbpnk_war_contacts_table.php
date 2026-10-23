<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enemies a Kolguyev squad knows about, as its in-game group perception reports them.
 * The mod sends the full list each tick, so rows for a reporting squad are replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbpnk_war_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('squad_id');
            $table->string('side', 8);
            $table->decimal('pos_x', 8, 2);
            $table->decimal('pos_z', 8, 2);
            $table->string('kind', 8);
            $table->string('how', 8);
            $table->timestamp('seen_at');
            $table->timestamps();
            $table->index(['session_id', 'seen_at']);
            $table->index('squad_id');
            $table->foreign('squad_id')->references('id')->on('cbpnk_war_squads')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbpnk_war_contacts');
    }
};
