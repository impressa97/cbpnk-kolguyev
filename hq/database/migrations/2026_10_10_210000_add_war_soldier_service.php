<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A soldier keeps a name, a note, and service that dies with them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->string('name', 32)->default('');
            $table->string('note', 80)->default('');
            $table->timestamp('joined_at')->nullable();
            $table->unsignedSmallInteger('service')->default(0);
            $table->timestamp('service_at')->nullable();
            $table->timestamp('fallen_at')->nullable();
            $table->string('loss', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_soldiers', function (Blueprint $table) {
            $table->dropColumn([
                'name', 'note', 'joined_at', 'service', 'service_at', 'fallen_at', 'loss',
            ]);
        });
    }
};
