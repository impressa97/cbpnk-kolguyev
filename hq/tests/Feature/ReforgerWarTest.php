<?php

use App\Enums\WarOrderStatus;
use App\Enums\WarSide;
use App\Models\User;
use App\Models\WarContact;
use App\Models\WarEnlistment;
use App\Models\WarOrder;
use App\Models\WarSession;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('opens the desk as the local commander, with no account', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reforger')
            ->where('war.enlisted_side', null));
});

it('shows an empty kolguyev command on the reforger page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/reforger')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reforger')
            ->where('war.world_size', 12800)
            ->where('war.enlisted_side', null)
            ->where('war.session.open', false)
            ->where('war.session.ends_at', null)
            ->where('war.squad_strength', 4)
            ->has('war.sides', 3)
            ->has('war.squads', 0)
            ->has('war.places', 28)
            ->where('war.places.0.name', 'Villa')
            ->has('war.markers', 0)
            ->has('war.events', 0)
            ->where('war.economy.supplies', null));
});

it('enlists a player onto one side and stands a squad at the muster', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/reforger/war/enlist', ['side' => WarSide::Us->value])
        ->assertRedirect();

    $playerId = $user->id;
    $enlistment = WarEnlistment::query()->where('commander_id', $playerId)->first();
    $squad = WarSquad::query()->where('commander_id', $playerId)->orderBy('id')->first();

    expect($enlistment)->not->toBeNull()
        ->and($enlistment->side)->toBe(WarSide::Us)
        ->and($squad)->not->toBeNull()
        ->and($squad->side)->toBe(WarSide::Us)
        ->and($squad->strength)->toBe(4)
        ->and($squad->alive)->toBe(4)
        ->and($squad->tier)->toBe(0)
        ->and($enlistment->supplies)->toBe(250)
        ->and((float) $squad->pos_x)->toBe(1951.0)
        ->and((float) $squad->pos_z)->toBe(7401.0);

    $this->actingAs($user)
        ->post('/reforger/war/enlist', ['side' => WarSide::Ussr->value])
        ->assertRedirect()
        ->assertSessionHasErrors('side');

    expect(WarEnlistment::query()->count())->toBe(1);

    $this->actingAs($user)
        ->get('/reforger')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('war.markers', 4)
            ->where('war.markers.0.name', 'Cole')
            ->where('war.markers.0.x', 1909)
            ->where('war.markers.0.z', 7429)
            ->where('war.events', function ($events) {
                $text = collect($events)->pluck('text')->implode("\n");

                return str_contains($text, 'Cole joined')
                    && str_contains($text, 'enlisted with 250');
            }));
});

it('rejects another squad once the ai budget is full', function () {
    config(['reforger.ai_limit' => 4]);

    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($first)
        ->post('/reforger/war/enlist', ['side' => WarSide::Us->value])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($second)
        ->post('/reforger/war/enlist', ['side' => WarSide::Fia->value])
        ->assertRedirect()
        ->assertSessionHasErrors('side');

    // Only the first commander's squad has men; the rest of his ten are names.
    expect(WarSquad::query()->where('strength', '>', 0)->count())->toBe(1)
        ->and(WarSquad::query()->count())->toBe(10);
});

it('replaces a pending move and refuses a point off the island', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/reforger/war/orders', ['x' => 1000, 'z' => 1000])
        ->assertRedirect()
        ->assertSessionHasErrors('side');

    $this->actingAs($user)
        ->post('/reforger/war/enlist', ['side' => WarSide::Fia->value])
        ->assertRedirect();

    $this->actingAs($user)
        ->post('/reforger/war/orders', ['x' => 3000, 'z' => 4000])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->post('/reforger/war/orders', ['x' => 5000, 'z' => 6000])
        ->assertRedirect();

    $this->actingAs($user)
        ->post('/reforger/war/orders', ['x' => 20000, 'z' => 10])
        ->assertRedirect()
        ->assertSessionHasErrors('x');

    $orders = WarOrder::query()->orderBy('id')->get();
    expect($orders)->toHaveCount(2)
        ->and($orders[0]->status)->toBe(WarOrderStatus::Superseded)
        ->and($orders[1]->status)->toBe(WarOrderStatus::Pending)
        ->and((float) $orders[1]->pos_x)->toBe(5000.0)
        ->and((float) $orders[1]->pos_z)->toBe(6000.0);
});

