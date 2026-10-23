<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A service (refuel and repair) is done to one vehicle. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_support', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicle_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_support', function (Blueprint $table) {
            $table->dropColumn('vehicle_id');
        });
    }
};
