<?php

namespace App\Actions\Reforger;

use App\Support\WarLock;

use App\Enums\WarOrderStatus;
use App\Enums\WarSupplyReason;
use App\Models\User;
use App\Models\WarEnlistment;
use App\Models\WarOrder;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Models\WarSupplyEntry;
use App\Models\WarSupport;
use App\Support\WarEconomy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ask HQ for fire on a mark: mortar HE, smoke, a flare. Also sends recovered soldiers from the
 * reserve back to their squad, and cancels a fire mission that has not happened yet (full
 * refund). HQ flies nobody out and drops nothing: wounded men and ammunition are logistics on
 * the ground (SupplyWarSquadAction).
 */
final class RequestWarSupportAction
{
    public function __construct(
        private readonly ResolveWarSessionAction $sessions,
        private readonly AccrueWarSuppliesAction $accrue,
    ) {}

    /**
     * @param  array{kind: string, squad_id?: int|null, x?: float|null, z?: float|null}  $input
     */
    public function request(User $player, array $input): ?WarSupport
    {
        $this->sessions->closeDue();

        return WarLock::run(fn () => DB::transaction(function () use ($player, $input) {

            $session = $this->sessions->current();
            if ($session === null) {
                throw ValidationException::withMessages(['support' => ['This week is over.']]);
            }

            $enlistment = WarEnlistment::query()
                ->where('session_id', $session->id)
                ->where('commander_id', $player->id)
                ->lockForUpdate()
                ->first();
            if ($enlistment === null) {
                throw ValidationException::withMessages(['support' => ['Enlist a side first.']]);
            }

            $this->accrue->accrue($enlistment);
            $kind = $input['kind'];

            if ($kind === 'deploy') {
                $this->deploy($player, $session->id, $this->squad($player, $session->id, $input['squad_id'] ?? null));

                return null;
            }

            $spec = WarEconomy::support($kind);
            $x = (float) ($input['x'] ?? -1);
            $z = (float) ($input['z'] ?? -1);
            $size = (float) config('reforger.war.world_size', 12800);
            if ($x < 0 || $z < 0 || $x > $size || $z > $size) {
                throw ValidationException::withMessages(['x' => ['Mark a point on Kolguyev for the fire mission.']]);
            }
            $this->assertClear($session->id, $enlistment->side->value, $x, $z, $spec['danger_close']);

            $this->assertReady($player, $kind, $spec['cooldown']);
            $this->debit($enlistment, $spec['cost'], $spec['name']);

            return WarSupport::query()->create([
                'session_id' => $session->id,
                'commander_id' => $player->id,
                'squad_id' => null,
                'kind' => $kind,
                'status' => 'requested',
                'pos_x' => round($x, 2),
                'pos_z' => round($z, 2),
                'cost' => $spec['cost'],
                'ready_at' => now()->addSeconds($spec['delay']),
            ]);
        }, 3));
    }

