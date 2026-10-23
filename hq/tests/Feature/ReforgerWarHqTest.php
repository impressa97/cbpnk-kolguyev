<?php

use App\Actions\Reforger\BuildWarCommandDataAction;
use App\Enums\WarOrderStatus;
use App\Enums\WarSide;
use App\Models\User;
use App\Models\WarAiPlan;
use App\Models\WarContact;
use App\Models\WarControl;
use App\Models\WarEnlistment;
use App\Models\WarObjective;
use App\Models\WarOrder;
use App\Models\WarRun;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Models\WarSupport;
use App\Models\WarTask;
use App\Models\WarVehicle;
use App\Support\WarEconomy;
use App\Support\WarTerritory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['reforger.bridge_secret' => 'war-secret']);
    Carbon::setTestNow(Carbon::parse('2026-10-11 12:00:00'));
});

afterEach(fn () => Carbon::setTestNow());

function warState(string $body)
{
    return test()->call('POST', '/api/reforger/war/state', [], [], [], [
        'CONTENT_TYPE' => 'text/plain',
        'HTTP_X_REFORGER_BRIDGE_SECRET' => 'war-secret',
    ], $body."END\n");
}

function warOrders(): string
{
    return test()->withHeader('X-Reforger-Bridge-Secret', 'war-secret')->get('/api/reforger/war/orders?format=tsv')->assertOk()->getContent();
}

/** @return array{0: User, 1: WarSquad, 2: WarEnlistment} */
function warCommander(WarSide $side = WarSide::Us, int $supplies = 400): array
{
    $user = User::factory()->create();
    test()->actingAs($user)->post('/reforger/war/enlist', ['side' => $side->value])->assertSessionHasNoErrors();
    $enlistment = WarEnlistment::query()->where('commander_id', $user->id)->first();
    $enlistment->forceFill(['supplies' => $supplies, 'supplied_at' => now()])->save();

    return [$user, WarSquad::query()->where('commander_id', $user->id)->orderBy('id')->first(), $enlistment];
}

function soldierLine(WarSoldier $soldier, int $health = 100, string $threat = 'safe', int $mags = 5, int $bleeding = 0, int $down = 0): string
{
    return "SOLDIER\t{$soldier->id}\tok\t{$health}\t{$bleeding}\t{$down}\t80\t{$threat}\t{$mags}\t0\n";
}

it('keeps each soldier\'s condition from the game and lets a death shake the squad', function () {
    [$user, $squad] = warCommander();
    [$a, $b, $c, $d] = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->get()->all();

    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\n"
        .soldierLine($a, 45, 'threatened', 1, 1)
        .soldierLine($b, 100, 'safe', 6)
        .soldierLine($c, 20, 'alerted', 0, 0, 1)
        .soldierLine($d)
        ."WORLD\t21.50\tRainy\t60\t20\t7.5\t270\t6.10\t19.40\t80\n")->assertOk();

    $a->refresh();
    expect($a->health)->toBe(45)->and($a->bleeding)->toBeTrue()->and($a->threat)->toBe('threatened')->and($a->mags)->toBe(1);

    Carbon::setTestNow(now()->addMinute());
    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t3\nSOLDIER\t{$d->id}\tdead\n"
        .soldierLine($a, 45, 'safe', 1, 1)
        .soldierLine($b)
        .soldierLine($c, 20, 'alerted', 0, 0, 1))->assertOk();

    $a->refresh();
    $b->refresh();
    expect($d->refresh()->loss)->toBe('killed')
        ->and($a->battles)->toBe(0)
        ->and((float) $b->morale)->toBeLessThan(60.0);

    // Shot at, then two minutes and more of quiet: he came through a battle.
    Carbon::setTestNow(now()->addMinutes(2));
    warState(soldierLine($a, 45, 'safe', 1, 1))->assertOk();
    expect($a->refresh()->battles)->toBe(1);

    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->where('war.world.weather', 'Rainy')
        ->where('war.world.hour', 21.5)
        ->where('war.squads.0.condition.wounded', 2)
        ->where('war.soldiers', fn ($rows) => in_array('bleeding', collect($rows)->firstWhere('id', $a->id)['flags'], true)
            && in_array('down', collect($rows)->firstWhere('id', $c->id)['flags'], true)));
});

it('delivers a vehicle at the side\'s spawn for no squad in particular, boards it by name, and writes it off when it burns', function () {
    [$user, $squad, $enlistment] = warCommander(WarSide::Ussr);
    config(['reforger.war.vehicle_limit' => 1]);

    // Nothing to board before the motor pool delivers.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'mount', 'x' => 1, 'z' => 1])->assertSessionHasErrors('vehicle_id');

    $this->actingAs($user)->post('/reforger/war/vehicles', ['kind' => 'truck'])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/reforger/war/vehicles', ['kind' => 'jeep'])->assertSessionHasErrors('vehicle');

    $vehicle = WarVehicle::query()->first();
    [$hx, $hz] = WarSide::Ussr->muster();
    expect($enlistment->refresh()->supplies)->toBe(320)
        ->and($vehicle->squad_id)->toBeNull()
        ->and((float) $vehicle->pos_x)->toBe((float) $hx)
        ->and(warOrders())->toContain("VEHICLE\t{$vehicle->id}\tussr\ttruck\t{D9B91FAB817A6033}Prefabs/Vehicles/Wheeled/Ural4320/Ural4320_transport_covered.et");

    warState("VEHICLE\t{$vehicle->id}\t11870.00\t10690.00\t100\t90\t14\t0\t1\n")->assertOk();
    expect($vehicle->refresh()->status)->toBe('fielded')->and($vehicle->seats)->toBe(14);

    // The game's "get in" on that vehicle: the order carries it to the mod as its target.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'mount', 'vehicle_id' => $vehicle->id, 'x' => 1, 'z' => 1])->assertSessionHasNoErrors();
    expect(WarOrder::query()->latest('id')->value('vehicle_id'))->toBe($vehicle->id)
        ->and(warOrders())->toContain("\tmount\t{$vehicle->id}\n");
    // Nobody is aboard yet, so there is nobody to get out.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'dismount', 'x' => 1, 'z' => 1])->assertSessionHasErrors('kind');

    warState("VEHICLE\t{$vehicle->id}\t11870.00\t10690.00\t0\t40\t14\t0\t0\n")->assertOk();
    expect($vehicle->refresh()->status)->toBe('destroyed')->and($vehicle->lost_at)->not->toBeNull();
});

it('takes the wounded to the hospital when a squad comes home, and lets them back when fit', function () {
    [$user, $squad, $enlistment] = warCommander();
    $soldiers = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->get();
    $hurt = $soldiers[0];

    // Out in the field nobody flies them out: the hospital is at home.
    $field = "SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\n".soldierLine($hurt, 30, 'safe', 1, 1);
    foreach ($soldiers->slice(1) as $s) {
        $field .= soldierLine($s, 100, 'safe', 1);
    }
    warState($field)->assertOk();
    $this->actingAs($user)->post('/reforger/war/support', ['kind' => 'medevac', 'squad_id' => $squad->id])->assertSessionHasErrors('kind');
    $this->actingAs($user)->post("/reforger/war/squads/{$squad->id}/logistics", ['action' => 'hospital'])->assertSessionHasErrors('squad_id');
    $this->actingAs($user)->post("/reforger/war/squads/{$squad->id}/logistics", ['action' => 'rearm'])->assertSessionHasErrors('squad_id');

    // Go home: when the game says they are there, the wounded go to the hospital, the rest rearm.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'rest', 'x' => 1, 'z' => 1])->assertSessionHasNoErrors();
    $home = WarOrder::query()->latest('id')->first();
    warState("SQUAD\t{$squad->id}\t1955.00\t7410.00\t4\nORDER\t{$home->id}\tdone\n")->assertOk();

    $hurt->refresh();
    $rearm = WarSupport::query()->where('squad_id', $squad->id)->first();
    expect($hurt->location)->toBe('reserve')
        ->and($hurt->ready_at->greaterThan(now()))->toBeTrue()
        ->and($soldiers[1]->refresh()->location)->toBe('field')
        ->and($rearm->kind)->toBe('resupply')
        ->and($rearm->cost)->toBe(0)
        ->and($home->refresh()->note)->toContain('Hospital: '.$hurt->name)
        ->and(warOrders())->toContain("SUPPLY\t{$rearm->id}\t{$squad->id}\t6\n")
        ->and(warOrders())->not->toContain("SOLDIER\t{$hurt->id}\t")
        ->and($enlistment->refresh()->supplies)->toBe(400);

    $this->actingAs($user)->post('/reforger/war/support', ['kind' => 'deploy', 'squad_id' => $squad->id])->assertSessionHasErrors('support');

    // The squad has moved on: the man goes back anyway, from the spawn, as a new man joins in the game.
    Carbon::setTestNow(now()->addMinutes(130));
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t3\n")->assertOk();
    $this->actingAs($user)->post('/reforger/war/support', ['kind' => 'deploy', 'squad_id' => $squad->id])->assertSessionHasNoErrors();
    expect($hurt->refresh()->location)->toBe('field')->and($hurt->health)->toBe(100)->and($hurt->pos_x)->toBeNull();

    [$hx, $hz] = WarSide::Us->muster();
    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->where('war.markers', fn ($markers) => collect($markers)->contains(fn ($m) => $m['id'] === $hurt->id && hypot($m['x'] - $hx, $m['z'] - $hz) < 80)));
});

it('rearms a squad beside one of your trucks for supplies, and not in the open', function () {
    [$user, $squad, $enlistment] = warCommander(WarSide::Us, 400);
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\n")->assertOk();
    $this->actingAs($user)->post("/reforger/war/squads/{$squad->id}/logistics", ['action' => 'rearm'])->assertSessionHasErrors('squad_id');

    $this->actingAs($user)->post('/reforger/war/vehicles', ['kind' => 'truck'])->assertSessionHasNoErrors();
    $truck = WarVehicle::query()->first();
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\nVEHICLE\t{$truck->id}\t5020.00\t6010.00\t100\t90\t14\t0\t1\n")->assertOk();

    $this->actingAs($user)->post("/reforger/war/squads/{$squad->id}/logistics", ['action' => 'rearm'])->assertSessionHasNoErrors();
    expect(WarSupport::query()->where('squad_id', $squad->id)->value('cost'))->toBe(10)
        ->and($enlistment->refresh()->supplies)->toBe(400 - 80 - 10);
    $this->actingAs($user)->post("/reforger/war/squads/{$squad->id}/logistics", ['action' => 'rearm'])->assertSessionHasErrors('squad_id');
});

