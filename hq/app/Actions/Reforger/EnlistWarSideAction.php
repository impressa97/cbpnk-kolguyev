<?php

namespace App\Actions\Reforger;

use App\Support\WarLock;

use App\Enums\WarSide;
use App\Enums\WarSupplyReason;
use App\Models\User;
use App\Models\WarEnlistment;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Models\WarSupplyEntry;
use App\Support\WarEconomy;
use App\Support\WarSquads;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Pick a side for the continuous Kolguyev war and stand up one AI squad. */
final class EnlistWarSideAction
{
    public function __construct(
        private readonly ResolveWarSessionAction $sessions,
    ) {}

    public function enlist(User $player, WarSide $side): WarEnlistment
    {
        $this->sessions->closeDue();

        return WarLock::run(fn () => DB::transaction(function () use ($player, $side) {

            $session = $this->sessions->current() ?? $this->sessions->start();

            $existing = WarEnlistment::query()
                ->where('session_id', $session->id)
                ->where('commander_id', $player->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->side !== $side) {
                    throw ValidationException::withMessages([
                        'side' => ['Your side is already set for this war.'],
                    ]);
                }

                return $existing;
            }

            $strength = (int) config('reforger.war.squad_strength', 4);
            $limit = (int) config('reforger.ai_limit', 192);
            WarSquad::query()->where('session_id', $session->id)->lockForUpdate()->get();
            $used = WarEconomy::fieldStrength($session->id);
            if ($used + $strength > $limit) {
                throw ValidationException::withMessages([
                    'side' => ['The AI budget on the server is full.'],
                ]);
            }

            [$x, $z] = $side->muster();
            $supplies = WarEconomy::startingSupplies();

            $squad = WarSquad::query()->create([
                'session_id' => $session->id,
                'commander_id' => $player->id,
                'side' => $side,
                'strength' => $strength,
                'alive' => $strength,
                'tier' => 0,
                'pos_x' => $x,
                'pos_z' => $z,
            ]);

            $rifle = WarEconomy::role('rifleman');
            $taken = [];
            for ($i = 0; $i < $strength; $i++) {
                $name = WarEconomy::unusedName($side->value, $taken);
                $taken[] = $name;
                WarSoldier::query()->create([
                    'session_id' => $session->id,
                    'squad_id' => $squad->id,
                    'commander_id' => $player->id,
                    'role' => 'rifleman',
                    'name' => $name,
                    'note' => '',
                    'aim' => $rifle['aim'],
                    'grit' => $rifle['grit'],
                    'fieldcraft' => $rifle['fieldcraft'],
                    'spent' => 0,
                    'service' => 0,
                    'joined_at' => now(),
                    'service_at' => now(),
                ]);
            }

            $enlistment = WarEnlistment::query()->create([
                'session_id' => $session->id,
                'commander_id' => $player->id,
                'side' => $side,
                'supplies' => $supplies,
                'supplied_at' => now(),
            ]);

            WarSupplyEntry::query()->create([
                'session_id' => $session->id,
                'commander_id' => $player->id,
                'amount' => $supplies,
                'reason' => WarSupplyReason::Grant,
                'balance' => $supplies,
            ]);

            // The other squads are his from the start, empty: men are what he pays for.
            WarSquads::ensure($enlistment);

            return $enlistment;
        }));
    }
}