    public function cancel(User $player, int $supportId): void
    {
        DB::transaction(function () use ($player, $supportId) {
            $support = WarSupport::query()
                ->whereKey($supportId)
                ->where('commander_id', $player->id)
                ->lockForUpdate()
                ->first();
            if ($support === null || $support->status !== 'requested' || $support->ready_at->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['support' => ['That request is already under way.']]);
            }

            $enlistment = WarEnlistment::query()
                ->where('session_id', $support->session_id)
                ->where('commander_id', $player->id)
                ->lockForUpdate()
                ->first();

            $support->status = 'cancelled';
            $support->done_at = now();
            $support->save();

            if ($enlistment !== null && $support->cost > 0) {
                $enlistment->supplies += $support->cost;
                $enlistment->save();
                WarSupplyEntry::query()->create([
                    'session_id' => $support->session_id,
                    'commander_id' => $player->id,
                    'amount' => $support->cost,
                    'reason' => WarSupplyReason::Refund,
                    'balance' => $enlistment->supplies,
                ]);
            }
        }, 3);
    }

    private function squad(User $player, int $sessionId, mixed $squadId): WarSquad
    {
        $squad = WarSquad::query()
            ->where('session_id', $sessionId)
            ->where('commander_id', $player->id)
            ->whereKey((int) $squadId)
            ->lockForUpdate()
            ->first();
        if ($squad === null) {
            throw ValidationException::withMessages(['squad_id' => ['Pick one of your squads.']]);
        }

        return $squad;
    }

    /** HQ will not drop rounds on its own people. */
    private function assertClear(int $sessionId, string $side, float $x, float $z, int $radius): void
    {
        if ($radius <= 0) {
            return;
        }

        $close = WarSquad::query()
            ->where('session_id', $sessionId)
            ->where('side', $side)
            ->whereHas('soldiers', fn ($query) => $query->whereNull('fallen_at')->where('location', 'field'))
            ->get()
            ->first(fn (WarSquad $squad) => hypot((float) $squad->pos_x - $x, (float) $squad->pos_z - $z) < $radius);

        if ($close !== null) {
            throw ValidationException::withMessages([
                'x' => ["Danger close: one of your side's squads is within {$radius} m of that mark."],
            ]);
        }
    }

    private function assertReady(User $player, string $kind, int $cooldown): void
    {
        if ($cooldown <= 0) {
            return;
        }

        $last = WarSupport::query()
            ->where('commander_id', $player->id)
            ->where('kind', $kind)
            ->whereNotIn('status', ['cancelled'])
            ->where('created_at', '>', now()->subSeconds($cooldown))
            ->orderByDesc('created_at')
            ->first();

        if ($last !== null) {
            $wait = (int) ceil($last->created_at->copy()->addSeconds($cooldown)->diffInSeconds(now(), true) / 60);
            throw ValidationException::withMessages([
                'support' => ["HQ needs about {$wait} more minutes before it can do that again."],
            ]);
        }
    }

    /**
     * Recovered soldiers go back: the game puts them at the side's spawn and they make their own
     * way to the squad, the way a new man joins a group in the field.
     */
    private function deploy(User $player, int $sessionId, WarSquad $squad): void
    {
        $ready = WarSoldier::query()
            ->where('squad_id', $squad->id)
            ->whereNull('fallen_at')
            ->where('location', 'reserve')
            ->where(fn ($query) => $query->whereNull('ready_at')->orWhere('ready_at', '<=', now()))
            ->lockForUpdate()
            ->get();
        if ($ready->isEmpty()) {
            throw ValidationException::withMessages(['support' => ['Nobody in reserve is fit to go back yet.']]);
        }

        $inField = WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->where('location', 'field')->count();
        [$hx, $hz] = $squad->side->muster();

        $limit = (int) config('reforger.ai_limit', 192);
        if (WarEconomy::fieldStrength($sessionId) + $ready->count() > $limit) {
            throw ValidationException::withMessages(['support' => ['The AI budget on the server is full.']]);
        }

        foreach ($ready as $soldier) {
            $soldier->location = 'field';
            $soldier->ready_at = null;
            $soldier->health = 100;
            $soldier->stamina = 100;
            $soldier->bleeding = false;
            $soldier->down = false;
            $soldier->threat = 'safe';
            $soldier->mags = null;
            $soldier->in_vehicle = false;
            // Where the game will put him: the spawn, until it reports him.
            $soldier->pos_x = null;
            $soldier->pos_z = null;
            $soldier->condition_at = now();
            $soldier->save();
        }

        if ($inField === 0) {
            $squad->pos_x = $hx;
            $squad->pos_z = $hz;
        }
        $squad->save();
    }

    private function debit(WarEnlistment $enlistment, int $cost, string $what): void
    {
        if ($cost <= 0) {
            return;
        }
        if ($enlistment->supplies < $cost) {
            throw ValidationException::withMessages(['supplies' => ["Not enough supplies for {$what}."]]);
        }

        $enlistment->supplies -= $cost;
        $enlistment->save();
        WarSupplyEntry::query()->create([
            'session_id' => $enlistment->session_id,
            'commander_id' => $enlistment->commander_id,
            'amount' => -$cost,
            'reason' => WarSupplyReason::Support,
            'balance' => $enlistment->supplies,
        ]);
    }

    /** Open move orders for a squad that just left the island are moot. */
    public static function standDownOrders(int $squadId): void
    {
        WarOrder::query()
            ->where('squad_id', $squadId)
            ->whereIn('status', [WarOrderStatus::Pending, WarOrderStatus::Acked])
            ->update(['status' => WarOrderStatus::Superseded]);
    }
}