it('chains orders: board, drive, get out, each once the game completes the one before', function () {
    [$user, $squad] = warCommander(WarSide::Us, 400);
    $this->actingAs($user)->post('/reforger/war/vehicles', ['kind' => 'truck'])->assertSessionHasNoErrors();
    $truck = WarVehicle::query()->first();
    warState("SQUAD\t{$squad->id}\t1990.00\t7400.00\t4\nVEHICLE\t{$truck->id}\t2000.00\t7410.00\t100\t90\t14\t0\t1\n")->assertOk();

    $this->actingAs($user)->post('/reforger/war/orders', [
        'squad_id' => $squad->id, 'kind' => 'mount', 'vehicle_id' => $truck->id, 'x' => 1, 'z' => 1,
        'then' => [['kind' => 'move', 'x' => 3000, 'z' => 6000], ['kind' => 'dismount', 'x' => 3000, 'z' => 6000]],
    ])->assertSessionHasNoErrors();
    [$mount, $move, $out] = WarOrder::query()->where('squad_id', $squad->id)->orderBy('id')->get()->all();
    expect([$mount->status, $move->status, $out->status])->toBe([WarOrderStatus::Pending, WarOrderStatus::Queued, WarOrderStatus::Queued])
        ->and(warOrders())->toContain("\tmount\t{$truck->id}\n")
        ->and(warOrders())->not->toContain("ORDER\t{$move->id}\t");

    // The game calls the board done when the leader is in; the drive waits for the whole squad.
    $men = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->get();
    $aboard = fn (int $n) => $men->map(fn (WarSoldier $s, int $i) => "SOLDIER\t{$s->id}\tok\t100\t0\t0\t80\tsafe\t5\t".($i < $n ? 1 : 0)."\t2000.00\t7410.00\t".($i === 0 && $n > 0 ? 'driver' : ($i < $n ? 'passenger' : '-'))."\tstand\tIdle\n")->implode('');
    warState("SQUAD\t{$squad->id}\t2000.00\t7410.00\t4\nORDER\t{$mount->id}\tdone\n".$aboard(1))->assertOk();
    expect($move->refresh()->status)->toBe(WarOrderStatus::Queued);
    warState("SQUAD\t{$squad->id}\t2000.00\t7410.00\t4\n".$aboard(4))->assertOk();
    expect($move->refresh()->status)->toBe(WarOrderStatus::Pending)->and($out->refresh()->status)->toBe(WarOrderStatus::Queued);

    // Appended: hold the ground after getting out. Nothing can wait behind a defend.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'defend', 'x' => 3000, 'z' => 6000, 'queue' => true])->assertSessionHasNoErrors();
    $defend = WarOrder::query()->latest('id')->first();
    expect($defend->status)->toBe(WarOrderStatus::Queued);
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'move', 'x' => 3100, 'z' => 6000, 'queue' => true])->assertSessionHasErrors('kind');

    // Taken out of the chain by hand; then the drive fails and the rest of the chain stops.
    $this->actingAs($user)->post("/reforger/war/orders/{$defend->id}/cancel")->assertSessionHasNoErrors();
    expect($defend->refresh()->status)->toBe(WarOrderStatus::Superseded);
    warState("SQUAD\t{$squad->id}\t2500.00\t7000.00\t4\nORDER\t{$move->id}\tfailed\n")->assertOk();
    expect($out->refresh()->status)->toBe(WarOrderStatus::Superseded)->and($out->note)->toContain('failed');

    // A plain order replaces whatever is under way and waiting.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'move', 'x' => 2600, 'z' => 7000])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'search', 'x' => 2700, 'z' => 7000, 'queue' => true])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'halt', 'x' => 1, 'z' => 1])->assertSessionHasNoErrors();
    expect(WarOrder::query()->where('squad_id', $squad->id)->whereIn('status', ['queued', 'pending', 'acked'])->pluck('kind')->all())->toBe(['halt']);
});

it('fires on a mark, refuses danger close, and waits out the battery', function () {
    [$user, $squad, $enlistment] = warCommander();

    $this->actingAs($user)->post('/reforger/war/support', ['kind' => 'fire_he_small', 'x' => 2000, 'z' => 7450])->assertSessionHasErrors('x');
    $this->actingAs($user)->post('/reforger/war/support', ['kind' => 'fire_he_small', 'x' => 6300, 'z' => 8000])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/reforger/war/support', ['kind' => 'fire_he_small', 'x' => 6400, 'z' => 8000])->assertSessionHasErrors('support');

    $mission = WarSupport::query()->first();
    expect(warOrders())->not->toContain("FIRE\t");

    Carbon::setTestNow(now()->addSeconds(95));
    expect(warOrders())->toContain("FIRE\t{$mission->id}\the_small\t6300.00\t8000.00");

    warState("FIRE\t{$mission->id}\tdone\n")->assertOk();
    expect($mission->refresh()->status)->toBe('done')->and($enlistment->refresh()->supplies)->toBe(355);
});

it('raids a harbour unseen: plunder for you, losses for every enemy commander', function () {
    // HQ hands out objectives at random; a raid bounty on this harbour would pay on top.
    config(['reforger.war.objectives.open' => 0]);
    [$user, $squad, $mine] = warCommander(WarSide::Fia, 100);
    [, , $enemy] = warCommander(WarSide::Us, 100);

    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'raid', 'x' => 6800, 'z' => 3480])->assertSessionHasNoErrors();
    $order = WarOrder::query()->where('squad_id', $squad->id)->first();
    expect($order->target)->toBe('ugoldar-harbour')->and(warOrders())->toContain("\traid\t0\n");

    $at = "SQUAD\t{$squad->id}\t6810.00\t3490.00\t4\n";
    warState($at)->assertOk();
    expect($order->refresh()->arrived_at)->not->toBeNull();

    Carbon::setTestNow(now()->addMinutes(3));
    warState($at)->assertOk();

    expect($order->refresh()->effect_until)->not->toBeNull()
        ->and($order->status)->toBe(WarOrderStatus::Done)
        ->and($mine->refresh()->supplies)->toBe(190)
        ->and($enemy->refresh()->supplies)->toBe(85);
});

it('sabotages a relay with a real charge the game plants, arms and sets off', function () {
    [$saboteur, $squad] = warCommander(WarSide::Ussr);
    [$watcher, $usSquad] = warCommander(WarSide::Us);

    $this->actingAs($saboteur)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'sabotage', 'x' => 6860, 'z' => 6780])->assertSessionHasErrors('kind');

    $sapper = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->first();
    $sapper->forceFill(['role' => 'sapper'])->save();
    $this->actingAs($saboteur)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'sabotage', 'x' => 6860, 'z' => 6780])->assertSessionHasNoErrors();
    $order = WarOrder::query()->latest('id')->first();
    // The mod gets the planting time and the fuze with the order.
    expect(warOrders())->toContain("\tsabotage\t0\nCHARGE\t{$order->id}\t20\t45\n");

    // Standing at the tower is nothing yet: the game reports the sapper planting, then the charge.
    $at = "SQUAD\t{$squad->id}\t6860.00\t6790.00\t4\nSQUAD\t{$usSquad->id}\t2000.00\t7400.00\t4\nCONTACT\t{$usSquad->id}\tussr\t2300.00\t7300.00\tinf\tseen\t5\n";
    warState($at."ORDER\t{$order->id}\tacked\nSABOTAGE\t{$order->id}\tplanting\t12\n")->assertOk();
    Carbon::setTestNow(now()->addMinutes(5));
    warState($at."ORDER\t{$order->id}\tacked\nSABOTAGE\t{$order->id}\tarmed\t30\n")->assertOk();
    expect($order->refresh()->effect_until)->toBeNull()->and($order->note)->toContain('Charge armed');

    warState($at."ORDER\t{$order->id}\tdone\nSABOTAGE\t{$order->id}\tdetonated\t0\n")->assertOk();
    expect($order->refresh()->effect_until)->not->toBeNull();
    $this->actingAs($watcher)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->has('war.contacts', 0)
        ->where('war.intel.blind_until', fn ($until) => $until !== null));
});

it('fails a sabotage whose charge somebody disarmed in time', function () {
    [$saboteur, $squad] = warCommander(WarSide::Ussr);
    WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->first()->forceFill(['role' => 'sapper'])->save();
    $this->actingAs($saboteur)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'sabotage', 'x' => 6860, 'z' => 6780])->assertSessionHasNoErrors();
    $order = WarOrder::query()->latest('id')->first();

    warState("SQUAD\t{$squad->id}\t6860.00\t6790.00\t4\nORDER\t{$order->id}\tfailed\nSABOTAGE\t{$order->id}\tdisarmed\t0\n")->assertOk();
    expect($order->refresh()->status)->toBe(WarOrderStatus::Failed)
        ->and($order->effect_until)->toBeNull()
        ->and($order->note)->toContain('disarmed');
});

it('lets HQ hold a point with its own squad, rotate it home on foot, and stand it down', function () {
    [$user, , $enlistment] = warCommander(WarSide::Us);

    $this->actingAs($user)->post('/reforger/war/tasks', ['kind' => 'hold', 'size' => 4, 'label' => 'Airport gate', 'a_x' => 2650, 'a_z' => 5370])->assertSessionHasNoErrors();
    $task = WarTask::query()->first();
    $hqSquad = WarSquad::query()->where('task_id', $task->id)->first();

    expect($hqSquad)->not->toBeNull()
        ->and($hqSquad->managed)->toBeTrue()
        ->and($hqSquad->soldiers()->count())->toBe(4)
        ->and(WarOrder::query()->where('squad_id', $hqSquad->id)->value('kind'))->toBe('defend');

    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $hqSquad->id, 'x' => 100, 'z' => 100])->assertSessionHasErrors('squad_id');
    $this->actingAs($user)->post('/reforger/war/tasks', ['kind' => 'hold', 'size' => 6, 'a_x' => 3000, 'a_z' => 5000])->assertSessionHasNoErrors();
    expect(WarTask::query()->latest('id')->first()->note)->toContain('Waiting for HQ soldiers');

    // Worn out (half the squad gone): HQ walks it home to rotate, nobody flies in.
    $men = $hqSquad->soldiers()->orderBy('id')->get();
    $hurt = $men[0];
    warState("SQUAD\t{$hqSquad->id}\t2650.00\t5370.00\t1\n".soldierLine($hurt, 40, 'safe', 3, 1)."SOLDIER\t{$men[1]->id}\tdead\nSOLDIER\t{$men[2]->id}\tdead\nSOLDIER\t{$men[3]->id}\tdead\n")->assertOk();
    $home = WarOrder::query()->where('squad_id', $hqSquad->id)->latest('id')->first();
    expect($home->kind)->toBe('rest')
        ->and(WarSupport::query()->where('squad_id', $hqSquad->id)->exists())->toBeFalse()
        ->and($task->refresh()->note)->toContain('Walking home')
        ->and($enlistment->refresh()->supplies)->toBe(400);
    warState("SQUAD\t{$hqSquad->id}\t1960.00\t7400.00\t1\nORDER\t{$home->id}\tdone\n")->assertOk();
    expect($hurt->refresh()->location)->toBe('reserve');

    $this->actingAs($user)->post("/reforger/war/tasks/{$task->id}/cancel")->assertSessionHasNoErrors();
    expect($task->refresh()->status)->toBe('closed')
        ->and(warOrders())->not->toContain("SOLDIER\t{$hurt->id}\t");
});

it('gives each enlisted side three different objectives about territory', function () {
    [$user] = warCommander(WarSide::Fia);
    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->has('war.objectives', 3)
        ->has('war.territory', 25));

    $kinds = WarObjective::query()->where('side', 'fia')->pluck('kind');
    expect($kinds->unique())->toHaveCount(3)
        ->and($kinds->diff(array_keys(config('reforger.war.objectives.kinds'))))->toHaveCount(0)
        ->and($kinds)->not->toContain('interdict');
});