it('exports pending orders to the mod and applies the state it reports', function () {
    config(['reforger.bridge_secret' => 'war-secret']);

    $user = User::factory()->create();
    $this->actingAs($user)->post('/reforger/war/enlist', ['side' => WarSide::Ussr->value]);
    $this->actingAs($user)->post('/reforger/war/orders', ['x' => 9000, 'z' => 2000]);

    $squad = WarSquad::query()->orderBy('id')->first();
    $order = WarOrder::query()->first();

    $this->getJson('/api/reforger/war/orders')->assertUnauthorized();

    $export = $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
        ->getJson('/api/reforger/war/orders')
        ->assertOk()
        ->json();

    expect($export['squads'])->toHaveCount(1)
        ->and($export['squads'][0]['side'])->toBe('ussr')
        ->and($export['orders'])->toHaveCount(1)
        ->and($export['orders'][0]['id'])->toBe($order->id)
        ->and($export['orders'][0]['x'])->toEqual(9000);

    $tsv = $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
        ->get('/api/reforger/war/orders?format=tsv')
        ->assertOk()
        ->getContent();

    $rifleman = WarSoldier::query()->where('squad_id', $squad->id)->orderBy('id')->first();

    expect($tsv)->toContain("SQUAD\t{$squad->id}\tussr\t")
        ->and($tsv)->toContain("ORDER\t{$order->id}\t{$squad->id}\tussr\t9000.00\t2000.00")
        ->and($tsv)->toContain("SOLDIER\t{$rifleman->id}\t{$squad->id}\trifleman\t40\t40\t40\t0");

    $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
        ->postJson('/api/reforger/war/state', [
            'squads' => [[
                'id' => $squad->id,
                'x' => 9100.5,
                'z' => 2100.25,
                'alive' => 3,
            ]],
            'orders' => [[
                'id' => $order->id,
                'status' => 'acked',
            ]],
        ])
        ->assertOk();

    $squad->refresh();
    $order->refresh();

    expect((float) $squad->pos_x)->toBe(9100.5)
        ->and((float) $squad->pos_z)->toBe(2100.25)
        ->and($squad->alive)->toBe(3)
        ->and($order->status)->toBe(WarOrderStatus::Acked);

    // Acked moves stay in the export so a restarted server walks them again.
    $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
        ->getJson('/api/reforger/war/orders')
        ->assertOk()
        ->assertJsonCount(1, 'orders');

    $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
        ->postJson('/api/reforger/war/state', [
            'squads' => [],
            'orders' => [['id' => $order->id, 'status' => 'done']],
        ])
        ->assertOk();

    expect($order->refresh()->status)->toBe(WarOrderStatus::Done);

    $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
        ->getJson('/api/reforger/war/orders')
        ->assertOk()
        ->assertJsonCount(0, 'orders');
});

it('accepts the mod state file as text', function () {
    config(['reforger.bridge_secret' => 'war-secret']);

    $user = User::factory()->create();
    $this->actingAs($user)->post('/reforger/war/enlist', ['side' => WarSide::Us->value]);
    $this->actingAs($user)->post('/reforger/war/orders', ['x' => 2000, 'z' => 3000]);

    $squad = WarSquad::query()->orderBy('id')->first();
    $order = WarOrder::query()->first();
    $body = "SQUAD\t{$squad->id}\t2001.00\t3001.50\t4\nORDER\t{$order->id}\tacked\nEND\n";

    $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
        ->call('POST', '/api/reforger/war/state', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_X_REFORGER_BRIDGE_SECRET' => 'war-secret',
        ], $body)
        ->assertOk();

    expect(WarOrder::query()->find($order->id)->status)->toBe(WarOrderStatus::Acked)
        ->and((float) WarSquad::query()->find($squad->id)->pos_z)->toBe(3001.5);
});

