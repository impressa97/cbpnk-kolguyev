<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each Kolguyev soldier is doing (reported by the mod each tick, morale kept here),
 * the reserve at base, HQ support requests, and the motor pool (one vehicle per squad).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->unsignedTinyInteger('health')->default(100);
            $table->unsignedTinyInteger('stamina')->default(100);
            $table->boolean('bleeding')->default(false);
            $table->boolean('down')->default(false);
            $table->string('threat', 12)->default('safe');
            $table->smallInteger('mags')->nullable();
            $table->boolean('in_vehicle')->default(false);
            $table->decimal('morale', 5, 2)->default(60);
            $table->unsignedSmallInteger('battles')->default(0);
            $table->timestamp('condition_at')->nullable();
            // field: on the island. reserve: evacuated to base, recovering until ready_at.
            $table->string('location', 8)->default('field');
            $table->timestamp('ready_at')->nullable();
        });

        Schema::create('cbpnk_war_support', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('commander_id');
            $table->unsignedBigInteger('squad_id')->nullable();
            $table->string('kind', 16);
            $table->string('status', 12)->default('requested');
            $table->decimal('pos_x', 8, 2)->nullable();
            $table->decimal('pos_z', 8, 2)->nullable();
            $table->unsignedInteger('cost');
            $table->timestamp('ready_at');
            $table->timestamp('done_at')->nullable();
            $table->string('note', 120)->default('');
            $table->timestamps();
            $table->index(['session_id', 'status']);
            $table->index(['commander_id', 'kind']);
            $table->foreign('squad_id')->references('id')->on('cbpnk_war_squads')->cascadeOnDelete();
            $table->foreign('commander_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('cbpnk_war_vehicles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('squad_id');
            $table->unsignedBigInteger('commander_id');
            $table->string('kind', 16);
            $table->string('side', 8);
            $table->string('status', 12)->default('ordered');
            $table->decimal('pos_x', 8, 2)->nullable();
            $table->decimal('pos_z', 8, 2)->nullable();
            $table->unsignedTinyInteger('health')->default(100);
            $table->unsignedTinyInteger('fuel')->default(100);
            $table->unsignedTinyInteger('seats')->nullable();
            $table->unsignedTinyInteger('crew')->default(0);
            $table->unsignedInteger('cost');
            $table->timestamp('lost_at')->nullable();
            $table->timestamps();
            $table->index(['session_id', 'status']);
            $table->index('squad_id');
            $table->foreign('squad_id')->references('id')->on('cbpnk_war_squads')->cascadeOnDelete();
            $table->foreign('commander_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbpnk_war_support');
        Schema::dropIfExists('cbpnk_war_vehicles');
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->dropColumn([
                'health', 'stamina', 'bleeding', 'down', 'threat', 'mags', 'in_vehicle',
                'morale', 'battles', 'condition_at', 'location', 'ready_at',
            ]);
        });
    }
};