it('takes a point by standing on it unopposed, then earns from it every hour', function () {
    [$user, $squad, $enlistment] = warCommander(WarSide::Us, 100);
    $objective = WarObjective::query()->create([
        'session_id' => $squad->session_id, 'side' => 'us', 'kind' => 'capture', 'target' => 'mineraly',
        'title' => 'Take Mineraly', 'pos_x' => 6322, 'pos_z' => 8012, 'reward' => 60, 'expires_at' => now()->addHours(6),
    ]);

    $there = "SQUAD\t{$squad->id}\t6330.00\t8020.00\t4\n";
    warState($there)->assertOk();
    Carbon::setTestNow(now()->addMinutes(2));
    warState($there."CONTACT\t{$squad->id}\tussr\t6400.00\t8100.00\tinf\tseen\t3\n")->assertOk();
    expect(WarControl::query()->where('place', 'mineraly')->value('claim_side'))->toBeNull();

    // The enemy is gone from the game's picture; half a minute later the ground counts as clear.
    warState($there)->assertOk();
    expect(WarControl::query()->where('place', 'mineraly')->value('claim_side'))->toBeNull();
    Carbon::setTestNow(now()->addMinute());
    warState($there)->assertOk();
    Carbon::setTestNow(now()->addMinutes(6));
    warState($there)->assertOk();

    $control = WarControl::query()->where('place', 'mineraly')->first();
    expect($control->side)->toBe('us')
        ->and($objective->refresh()->status)->toBe('done')
        ->and($enlistment->refresh()->supplies)->toBe(160);

    // The base 60/h plus Mineraly's 12/h.
    $enlistment->forceFill(['supplied_at' => now()])->save();
    Carbon::setTestNow(now()->addHour());
    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->where('war.income.territory', 12)
        ->where('war.economy.supplies', 232));
});

it('pays the driver and the whole side for a supply run from a harbour', function () {
    [$driver, $squad, $mine] = warCommander(WarSide::Us, 200);
    [, , $teammate] = warCommander(WarSide::Us, 100);
    $this->actingAs($driver)->post('/reforger/war/vehicles', ['kind' => 'truck'])->assertSessionHasNoErrors();
    $vehicle = WarVehicle::query()->first();
    $objective = WarObjective::query()->create([
        'session_id' => $squad->session_id, 'side' => 'us', 'kind' => 'supply_run', 'target' => 'west-cape-dock',
        'title' => 'Bring cargo', 'pos_x' => 1642, 'pos_z' => 7386, 'reward' => 60, 'expires_at' => now()->addHours(6),
    ]);

    warState("VEHICLE\t{$vehicle->id}\t1650.00\t7390.00\t90\t70\t14\t0\t1\n")->assertOk();
    expect($objective->refresh()->stage)->toBeNull();

    warState("VEHICLE\t{$vehicle->id}\t1650.00\t7390.00\t90\t70\t14\t2\t1\n")->assertOk();
    expect($objective->refresh()->stage)->toBe('loaded');

    warState("VEHICLE\t{$vehicle->id}\t1960.00\t7410.00\t90\t60\t14\t2\t1\n")->assertOk();
    expect($objective->refresh()->status)->toBe('done')
        ->and($mine->refresh()->supplies)->toBe(180)
        ->and($teammate->refresh()->supplies)->toBe(130);
});

it('interdicts an enemy-held point the game says was scouted: it stops paying its holder', function () {
    [$ussr, $ussrSquad, $holder] = warCommander(WarSide::Ussr, 0);
    [$us, $usSquad] = warCommander(WarSide::Us);
    WarControl::query()->create(['session_id' => $usSquad->session_id, 'place' => 'gorshkovo', 'side' => 'ussr', 'held_since' => now()]);
    expect(WarTerritory::incomeFor($usSquad->session_id, WarSide::Ussr))->toBe(12);

    $this->actingAs($us)->post('/reforger/war/orders', ['squad_id' => $usSquad->id, 'kind' => 'recon', 'x' => 8400, 'z' => 6600])->assertSessionHasNoErrors();
    $order = WarOrder::query()->latest('id')->first();
    // The game's Scout holds the area for war.operations.recon.hold seconds.
    expect(warOrders())->toContain("\trecon\t600\n");
    // Watching from 180 m off, not standing on the point (that would take it).
    $at = "SQUAD\t{$usSquad->id}\t8600.00\t6592.00\t4\n";
    // There is not done: the scout holds until the game completes it.
    warState($at."ORDER\t{$order->id}\tacked\n")->assertOk();
    Carbon::setTestNow(now()->addMinutes(30));
    warState($at."ORDER\t{$order->id}\tacked\n")->assertOk();
    expect(WarTerritory::incomeFor($usSquad->session_id, WarSide::Ussr))->toBe(12);
    warState($at."ORDER\t{$order->id}\tdone\n")->assertOk();

    expect(WarOrder::query()->where('squad_id', $usSquad->id)->value('target'))->toBe('gorshkovo')
        ->and(WarTerritory::incomeFor($usSquad->session_id, WarSide::Ussr))->toBe(0);
});

it('gives orders the game way: to the whole squad, a medic for one man, a combat mode, a transfer', function () {
    [$user, $squad] = warCommander();
    [$man, $other] = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->take(2)->get()->all();

    // Orders go to the squad; only treatment names a man, and it needs a medic on his feet.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'soldier_id' => $man->id, 'kind' => 'move', 'x' => 3100, 'z' => 6000])->assertSessionHasErrors('kind');
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'heal', 'x' => 1, 'z' => 1])->assertSessionHasErrors('soldier_id');
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'soldier_id' => $man->id, 'kind' => 'heal', 'x' => 1, 'z' => 1])->assertSessionHasErrors('kind');
    $other->forceFill(['role' => 'medic'])->save();
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'soldier_id' => $man->id, 'kind' => 'heal', 'x' => 1, 'z' => 1])->assertSessionHasNoErrors();
    $heal = WarOrder::query()->where('kind', 'heal')->first();
    expect($heal->soldier_id)->toBe($man->id)
        ->and(warOrders())->toContain("ORDER\t{$heal->id}\t{$squad->id}\tus\t");

    $this->actingAs($user)->post("/reforger/war/squads/{$squad->id}/combat", ['mode' => 'hold'])->assertSessionHasNoErrors();
    expect(warOrders())->toContain("COMBAT\t{$squad->id}\thold\n");

    // The game says what the group is doing, how it actually fights, and where a man sits.
    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\theal\thold\n"
        ."SOLDIER\t{$man->id}\tok\t70\t1\t0\t80\tsafe\t-1\t1\t3001.00\t6002.00\tdriver\n")->assertOk();
    expect($squad->refresh()->doing)->toBe('heal')
        ->and($squad->combat_actual)->toBe('hold')
        ->and($man->refresh()->seat)->toBe('driver');

    // A man moves to another of your squads (an empty one too); the mod moves him between groups.
    $second = WarSquad::query()->where('commander_id', $user->id)->where('id', '!=', $squad->id)->orderBy('id')->first();
    $this->actingAs($user)->post("/reforger/war/soldiers/{$man->id}/transfer", ['squad_id' => $second->id])->assertSessionHasNoErrors();
    expect($man->refresh()->squad_id)->toBe($second->id)
        ->and(warOrders())->toContain("SOLDIER\t{$man->id}\t{$second->id}\t");
});

it('runs an HQ patrol as the game\'s own patrol cycle between the two ends', function () {
    [$user] = warCommander(WarSide::Us);

    $this->actingAs($user)->post('/reforger/war/tasks', ['kind' => 'patrol', 'size' => 4, 'a_x' => 3000, 'a_z' => 6000, 'b_x' => 3400, 'b_z' => 6200])->assertSessionHasNoErrors();
    $squad = WarSquad::query()->where('task_id', WarTask::query()->value('id'))->first();
    $order = WarOrder::query()->where('squad_id', $squad->id)->latest('id')->first();

    // One standing order the game keeps by itself, never one leg at a time from the site.
    expect($order->kind)->toBe('patrol')
        ->and((float) $order->pos2_x)->toBe(3400.0)
        ->and(warOrders())->toContain("\tpatrol\t0\t3400.00\t6200.00\n");
});

it('serves each commander their desk after a bridge tick', function () {
    [$user, $squad] = warCommander();

    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\n")->assertOk();

    $this->actingAs($user)->getJson('/desk')
        ->assertOk()
        ->assertJsonPath('squads.0.x', 3000);
});

it('aims operations by their rules in config and hands the page the same rules', function () {
    [$user, $squad] = warCommander(WarSide::Fia);

    // A raid needs a harbour within reach of the mark; recon in open country stays on the mark.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'raid', 'x' => 5000, 'z' => 5000])->assertSessionHasErrors('kind');
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'recon', 'x' => 5000, 'z' => 5000])->assertSessionHasNoErrors();
    $recon = WarOrder::query()->where('squad_id', $squad->id)->latest('id')->first();
    expect($recon->target)->toBeNull()->and((float) $recon->pos_x)->toBe(5000.0);

    // Near a point, recon snaps onto it.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'recon', 'x' => 6400, 'z' => 8000])->assertSessionHasNoErrors();
    expect(WarOrder::query()->where('squad_id', $squad->id)->latest('id')->value('target'))->toBe('mineraly');

    config(['reforger.war.operations.raid.hold' => 300, 'reforger.war.operations.raid.plunder' => 75]);
    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->has('war.operations', 3)
        ->where('war.operations.2.id', 'raid')
        ->where('war.operations.2.targets', ['harbour'])
        ->where('war.operations.2.help', fn ($help) => str_contains($help, '5 min') && str_contains($help, '+75 supplies'))
        ->where('war.operations.1.needs', 'sapper'));
});

it('interdicts only the point a recon was aimed at, not one nearby', function () {
    [, , $holder] = warCommander(WarSide::Ussr, 0);
    [$us, $usSquad] = warCommander(WarSide::Us);
    WarControl::query()->create(['session_id' => $usSquad->session_id, 'place' => 'gorshkovo', 'side' => 'ussr', 'held_since' => now()]);

    // 300 m off Gorshkovo: outside recon's snap, so it watches the mark and leaves the point paying.
    $this->actingAs($us)->post('/reforger/war/orders', ['squad_id' => $usSquad->id, 'kind' => 'recon', 'x' => 8422, 'z' => 6892])->assertSessionHasNoErrors();
    $order = WarOrder::query()->latest('id')->first();
    warState("SQUAD\t{$usSquad->id}\t8420.00\t6890.00\t4\nORDER\t{$order->id}\tdone\n")->assertOk();

    expect(WarOrder::query()->where('squad_id', $usSquad->id)->value('effect_until'))->not->toBeNull()
        ->and(WarTerritory::incomeFor($usSquad->session_id, WarSide::Ussr))->toBe(12);
});

it('keeps an enemy on the map where he was last seen after the game loses him, then lets him age out', function () {
    [$user, $squad] = warCommander(WarSide::Us);
    $at = "SQUAD\t{$squad->id}\t2000.00\t7400.00\t4\n";

    warState($at."CONTACT\t{$squad->id}\tussr\t2300.00\t7300.00\tinf\theard\t3\n")->assertOk();
    // Seen again a little further on: the same contact, moved, not a second one.
    warState($at."CONTACT\t{$squad->id}\tussr\t2330.00\t7310.00\tinf\tseen\t1\n")->assertOk();
    // Dead or out of sight: the game no longer reports him.
    warState($at)->assertOk();

    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->has('war.contacts', 1)
        ->where('war.contacts.0.x', 2330)
        ->where('war.contacts.0.how', 'seen')
        // It moved 30 m: where it was before is its trail.
        ->where('war.contacts.0.trail', [['x' => 2300, 'z' => 7300]]));

    Carbon::setTestNow(now()->addMinutes(16));
    warState($at)->assertOk();
    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page->has('war.contacts', 0));
});

