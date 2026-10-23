<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HQ no longer flies squads out or drops ammunition: open requests of those kinds are called
 * off and paid back. Wounded men and magazines are logistics on the ground now.
 */
return new class extends Migration
{
    public function up(): void
    {
        $open = DB::table('cbpnk_war_support')
            ->whereIn('kind', ['medevac', 'extract', 'resupply'])
            ->whereIn('status', ['requested', 'waiting'])
            ->get();

        foreach ($open as $row) {
            DB::table('cbpnk_war_support')->where('id', $row->id)->update([
                'status' => 'cancelled',
                'note' => 'HQ no longer flies this. Paid back.',
                'done_at' => now(),
                'updated_at' => now(),
            ]);
            if ($row->cost <= 0) {
                continue;
            }
            $enlistment = DB::table('cbpnk_war_enlistments')
                ->where('session_id', $row->session_id)
                ->where('commander_id', $row->commander_id)
                ->first();
            if ($enlistment === null) {
                continue;
            }
            $balance = $enlistment->supplies + $row->cost;
            DB::table('cbpnk_war_enlistments')->where('id', $enlistment->id)->update(['supplies' => $balance]);
            DB::table('cbpnk_war_supply_entries')->insert([
                'session_id' => $row->session_id,
                'commander_id' => $row->commander_id,
                'amount' => $row->cost,
                'reason' => 'refund',
                'balance' => $balance,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void {}
};
