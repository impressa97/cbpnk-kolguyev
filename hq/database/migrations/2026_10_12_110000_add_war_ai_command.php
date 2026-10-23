<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An AI command runs the sides no commander plays: its squads, men and orders belong to no
 * player (ai), and its plans (garrisons, patrols, counterattacks) are kept here.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cbpnk_war_squads', 'cbpnk_war_soldiers', 'cbpnk_war_orders'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('commander_id')->nullable()->change();
            });
        }
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->boolean('ai')->default(false);
        });
        Schema::create('cbpnk_war_ai_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->string('side', 8);
            $table->string('kind', 12);
            $table->unsignedBigInteger('squad_id')->nullable();
            $table->string('place', 32)->nullable();
            $table->decimal('pos_x', 8, 2);
            $table->decimal('pos_z', 8, 2);
            $table->string('status', 8)->default('active');
            $table->timestamps();

            $table->index(['session_id', 'side', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbpnk_war_ai_plans');
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->dropColumn('ai');
        });
    }
};