it('gives the game\'s formation, pace, routes, smoke, follow and attack', function () {
    [$user, $squad, $enlistment] = warCommander(WarSide::Us, 400);
    $second = WarSquad::query()->where('commander_id', $user->id)->whereKeyNot($squad->id)->orderBy('id')->first();
    $this->actingAs($user)->post('/reforger/war/upgrade', ['kind' => 'recruit', 'squad_id' => $second->id])->assertSessionHasNoErrors();

    // Formation and pace for the whole squad: group settings the mod applies.
    $this->actingAs($user)->post("/reforger/war/squads/{$squad->id}/form", ['formation' => 'line', 'pace' => 'walk'])->assertSessionHasNoErrors();
    $this->actingAs($user)->post("/reforger/war/squads/{$squad->id}/form", ['formation' => 'square'])->assertSessionHasErrors('formation');
    expect(warOrders())->toContain("FORM\t{$squad->id}\tline\twalk\n");

    // A move along a route: the first point, then a LEG per further point.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'move', 'x' => 2100, 'z' => 7400,
        'route' => [['x' => 2200, 'z' => 7450], ['x' => 2300, 'z' => 7500]]])->assertSessionHasNoErrors();
    $move = WarOrder::query()->latest('id')->first();
    expect(warOrders())->toContain("LEG\t{$move->id}\t2200.00\t7450.00\nLEG\t{$move->id}\t2300.00\t7500.00\n");
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'defend', 'x' => 2100, 'z' => 7400,
        'route' => [['x' => 2200, 'z' => 7450]]])->assertSessionHasErrors('route');

    // Smoke rides on top: the move stays under way.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'smoke', 'x' => 1, 'z' => 1])->assertSessionHasNoErrors();
    expect($move->refresh()->status)->toBe(WarOrderStatus::Pending)
        ->and(warOrders())->toContain("\tsmoke\t0\n");

    // Follow another squad of yours; the order names it for the mod.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'follow', 'x' => 1, 'z' => 1])->assertSessionHasErrors('follow_squad_id');
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'follow', 'follow_squad_id' => $squad->id, 'x' => 1, 'z' => 1])->assertSessionHasErrors('follow_squad_id');
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'follow', 'follow_squad_id' => $second->id, 'x' => 1, 'z' => 1])->assertSessionHasNoErrors();
    expect(warOrders())->toContain("\tfollow\t{$second->id}\n")
        ->and($move->refresh()->status)->toBe(WarOrderStatus::Superseded);

    // Attack a known enemy: the mark must be near a contact the side has.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'attack', 'x' => 2400, 'z' => 7300])->assertSessionHasErrors('kind');
    warState("SQUAD\t{$squad->id}\t2000.00\t7400.00\t4\nCONTACT\t{$squad->id}\tussr\t2400.00\t7300.00\tinf\tseen\t1\n")->assertOk();
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'attack', 'x' => 2450, 'z' => 7330])->assertSessionHasNoErrors();
    $attack = WarOrder::query()->latest('id')->first();
    expect((float) $attack->pos_x)->toBe(2400.0)->and($attack->target)->toBe('ussr');
});

it('runs HQ detachments on their own, out of the commander\'s hands, and only for as long as asked', function () {
    [$user, $squad] = warCommander(WarSide::Us);

    $this->actingAs($user)->post('/reforger/war/tasks', ['kind' => 'hold', 'size' => 4, 'minutes' => 45, 'a_x' => 2650, 'a_z' => 5370])->assertSessionHasErrors('minutes');
    $this->actingAs($user)->post('/reforger/war/tasks', ['kind' => 'hold', 'size' => 4, 'minutes' => 30, 'a_x' => 2650, 'a_z' => 5370])->assertSessionHasNoErrors();
    $task = WarTask::query()->first();
    $hq = WarSquad::query()->where('task_id', $task->id)->first();
    expect($task->ends_at->diffInMinutes(now(), true))->toBe(30.0);

    // The commander sees the detachment down to the man on the map, marked as HQ's; it is not
    // in his roster and takes no orders from him.
    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->where('war.squads', fn ($squads) => collect($squads)->contains(fn ($s) => $s['id'] === $hq->id && $s['label'] === 'HQ Alpha'))
        ->where('war.markers', fn ($markers) => collect($markers)->where('squad_id', $hq->id)->count() === 4
            && collect($markers)->where('squad_id', $hq->id)->every(fn ($m) => $m['hq'] === true))
        ->where('war.soldiers', fn ($soldiers) => collect($soldiers)->every(fn ($s) => $s['squad_id'] !== $hq->id))
        ->where('war.tasks.0.men', 4)
        ->where('war.tasks.0.ends_at', fn ($at) => $at !== null));

    // Only squads with men on the island go to the game: not the nine empty ones.
    expect(substr_count(warOrders(), "\nSQUAD\t"))->toBe(2);

    // Time is up: HQ calls the detachment in on its own.
    Carbon::setTestNow(now()->addMinutes(31));
    $this->actingAs($user)->get('/reforger')->assertOk();
    expect($task->refresh()->status)->toBe('closed')
        ->and($task->note)->toContain('Time is up')
        ->and(warOrders())->not->toContain("SQUAD\t{$hq->id}\t");
});

it('keeps a squad holding when the game lets its defend waypoint go', function () {
    [$user, $squad] = warCommander();
    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\n")->assertOk();
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'defend', 'x' => 3050, 'z' => 6020])->assertSessionHasNoErrors();
    $defend = WarOrder::query()->latest('id')->first();

    warState("SQUAD\t{$squad->id}\t3050.00\t6020.00\t4\nORDER\t{$defend->id}\tdone\n")->assertOk();
    $again = WarOrder::query()->latest('id')->first();
    expect($again->id)->not->toBe($defend->id)
        ->and($again->kind)->toBe('defend')
        ->and($again->status)->toBe(WarOrderStatus::Pending)
        ->and((float) $again->pos_x)->toBe(3050.0)
        ->and(warOrders())->toContain("ORDER\t{$again->id}\t");

    // A newer order is the squad's word: nothing comes back.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'move', 'x' => 3100, 'z' => 6000])->assertSessionHasNoErrors();
    $count = WarOrder::query()->count();
    warState("SQUAD\t{$squad->id}\t3050.00\t6020.00\t4\nORDER\t{$again->id}\tdone\n")->assertOk();
    expect(WarOrder::query()->count())->toBe($count);
});

it('unloads a vehicle\'s passengers as a squad of their own while the crew keeps the vehicle', function () {
    [$user, $squad] = warCommander(WarSide::Us, 400);
    $men = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->get();
    $seat = fn (WarSoldier $s, string $seat) => "SOLDIER\t{$s->id}\tok\t100\t0\t0\t80\tsafe\t5\t1\t3000.00\t6000.00\t{$seat}\tstand\tVehicle\n";

    // Nobody aboard: nothing to part.
    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\n")->assertOk();
    $this->actingAs($user)->post("/reforger/war/squads/{$squad->id}/split", ['part' => 'passengers'])->assertSessionHasErrors('squad_id');

    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\n".$seat($men[0], 'driver').$seat($men[1], 'gunner').$seat($men[2], 'passenger').$seat($men[3], 'passenger'))->assertOk();
    expect($men[0]->refresh()->seat)->toBe('driver');

    $this->actingAs($user)->post("/reforger/war/squads/{$squad->id}/split", ['part' => 'passengers', 'unload' => true])->assertSessionHasNoErrors();
    $into = WarSquad::query()->whereKey($men[2]->refresh()->squad_id)->first();
    expect($into->id)->not->toBe($squad->id)
        ->and($men[3]->refresh()->squad_id)->toBe($into->id)
        ->and($men[0]->refresh()->squad_id)->toBe($squad->id)
        ->and($into->strength)->toBe(2)
        ->and($squad->refresh()->strength)->toBe(2)
        ->and((float) $into->pos_x)->toBe(3000.0)
        ->and(WarOrder::query()->where('squad_id', $into->id)->value('kind'))->toBe('dismount')
        ->and(warOrders())->toContain("SQUAD\t{$into->id}\t");

    // The crew alone is the whole squad now: give it the order itself.
    $this->actingAs($user)->post("/reforger/war/squads/{$squad->id}/split", ['part' => 'crew'])->assertSessionHasErrors('squad_id');
});

it('refuels and repairs a vehicle at home for free, beside a truck for supplies, and not in the open', function () {
    [$user, , $enlistment] = warCommander(WarSide::Us, 400);
    $this->actingAs($user)->post('/reforger/war/vehicles', ['kind' => 'jeep'])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/reforger/war/vehicles', ['kind' => 'truck'])->assertSessionHasNoErrors();
    [$jeep, $truck] = WarVehicle::query()->orderBy('id')->get()->all();
    $paid = $enlistment->refresh()->supplies;

    // At home: the depot, free.
    warState("VEHICLE\t{$jeep->id}\t1960.00\t7400.00\t40\t10\t2\t0\t1\nVEHICLE\t{$truck->id}\t1965.00\t7405.00\t100\t90\t14\t0\t1\n")->assertOk();
    $this->actingAs($user)->post("/reforger/war/vehicles/{$jeep->id}/service")->assertSessionHasNoErrors();
    $service = WarSupport::query()->latest('id')->first();
    expect($service->kind)->toBe('service')->and($service->cost)->toBe(0)->and($service->vehicle_id)->toBe($jeep->id)
        ->and(warOrders())->toContain("SERVICE\t{$service->id}\t{$jeep->id}\n");
    $this->actingAs($user)->post("/reforger/war/vehicles/{$jeep->id}/service")->assertSessionHasErrors('vehicle');
    warState("SERVICE\t{$service->id}\tdone\n")->assertOk();
    expect($service->refresh()->status)->toBe('done')->and($service->note)->toBe('Refuelled and repaired.');

    // In the open: no; beside the truck: for supplies.
    warState("VEHICLE\t{$jeep->id}\t5000.00\t6000.00\t40\t10\t2\t0\t1\nVEHICLE\t{$truck->id}\t5600.00\t6000.00\t100\t90\t14\t0\t1\n")->assertOk();
    $this->actingAs($user)->post("/reforger/war/vehicles/{$jeep->id}/service")->assertSessionHasErrors('vehicle');
    warState("VEHICLE\t{$truck->id}\t5020.00\t6010.00\t100\t90\t14\t0\t1\n")->assertOk();
    $this->actingAs($user)->post("/reforger/war/vehicles/{$jeep->id}/service")->assertSessionHasNoErrors();
    expect(WarSupport::query()->latest('id')->value('cost'))->toBe(15)
        ->and($enlistment->refresh()->supplies)->toBe($paid - 15);
});

