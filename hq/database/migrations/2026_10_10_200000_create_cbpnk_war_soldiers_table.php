<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Named soldiers in a Kolguyev squad. Stats are the website order; the mod reads them from the bridge. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('squad_id');
            $table->unsignedBigInteger('commander_id');
            $table->string('role', 24);
            $table->unsignedSmallInteger('aim');
            $table->unsignedSmallInteger('grit');
            $table->unsignedSmallInteger('fieldcraft');
            $table->unsignedInteger('spent')->default(0);
            $table->timestamps();

            $table->index(['squad_id', 'id']);
            $table->foreign('squad_id')->references('id')->on('cbpnk_war_squads')->cascadeOnDelete();
            $table->foreign('commander_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbpnk_war_soldiers');
    }
};
