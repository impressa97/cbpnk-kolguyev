<?php

/**
 * Local demo of the Kolguyev war: two commanders, a few squads, a vehicle, HQ requests, a task,
 * and one mod tick of state, all through the real actions and the real bridge codec.
 * Local only. Wipes this week's war rows first.
 *
 *   cd hq && php artisan tinker ../scripts/war-demo.php
 */

use App\Actions\Reforger\EnlistWarSideAction;
use App\Actions\Reforger\IngestWarStateAction;
use App\Actions\Reforger\IssueWarMoveOrderAction;
use App\Actions\Reforger\ManageWarTaskAction;
use App\Actions\Reforger\OrderWarVehicleAction;
use App\Actions\Reforger\RequestWarSupportAction;
use App\Actions\Reforger\UpgradeWarSquadAction;
use App\Enums\WarOrderKind;
use App\Enums\WarSide;
use App\Models\User;
use App\Models\WarControl;
use App\Models\WarEnlistment;
use App\Models\WarSession;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Support\WarBridgeCodec;
use Illuminate\Support\Facades\DB;

if (! app()->environment('local')) {
    throw new RuntimeException('war-demo is local only.');
}

DB::table('cbpnk_war_sessions')->delete();
foreach (['cbpnk_war_objectives', 'cbpnk_war_control', 'cbpnk_war_tasks'] as $table) {
    DB::table($table)->delete();
}

$me = User::query()->where('email', 'test@example.com')->firstOrFail();
$rival = User::query()->where('email', '!=', 'test@example.com')->orderBy('id')->firstOrFail();

app(EnlistWarSideAction::class)->enlist($me, WarSide::Us);
app(EnlistWarSideAction::class)->enlist($rival, WarSide::Ussr);
WarEnlistment::query()->update(['supplies' => 900]);

$upgrade = app(UpgradeWarSquadAction::class);
$upgrade->apply($me, ['kind' => 'squad']);
$alpha = WarSquad::query()->where('commander_id', $me->id)->orderBy('id')->first();
$bravo = WarSquad::query()->where('commander_id', $me->id)->orderBy('id')->skip(1)->first();
$upgrade->apply($me, ['kind' => 'order', 'squad_id' => $alpha->id, 'role' => 'medic', 'name' => 'Doc Rivera', 'note' => 'Carries extra morphine']);
$upgrade->apply($me, ['kind' => 'order', 'squad_id' => $alpha->id, 'role' => 'machinegun', 'name' => 'Kowalski', 'note' => '']);
$upgrade->apply($me, ['kind' => 'order', 'squad_id' => $bravo->id, 'role' => 'sapper', 'name' => 'Okafor', 'note' => 'Quiet hands']);
$upgrade->apply($me, ['kind' => 'train', 'squad_id' => $alpha->id]);

app(OrderWarVehicleAction::class)->order($me, $alpha->id, 'truck');
$ussr = WarSquad::query()->where('commander_id', $rival->id)->first();

// One mod tick: Alpha at Mineraly in a firefight, Bravo walking to the relay, the truck parked.
$session = WarSession::query()->whereNull('closed_at')->first();
$alphaMen = WarSoldier::query()->where('squad_id', $alpha->id)->orderBy('id')->get();
$bravoMen = WarSoldier::query()->where('squad_id', $bravo->id)->orderBy('id')->get();
$truck = App\Models\WarVehicle::query()->where('squad_id', $alpha->id)->first();
$state = "SQUAD\t{$alpha->id}\t6290.00\t7980.00\t6\nSQUAD\t{$bravo->id}\t5600.00\t6900.00\t5\nSQUAD\t{$ussr->id}\t11860.00\t10670.00\t4\n";
$health = [100, 42, 85, 100, 15, 100];
$threat = ['threatened', 'threatened', 'alerted', 'threatened', 'alerted', 'vigilant'];
foreach ($alphaMen as $i => $soldier) {
    $state .= implode("\t", ['SOLDIER', $soldier->id, 'ok', $health[$i] ?? 100, $i === 1 ? 1 : 0, $i === 4 ? 1 : 0, 60, $threat[$i] ?? 'safe', $i === 3 ? 1 : 4, 0])."\n";
}
foreach ($bravoMen as $soldier) {
    $state .= implode("\t", ['SOLDIER', $soldier->id, 'ok', 100, 0, 0, 70, 'safe', 5, 0])."\n";
}
$state .= "CONTACT\t{$alpha->id}\tussr\t6420.00\t8110.00\tinf\tseen\t20\nCONTACT\t{$alpha->id}\tussr\t6460.00\t8180.00\tveh\theard\t90\n";
$state .= "VEHICLE\t{$truck->id}\t6250.00\t7930.00\t88\t64\t14\t0\t1\n";
$state .= "WORLD\t19.75\tCloudy\t15\t30\t6.2\t240\t6.05\t19.95\t70\nEND\n";
app(IngestWarStateAction::class)->ingest(WarBridgeCodec::decodeState($state));

app(IssueWarMoveOrderAction::class)->move($me, 6860, 6780, $bravo->id, WarOrderKind::Sabotage);
app(RequestWarSupportAction::class)->request($me, ['kind' => 'medevac', 'squad_id' => $alpha->id]);
app(RequestWarSupportAction::class)->request($me, ['kind' => 'smoke', 'x' => 6400, 'z' => 8100]);
app(ManageWarTaskAction::class)->create($me, ['kind' => 'hold', 'size' => 4, 'label' => 'Airport gate', 'a_x' => 2646, 'a_z' => 5367]);
WarControl::query()->updateOrCreate(['session_id' => $session->id, 'place' => 'airport'], ['side' => 'us', 'held_since' => now()]);
WarControl::query()->updateOrCreate(['session_id' => $session->id, 'place' => 'east-coast'], ['side' => 'ussr', 'held_since' => now()]);
WarControl::query()->updateOrCreate(['session_id' => $session->id, 'place' => 'mineraly'], ['claim_side' => 'us', 'claim_since' => now()->subMinutes(2)]);

echo "War demo ready: sign in as test / password and open /reforger\n";