it('closes the orders of a squad that has nobody left on the island', function () {
    [$user, $squad] = warCommander();
    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\n")->assertOk();
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'defend', 'x' => 3050, 'z' => 6020])->assertSessionHasNoErrors();
    $defend = WarOrder::query()->latest('id')->first();

    $dead = WarSoldier::query()->where('squad_id', $squad->id)->pluck('id')->map(fn ($id) => "SOLDIER\t{$id}\tdead\n")->implode('');
    warState("SQUAD\t{$squad->id}\t3050.00\t6020.00\t0\nORDER\t{$defend->id}\tacked\n".$dead)->assertOk();
    expect($defend->refresh()->status)->toBe(WarOrderStatus::Superseded)
        ->and($defend->note)->toContain('Nobody left')
        ->and(WarOrder::query()->where('squad_id', $squad->id)->whereIn('status', ['pending', 'acked', 'queued'])->exists())->toBeFalse();
});

it('counts each kill the game reports once, and what a man learns from kills and fights', function () {
    [$user, $squad] = warCommander();
    $man = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->first();
    $before = WarEconomy::effectiveStat($man->aim, WarEconomy::experience($man));

    $kills = "KILL\t1791800000\t1\t{$man->id}\tussr\nKILL\t1791800000\t2\t{$man->id}\tfia\n";
    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\n".$kills)->assertOk();
    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\n".$kills)->assertOk();
    expect($man->refresh()->kills)->toBe(2);

    // Alerted and calm again is no fight: the game steps down through alerted on any march.
    warState(soldierLine($man, 100, 'alerted'))->assertOk();
    warState(soldierLine($man, 100, 'safe'))->assertOk();
    expect($man->refresh()->battles)->toBe(0);

    // A fight: shot at, then quiet for two minutes, on his feet.
    warState(soldierLine($man, 100, 'threatened'))->assertOk();
    Carbon::setTestNow(now()->addMinutes(3));
    warState(soldierLine($man, 100, 'safe'))->assertOk();
    $man->refresh();
    expect($man->battles)->toBe(1)
        ->and(WarEconomy::effectiveStat($man->aim, WarEconomy::experience($man)))->toBe($before + 7)
        ->and(warOrders())->toContain("SOLDIER\t{$man->id}\t{$squad->id}\t");
    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->where('war.soldiers', fn ($rows) => collect($rows)->firstWhere('id', $man->id)['kills'] === 2));
});

it('says a move failed when the game could not take the squad there, and lets a straggler lead his own squad', function () {
    [$user, $squad] = warCommander();
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'move', 'x' => 5000, 'z' => 6000])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'defend', 'x' => 5000, 'z' => 6000, 'queue' => true])->assertSessionHasNoErrors();
    $move = WarOrder::query()->where('kind', 'move')->latest('id')->first();
    // Stopped on the way once: they go on, and the defend behind it still waits.
    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\nORDER\t{$move->id}\tfailed\n")->assertOk();
    $again = WarOrder::query()->where('kind', 'move')->latest('id')->first();
    expect($move->refresh()->status)->toBe(WarOrderStatus::Superseded)
        ->and($again->id)->not->toBe($move->id)->and($again->attempt)->toBe(1)->and($again->status)->toBe(WarOrderStatus::Pending)
        ->and(WarOrder::query()->where('kind', 'defend')->value('status'))->toBe(WarOrderStatus::Queued);
    // Twice: it says so, and the chain stops.
    warState("ORDER\t{$again->id}\tfailed\n")->assertOk();
    expect($again->refresh()->note)->toContain('could not take them there')
        ->and(WarOrder::query()->where('kind', 'defend')->value('status'))->toBe(WarOrderStatus::Superseded);

    $straggler = WarSoldier::query()->where('squad_id', $squad->id)->orderByDesc('id')->first();
    $straggler->forceFill(['pos_x' => 6800, 'pos_z' => 3100])->save();
    $this->actingAs($user)->post("/reforger/war/soldiers/{$straggler->id}/detach")->assertSessionHasNoErrors();
    $own = WarSquad::query()->whereKey($straggler->refresh()->squad_id)->first();
    expect($own->id)->not->toBe($squad->id)->and($own->strength)->toBe(1)->and((float) $own->pos_x)->toBe(6800.0)
        ->and($squad->refresh()->strength)->toBe(3);
});

it('fights a recruit as a regular, a good shot as a veteran, and lets experience make an expert', function () {
    [, $squad] = warCommander();
    $man = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->first();
    expect(WarEconomy::aiSkill($man, 0))->toBe('regular');

    // Aim alone: a scout's eyes or a veteran's nerve do not make him shoot straighter.
    $man->forceFill(['aim' => 40, 'grit' => 90, 'fieldcraft' => 90])->save();
    expect(WarEconomy::aiSkill($man->refresh(), 0))->toBe('regular');
    $man->forceFill(['aim' => 60])->save();
    expect(WarEconomy::aiSkill($man->refresh(), 0))->toBe('veteran');

    $man->forceFill(['service' => 12, 'battles' => 4, 'kills' => 2])->save();
    expect(WarEconomy::aiSkill($man->refresh(), 0))->toBe('expert');
});

it('puts kills, fire, lost vehicles and failed orders with their reason on the radio log', function () {
    [$user, $squad] = warCommander(WarSide::Us, 400);
    $man = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->first();
    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\nKILL\t1791800000\t9\t{$man->id}\tussr\n")->assertOk();

    $this->actingAs($user)->post('/reforger/war/support', ['kind' => 'illum', 'x' => 8000, 'z' => 8000])->assertSessionHasNoErrors();
    $flare = WarSupport::query()->latest('id')->first();
    $flare->forceFill(['ready_at' => now()])->save();
    warState("FIRE\t{$flare->id}\tdone\n")->assertOk();

    $this->actingAs($user)->post('/reforger/war/vehicles', ['kind' => 'jeep'])->assertSessionHasNoErrors();
    $jeep = WarVehicle::query()->latest('id')->first();
    warState("VEHICLE\t{$jeep->id}\t2000.00\t7400.00\t100\t90\t2\t0\t1\n")->assertOk();
    warState("VEHICLE\t{$jeep->id}\t2000.00\t7400.00\t0\t90\t2\t0\t0\n")->assertOk();

    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'move', 'x' => 5000, 'z' => 6000])->assertSessionHasNoErrors();
    $move = WarOrder::query()->latest('id')->first();
    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\nORDER\t{$move->id}\tfailed\n")->assertOk();
    $again = WarOrder::query()->latest('id')->first();
    warState("ORDER\t{$again->id}\tfailed\n")->assertOk();

    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->where('war.events', function ($events) use ($man) {
            $texts = collect($events)->pluck('text');

            return $texts->contains(fn ($t) => str_contains($t, "{$man->name} killed a USSR soldier"))
                && $texts->contains(fn ($t) => str_contains($t, 'Illumination at') && str_contains($t, 'Rounds on target'))
                && $texts->contains(fn ($t) => str_contains($t, 'was destroyed'))
                && $texts->contains(fn ($t) => str_contains($t, 'move at') && str_contains($t, 'could not take them there'));
        }));
});

it('runs the sides nobody plays with an AI command: garrisons, a patrol, and a counterattack on a point taken near its ground', function () {
    config(['reforger.war.pve.enabled' => true, 'reforger.war.pve.budget' => 30]);
    [$user] = warCommander(WarSide::Fia);

    // Nobody plays the US or the USSR: their AI commands hold their bases and walk a patrol.
    warState("END\n")->assertOk();
    $us = WarSquad::query()->where('ai', true)->where('side', 'us')->get();
    expect($us->count())->toBeGreaterThanOrEqual(2)
        ->and($us->every(fn ($s) => $s->commander_id === null && $s->managed))->toBeTrue()
        ->and(WarOrder::query()->whereIn('squad_id', $us->pluck('id'))->pluck('kind')->unique()->sort()->values()->all())->toBe(['defend', 'patrol'])
        ->and(warOrders())->toContain("SQUAD\t{$us->first()->id}\tus\t")
        ->and(WarSquad::query()->where('ai', true)->where('side', 'fia')->exists())->toBeFalse();

    // FIA takes the airport, next to the US home: the US counterattacks it, and FIA hears of it.
    WarControl::query()->updateOrCreate(['session_id' => $us->first()->session_id, 'place' => 'airport'], ['side' => 'fia', 'held_since' => now()]);
    warState("END\n")->assertOk();
    $plan = WarAiPlan::query()->where('kind', 'counter')->first();
    expect($plan)->not->toBeNull()->and($plan->place)->toBe('airport')
        // Far from home it goes by truck: board, drive, get out short of it, rush, search.
        ->and(WarOrder::query()->where('squad_id', $plan->squad_id)->orderBy('id')->pluck('kind')->all())->toBe(['mount', 'move', 'dismount', 'move_forced', 'search'])
        ->and(WarVehicle::query()->find($plan->vehicle_id)?->commander_id)->toBeNull()
        ->and(WarOrder::query()->where('squad_id', $plan->squad_id)->orderBy('id')->value('vehicle_id'))->toBe($plan->vehicle_id);
    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->where('war.events', fn ($events) => collect($events)->contains(fn ($e) => $e['kind'] === 'threat' && str_contains($e['text'], 'US counterattack heading for Airport in trucks'))));

    // Wiped out: no new squad for that post until the cooldown runs out.
    $dead = WarSoldier::query()->where('squad_id', $plan->squad_id)->pluck('id')->map(fn ($id) => "SOLDIER\t{$id}\tdead\n")->implode('');
    warState($dead)->assertOk();
    expect($plan->refresh()->status)->toBe('lost')
        ->and(WarAiPlan::query()->where('kind', 'counter')->where('status', 'active')->exists())->toBeFalse()
        ->and(WarVehicle::query()->find($plan->vehicle_id)->status)->toBe('scrapped');
});

it('keeps the AI command busy: hunts a sighting, relieves a post under fire, attacks on its own, and pulls back a beaten squad', function () {
    config(['reforger.war.pve.enabled' => true, 'reforger.war.pve.budget' => 40]);
    [$user, $fia] = warCommander(WarSide::Fia);
    warState("END\n")->assertOk();
    $patrol = WarAiPlan::query()->where('side', 'us')->where('kind', 'patrol')->first();
    $garrison = WarAiPlan::query()->where('side', 'us')->where('kind', 'garrison')->orderBy('id')->first();
    $patrolSquad = WarSquad::query()->find($patrol->squad_id);
    expect(WarAiPlan::query()->where('kind', 'raid')->exists())->toBeFalse();

    // A US squad sees FIA men near the patrol: the patrol searches there, then walks its beat again.
    WarContact::query()->create(['session_id' => $patrolSquad->session_id, 'squad_id' => $garrison->squad_id, 'side' => 'fia',
        'pos_x' => (float) $patrolSquad->pos_x + 300, 'pos_z' => (float) $patrolSquad->pos_z, 'kind' => 'infantry', 'how' => 'seen', 'seen_at' => now()]);
    warState("END\n")->assertOk();
    expect(WarOrder::query()->where('squad_id', $patrolSquad->id)->whereIn('status', ['pending', 'queued'])->orderBy('id')->pluck('kind')->all())->toBe(['search', 'patrol']);

    // The garrison comes under fire: with the patrol on an errand, a squad comes out from home.
    $men = WarSoldier::query()->where('squad_id', $garrison->squad_id)->orderBy('id')->get();
    warState(soldierLine($men[0], 80, 'threatened'))->assertOk();
    $qrf = WarAiPlan::query()->where('kind', 'qrf')->first();
    expect($qrf?->place)->toBe($garrison->place)
        ->and(WarOrder::query()->where('squad_id', $qrf->squad_id)->orderBy('id')->pluck('kind')->first())->toBeIn(['mount', 'move_forced', 'search']);
    warState(soldierLine($men[0], 80, 'threatened'))->assertOk();
    expect(WarAiPlan::query()->where('kind', 'qrf')->count())->toBe(1);

    // Settled in for a while: it goes for the nearest point it does not hold, and FIA hears of it.
    Carbon::setTestNow(now()->addMinutes(30));
    warState("END\n")->assertOk();
    $raid = WarAiPlan::query()->where('side', 'us')->where('kind', 'raid')->first();
    expect($raid)->not->toBeNull()
        ->and(WarControl::query()->where('place', $raid->place)->value('side'))->not->toBe('us');
    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->where('war.events', fn ($events) => collect($events)->contains(fn ($e) => $e['kind'] === 'threat' && str_contains($e['text'], 'US attack heading for'))));

    // Three of four men of the garrison killed: the last one falls back home, and a fresh
    // garrison takes the post once the cooldown is out.
    warState($men->take(3)->map(fn ($s) => "SOLDIER\t{$s->id}\tdead\n")->implode(''))->assertOk();
    expect($garrison->refresh()->status)->toBe('retreat')
        ->and(WarOrder::query()->where('squad_id', $garrison->squad_id)->latest('id')->value('kind'))->toBe('rest');
    Carbon::setTestNow(now()->addMinutes(16));
    warState("END\n")->assertOk();
    expect(WarAiPlan::query()->where('kind', 'garrison')->where('place', $garrison->place)->where('status', 'active')->exists())->toBeTrue();
});

