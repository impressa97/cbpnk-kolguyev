<?php

namespace App\Actions\Reforger;

use App\Enums\WarOrderKind;
use App\Enums\WarOrderStatus;
use App\Enums\WarSide;
use App\Models\WarContact;
use App\Models\WarOrder;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Models\WarSupport;
use App\Models\WarVehicle;
use App\Support\WarEconomy;
use App\Support\WarOperations;
use Illuminate\Support\Collection;

/**
 * Snapshot the mod should hold on the island: homes, squads, their vehicles, open orders,
 * soldiers in the field (the reserve at base is left out, so the mod takes them off the island),
 * HQ fire missions whose time has come, and squads rearming at home or at a truck.
 */
final class ExportWarOrdersAction
{
    public function __construct(
        private readonly ResolveWarSessionAction $sessions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $this->sessions->closeDue();
        $session = $this->sessions->current();
        if ($session === null) {
            return [
                'session_id' => 0,
                'homes' => $this->homes(),
                'squads' => [],
                'vehicles' => [],
                'orders' => [],
                'soldiers' => [],
                'fire' => [],
                'supply' => [],
                'service' => [],
            ];
        }

        // Only squads with someone on the island are in the game; an empty one is just a name.
        $squads = WarSquad::query()
            ->where('session_id', $session->id)
            ->whereHas('soldiers', fn ($q) => $q->whereNull('fallen_at')->where('location', 'field'))
            ->orderBy('id')
            ->get();
        $tiers = $squads->pluck('tier', 'id');
        // What each side has seen lately, for squads left on normal readiness.
        $sightings = WarContact::query()->with('squad')->where('session_id', $session->id)
            ->where('seen_at', '>=', now()->subSeconds((int) config('reforger.war.alert_seen', 120)))->get();
        $orders = WarOrder::query()
            ->with('squad')
            ->where('session_id', $session->id)
            ->whereIn('status', [WarOrderStatus::Pending, WarOrderStatus::Acked])
            ->orderBy('id')
            ->get();
        $soldiers = WarSoldier::query()
            ->where('session_id', $session->id)
            ->whereNull('fallen_at')
            ->where('location', 'field')
            ->orderBy('id')
            ->get();
        $vehicles = WarVehicle::query()
            ->where('session_id', $session->id)
            ->whereIn('status', WarVehicle::ACTIVE)
            ->orderBy('id')
            ->get();
        $support = WarSupport::query()
            ->where('session_id', $session->id)
            ->whereIn('kind', ['smoke', 'illum', 'fire_he_small', 'fire_he_medium', 'resupply', 'service'])
            ->where('status', 'requested')
            ->where('ready_at', '<=', now())
            ->orderBy('id')
            ->get();

        return [
            'session_id' => $session->id,
            'homes' => $this->homes(),
            'squads' => $squads->map(fn (WarSquad $squad) => [
                'id' => $squad->id,
                'side' => $squad->side->value,
                'x' => (float) $squad->pos_x,
                'z' => (float) $squad->pos_z,
                'strength' => $squad->strength,
                'tier' => $squad->tier,
                'combat' => $squad->combat,
                'alert' => $this->alertFor($squad, $sightings),
                'formation' => $squad->formation,
                'pace' => $squad->pace_order,
            ])->values()->all(),
            'vehicles' => $vehicles->map(fn (WarVehicle $vehicle) => [
                'id' => $vehicle->id,
                'side' => $vehicle->side->value,
                'kind' => $vehicle->kind,
                'prefab' => WarEconomy::vehicle($vehicle->kind, $vehicle->side)['prefab'],
            ])->values()->all(),
            'orders' => $orders->map(fn (WarOrder $order) => [
                'id' => $order->id,
                'squad_id' => $order->squad_id,
                // Whom the medic treats, or which vehicle to get into.
                // Whom the medic treats, which vehicle to get into, which squad to follow, or how
                // long a scout holds.
                'target_id' => $order->soldier_id ?? $order->vehicle_id ?? $order->follow_squad_id ?? $order->enemy_id
                    ?? ($order->kind === WarOrderKind::Recon->value ? (int) (WarOperations::spec(WarOrderKind::Recon)['hold'] ?? 600) : 0),
                'charge' => $order->kind === WarOrderKind::Sabotage->value
                    ? [(int) (WarOperations::spec(WarOrderKind::Sabotage)['plant'] ?? 20), (int) (WarOperations::spec(WarOrderKind::Sabotage)['fuze'] ?? 45)]
                    : null,
                'route' => $order->route ?? [],
                'side' => $order->squad?->side->value ?? 'us',
                'x' => (float) $order->pos_x,
                'z' => (float) $order->pos_z,
                'x2' => $order->pos2_x !== null ? (float) $order->pos2_x : null,
                'z2' => $order->pos2_z !== null ? (float) $order->pos2_z : null,
                'kind' => $order->kind,
            ])->values()->all(),
            // Skill goes to the game's own AI skill levels as trained; the site adds nothing of its own.
            'soldiers' => $soldiers->map(fn (WarSoldier $soldier) => [
                'id' => $soldier->id,
                'squad_id' => $soldier->squad_id,
                'role' => $soldier->role,
                'aim' => $this->stat(WarEconomy::effectiveStat($soldier->aim, WarEconomy::experience($soldier))),
                'grit' => $this->stat(WarEconomy::effectiveStat($soldier->grit, WarEconomy::experience($soldier))),
                'fieldcraft' => $this->stat(WarEconomy::effectiveStat($soldier->fieldcraft, WarEconomy::experience($soldier))),
                'endurance' => $this->stat(WarEconomy::effectiveStat($soldier->endurance ?? 40, WarEconomy::experience($soldier))),
                'service' => $soldier->service,
                'stance' => $soldier->stance_order,
                'pace' => $soldier->pace_order,
            ])->values()->all(),
            'fire' => $support->filter(fn (WarSupport $row) => $row->isFire())->map(fn (WarSupport $row) => [
                'id' => $row->id,
                'kind' => $row->kind === 'fire_he_small' ? 'he_small' : ($row->kind === 'fire_he_medium' ? 'he_medium' : $row->kind),
                'x' => (float) $row->pos_x,
                'z' => (float) $row->pos_z,
            ])->values()->all(),
            'supply' => $support->where('kind', 'resupply')->map(fn (WarSupport $row) => [
                'id' => $row->id,
                'squad_id' => $row->squad_id,
                'magazines' => (int) config('reforger.war.logistics.magazines', 6),
            ])->values()->all(),
            'service' => $support->where('kind', 'service')->filter(fn (WarSupport $row) => $row->vehicle_id !== null)->map(fn (WarSupport $row) => [
                'id' => $row->id,
                'vehicle_id' => $row->vehicle_id,
            ])->values()->all(),
        ];
    }

