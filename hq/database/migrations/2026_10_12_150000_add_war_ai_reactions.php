<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When an AI squad last reacted (hunted a sighting, ran to a post under fire), for its cooldown. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_ai_plans', function (Blueprint $table) {
            $table->timestamp('acted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_ai_plans', function (Blueprint $table) {
            $table->dropColumn('acted_at');
        });
    }
};