it('hands a side\'s AI squads home when a commander enlists on it, and stops giving a defend the game cannot carry out', function () {
    config(['reforger.war.pve.enabled' => true]);
    [$fiaUser, $fia] = warCommander(WarSide::Fia);
    warState("END\n")->assertOk();
    $ussr = WarAiPlan::query()->where('side', 'ussr')->where('status', 'active')->get();
    expect($ussr)->not->toBeEmpty();

    warCommander(WarSide::Ussr);
    warState("END\n")->assertOk();
    expect(WarAiPlan::query()->where('side', 'ussr')->where('status', 'active')->exists())->toBeFalse()
        ->and(WarOrder::query()->where('squad_id', $ussr->first()->squad_id)->latest('id')->value('kind'))->toBe('rest');

    // At the point, a defend the game lets go of every tick is given a few times, then left be.
    warState("SQUAD\t{$fia->id}\t5010.00\t6000.00\t4\n")->assertOk();
    $this->actingAs($fiaUser)->post('/reforger/war/orders', ['squad_id' => $fia->id, 'kind' => 'defend', 'x' => 5000, 'z' => 6000])->assertSessionHasNoErrors();
    for ($i = 0; $i < 8; $i++) {
        $open = WarOrder::query()->where('squad_id', $fia->id)->where('kind', 'defend')->whereIn('status', ['pending', 'acked'])->latest('id')->first();
        if ($open === null) {
            break;
        }
        warState("ORDER\t{$open->id}\tdone\n")->assertOk();
    }
    expect(WarOrder::query()->where('squad_id', $fia->id)->where('kind', 'defend')->count())->toBe(4)
        ->and(WarOrder::query()->where('squad_id', $fia->id)->latest('id')->value('note'))->toContain('cannot move them');
});

it('sends an HQ ammunition truck with its own driver to a squad, which rearms beside it, then takes it home', function () {
    [$user, $squad, $enlistment] = warCommander(WarSide::Us, 400);
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\n")->assertOk();
    $this->actingAs($user)->post('/reforger/war/runs', ['kind' => 'supply', 'squad_id' => $squad->id])->assertSessionHasNoErrors();
    $run = WarRun::query()->first();
    $truck = WarVehicle::query()->find($run->vehicle_id);
    expect($truck->hq)->toBeTrue()->and($enlistment->refresh()->supplies)->toBe(380)
        ->and(WarOrder::query()->where('squad_id', $run->crew_squad_id)->orderBy('id')->pluck('kind')->all())->toBe(['mount', 'move']);
    // HQ's truck is not one of his own: not counted, not his to board.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'mount', 'vehicle_id' => $truck->id, 'x' => 1, 'z' => 1])->assertSessionHasErrors('vehicle_id');

    $driver = WarSoldier::query()->where('squad_id', $run->crew_squad_id)->first();
    [$mount, $drive] = WarOrder::query()->where('squad_id', $run->crew_squad_id)->orderBy('id')->get()->all();
    $aboard = "SOLDIER\t{$driver->id}\tok\t100\t0\t0\t80\tsafe\t5\t1\t1960.00\t7400.00\tdriver\tstand\tVehicle\n";
    warState("VEHICLE\t{$truck->id}\t1960.00\t7400.00\t100\t90\t14\t1\t1\nORDER\t{$mount->id}\tdone\n".$aboard)->assertOk();
    expect($drive->refresh()->status)->toBe(WarOrderStatus::Pending);
    warState("VEHICLE\t{$truck->id}\t5020.00\t6010.00\t100\t80\t14\t1\t1\nORDER\t{$drive->id}\tdone\n")->assertOk();
    expect($run->refresh()->stage)->toBe('arrived');
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\n")->assertOk();
    expect(WarSupport::query()->where('squad_id', $squad->id)->where('kind', 'resupply')->exists())->toBeTrue()
        ->and($run->refresh()->stage)->toBe('home')
        ->and(WarOrder::query()->where('squad_id', $run->crew_squad_id)->latest('id')->value('kind'))->toBe('rest');

    // The driver takes the truck back (he is at the wheel), then drives it home.
    [$retake, $home] = WarOrder::query()->where('squad_id', $run->crew_squad_id)->latest('id')->take(2)->get()->reverse()->values()->all();
    expect($retake->kind)->toBe('mount')->and($home->status)->toBe(WarOrderStatus::Queued);
    warState("ORDER\t{$retake->id}\tdone\n".$aboard)->assertOk();
    warState("ORDER\t{$home->id}\tdone\n")->assertOk();
    expect($run->refresh()->status)->toBe('done')->and($truck->refresh()->status)->toBe('scrapped')
        ->and($driver->refresh()->fallen_at)->not->toBeNull();
});

it('spreads HQ trucks around the spawn and puts a driver who got out back at the wheel, then drops them where the truck is', function () {
    [$user, $squad, $enlistment] = warCommander(WarSide::Us, 1000);
    [$other] = WarSquad::query()->where('commander_id', $squad->commander_id)->where('id', '!=', $squad->id)->orderBy('id')->get()->all();
    WarSoldier::query()->where('squad_id', $squad->id)->limit(1)->update(['squad_id' => $other->id]);
    WarSquad::query()->whereKey($other->id)->update(['strength' => 1, 'alive' => 1]);
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t3\nSQUAD\t{$other->id}\t5000.00\t6000.00\t1\n")->assertOk();
    foreach ([$squad, $other] as $s) {
        $this->actingAs($user)->post('/reforger/war/runs', ['kind' => 'transport', 'squad_id' => $s->id, 'x' => 7000, 'z' => 8000])->assertSessionHasNoErrors();
    }
    [$a, $b] = WarRun::query()->orderBy('id')->get()->all();
    $va = WarVehicle::query()->find($a->vehicle_id);
    $vb = WarVehicle::query()->find($b->vehicle_id);
    expect(hypot((float) $va->pos_x - (float) $vb->pos_x, (float) $va->pos_z - (float) $vb->pos_z))->toBeGreaterThan(20);

    // Carrying, the driver is out of his seat (the game took him out): back in, and drive again.
    $a->forceFill(['stage' => 'carrying', 'stage_at' => now()->subMinute()])->save();
    $va->forceFill(['status' => 'fielded', 'pos_x' => 5000, 'pos_z' => 6000])->save();
    WarOrder::query()->create(['session_id' => $a->session_id, 'squad_id' => $a->crew_squad_id, 'commander_id' => $a->commander_id, 'kind' => 'move', 'status' => 'acked', 'pos_x' => 7000, 'pos_z' => 8000, 'note' => '']);
    $driver = WarSoldier::query()->where('squad_id', $a->crew_squad_id)->first();
    $out = "SOLDIER\t{$driver->id}\tok\t100\t0\t0\t80\tsafe\t5\t0\t5040.00\t6000.00\t-\tstand\tMoveInFormation\n";
    warState($out)->assertOk();
    expect($a->refresh()->tries)->toBe(1)
        ->and(WarOrder::query()->where('squad_id', $a->crew_squad_id)->whereIn('status', ['pending', 'queued'])->orderBy('id')->pluck('kind')->all())->toBe(['mount', 'move']);

    // Out again after both tries: the squad gets out where the truck is.
    $a->forceFill(['tries' => 2, 'stage_at' => now()->subMinute()])->save();
    WarOrder::query()->where('squad_id', $a->crew_squad_id)->where('kind', 'move')->update(['status' => 'acked']);
    $riders = WarSoldier::query()->where('squad_id', $squad->id)->get()->map(fn ($s) => "SOLDIER\t{$s->id}\tok\t100\t0\t0\t80\tsafe\t5\t1\t5000.00\t6000.00\tpassenger\tstand\tVehicle\n")->implode('');
    warState($out.$riders)->assertOk();
    expect($a->refresh()->stage)->toBe('unloading')
        ->and(WarOrder::query()->where('squad_id', $squad->id)->latest('id')->value('kind'))->toBe('dismount');
});

it('keeps an order clicked twice on the same spot, holds orders while riding an HQ truck, and puts squads near a sighting on alert', function () {
    [$user, $squad] = warCommander(WarSide::Us, 400);
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\n")->assertOk();

    // The same attack clicked again a few metres off: one order, the squad not restarted.
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'defend', 'x' => 5200, 'z' => 6000])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'defend', 'x' => 5210, 'z' => 6005])->assertSessionHasNoErrors();
    expect(WarOrder::query()->where('squad_id', $squad->id)->where('kind', 'defend')->count())->toBe(1);
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'defend', 'x' => 5400, 'z' => 6000])->assertSessionHasNoErrors();
    expect(WarOrder::query()->where('squad_id', $squad->id)->where('kind', 'defend')->count())->toBe(2);

    // On normal readiness, but the side saw the enemy 300 m away a minute ago: alert.
    expect(warOrders())->toContain("ALERT\t{$squad->id}\tnormal");
    WarContact::query()->create(['session_id' => $squad->session_id, 'squad_id' => $squad->id, 'side' => 'ussr',
        'pos_x' => 5300, 'pos_z' => 6000, 'kind' => 'inf', 'how' => 'seen', 'seen_at' => now()->subMinute()]);
    expect(warOrders())->toContain("ALERT\t{$squad->id}\talert");

    // Riding an HQ lift: the commander's orders wait until they are dropped.
    $this->actingAs($user)->post('/reforger/war/runs', ['kind' => 'transport', 'squad_id' => $squad->id, 'x' => 7000, 'z' => 8000])->assertSessionHasNoErrors();
    $run = WarRun::query()->first();
    $run->forceFill(['stage' => 'carrying', 'stage_at' => now()])->save();
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'move', 'x' => 6000, 'z' => 6000])->assertSessionHasErrors('squad_id');

    // Dropped: one man stays in the back. The truck waits; he is told again, and the order the
    // commander gave meanwhile waits behind it.
    $run->forceFill(['stage' => 'unloading', 'stage_at' => now()->subMinutes(2)])->save();
    WarVehicle::query()->whereKey($run->vehicle_id)->update(['status' => 'fielded', 'pos_x' => 7000, 'pos_z' => 8000]);
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'defend', 'x' => 7100, 'z' => 8000])->assertSessionHasNoErrors();
    $stuck = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->first();
    warState("SOLDIER\t{$stuck->id}\tok\t100\t0\t0\t80\tsafe\t5\t1\t7000.00\t8000.00\tpassenger\tstand\tVehicle\n")->assertOk();
    expect($run->refresh()->stage)->toBe('unloading')
        ->and(WarOrder::query()->where('squad_id', $squad->id)->whereIn('status', ['pending', 'queued'])->orderBy('id')->pluck('kind')->all())->toBe(['dismount', 'defend']);
});

