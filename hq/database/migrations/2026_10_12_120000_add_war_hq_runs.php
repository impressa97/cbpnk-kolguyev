<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HQ runs: an HQ driver and vehicle sent to one of a commander's squads (an ammunition truck,
 * an ambulance, or a lift to a point), and the vehicles HQ lends for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_vehicles', function (Blueprint $table) {
            $table->boolean('hq')->default(false);
        });
        Schema::create('cbpnk_war_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('commander_id');
            $table->string('kind', 12);
            $table->unsignedBigInteger('squad_id');
            $table->unsignedBigInteger('crew_squad_id')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->string('stage', 12)->default('out');
            $table->timestamp('stage_at')->nullable();
            $table->string('status', 8)->default('active');
            $table->decimal('pick_x', 8, 2);
            $table->decimal('pick_z', 8, 2);
            $table->decimal('drop_x', 8, 2)->nullable();
            $table->decimal('drop_z', 8, 2)->nullable();
            $table->json('riders')->nullable();
            $table->unsignedInteger('cost')->default(0);
            $table->string('note')->default('');
            $table->timestamp('ends_at');
            $table->timestamps();

            $table->index(['session_id', 'status']);
            $table->foreign('commander_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbpnk_war_runs');
        Schema::table('cbpnk_war_vehicles', function (Blueprint $table) {
            $table->dropColumn('hq');
        });
    }
};
