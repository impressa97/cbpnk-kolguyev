<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Endurance, the fourth thing a man is drilled in: his breath on the move and what he carries. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->unsignedTinyInteger('endurance')->default(40);
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->dropColumn('endurance');
        });
    }
};
