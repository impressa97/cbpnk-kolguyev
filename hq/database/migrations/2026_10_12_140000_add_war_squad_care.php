<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auto care: HQ sends the ambulance and the ammo truck to the squad on its own. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->boolean('care')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->dropColumn('care');
        });
    }
};
