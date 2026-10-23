<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A squad's readiness for contact (normal, alert, ready) in place of auto care, which HQ no
 * longer offers: a commander calls the ammo truck and the ambulance himself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->string('alert', 8)->default('normal');
            $table->dropColumn('care');
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->boolean('care')->default(false);
            $table->dropColumn('alert');
        });
    }
};
