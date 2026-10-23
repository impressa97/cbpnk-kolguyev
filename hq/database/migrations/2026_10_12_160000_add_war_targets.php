<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is who in a fight: the enemy soldier a contact is (one marker per man, not one per
 * 60 m), and the enemy a soldier of ours is fighting (the game's current target).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cbpnk_war_soldiers', 'cbpnk_war_contacts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('target_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['cbpnk_war_soldiers', 'cbpnk_war_contacts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('target_id');
            });
        }
    }
};
