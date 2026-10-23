<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicles as the game has them: drawn at the side's spawn and belonging to the commander who
 * paid, not to a squad. Any of his squads boards one by name (the "get in" command on that
 * vehicle), so a board order carries the vehicle.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The squad it was first drawn for, kept for old rows only; losing that squad no
        // longer takes the vehicle with it.
        Schema::table('cbpnk_war_vehicles', function (Blueprint $table) {
            $table->dropForeign(['squad_id']);
        });
        Schema::table('cbpnk_war_vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('squad_id')->nullable()->change();
            $table->foreign('squad_id')->references('id')->on('cbpnk_war_squads')->nullOnDelete();
        });
        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicle_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->dropColumn('vehicle_id');
        });
    }
};