it('redirects guests away from enlistment', function () {
    $this->post('/reforger/war/enlist', ['side' => 'us'])->assertRedirect();
});

it('accrues supplies and spends them on soldiers and training', function () {
    config(['reforger.bridge_secret' => 'war-secret']);
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00'));

    try {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->post('/reforger/war/enlist', ['side' => WarSide::Us->value])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Carbon::setTestNow(Carbon::parse('2026-10-10 13:00:00'));

        $this->actingAs($user)
            ->get('/reforger')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('war.economy.supplies', 310)
                ->where('war.economy.recruit.cost', 30)
                ->where('war.economy.recruit.available', true)
                ->where('war.economy.train.cost', 60));

        $this->actingAs($user)
            ->post('/reforger/war/upgrade', ['kind' => 'recruit'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $squad = WarSquad::query()->where('commander_id', $user->id)->orderBy('id')->first();
        $enlistment = WarEnlistment::query()->where('commander_id', $user->id)->first();

        expect($enlistment->supplies)->toBe(280)
            ->and($squad->strength)->toBe(5)
            ->and($squad->alive)->toBe(5)
            ->and($squad->tier)->toBe(0);

        $this->actingAs($user)
            ->post('/reforger/war/upgrade', ['kind' => 'train'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $squad->refresh();
        $enlistment->refresh();

        expect($enlistment->supplies)->toBe(220)
            ->and($squad->tier)->toBe(1);

        // Short of a rifleman's price, nobody joins.
        $enlistment->forceFill(['supplies' => 10])->save();
        $this->actingAs($user)
            ->post('/reforger/war/upgrade', ['kind' => 'recruit'])
            ->assertRedirect()
            ->assertSessionHasErrors('supplies');

        expect(WarSquad::query()->find($squad->id)->strength)->toBe(5);

        $tsv = $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
            ->get('/api/reforger/war/orders?format=tsv')
            ->assertOk()
            ->getContent();

        expect($tsv)->toContain("SQUAD\t{$squad->id}\tus\t1951.00\t7401.00\t5\t1");

        // At the cap, ten hours away earn nothing more.
        $enlistment->forceFill([
            'supplies' => 1500,
            'supplied_at' => Carbon::parse('2026-10-10 03:00:00'),
        ])->save();

        $this->actingAs($user)
            ->post('/reforger/war/upgrade', ['kind' => 'recruit'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $enlistment->refresh();
        $squad->refresh();

        expect($enlistment->supplies)->toBe(1470)
            ->and($squad->strength)->toBe(6);
    } finally {
        Carbon::setTestNow();
    }
});

it('refuses a recruit when the ai budget has no room for another soldier', function () {
    config(['reforger.ai_limit' => 4]);

    $user = User::factory()->create();
    $this->actingAs($user)
        ->post('/reforger/war/enlist', ['side' => WarSide::Fia->value])
        ->assertRedirect();

    $this->actingAs($user)
        ->post('/reforger/war/upgrade', ['kind' => 'recruit'])
        ->assertRedirect()
        ->assertSessionHasErrors('supplies');

    expect(WarSquad::query()->where('commander_id', $user->id)->orderBy('id')->first()->strength)->toBe(4);
});

it('trains a living soldier into a specialty and leaves their service in place', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->post('/reforger/war/enlist', ['side' => WarSide::Us->value])
        ->assertRedirect();

    $soldier = WarSoldier::query()->where('commander_id', $user->id)->orderBy('id')->first();
    $soldier->forceFill(['service' => 4])->save();

    $enlistment = WarEnlistment::query()->where('commander_id', $user->id)->first();
    $enlistment->forceFill(['supplies' => 200])->save();

    $this->actingAs($user)
        ->post('/reforger/war/upgrade', [
            'kind' => 'tune',
            'soldier_id' => $soldier->id,
            'role' => 'marksman',
            'aim' => 99,
            'grit' => 99,
            'fieldcraft' => 99,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $soldier->refresh();
    $enlistment->refresh();

    expect($soldier->role)->toBe('marksman')
        ->and($soldier->aim)->toBe(70)
        ->and($soldier->grit)->toBe(35)
        ->and($soldier->fieldcraft)->toBe(50)
        ->and($soldier->service)->toBe(4)
        ->and($soldier->spent)->toBe(55)
        ->and($enlistment->supplies)->toBe(145)
        ->and($soldier->fallen_at)->toBeNull();

    $this->actingAs($user)
        ->post('/reforger/war/upgrade', [
            'kind' => 'tune',
            'soldier_id' => $soldier->id,
            'role' => 'marksman',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('role');

    $this->actingAs($user)
        ->get('/reforger')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('war.soldiers.0.aim_effective', 74)
            ->where('war.soldiers.0.service', 4));
});

it('keeps a lost soldier personal and refunds nothing', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00'));

    try {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->post('/reforger/war/enlist', ['side' => WarSide::Us->value])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $enlistment = WarEnlistment::query()->where('commander_id', $user->id)->first();
        $enlistment->forceFill(['supplies' => 200])->save();

        $this->actingAs($user)
            ->post('/reforger/war/upgrade', [
                'kind' => 'order',
                'role' => 'marksman',
                'name' => 'Cole Voss',
                'note' => 'Writes home on Sundays',
                'aim' => 80,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $soldier = WarSoldier::query()->where('name', 'Cole Voss')->first();
        $enlistment->refresh();

        expect($soldier)->not->toBeNull()
            ->and($soldier->aim)->toBe(70)
            ->and($soldier->spent)->toBe(55)
            ->and($soldier->service)->toBe(0)
            ->and($enlistment->supplies)->toBe(145);

        $squad = WarSquad::query()->where('commander_id', $user->id)->orderBy('id')->first();

        Carbon::setTestNow(Carbon::parse('2026-10-10 15:00:00'));
        $enlistment->forceFill(['supplied_at' => now()])->save();
        $this->actingAs($user)->get('/reforger')->assertOk();

        // The mod names the dead soldier and keeps doing so until the export drops them.
        config(['reforger.bridge_secret' => 'war-secret']);
        $state = "SQUAD\t{$squad->id}\t1960.00\t7410.00\t4\nSOLDIER\t{$soldier->id}\tdead\nEND\n";
        foreach ([1, 2] as $_) {
            $this->call('POST', '/api/reforger/war/state', [], [], [], [
                'CONTENT_TYPE' => 'text/plain',
                'HTTP_X_REFORGER_BRIDGE_SECRET' => 'war-secret',
            ], $state)->assertOk();
        }

        $soldier->refresh();
        $squad->refresh();
        expect($squad->strength)->toBe(4);
        $enlistment->refresh();

        expect($soldier->fallen_at)->not->toBeNull()
            ->and($soldier->loss)->toBe('killed')
            ->and($soldier->note)->toBe('Writes home on Sundays')
            ->and($soldier->service)->toBe(3)
            ->and($enlistment->supplies)->toBe(158)
            ->and(WarSoldier::query()->whereNull('fallen_at')->count())->toBe(4);

        $living = WarSoldier::query()->whereNull('fallen_at')->orderBy('id')->first();
        $tsv = $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
            ->get('/api/reforger/war/orders?format=tsv')
            ->assertOk()
            ->getContent();

        expect($tsv)->toContain("SOLDIER\t{$living->id}\t")
            ->and($tsv)->not->toContain("SOLDIER\t{$soldier->id}\t");

        $this->actingAs($user)
            ->post('/reforger/war/upgrade', ['kind' => 'recruit'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $replacement = WarSoldier::query()->whereNull('fallen_at')->orderByDesc('id')->first();

        expect($replacement->service)->toBe(0)
            ->and($replacement->name)->not->toBe('Cole Voss');

        $this->actingAs($user)
            ->get('/reforger')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('war.fallen.0.name', 'Cole Voss')
                ->where('war.fallen.0.service', 3)
                ->has('war.soldiers', 5)
                ->has('war.markers', 5)
                ->where('war.markers', fn ($markers) => collect($markers)->where('name', 'Cole Voss')->isEmpty())
                ->where('war.events', fn ($events) => collect($events)->pluck('text')->contains(
                    fn (string $text) => str_contains($text, 'Cole Voss was killed'),
                )));
    } finally {
        Carbon::setTestNow();
    }
});

it('ends the war after seven real days and opens a new one on the next enlistment', function () {
    config(['reforger.bridge_secret' => 'war-secret']);
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00'));

    try {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->post('/reforger/war/enlist', ['side' => WarSide::Us->value])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $session = WarSession::query()->first();
        $squad = WarSquad::query()->where('commander_id', $user->id)->orderBy('id')->first();

        expect($session->ends_at->equalTo($session->starts_at->copy()->addDays(7)))->toBeTrue()
            ->and($session->closed_at)->toBeNull();

        Carbon::setTestNow(Carbon::parse('2026-10-17 12:01:00'));

        $this->actingAs($user)
            ->post('/reforger/war/upgrade', ['kind' => 'recruit'])
            ->assertRedirect()
            ->assertSessionHasErrors('supplies');

        $this->actingAs($user)
            ->post('/reforger/war/orders', ['x' => 2000, 'z' => 3000])
            ->assertRedirect()
            ->assertSessionHasErrors('side');

        $this->actingAs($user)
            ->get('/reforger')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('war.session.open', false)
                ->where('war.enlisted_side', 'us'));

        $closed = $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
            ->get('/api/reforger/war/orders?format=tsv')
            ->assertOk()
            ->getContent();

        expect($closed)->toStartWith("WEEK\t0\nHOME\t")
            ->and($closed)->not->toContain("SQUAD\t")
            ->and($session->fresh()->closed_at)->not->toBeNull();

        $this->actingAs($user)
            ->post('/reforger/war/enlist', ['side' => WarSide::Fia->value])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $open = WarSession::query()->whereNull('closed_at')->first();
        $next = WarSquad::query()->where('session_id', $open->id)->orderBy('id')->first();
        $tsv = $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
            ->get('/api/reforger/war/orders?format=tsv')
            ->assertOk()
            ->getContent();

        expect(WarSession::query()->count())->toBe(2)
            ->and($open->id)->not->toBe($session->id)
            ->and($next->side)->toBe(WarSide::Fia)
            ->and($tsv)->toContain("WEEK\t{$open->id}\n")
            ->and($tsv)->toContain("SQUAD\t{$next->id}\tfia\t")
            ->and($tsv)->not->toContain("SQUAD\t{$squad->id}\t");
    } finally {
        Carbon::setTestNow();
    }
});

it('gives a commander ten squads from the start: the men cost, the squads do not', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->post('/reforger/war/enlist', ['side' => WarSide::Us->value])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $enlistment = WarEnlistment::query()->where('commander_id', $user->id)->first();
    $enlistment->forceFill(['supplies' => 500])->save();
    $squads = WarSquad::query()->where('commander_id', $user->id)->orderBy('id')->get();
    expect($squads)->toHaveCount(10)
        ->and($squads->first()->strength)->toBe(4)
        ->and($squads->slice(1)->every(fn (WarSquad $s) => $s->strength === 0))->toBeTrue();

    // An empty squad takes no orders until it has a man on the island.
    $second = $squads->get(1);
    $this->actingAs($user)->post('/reforger/war/orders', ['x' => 2200, 'z' => 6400, 'squad_id' => $second->id])->assertSessionHasErrors('squad_id');

    $this->actingAs($user)->post('/reforger/war/upgrade', ['kind' => 'recruit', 'squad_id' => $second->id])->assertSessionHasNoErrors();
    $soldier = WarSoldier::query()->where('squad_id', $second->id)->first();
    expect($enlistment->refresh()->supplies)->toBe(470)
        ->and($second->refresh()->strength)->toBe(1)
        ->and($soldier->spent)->toBe(30);

    // Buying a squad is gone; naming one is free.
    $this->actingAs($user)->post('/reforger/war/upgrade', ['kind' => 'squad'])->assertSessionHasErrors('kind');
    $this->actingAs($user)->post("/reforger/war/squads/{$second->id}/name", ['name' => 'Night Owls'])->assertSessionHasNoErrors();
    $this->actingAs($user)->get('/reforger')->assertOk()->assertInertia(fn ($page) => $page
        ->where('war.squads.0.label', 'Alpha')
        ->where('war.squads.1.label', 'Night Owls')
        ->where('war.squads.2.label', 'Charlie'));

    $this->actingAs($user)
        ->post('/reforger/war/orders', ['x' => 2200, 'z' => 6400, 'squad_id' => $second->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(WarOrder::query()->where('squad_id', $second->id)->where('status', 'pending')->count())->toBe(1)
        ->and(WarOrder::query()->where('squad_id', $squads->first()->id)->count())->toBe(0);
});

it('lists each side its own vanilla soldier types', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->post('/reforger/war/enlist', ['side' => WarSide::Us->value])
        ->assertRedirect();

    $this->actingAs($user)
        ->get('/reforger')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('war.catalog.roles', function ($roles) {
            $byId = collect($roles)->keyBy('id');

            return $byId['marksman']['weapons']['us'] === 'M21 SWS'
                && $byId['marksman']['weapons']['ussr'] === 'SVD'
                && $byId['autorifle']['weapons']['fia'] === null
                && $byId['senior']['sides'] === ['ussr']
                && in_array('fia', $byId['plain']['sides'], true)
                && $byId['antitank']['weapons']['us'] === 'M72A3 LAW'
                && $byId['machinegun']['weapons']['fia'] === 'UK-59L';
        }));

    $this->actingAs($user)
        ->post('/reforger/war/upgrade', [
            'kind' => 'order',
            'role' => 'senior',
            'name' => 'Reed',
            'note' => '',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('role');

    $enlistment = WarEnlistment::query()->where('commander_id', $user->id)->first();
    $enlistment->forceFill(['supplies' => 400])->save();

    $this->actingAs($user)
        ->post('/reforger/war/upgrade', [
            'kind' => 'order',
            'role' => 'machinegun',
            'name' => 'Reed',
            'note' => 'The belt',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $gunner = WarSoldier::query()->where('commander_id', $user->id)->where('name', 'Reed')->first();
    expect($gunner->role)->toBe('machinegun')
        ->and($gunner->aim)->toBe(40)
        ->and($gunner->grit)->toBe(60)
        ->and($gunner->fieldcraft)->toBe(25)
        ->and($gunner->spent)->toBe(65);

    $fia = User::factory()->create();
    $this->actingAs($fia)
        ->post('/reforger/war/enlist', ['side' => WarSide::Fia->value])
        ->assertRedirect();

    $this->actingAs($fia)
        ->post('/reforger/war/upgrade', [
            'kind' => 'order',
            'role' => 'autorifle',
            'name' => 'Davor',
            'note' => '',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('role');
});

it('discharges a soldier by hand without calling it a death', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/reforger/war/enlist', ['side' => WarSide::Fia->value]);

    $soldier = WarSoldier::query()->orderBy('id')->first();
    $squad = WarSquad::query()->orderBy('id')->first();
    $squad->forceFill(['alive' => 2])->save();

    $this->actingAs($user)
        ->post('/reforger/war/upgrade', ['kind' => 'dismiss', 'soldier_id' => $soldier->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($soldier->refresh()->loss)->toBe('discharged')
        ->and($squad->refresh()->strength)->toBe(3);
});

it('ignores a half-written state file and unknown dead soldiers', function () {
    config(['reforger.bridge_secret' => 'war-secret']);

    $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
        ->call('POST', '/api/reforger/war/state', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_X_REFORGER_BRIDGE_SECRET' => 'war-secret',
        ], "SOLDIER\t999999\tdead\nEND\n")
        ->assertOk();

    $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
        ->call('POST', '/api/reforger/war/state', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_X_REFORGER_BRIDGE_SECRET' => 'war-secret',
        ], "SOLDIER\t12\talive\n")
        ->assertSessionHasErrors('state');
});

it('musters the second ussr squad inland of kamensk', function () {
    [$x, $z] = WarSide::Ussr->muster(1);

    expect($x)->toBeLessThan(11867.0)
        ->and($z)->toBeLessThan(10680.0);
});

it('exports each side home so the mod can place faction respawns', function () {
    config(['reforger.bridge_secret' => 'war-secret']);

    $tsv = $this->withHeader('X-Reforger-Bridge-Secret', 'war-secret')
        ->get('/api/reforger/war/orders?format=tsv')
        ->assertOk()
        ->getContent();

    expect($tsv)->toStartWith("WEEK\t0\n")
        ->and($tsv)->toContain("HOME\tus\t1951.00\t7401.00")
        ->and($tsv)->toContain("HOME\tussr\t11867.00\t10680.00")
        ->and($tsv)->toContain("HOME\tfia\t6642.00\t3907.00");
});

it('shows a commander only their side and the enemies their side has seen', function () {
    config(['reforger.bridge_secret' => 'war-secret']);

    $us = User::factory()->create();
    $ussr = User::factory()->create();
    $this->actingAs($us)->post('/reforger/war/enlist', ['side' => WarSide::Us->value]);
    $this->actingAs($ussr)->post('/reforger/war/enlist', ['side' => WarSide::Ussr->value]);
    $this->actingAs($ussr)->post('/reforger/war/orders', ['x' => 9000, 'z' => 9000]);

    $usSquad = WarSquad::query()->where('commander_id', $us->id)->orderBy('id')->first();
    $ussrSquad = WarSquad::query()->where('commander_id', $ussr->id)->orderBy('id')->first();

    $state = "SQUAD\t{$usSquad->id}\t1960.00\t7410.00\t4\n"
        ."CONTACT\t{$usSquad->id}\tussr\t2400.50\t7300.00\tinf\tseen\t12\n"
        ."CONTACT\t{$usSquad->id}\tussr\t2420.00\t7310.00\tinf\theard\t30\n"
        ."CONTACT\t{$usSquad->id}\tfia\t3000.00\t6000.00\tveh\theard\t5\n"
        ."END\n";
    $this->call('POST', '/api/reforger/war/state', [], [], [], [
        'CONTENT_TYPE' => 'text/plain',
        'HTTP_X_REFORGER_BRIDGE_SECRET' => 'war-secret',
    ], $state)->assertOk();

    expect(WarContact::query()->count())->toBe(3);

    $this->actingAs($us)
        ->get('/reforger')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('war.squads', 10)
            ->where('war.squads.0.id', $usSquad->id)
            ->has('war.markers', 4)
            ->has('war.contacts', 2)
            ->where('war.contacts.0.side', 'fia')
            ->where('war.contacts.0.kind', 'veh')
            ->where('war.contacts.1.side', 'ussr')
            ->where('war.contacts.1.how', 'seen')
            ->where('war.sides.1.squads', 1)
            ->where('war.events', fn ($events) => collect($events)->pluck('text')->contains('USSR put a squad on the island')
                && ! collect($events)->pluck('text')->contains(fn (string $text) => str_contains($text, 'ordered'))));

    $this->actingAs($ussr)
        ->get('/reforger')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('war.squads', 10)
            ->where('war.squads.0.id', $ussrSquad->id)
            ->has('war.contacts', 0)
            ->where('war.events', fn ($events) => collect($events)->pluck('text')->contains(
                fn (string $text) => str_contains($text, ': move at 090 090'),
            )));

    // A squad that stops reporting them leaves its last sightings on the map until they age out.
    $this->call('POST', '/api/reforger/war/state', [], [], [], [
        'CONTENT_TYPE' => 'text/plain',
        'HTTP_X_REFORGER_BRIDGE_SECRET' => 'war-secret',
    ], "SQUAD\t{$usSquad->id}\t1960.00\t7410.00\t4\nEND\n")->assertOk();

    expect(WarContact::query()->count())->toBe(3);
});

it('hides every squad from someone who has not enlisted', function () {
    $commander = User::factory()->create();
    $this->actingAs($commander)->post('/reforger/war/enlist', ['side' => WarSide::Fia->value]);

    $this->actingAs(User::factory()->create())
        ->get('/reforger')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('war.squads', 0)
            ->has('war.markers', 0)
            ->has('war.contacts', 0)
            ->where('war.sides.2.squads', 1)
            ->where('war.sides.2.commanders', 1));
});
