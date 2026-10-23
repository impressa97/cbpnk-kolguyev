<?php

namespace App\Actions\Reforger;

use App\Enums\WarOrderKind;
use App\Enums\WarOrderStatus;
use App\Enums\WarSupplyReason;
use App\Models\WarControl;
use App\Models\WarEnlistment;
use App\Models\WarOrder;
use App\Models\WarSoldier;
use App\Models\WarSupplyEntry;
use App\Support\WarEconomy;
use App\Support\WarOperations;
use App\Support\WarTerritory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Covert operations. A squad sent on recon, sabotage, or a raid walks in holding fire. Once it
 * is within the target radius and nobody is alerted or under fire, the clock starts; if it
 * stays unseen for the hold time, the operation succeeds. Being spotted resets the clock.
 * Numbers live in config/reforger.php war.operations.
 */
final class ResolveWarOperationsAction
{
    public function resolve(): void
    {
        DB::transaction(function () {
            $orders = WarOrder::query()
                ->with('squad')
                ->whereIn('kind', [WarOrderKind::Recon->value, WarOrderKind::Sabotage->value, WarOrderKind::Raid->value])
                ->whereIn('status', [WarOrderStatus::Pending, WarOrderStatus::Acked, WarOrderStatus::Done])
                ->whereNull('effect_until')
                ->lockForUpdate()
                ->get();

            foreach ($orders as $order) {
                $this->advance($order);
            }
        });
    }

    private function advance(WarOrder $order): void
    {
        $squad = $order->squad;
        if ($squad === null) {
            return;
        }

        $spec = WarOperations::spec($order->kind);
        $radius = (float) ($spec['radius'] ?? 75);
        $hold = (int) ($spec['hold'] ?? 120);

        $field = WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->where('location', 'field')->get();
        if ($field->isEmpty()) {
            $this->fail($order, 'No one left to carry it out.');

            return;
        }

        $near = hypot((float) $squad->pos_x - (float) $order->pos_x, (float) $squad->pos_z - (float) $order->pos_z) <= $radius;
        $unseen = $field->every(fn (WarSoldier $soldier) => in_array($soldier->threat, ['safe', 'vigilant'], true));

        // Sabotage runs in the game (the sapper, the charge) and reports through sabotageReport();
        // a squad that lost its sapper before planting has nobody to do it.
        if ($order->kind === WarOrderKind::Sabotage->value) {
            if ($order->arrived_at === null && ! WarOperations::staffed($order->kind, $field)) {
                $this->fail($order, sprintf('The squad has no %s left to carry it out.', $spec['needs'] ?? 'specialist'));
            }

            return;
        }

        // Recon is the game's Scout: done when the game completes the waypoint.
        if ($order->kind === WarOrderKind::Recon->value) {
            if ($order->status === WarOrderStatus::Done) {
                $this->recon($order, $spec);
            } elseif ($near && $order->note === '') {
                $order->note = 'Scouting the area.';
                $order->save();
            }

            return;
        }

        if (! $near) {
            return;
        }

        if (! $unseen) {
            if ($order->arrived_at !== null) {
                $order->arrived_at = null;
                $order->note = 'Spotted at the target. Waiting for it to go quiet.';
                $order->save();
            }

            return;
        }

        if ($order->arrived_at === null) {
            $order->arrived_at = now();
            $order->note = 'In position. Staying out of sight.';
            $order->save();

            return;
        }

        if ($order->arrived_at->diffInSeconds(now(), true) < $hold) {
            return;
        }

        match ($order->kind) {
            WarOrderKind::Recon->value => $this->recon($order, $spec),
            WarOrderKind::Sabotage->value => $this->sabotage($order, $spec),
            WarOrderKind::Raid->value => $this->raid($order, $spec),
            default => null,
        };
    }

    /**
     * What the game says about a sabotage: the sapper planting, the charge armed, gone off, or
     * disarmed. A charge that went off takes the relay down.
     */
    public function sabotageReport(int $orderId, string $phase, int $left): void
    {
        $order = WarOrder::query()->with('squad')->whereKey($orderId)->where('kind', WarOrderKind::Sabotage->value)->lockForUpdate()->first();
        if ($order === null || $order->effect_until !== null || $order->squad === null) {
            return;
        }

        match ($phase) {
            'approach' => $order->note = 'Moving to the tower.',
            'planting' => $order->note = "Sapper planting the charge: {$left} s.",
            'armed' => [$order->arrived_at ??= now(), $order->note = "Charge armed: {$left} s. Squad falling back."],
            'detonated' => $this->sabotage($order, WarOperations::spec(WarOrderKind::Sabotage)),
            'disarmed' => [$order->status = WarOrderStatus::Failed, $order->note = 'The charge was disarmed before it went off.'],
            default => null,
        };
        if ($order->isDirty()) {
            $order->save();
        }
    }