it('drives the wounded home by HQ ambulance, back on their own squad in the hospital', function () {
    [$user, $squad] = warCommander(WarSide::Us, 400);
    $hurt = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->first();
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\n".soldierLine($hurt, 30, 'safe', 3, 1))->assertOk();
    $this->actingAs($user)->post('/reforger/war/runs', ['kind' => 'ambulance', 'squad_id' => $squad->id])->assertSessionHasNoErrors();
    $run = WarRun::query()->first();
    $run->forceFill(['stage' => 'arrived', 'stage_at' => now()])->save();
    WarVehicle::query()->whereKey($run->vehicle_id)->update(['status' => 'fielded', 'pos_x' => 5010, 'pos_z' => 6005]);
    $hurt->forceFill(['pos_x' => 5000, 'pos_z' => 6000])->save();

    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\n")->assertOk();
    expect($hurt->refresh()->squad_id)->toBe($run->crew_squad_id)->and($run->refresh()->stage)->toBe('loading');

    Carbon::setTestNow(now()->addMinutes(6));
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t3\n")->assertOk();
    [$retake, $home] = WarOrder::query()->where('squad_id', $run->crew_squad_id)->latest('id')->take(2)->get()->reverse()->values()->all();
    expect($retake->kind)->toBe('mount')->and($home->kind)->toBe('rest');
    $aboard = WarSoldier::query()->where('squad_id', $run->crew_squad_id)->get()
        ->map(fn ($s) => "SOLDIER\t{$s->id}\tok\t60\t0\t0\t80\tsafe\t3\t1\t5010.00\t6005.00\tpassenger\tstand\tVehicle\n")->implode('');
    warState("ORDER\t{$retake->id}\tdone\n".$aboard)->assertOk();
    warState("SQUAD\t{$run->crew_squad_id}\t1960.00\t7400.00\t2\nORDER\t{$home->id}\tdone\n")->assertOk();
    $hurt->refresh();
    expect($run->refresh()->status)->toBe('done')
        ->and($hurt->squad_id)->toBe($squad->id)
        ->and($hurt->location)->toBe('reserve')
        ->and($hurt->fallen_at)->toBeNull();
});

it('walks the squad to an HQ truck that stopped short, rearms it there, then gives it back its own orders', function () {
    [$user, $squad] = warCommander(WarSide::Us, 400);
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\n")->assertOk();
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'defend', 'x' => 5000, 'z' => 6000])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/reforger/war/runs', ['kind' => 'supply', 'squad_id' => $squad->id])->assertSessionHasNoErrors();
    $run = WarRun::query()->first();
    $run->forceFill(['stage' => 'arrived', 'stage_at' => now()])->save();
    WarVehicle::query()->whereKey($run->vehicle_id)->update(['status' => 'fielded', 'pos_x' => 5100, 'pos_z' => 6000]);

    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\n")->assertOk();
    [$walk, $resume] = WarOrder::query()->where('squad_id', $squad->id)->whereIn('status', [WarOrderStatus::Pending, WarOrderStatus::Queued])->orderBy('id')->get()->all();
    expect($run->refresh()->stage)->toBe('meeting')
        ->and($walk->kind)->toBe('move')->and((float) $walk->pos_x)->toBe(5100.0)->and($walk->note)->toBe('To the HQ ammo truck.')
        ->and($resume->kind)->toBe('defend')->and($resume->status)->toBe(WarOrderStatus::Queued);

    // Beside the truck: rearmed, the walk is over, and the defend comes back on the next tick.
    warState("SQUAD\t{$squad->id}\t5080.00\t6000.00\t4\n")->assertOk();
    expect(WarSupport::query()->where('squad_id', $squad->id)->where('kind', 'resupply')->exists())->toBeTrue()
        ->and($run->refresh()->stage)->toBe('home')
        ->and($walk->refresh()->status)->toBe(WarOrderStatus::Done);
    warState("SQUAD\t{$squad->id}\t5080.00\t6000.00\t4\n")->assertOk();
    expect($resume->refresh()->status)->toBe(WarOrderStatus::Pending);
});

it('sets a squad ready for contact and drills one man into a specialist, both sent to the game', function () {
    [$user, $squad, $enlistment] = warCommander(WarSide::Us, 400);
    $man = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->first();

    $this->actingAs($user)->post("/reforger/war/squads/{$squad->id}/alert", ['level' => 'ready'])->assertSessionHasNoErrors();
    expect($squad->refresh()->alert)->toBe('ready')->and(warOrders())->toContain("ALERT\t{$squad->id}\tready");
    $this->actingAs($user)->post("/reforger/war/squads/{$squad->id}/alert", ['level' => 'panic'])->assertSessionHasErrors('level');

    // Aim drilled step by step, each step dearer, up to the top; his aim alone makes him a marksman.
    $start = $man->aim;
    $first = WarEconomy::drillCost($man, 'aim');
    $this->actingAs($user)->post('/reforger/war/upgrade', ['kind' => 'drill', 'squad_id' => $squad->id, 'soldier_id' => $man->id, 'stat' => 'aim'])->assertSessionHasNoErrors();
    $man->refresh();
    expect($man->aim)->toBe($start + 10)
        ->and($enlistment->refresh()->supplies)->toBe(400 - $first)
        ->and(WarEconomy::drillCost($man, 'aim'))->toBeGreaterThan($first);
    $man->forceFill(['aim' => 100])->save();
    expect(WarEconomy::drillCost($man, 'aim'))->toBeNull()
        ->and(WarEconomy::aiSkill($man, 0))->toBe('expert');
    $this->actingAs($user)->post('/reforger/war/upgrade', ['kind' => 'drill', 'squad_id' => $squad->id, 'soldier_id' => $man->id, 'stat' => 'aim'])->assertSessionHasErrors('stat');

    // Endurance starts at 40 for every role; drilled, it goes to the game on his SOLDIER line.
    $this->actingAs($user)->post('/reforger/war/upgrade', ['kind' => 'drill', 'squad_id' => $squad->id, 'soldier_id' => $man->id, 'stat' => 'endurance'])->assertSessionHasNoErrors();
    expect($man->refresh()->endurance)->toBe(50)
        ->and(warOrders())->toMatch("/SOLDIER\\t{$man->id}\\t[^\\n]*\\t5\\d\\n/");
    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->where('war.soldiers', fn ($rows) => collect($rows)->firstWhere('id', $man->id)['drill']['aim'] === null));
});

it('shows where our men and the enemies we see are looking', function () {
    [$user, $squad] = warCommander();
    $man = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->first();
    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\n"
        ."SOLDIER\t{$man->id}\tok\t100\t0\t0\t80\tsafe\t5\t0\t3001.00\t6002.00\t-\tstand\tIdle\t135\n"
        ."CONTACT\t{$squad->id}\tussr\t3400.00\t6100.00\tinf\tseen\t2\t270\n")->assertOk();
    expect($man->refresh()->heading)->toBe(135);
    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->where('war.markers', fn ($m) => collect($m)->firstWhere('id', $man->id)['heading'] === 135)
        ->where('war.contacts.0.heading', 270));
});

it('marks each enemy the game names as his own contact, and shows whom each of our men is fighting', function () {
    [$user, $squad] = warCommander();
    $man = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->first();
    [, $foes] = warCommander(WarSide::Ussr);
    [$a, $b] = WarSoldier::query()->where('squad_id', $foes->id)->orderBy('id')->get()->all();
    $line = fn (int $target) => "SOLDIER\t{$man->id}\tok\t100\t0\t0\t80\tthreatened\t5\t0\t3001.00\t6002.00\t-\tprone\tAttack\t80\t{$target}\n";
    // Two USSR men 20 m apart: two contacts, not one; our man fights the first.
    warState("SQUAD\t{$squad->id}\t3000.00\t6000.00\t4\n".$line($a->id)
        ."SOLDIER\t{$a->id}\tok\t100\t0\t0\t80\tthreatened\t5\t0\t3400.00\t6100.00\t-\tstand\tAttack\t270\t0\n"
        ."CONTACT\t{$squad->id}\tussr\t3400.00\t6100.00\tinf\tseen\t1\t270\t{$a->id}\n"
        ."CONTACT\t{$squad->id}\tussr\t3420.00\t6100.00\tinf\tseen\t1\t260\t{$b->id}\n")->assertOk();
    expect(WarContact::query()->where('squad_id', $squad->id)->count())->toBe(2)
        ->and($man->refresh()->target_id)->toBe($a->id);
    // He moved 300 m: still the same contact.
    warState("CONTACT\t{$squad->id}\tussr\t3700.00\t6100.00\tinf\tseen\t1\t90\t{$a->id}\n")->assertOk();
    expect(WarContact::query()->where('squad_id', $squad->id)->count())->toBe(2);

    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->where('war.contacts', fn ($c) => count($c) === 2)
        ->where('war.markers', fn ($m) => collect($m)->firstWhere('id', $man->id)['target'] === ['x' => 3400, 'z' => 6100]));

    // Out of the fight: no line.
    warState($line(0))->assertOk();
    expect($man->refresh()->target_id)->toBeNull();
});

