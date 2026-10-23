<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HQ tasks (hold a point, patrol a route) carried out by squads HQ raises and runs itself,
 * and special operations (recon, sabotage, raid) tracked on the order that launched them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbpnk_war_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('commander_id');
            $table->string('kind', 12);            // hold|patrol
            $table->string('status', 12)->default('active');
            $table->unsignedTinyInteger('size');
            $table->string('label', 64)->default('');
            $table->decimal('a_x', 8, 2);
            $table->decimal('a_z', 8, 2);
            $table->decimal('b_x', 8, 2)->nullable();
            $table->decimal('b_z', 8, 2)->nullable();
            $table->string('note', 160)->default('');
            $table->timestamps();
            $table->index(['session_id', 'status']);
            $table->foreign('commander_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->unsignedBigInteger('task_id')->nullable();
            $table->boolean('managed')->default(false);
            $table->foreign('task_id')->references('id')->on('cbpnk_war_tasks')->nullOnDelete();
        });

        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->string('target', 32)->nullable();      // place id for an operation
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('effect_until')->nullable();
            $table->string('note', 160)->default('');
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_orders', function (Blueprint $table) {
            $table->dropColumn(['target', 'arrived_at', 'effect_until', 'note']);
        });
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->dropColumn(['task_id', 'managed']);
        });
        Schema::dropIfExists('cbpnk_war_tasks');
    }
};
