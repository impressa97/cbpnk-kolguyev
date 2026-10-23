<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where a contact was seen before it moved: the last few spots, for its trail on the map. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_contacts', function (Blueprint $table) {
            $table->json('trail')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_contacts', function (Blueprint $table) {
            $table->dropColumn('trail');
        });
    }
};
