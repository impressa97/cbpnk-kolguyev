<?php

namespace App\Actions\Reforger;

use App\Support\WarLock;

use App\Enums\WarSide;
use App\Enums\WarSupplyReason;
use App\Models\WarControl;
use App\Models\WarEnlistment;
use App\Models\WarObjective;
use App\Models\WarOrder;
use App\Models\WarSession;
use App\Models\WarSquad;
use App\Models\WarSupplyEntry;
use App\Models\WarVehicle;
use App\Support\WarTerritory;
use Illuminate\Support\Facades\DB;

/**
 * HQ objectives: bounties that point a side at the money. Each one is about territory income:
 * take a point (and its hourly income), bring cargo home, or deny an enemy its income.
 * Every check reads what the mod already reports. Config: war.objectives, war.territory.
 */
final class RunWarObjectivesAction
{
    public function run(): void
    {
        $session = WarSession::query()->whereNull('closed_at')->orderByDesc('id')->first();
        if ($session === null || ! $session->isOpen()) {
            return;
        }

        WarLock::run(fn () => DB::transaction(function () use ($session) {

            $sides = WarEnlistment::query()->where('session_id', $session->id)->get()->pluck('side')->unique(fn (WarSide $side) => $side->value);
            foreach ($sides as $side) {
                $this->tend($session, $side);
            }
        }));
    }

    private function tend(WarSession $session, WarSide $side): void
    {
        $open = WarObjective::query()->where('session_id', $session->id)->where('side', $side->value)->where('status', 'open')->lockForUpdate()->get();

        foreach ($open as $objective) {
            if ($objective->expires_at->isPast()) {
                $objective->status = 'expired';
                $objective->save();

                continue;
            }

            match ($objective->kind) {
                'capture' => $this->capture($objective),
                'supply_run' => $this->supplyRun($objective),
                'interdict', 'sabotage', 'raid' => $this->operation($objective),
                default => null,
            };
        }

        $still = WarObjective::query()->where('session_id', $session->id)->where('side', $side->value)->where('status', 'open')->get();
        for ($i = $still->count(); $i < (int) config('reforger.war.objectives.open', 3); $i++) {
            $issued = $this->issue($session, $side, $still->pluck('kind')->all(), $still->pluck('target')->all());
            if ($issued === null) {
                break;
            }
            $still->push($issued);
        }
    }

    /** Done when the side holds the point. The bounty goes to whoever's squad stood there last. */
    private function capture(WarObjective $objective): void
    {
        $control = WarControl::query()->where('session_id', $objective->session_id)->where('place', $objective->target)->first();
        if ($control === null) {
            return;
        }

        if ($control->claim_side === $objective->side->value && $objective->progress_at === null) {
            $objective->progress_at = $control->claim_since;
            $objective->squad_id = $this->nearestSquad($objective)?->id;
            $objective->save();
        }

        if ($control->side === $objective->side->value && $control->held_since !== null && $control->held_since->greaterThanOrEqualTo($objective->created_at)) {
            $claimer = $objective->squad_id === null ? null : WarSquad::query()->find($objective->squad_id);
            $this->complete($objective, $claimer->commander_id ?? $this->nearestSquad($objective)?->commander_id);
        }
    }

    /** Load at the harbour with a crewed vehicle, then bring it within reach of home. */
    private function supplyRun(WarObjective $objective): void
    {
        $radius = (float) ($this->spec('supply_run')['radius'] ?? 60);
        $vehicles = WarVehicle::query()
            ->where('session_id', $objective->session_id)
            ->where('side', $objective->side->value)
            ->where('status', 'fielded')
            ->where('crew', '>', 0)
            ->get();

        if ($objective->stage === null) {
            $loaded = $vehicles->first(fn (WarVehicle $v) => $this->near($v->pos_x, $v->pos_z, (float) $objective->pos_x, (float) $objective->pos_z, $radius));
            if ($loaded !== null) {
                $objective->stage = 'loaded';
                $objective->vehicle_id = $loaded->id;
                $objective->progress_at = now();
                $objective->save();
            }

            return;
        }

        $vehicle = $vehicles->firstWhere('id', $objective->vehicle_id);
        if ($vehicle === null) {
            // The truck burned or was sent back with the cargo aboard: start over.
            $objective->stage = null;
            $objective->vehicle_id = null;
            $objective->progress_at = null;
            $objective->save();

            return;
        }

        [$hx, $hz] = $objective->side->muster();
        if ($this->near($vehicle->pos_x, $vehicle->pos_z, $hx, $hz, 150)) {
            $this->complete($objective, $vehicle->commander_id);
            $this->share($objective, $vehicle->commander_id);
        }
    }

    /** Interdiction is a recon operation on the point; sabotage and raids are their own operations. */
    private function operation(WarObjective $objective): void
    {
        $kind = $objective->kind === 'interdict' ? 'recon' : $objective->kind;
        $order = WarOrder::query()
            ->where('session_id', $objective->session_id)
            ->where('kind', $kind)
            ->where('target', $objective->target)
            ->whereNotNull('effect_until')
            ->where('updated_at', '>=', $objective->created_at)
            ->whereHas('squad', fn ($q) => $q->where('side', $objective->side->value))
            ->orderByDesc('updated_at')
            ->first();

        if ($order !== null) {
            $this->complete($objective, $order->commander_id);
        }
    }

    private function complete(WarObjective $objective, ?int $playerId): void
    {
        $objective->status = 'done';
        $objective->commander_id = $playerId;
        $objective->done_at = now();
        $objective->save();

        if ($playerId !== null) {
            $this->pay($objective, $playerId, $objective->reward);
        }
    }

