<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The war's log: one row per thing that happened (an order given, done or failed, a squad under
 * fire and out of it, a sighting, a death and who did it, a lift's stage, an AI plan, a place
 * taken), each with the ids it touches, so a fight can be put back together afterwards. Rows are
 * only added; `key` keeps a repeated report from the mod to one row.
 *
 * A soldier's death keeps who killed him, how, with what and from how far, and a squad keeps
 * when its current fight began and when it was last shot at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbpnk_war_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->timestamp('at');
            $table->string('kind', 32);
            $table->string('side', 8)->nullable();
            $table->unsignedBigInteger('commander_id')->nullable();
            $table->unsignedBigInteger('squad_id')->nullable();
            $table->unsignedBigInteger('soldier_id')->nullable();
            $table->unsignedBigInteger('other_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('ref', 32)->nullable();
            $table->decimal('pos_x', 8, 2)->nullable();
            $table->decimal('pos_z', 8, 2)->nullable();
            $table->string('text', 255);
            $table->json('data')->nullable();
            $table->string('key', 64)->nullable()->unique();

            $table->index(['session_id', 'at']);
            $table->index(['squad_id', 'at']);
            $table->index(['soldier_id', 'at']);
            $table->index(['other_id']);
            $table->index(['kind', 'at']);
        });

        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->unsignedBigInteger('killed_by_id')->nullable();
            $table->string('death_cause', 16)->nullable();
            $table->string('death_weapon', 64)->nullable();
            $table->integer('death_metres')->nullable();
        });

        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->timestamp('engaged_at')->nullable();
            $table->timestamp('fired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbpnk_war_log');
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->dropColumn(['killed_by_id', 'death_cause', 'death_weapon', 'death_metres']);
        });
        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->dropColumn(['engaged_at', 'fired_at']);
        });
    }
};
