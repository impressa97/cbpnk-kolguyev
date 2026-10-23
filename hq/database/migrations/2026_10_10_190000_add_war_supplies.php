<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolguyev treasury: supplies accrue on the enlistment and pay for squad upgrades.
 * Separate from city credits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_enlistments', function (Blueprint $table) {
            $table->unsignedInteger('supplies')->default(0);
            $table->timestamp('supplied_at')->nullable();
        });

        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->unsignedTinyInteger('tier')->default(0);
        });

        Schema::create('cbpnk_war_supply_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commander_id');
            $table->integer('amount');
            $table->string('reason', 16);
            $table->unsignedInteger('balance');
            $table->timestamps();

            $table->index(['commander_id', 'id']);
            $table->foreign('commander_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbpnk_war_supply_entries');

        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->dropColumn('tier');
        });

        Schema::table('cbpnk_war_enlistments', function (Blueprint $table) {
            $table->dropColumn(['supplies', 'supplied_at']);
        });
    }
};
