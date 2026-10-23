<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How many times an HQ driver who left his vehicle has been put back at the wheel this run. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_runs', function (Blueprint $table) {
            $table->unsignedTinyInteger('tries')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_runs', function (Blueprint $table) {
            $table->dropColumn('tries');
        });
    }
};
