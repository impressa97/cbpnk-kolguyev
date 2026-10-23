<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A Kolguyev war lasts one real week. Rows from before this migration share the week that starts now. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbpnk_war_sessions', function (Blueprint $table) {
            $table->id();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        $tables = [
            'cbpnk_war_enlistments',
            'cbpnk_war_squads',
            'cbpnk_war_orders',
            'cbpnk_war_soldiers',
            'cbpnk_war_supply_entries',
        ];

        foreach ($tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('session_id')->nullable();
            });
        }

        if ($this->hasRows()) {
            $start = now();
            $sessionId = DB::table('cbpnk_war_sessions')->insertGetId([
                'starts_at' => $start,
                'ends_at' => $start->copy()->addDays(7),
                'created_at' => $start,
                'updated_at' => $start,
            ]);

            foreach ($tables as $name) {
                DB::table($name)->update(['session_id' => $sessionId]);
            }
        }

        Schema::table('cbpnk_war_enlistments', function (Blueprint $table) {
            $table->dropUnique(['commander_id']);
            $table->unique(['session_id', 'commander_id']);
            $table->foreign('session_id')->references('id')->on('cbpnk_war_sessions')->cascadeOnDelete();
        });

        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->dropUnique(['commander_id']);
            $table->unique(['session_id', 'commander_id']);
            $table->foreign('session_id')->references('id')->on('cbpnk_war_sessions')->cascadeOnDelete();
        });

        foreach (['cbpnk_war_orders', 'cbpnk_war_soldiers', 'cbpnk_war_supply_entries'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreign('session_id')->references('id')->on('cbpnk_war_sessions')->cascadeOnDelete();
            });
        }

        foreach ($tables as $name) {
            DB::statement('ALTER TABLE '.$name.' ALTER COLUMN session_id SET NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('cbpnk_war_enlistments', function (Blueprint $table) {
            $table->dropUnique(['session_id', 'commander_id']);
            $table->dropForeign(['session_id']);
            $table->dropColumn('session_id');
            $table->unique('commander_id');
        });

        Schema::table('cbpnk_war_squads', function (Blueprint $table) {
            $table->dropUnique(['session_id', 'commander_id']);
            $table->dropForeign(['session_id']);
            $table->dropColumn('session_id');
            $table->unique('commander_id');
        });

        foreach (['cbpnk_war_orders', 'cbpnk_war_soldiers', 'cbpnk_war_supply_entries'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['session_id']);
                $table->dropColumn('session_id');
            });
        }

        Schema::dropIfExists('cbpnk_war_sessions');
    }

    private function hasRows(): bool
    {
        foreach ([
            'cbpnk_war_enlistments',
            'cbpnk_war_squads',
            'cbpnk_war_orders',
            'cbpnk_war_soldiers',
            'cbpnk_war_supply_entries',
        ] as $name) {
            if (DB::table($name)->exists()) {
                return true;
            }
        }

        return false;
    }
};
