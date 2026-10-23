<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolguyev continuous war: one side and one AI squad per player.
 * The Reforger mod is the simulation; these rows are the website HQ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbpnk_war_enlistments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commander_id');
            $table->string('side', 8);
            $table->timestamps();

            $table->unique('commander_id');
            $table->foreign('commander_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('cbpnk_war_squads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commander_id');
            $table->string('side', 8);
            $table->unsignedTinyInteger('strength');
            $table->unsignedTinyInteger('alive');
            $table->decimal('pos_x', 8, 2);
            $table->decimal('pos_z', 8, 2);
            $table->timestamps();

            $table->unique('commander_id');
            $table->foreign('commander_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('cbpnk_war_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('squad_id');
            $table->unsignedBigInteger('commander_id');
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->decimal('pos_x', 8, 2);
            $table->decimal('pos_z', 8, 2);
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->foreign('squad_id')->references('id')->on('cbpnk_war_squads')->cascadeOnDelete();
            $table->foreign('commander_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbpnk_war_orders');
        Schema::dropIfExists('cbpnk_war_squads');
        Schema::dropIfExists('cbpnk_war_enlistments');
    }
};