    private function stat(int $value): int
    {
        return max(WarEconomy::statMin(), min(WarEconomy::statMax(), $value));
    }

    /**
     * Each side's respawn and muster ground. The mod puts a vanilla faction spawn point there.
     *
     * @return list<array{side: string, x: float, z: float}>
     */
    private function homes(): array
    {
        return array_map(function (WarSide $side) {
            [$x, $z] = $side->muster();

            return ['side' => $side->value, 'x' => $x, 'z' => $z];
        }, WarSide::cases());
    }

    /**
     * A squad left on normal goes on alert by itself once its side has seen the enemy within
     * war.alert_radius of it in the last war.alert_seen seconds (radio reports): vigilant, it
     * is not surprised walking into them. The commander's alert and ready stand as set.
     *
     * @param  Collection<int, WarContact>  $sightings
     */
    private function alertFor(WarSquad $squad, Collection $sightings): string
    {
        if ($squad->alert !== 'normal') {
            return $squad->alert;
        }
        $radius = (float) config('reforger.war.alert_radius', 500);
        $near = $sightings->contains(fn (WarContact $c) => $c->squad?->side === $squad->side && $c->side !== $squad->side
            && hypot((float) $c->pos_x - (float) $squad->pos_x, (float) $c->pos_z - (float) $squad->pos_z) <= $radius);

        return $near ? 'alert' : 'normal';
    }
}
