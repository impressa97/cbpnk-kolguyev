<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A commander may hold up to four squads in one week. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->dropUnique(['session_id', 'commander_id']);
            $table->index(['session_id', 'commander_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->dropIndex(['session_id', 'commander_id']);
            $table->unique(['session_id', 'commander_id']);
        });
    }
};