it('logs a fight: orders, the squad under fire and out of it, a death with who did it, and a timeline to read it', function () {
    [$user, $squad] = warCommander(WarSide::Us, 400);
    [, $enemy] = warCommander(WarSide::Ussr, 400);
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\nSQUAD\t{$enemy->id}\t5100.00\t6000.00\t4\n")->assertOk();

    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'move', 'x' => 5200, 'z' => 6000])->assertSessionHasNoErrors();
    $order = WarOrder::query()->where('squad_id', $squad->id)->latest('id')->first();
    expect(DB::table('cbpnk_war_log')->where('kind', 'order.given')->where('order_id', $order->id)->value('squad_id'))->toBe($squad->id);

    $man = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->first();
    $shooter = WarSoldier::query()->where('squad_id', $enemy->id)->orderBy('id')->first();

    // Shot at: the squad's fight begins.
    warState("ORDER\t{$order->id}\tacked\n".soldierLine($man, 80, 'threatened'))->assertOk();
    expect(DB::table('cbpnk_war_log')->where('kind', 'order.acked')->where('order_id', $order->id)->exists())->toBeTrue()
        ->and(DB::table('cbpnk_war_log')->where('kind', 'squad.engaged')->where('squad_id', $squad->id)->count())->toBe(1);
    warState(soldierLine($man, 70, 'threatened'))->assertOk();
    expect(DB::table('cbpnk_war_log')->where('kind', 'squad.engaged')->count())->toBe(1);

    // He dies; the mod says who did it, with what, from how far, in every file after.
    $death = "DEATH\t1700000000\t7\t{$man->id}\t{$shooter->id}\tenemy\tRifle_AK74\t140\t5150.0\t6000.0\t5290.0\t6000.0\n";
    warState("SOLDIER\t{$man->id}\tdead\n".$death)->assertOk();
    warState("SOLDIER\t{$man->id}\tdead\n".$death)->assertOk();
    $man->refresh();
    expect($man->killed_by_id)->toBe($shooter->id)
        ->and($man->death_cause)->toBe('enemy')
        ->and($man->death_weapon)->toBe('Rifle_AK74')
        ->and($man->death_metres)->toBe(140);
    $row = DB::table('cbpnk_war_log')->where('kind', 'soldier.killed')->where('soldier_id', $man->id)->sole();
    expect($row->other_id)->toBe($shooter->id)
        ->and($row->text)->toContain('Rifle_AK74')->toContain('140 m')
        ->and((float) $row->pos_x)->toBe(5150.0);

    // Quiet for two minutes: out of the fight, with its cost.
    Carbon::setTestNow(now()->addMinutes(3));
    $other = WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->orderBy('id')->first();
    warState(soldierLine($other, 100, 'safe'))->assertOk();
    expect(DB::table('cbpnk_war_log')->where('kind', 'squad.disengaged')->where('squad_id', $squad->id)->value('text'))->toContain('1 killed')
        ->and($squad->refresh()->engaged_at)->toBeNull();

    // The timeline around the place, and for the killer.
    $this->artisan('war:timeline', ['--near' => '5150,6000', '--radius' => 100, '--since' => '1h', '--tz' => 'UTC'])
        ->expectsOutputToContain('soldier.killed')
        ->assertSuccessful();
    $this->artisan('war:timeline', ['--soldier' => [$shooter->id], '--kinds' => 'soldier.', '--since' => '1h'])
        ->expectsOutputToContain('Rifle_AK74')
        ->assertSuccessful();
});

/**
 * Every key path in a desk: "squads[].condition.field". Lists merge their elements.
 *
 * @return array<string, true>
 */
function warShape(mixed $value, string $path = ''): array
{
    if (! is_array($value)) {
        return [];
    }
    $out = [];
    if (array_is_list($value)) {
        foreach ($value as $item) {
            $out += warShape($item, $path.'[]');
        }

        return $out;
    }
    foreach ($value as $key => $item) {
        $at = $path === '' ? (string) $key : $path.'.'.$key;
        $out[$at] = true;
        $out += warShape($item, $at);
    }

    return $out;
}

it('builds the desk the page contract describes (resources/js/features/reforger-war/__fixtures__/war-desk.json)', function () {
    [$user, $squad] = warCommander(WarSide::Us, 2000);
    [, $enemy] = warCommander(WarSide::Ussr, 400);
    [$a, $b, $c] = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->get()->all();
    $foe = WarSoldier::query()->where('squad_id', $enemy->id)->orderBy('id')->first();

    $this->actingAs($user)->post('/reforger/war/vehicles', ['kind' => 'truck'])->assertSessionHasNoErrors();
    $vehicle = WarVehicle::query()->where('commander_id', $user->id)->first();
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\tmove\tfree\nSQUAD\t{$enemy->id}\t5300.00\t6000.00\t4\tdefend\thold\n"
        ."SOLDIER\t{$a->id}\tok\t70\t1\t0\t80\tthreatened\t3\t0\t5001.00\t6001.00\t-\tcrouch\tAttack\t90\t{$foe->id}\n"
        .soldierLine($b)
        ."CONTACT\t{$squad->id}\tussr\t5290.00\t6000.00\tinf\tseen\t3\t270\t{$foe->id}\n"
        ."VEHICLE\t{$vehicle->id}\t5010.00\t6010.00\t90\t80\t14\t0\t1\n"
        ."SOLDIER\t{$c->id}\tdead\n"
        ."DEATH\t1700000000\t1\t{$c->id}\t{$foe->id}\tenemy\tRifle_AK74\t180\t5005.0\t6000.0\t5185.0\t6000.0\n"
        ."WORLD\t12.00\tClear\t0\t0\t3.0\t90\t6.00\t20.00\t50\n")->assertOk();

    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'move', 'x' => 5200, 'z' => 6100,
        'route' => [['x' => 5250, 'z' => 6150]]])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'patrol', 'x' => 5300, 'z' => 6200,
        'x2' => 5400, 'z2' => 6200, 'queue' => true])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/reforger/war/support', ['kind' => 'fire_he_small', 'x' => 9000, 'z' => 9000])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/reforger/war/runs', ['kind' => 'supply', 'squad_id' => $squad->id])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/reforger/war/tasks', ['kind' => 'hold', 'size' => 4, 'minutes' => 30, 'a_x' => 4000, 'a_z' => 4000])->assertSessionHasNoErrors();

    $desk = app(BuildWarCommandDataAction::class)->build($user);
    $fixture = base_path('resources/js/features/reforger-war/__fixtures__/war-desk.json');
    if (env('WAR_FIXTURE') === 'write') {
        file_put_contents($fixture, json_encode($desk, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
    }

    // Every list the page draws has a row here, so the contract test sees each row's fields.
    foreach (['squads', 'orders', 'markers', 'contacts', 'vehicles', 'runs', 'support', 'tasks', 'territory', 'events', 'soldiers', 'fallen', 'places', 'sides'] as $list) {
        expect($desk[$list])->not->toBeEmpty("desk.{$list} is empty in the contract scenario");
    }
    $have = array_keys(warShape($desk));
    $want = array_keys(warShape(json_decode((string) file_get_contents($fixture), true)));
    sort($have);
    sort($want);
    // The desk changed shape: run WAR_FIXTURE=write php artisan test --filter=contract, update
    // lib/schemas/reforger.ts to match, and npm run test:js checks the fixture against it.
    expect(array_values(array_diff($have, $want)))->toBe([], 'new in the desk, not in the fixture')
        ->and(array_values(array_diff($want, $have)))->toBe([], 'in the fixture, gone from the desk');
});

it('puts a death under HQ fire down to the barrage that came down there, and who called it', function () {
    [$user] = warCommander(WarSide::Us, 400);
    [, $enemy] = warCommander(WarSide::Ussr, 400);
    warState("SQUAD\t{$enemy->id}\t9000.00\t9000.00\t4\n")->assertOk();
    $this->actingAs($user)->post('/reforger/war/support', ['kind' => 'fire_he_small', 'x' => 9000, 'z' => 9000])->assertSessionHasNoErrors();
    $support = WarSupport::query()->latest('id')->first();
    warState("FIRE\t{$support->id}\tdone\n")->assertOk();

    // The mod as it was: the Game Master's module named as the killer's weapon.
    $man = WarSoldier::query()->where('squad_id', $enemy->id)->orderBy('id')->first();
    warState("SOLDIER\t{$man->id}\tdead\nDEATH\t1700000000\t3\t{$man->id}\t0\tother\tEffectModule_Zoned_MortarBarrage_Small\t4\t9010.0\t9005.0\t9000.0\t9000.0\n")->assertOk();

    // Another, last seen bleeding, dies with no killer named: he bled out.
    $other = WarSoldier::query()->where('squad_id', $enemy->id)->whereNull('fallen_at')->orderBy('id')->first();
    warState(soldierLine($other, 30, 'safe', 5, 1))->assertOk();
    warState("SOLDIER\t{$other->id}\tdead\nDEATH\t1700000000\t4\t{$other->id}\t0\tother\t-\t-1\t8000.0\t8000.0\t-\t-\n")->assertOk();
    expect($other->refresh()->death_cause)->toBe('wounds');

    $man->refresh();
    $row = DB::table('cbpnk_war_log')->where('kind', 'soldier.killed')->where('soldier_id', $man->id)->sole();
    expect($man->death_cause)->toBe('fire')
        ->and($man->death_weapon)->toBeNull()
        ->and($row->ref)->toBe('support:'.$support->id)
        ->and($row->text)->toContain('fire_he_small #'.$support->id)
        ->and(json_decode($row->data, true)['called_by'])->toBe($user->id);
    $this->artisan('war:timeline', ['--kinds' => 'soldier.killed,support.', '--since' => '1h'])
        ->expectsOutputToContain('support:'.$support->id)
        ->assertSuccessful();
});

it('aims an attack at the man behind the contact, and tells allies and enemies apart by what they do', function () {
    [$user, $squad] = warCommander(WarSide::Us, 400);
    [, $enemy] = warCommander(WarSide::Ussr, 400);
    $foe = WarSoldier::query()->where('squad_id', $enemy->id)->orderBy('id')->first();
    $foe->forceFill(['role' => 'machinegun'])->save();
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\nSQUAD\t{$enemy->id}\t5300.00\t6000.00\t4\n"
        ."CONTACT\t{$squad->id}\tussr\t5290.00\t6000.00\tinf\tseen\t3\t270\t{$foe->id}\n")->assertOk();

    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'attack', 'x' => 5280, 'z' => 6010])->assertSessionHasNoErrors();
    $order = WarOrder::query()->where('squad_id', $squad->id)->where('kind', 'attack')->sole();
    expect($order->enemy_id)->toBe($foe->id)
        ->and(warOrders())->toContain("\tattack\t{$foe->id}\n");

    // The man seen is known by his weapon; our own men carry their role.
    $mine = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->first();
    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->where('war.contacts.0.role', 'mg')
        ->where('war.contacts.0.vehicle', null)
        ->where('war.markers', fn ($rows) => collect($rows)->firstWhere('id', $mine->id)['role'] === WarEconomy::roleClass($mine->role)));
});

it('sends a squad to its defend point first when the game drops the defend short of it, then gives up', function () {
    [$user, $squad] = warCommander(WarSide::Us, 400);
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\n")->assertOk();
    $this->actingAs($user)->post('/reforger/war/orders', ['squad_id' => $squad->id, 'kind' => 'defend', 'x' => 5600, 'z' => 6000])->assertSessionHasNoErrors();
    $defend = WarOrder::query()->where('squad_id', $squad->id)->sole();

    // Dropped 600 m short: a rush there, the defend waiting behind it.
    warState("SQUAD\t{$squad->id}\t5000.00\t6000.00\t4\nORDER\t{$defend->id}\tdone\n")->assertOk();
    $open = WarOrder::query()->where('squad_id', $squad->id)->whereIn('status', ['pending', 'queued'])->orderBy('id')->get();
    expect($open->pluck('kind')->all())->toBe(['move_forced', 'defend'])
        ->and($open->pluck('status')->map(fn ($s) => $s->value)->all())->toBe(['pending', 'queued']);

    // The rush gets them no nearer and the defend drops again: they stay, and it says why.
    $rush = $open->first();
    warState("SQUAD\t{$squad->id}\t5050.00\t6000.00\t4\nORDER\t{$rush->id}\tdone\n")->assertOk();
    $again = WarOrder::query()->where('squad_id', $squad->id)->where('kind', 'defend')->where('status', 'pending')->sole();
    warState("SQUAD\t{$squad->id}\t5050.00\t6000.00\t4\nORDER\t{$again->id}\tdone\n")->assertOk();
    expect($again->refresh()->note)->toContain('stay where they are')
        ->and(WarOrder::query()->where('squad_id', $squad->id)->whereIn('status', ['pending', 'queued', 'acked'])->count())->toBe(0);
});