    /** @param  array<string, mixed>  $spec */
    private function recon(WarOrder $order, array $spec): void
    {
        $minutes = (int) ($spec['intel_minutes'] ?? 30);
        $note = "Area scouted. What they saw stays on the map for {$minutes} minutes.";

        // Recon aimed at an enemy-held point marks it for interdiction: it pays its holder nothing for a while.
        $point = $order->target === null ? null : WarTerritory::point($order->target);
        if ($point !== null) {
            $control = WarControl::query()->where('session_id', $order->session_id)->where('place', $point['id'])->first();
            if ($control !== null && $control->side !== null && $control->side !== $order->squad->side->value) {
                WarTerritory::interdict($order->session_id, $point['id']);
                $note .= " {$point['name']} is interdicted: it pays its holder nothing for a while.";
            }
        }

        $this->succeed($order, now()->addMinutes($minutes), $note);
    }

    /** @param  array<string, mixed>  $spec */
    private function sabotage(WarOrder $order, array $spec): void
    {
        $minutes = (int) ($spec['blackout_minutes'] ?? 60);
        $note = "The charge went off: relay down. The other sides are blind for {$minutes} minutes.";
        if ($order->target !== null && WarTerritory::interdict($order->session_id, $order->target) !== null) {
            $note .= ' Its holder loses the relay income for a while.';
        }

        $this->succeed($order, now()->addMinutes($minutes), $note);
    }

    /** @param  array<string, mixed>  $spec */
    private function raid(WarOrder $order, array $spec): void
    {
        $squad = $order->squad;
        $hours = (int) ($spec['cooldown_hours'] ?? 6);
        $stripped = WarOrder::query()
            ->where('kind', WarOrderKind::Raid->value)
            ->where('target', $order->target)
            ->whereKeyNot($order->id)
            ->whereNotNull('effect_until')
            ->where('updated_at', '>', now()->subHours($hours))
            ->whereHas('squad', fn ($query) => $query->where('side', $squad->side->value))
            ->exists();
        if ($stripped) {
            $this->succeed($order, now(), 'Your side already stripped this harbour. Nothing left to take.');

            return;
        }

        $plunder = (int) ($spec['plunder'] ?? 60);
        $loss = (int) ($spec['enemy_loss'] ?? 20);

        $mine = WarEnlistment::query()->where('session_id', $order->session_id)->where('commander_id', $order->commander_id)->lockForUpdate()->first();
        if ($mine !== null) {
            $gain = max(0, min($plunder, WarEconomy::supplyCap() - $mine->supplies));
            $mine->supplies += $gain;
            $mine->save();
            $this->ledger($mine, $gain, WarSupplyReason::Plunder);
        }

        $enemies = WarEnlistment::query()
            ->where('session_id', $order->session_id)
            ->where('side', '!=', $squad->side->value)
            ->lockForUpdate()
            ->get();
        foreach ($enemies as $enemy) {
            $taken = min($loss, $enemy->supplies);
            $enemy->supplies -= $taken;
            $enemy->save();
            $this->ledger($enemy, -$taken, WarSupplyReason::Raided);
        }

        $this->succeed($order, now(), "Harbour raided: +{$plunder} supplies, every enemy commander loses {$loss}.");
    }

    private function ledger(WarEnlistment $enlistment, int $amount, WarSupplyReason $reason): void
    {
        if ($amount === 0) {
            return;
        }

        WarSupplyEntry::query()->create([
            'session_id' => $enlistment->session_id,
            'commander_id' => $enlistment->commander_id,
            'amount' => $amount,
            'reason' => $reason,
            'balance' => $enlistment->supplies,
        ]);
    }

    private function succeed(WarOrder $order, Carbon $until, string $note): void
    {
        $order->effect_until = $until;
        $order->note = $note;
        $order->status = WarOrderStatus::Done;
        $order->save();
    }

    private function fail(WarOrder $order, string $note): void
    {
        $order->status = WarOrderStatus::Failed;
        $order->note = $note;
        $order->save();
    }
}
