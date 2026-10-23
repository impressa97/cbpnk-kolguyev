<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Territory and the objectives HQ hands out around it. A control point pays its holder supplies
 * every hour; objectives are bounties for taking, supplying, and denying that income.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbpnk_war_objectives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->string('side', 8);
            $table->string('kind', 12);
            $table->string('target', 32);
            $table->string('title', 96);
            $table->decimal('pos_x', 8, 2);
            $table->decimal('pos_z', 8, 2);
            $table->unsignedSmallInteger('reward');
            $table->string('status', 12)->default('open');   // open|done|expired
            $table->string('stage', 12)->nullable();        // supply run: loaded
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->string('drop', 32)->nullable();         // supply run: where the cargo goes
            $table->unsignedBigInteger('squad_id')->nullable(); // who is making progress
            $table->timestamp('progress_at')->nullable();
            $table->unsignedBigInteger('commander_id')->nullable(); // who completed it
            $table->timestamp('expires_at');
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->index(['session_id', 'side', 'status']);
        });

        Schema::create('cbpnk_war_control', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->string('place', 32);
            $table->string('side', 8)->nullable();          // holder
            $table->timestamp('held_since')->nullable();
            $table->string('claim_side', 8)->nullable();    // side taking it now
            $table->timestamp('claim_since')->nullable();
            $table->timestamp('interdicted_until')->nullable(); // pays nobody until then
            $table->timestamps();
            $table->unique(['session_id', 'place']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbpnk_war_control');
        Schema::dropIfExists('cbpnk_war_objectives');
    }
};
