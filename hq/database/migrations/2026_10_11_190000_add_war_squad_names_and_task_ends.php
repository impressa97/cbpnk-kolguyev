<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A commander's own name for each of his ten squads, and when an HQ task runs out: HQ works
 * for the commander on request and for a while only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->string('name', 24)->nullable();
        });
        Schema::table('cbpnk_war_tasks', function (Blueprint $table) {
            $table->timestamp('ends_at')->nullable();
        });
        // Tasks already running had no end: give them an hour from now.
        DB::table('cbpnk_war_tasks')->where('status', 'active')->whereNull('ends_at')->update(['ends_at' => now()->addHour()]);
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_tasks', function (Blueprint $table) {
            $table->dropColumn('ends_at');
        });
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