    /** A supply run feeds the whole side, not only the driver. */
    private function share(WarObjective $objective, int $driverId): void
    {
        $share = (int) ($this->spec('supply_run')['share'] ?? 20);
        $others = WarEnlistment::query()
            ->where('session_id', $objective->session_id)
            ->where('side', $objective->side->value)
            ->where('commander_id', '!=', $driverId)
            ->pluck('commander_id');
        foreach ($others as $playerId) {
            $this->pay($objective, (int) $playerId, $share);
        }
    }

    /** Bounties are earned, so they are paid in full even above the passive income cap. */
    private function pay(WarObjective $objective, int $playerId, int $amount): void
    {
        $enlistment = WarEnlistment::query()->where('session_id', $objective->session_id)->where('commander_id', $playerId)->lockForUpdate()->first();
        if ($enlistment === null || $amount <= 0) {
            return;
        }
        $enlistment->supplies += $amount;
        $enlistment->save();
        WarSupplyEntry::query()->create([
            'session_id' => $objective->session_id,
            'commander_id' => $playerId,
            'amount' => $amount,
            'reason' => WarSupplyReason::Objective,
            'balance' => $enlistment->supplies,
        ]);
    }

    /** The commander's own squad on the point beats an HQ squad: the bounty is for leading it. */
    private function nearestSquad(WarObjective $objective): ?WarSquad
    {
        $radius = (float) config('reforger.war.territory.radius', 150);

        return WarSquad::query()
            ->where('session_id', $objective->session_id)
            ->where('side', $objective->side->value)
            ->get()
            ->filter(fn (WarSquad $squad) => $this->near($squad->pos_x, $squad->pos_z, (float) $objective->pos_x, (float) $objective->pos_z, $radius))
            ->sortBy(fn (WarSquad $squad) => $squad->managed ? 1 : 0)
            ->first();
    }

    /**
     * @param  list<string>  $busyKinds
     * @param  list<string>  $busyTargets
     */
    private function issue(WarSession $session, WarSide $side, array $busyKinds, array $busyTargets): ?WarObjective
    {
        $kinds = array_keys(config('reforger.war.objectives.kinds', []));
        shuffle($kinds);
        usort($kinds, fn (string $a, string $b) => (int) in_array($a, $busyKinds, true) <=> (int) in_array($b, $busyKinds, true));
        $control = WarControl::query()->where('session_id', $session->id)->pluck('side', 'place');

        foreach ($kinds as $kind) {
            $place = $this->pickPlace($kind, $side, $busyTargets, $control->all());
            if ($place === null) {
                continue;
            }

            return WarObjective::query()->create([
                'session_id' => $session->id,
                'side' => $side->value,
                'kind' => $kind,
                'target' => (string) $place['id'],
                'title' => $this->title($kind, $place, $side, $control->get($place['id'])),
                'pos_x' => (float) $place['x'],
                'pos_z' => (float) $place['z'],
                'reward' => (int) $this->spec($kind)['reward'],
                'expires_at' => now()->addHours((int) config('reforger.war.objectives.expire_hours', 6)),
            ]);
        }

        return null;
    }

    /**
     * @param  list<string>  $busyTargets
     * @param  array<string, string|null>  $holders
     * @return array<string, mixed>|null
     */
    private function pickPlace(string $kind, WarSide $side, array $busyTargets, array $holders): ?array
    {
        $kinds = $this->spec($kind)['places'] ?? [];
        $candidates = array_values(array_filter(WarTerritory::points(), function (array $place) use ($kind, $kinds, $side, $busyTargets, $holders) {
            if (! in_array($place['kind'], $kinds, true) || in_array($place['id'], $busyTargets, true)) {
                return false;
            }
            $holder = $holders[$place['id']] ?? null;

            return match ($kind) {
                'capture' => $holder !== $side->value,
                // Interdiction only makes sense where someone else is earning.
                'interdict' => $holder !== null && $holder !== $side->value,
                'sabotage', 'raid' => $holder !== $side->value,
                // Cargo comes off a harbour your side holds; with none held, any harbour will do.
                'supply_run' => $holder === $side->value || ! in_array($side->value, $holders, true),
                default => false,
            };
        }));

        return $candidates === [] ? null : $candidates[array_rand($candidates)];
    }

    /** @param  array<string, mixed>  $place */
    private function title(string $kind, array $place, WarSide $side, ?string $holder): string
    {
        $name = (string) $place['name'];
        $income = WarTerritory::income((string) $place['kind']);
        $owner = $holder === null ? null : strtoupper($holder);

        return match ($kind) {
            'capture' => "Take {$name} · +{$income}/h for every {$side->label()} commander",
            'supply_run' => "Bring cargo from {$name} home by vehicle",
            'interdict' => "Scout {$name} · {$owner} loses {$income}/h",
            'sabotage' => "Knock out the {$name}".($owner !== null && $owner !== strtoupper($side->value) ? " · {$owner} loses {$income}/h and goes blind" : ' · the other sides go blind'),
            'raid' => "Raid {$name} · plunder, enemy commanders lose supplies",
            default => $name,
        };
    }

    /** @return array<string, mixed> */
    private function spec(string $kind): array
    {
        return config('reforger.war.objectives.kinds.'.$kind, []);
    }

    private function near(mixed $x, mixed $z, float $tx, float $tz, float $radius): bool
    {
        return hypot((float) $x - $tx, (float) $z - $tz) <= $radius;
    }
}
