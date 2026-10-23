<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each soldier's own ground as the mod reports it, and orders for one soldier: the squad leader
 * sends a man somewhere on his own (the mod splits him into a fireteam of one) or calls him back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->decimal('pos_x', 8, 2)->nullable();
            $table->decimal('pos_z', 8, 2)->nullable();
        });

        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('soldier_id')->nullable()->after('squad_id');
            $table->index(['soldier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->dropIndex(['soldier_id', 'status']);
            $table->dropColumn('soldier_id');
        });

        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->dropColumn(['pos_x', 'pos_z']);
        });
    }
};
