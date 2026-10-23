<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A battle is a spell under fire and a calm after it (under_fire_at keeps when he was last shot
 * at). The old count went up on every step down from alerted, which the game makes on any
 * march: veterans had hundreds. They are cut back to 30, what experience counts at most.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->timestamp('under_fire_at')->nullable();
        });
        DB::table('cbpnk_war_soldiers')->where('battles', '>', 30)->update(['battles' => 30]);
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->dropColumn('under_fire_at');
        });
    }
};
