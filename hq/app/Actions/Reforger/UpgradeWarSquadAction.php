<?php

namespace App\Actions\Reforger;

use App\Support\WarLock;

use App\Enums\WarSupplyReason;
use App\Models\User;
use App\Models\WarEnlistment;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Models\WarSupplyEntry;
use App\Support\WarEconomy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spend supplies to put a soldier in one of your squads, retrain one, or raise a squad's training tier. */
final class UpgradeWarSquadAction
{
    /** What a man can be drilled in. */
    public const DRILLS = ['aim', 'grit', 'fieldcraft', 'endurance'];

    public function __construct(
        private readonly AccrueWarSuppliesAction $accrue,
        private readonly AccrueWarServiceAction $service,
        private readonly ResolveWarSessionAction $sessions,
    ) {}

    /**
     * @param  array{kind: string, role?: string|null, soldier_id?: int|null, name?: string|null, note?: string|null, squad_id?: int|null, stat?: string|null}  $input
     */
    public function apply(User $player, array $input): void
    {
        $this->sessions->closeDue();

        WarLock::run(fn () => DB::transaction(function () use ($player, $input) {

            $session = $this->sessions->current();
            if ($session === null) {
                throw ValidationException::withMessages([
                    'supplies' => ['This week is over.'],
                ]);
            }

            $enlistment = WarEnlistment::query()
                ->where('session_id', $session->id)
                ->where('commander_id', $player->id)
                ->lockForUpdate()
                ->first();

            if ($enlistment === null) {
                throw ValidationException::withMessages([
                    'supplies' => ['Enlist a side before upgrading the squad.'],
                ]);
            }

            $this->accrue->accrue($enlistment);

            $squads = WarSquad::query()
                ->where('session_id', $session->id)
                ->where('commander_id', $player->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($squads as $existing) {
                $this->service->accrue($existing);
            }

            $kind = $input['kind'];
            $squad = $this->pick($squads, isset($input['squad_id']) ? (int) $input['squad_id'] : null);
            if ($squad === null) {
                throw ValidationException::withMessages([
                    'supplies' => ['That squad is not in this week.'],
                ]);
            }
            if ($kind === 'train') {
                $this->train($enlistment, $squad);

                return;
            }

            if ($kind === 'drill') {
                $this->drill($enlistment, $squad, (int) $input['soldier_id'], (string) $input['stat']);

                return;
            }

            if ($kind === 'lose' || $kind === 'dismiss') {
                $this->lose($squad, (int) $input['soldier_id']);

                return;
            }

            if ($kind === 'remember') {
                $this->remember($squad, (int) $input['soldier_id'], (string) $input['name'], (string) ($input['note'] ?? ''));

                return;
            }

            if ($kind === 'tune') {
                $this->tune($enlistment, $squad, (int) $input['soldier_id'], (string) $input['role']);

                return;
            }

            if ($kind === 'recruit') {
                $taken = WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->pluck('name')->all();
                $this->order(
                    $enlistment,
                    $squad,
                    'rifleman',
                    WarEconomy::unusedName($squad->side->value, $taken),
                    '',
                );

                return;
            }

            // No name given: he comes with one of his side's own, as a recruit does.
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                $name = WarEconomy::unusedName($squad->side->value, WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->pluck('name')->all());
            }
            $this->order($enlistment, $squad, (string) $input['role'], $name, (string) ($input['note'] ?? ''));
        }, 3));
    }

    /**
     * @param  Collection<int, WarSquad>  $squads
     */
    private function pick(Collection $squads, ?int $squadId): ?WarSquad
    {
        if ($squadId === null || $squadId === 0) {
            return $squads->first();
        }

        return $squads->firstWhere('id', $squadId);
    }

    private function order(WarEnlistment $enlistment, WarSquad $squad, string $role, string $name, string $note): void
    {
        if ($squad->strength >= WarEconomy::maxStrength()) {
            throw ValidationException::withMessages([
                'supplies' => ['This squad is already at full strength.'],
            ]);
        }

        $limit = (int) config('reforger.ai_limit', 192);
        WarSquad::query()->where('session_id', $squad->session_id)->lockForUpdate()->get();
        $used = WarEconomy::fieldStrength($squad->session_id);
        if ($used + 1 > $limit) {
            throw ValidationException::withMessages([
                'supplies' => ['The AI budget on the server is full.'],
            ]);
        }

        $spec = WarEconomy::roleForSide($role, $enlistment->side);
        $this->debit($enlistment, $spec['cost'], WarSupplyReason::Recruit);
        $this->claimName($squad, $name);

        // A squad with nobody on the island starts again at home, where its new man appears.
        if (! $squad->soldiers()->whereNull('fallen_at')->where('location', 'field')->exists()) {
            [$squad->pos_x, $squad->pos_z] = $squad->side->muster();
        }

        WarSoldier::query()->create([
            'session_id' => $squad->session_id,
            'squad_id' => $squad->id,
            'commander_id' => $squad->commander_id,
            'role' => $role,
            'name' => trim($name),
            'note' => trim($note),
            'aim' => $spec['aim'],
            'grit' => $spec['grit'],
            'fieldcraft' => $spec['fieldcraft'],
            'spent' => $spec['cost'],
            'service' => 0,
            'joined_at' => now(),
            'service_at' => now(),
        ]);

        $squad->alive += 1;
        $this->syncHeadcount($squad);
    }

    /** Pay for a specialty. The numbers themselves come from hours served, not from the order. */
    private function tune(WarEnlistment $enlistment, WarSquad $squad, int $soldierId, string $role): void
    {
        $soldier = $this->soldier($squad, $soldierId);
        if ($soldier->role === $role) {
            throw ValidationException::withMessages([
                'role' => ['They already train as that.'],
            ]);
        }

        $spec = WarEconomy::roleForSide($role, $enlistment->side);
        $this->debit($enlistment, $spec['cost'], WarSupplyReason::Train);
        $soldier->spent += $spec['cost'];
        $soldier->role = $role;
        $soldier->aim = $spec['aim'];
        $soldier->grit = $spec['grit'];
        $soldier->fieldcraft = $spec['fieldcraft'];
        $soldier->save();
    }

    /**
     * The commander sends someone home. Half of what was spent on them comes back
     * (refund_discharged); their service does not. Deaths are not chosen here: the mod reports who was killed.
     */
    private function lose(WarSquad $squad, int $soldierId): void
    {
        $soldier = $this->soldier($squad, $soldierId);
        $soldier->fallen_at = now();
        $soldier->loss = 'discharged';
        $soldier->save();
        WarEconomy::refundLoss($soldier, 'discharged');
        $this->syncHeadcount($squad);
    }

    private function remember(WarSquad $squad, int $soldierId, string $name, string $note): void
    {
        $soldier = $this->soldier($squad, $soldierId);
        WarEconomy::assertPerson($name, $note);
        $this->claimName($squad, $name, $soldier->id);
        $soldier->name = trim($name);
        $soldier->note = trim($note);
        $soldier->save();
    }

    private function claimName(WarSquad $squad, string $name, ?int $exceptId = null): void
    {
        WarEconomy::assertPerson($name, '');
        $query = WarSoldier::query()
            ->where('squad_id', $squad->id)
            ->whereNull('fallen_at')
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))]);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ['Someone in the squad already has that name.'],
            ]);
        }
    }

    private function soldier(WarSquad $squad, int $soldierId): WarSoldier
    {
        $soldier = WarSoldier::query()
            ->whereKey($soldierId)
            ->where('squad_id', $squad->id)
            ->whereNull('fallen_at')
            ->lockForUpdate()
            ->first();

        if ($soldier === null) {
            throw ValidationException::withMessages([
                'soldier_id' => ['That soldier is not in your squad.'],
            ]);
        }

        return $soldier;
    }

    private function syncHeadcount(WarSquad $squad): void
    {
        $squad->strength = $squad->soldiers()->whereNull('fallen_at')->count();
        $squad->alive = min($squad->alive, $squad->strength);
        $squad->save();
    }

    private function train(WarEnlistment $enlistment, WarSquad $squad): void
    {
        $cost = WarEconomy::trainCost($squad->tier);
        if ($cost === null) {
            throw ValidationException::withMessages([
                'supplies' => ['This squad is already veteran.'],
            ]);
        }

        $this->debit($enlistment, $cost, WarSupplyReason::Train);
        $squad->tier += 1;
        $squad->save();
    }

    /**
     * Drill one man in one thing, drill_step points a time: aim (the game's aiming skill and his
     * weapon's recoil), fieldcraft (how soon and how far he spots), grit (vigilant on his own,
     * bleeds slower) or endurance (breath on the move, magazines carried). A specialist is made
     * by drilling his one or two things up; each step costs more.
     */
    private function drill(WarEnlistment $enlistment, WarSquad $squad, int $soldierId, string $stat): void
    {
        if (! in_array($stat, self::DRILLS, true)) {
            throw ValidationException::withMessages(['stat' => ['Aim, grit, fieldcraft or endurance.']]);
        }
        $soldier = WarSoldier::query()->whereKey($soldierId)->where('squad_id', $squad->id)->whereNull('fallen_at')->lockForUpdate()->first();
        if ($soldier === null) {
            throw ValidationException::withMessages(['soldier_id' => ['He is not in that squad.']]);
        }
        $cost = WarEconomy::drillCost($soldier, $stat);
        if ($cost === null) {
            throw ValidationException::withMessages(['stat' => ['He cannot get any better at that.']]);
        }

        $this->debit($enlistment, $cost, WarSupplyReason::Train);
        $soldier->{$stat} = min(WarEconomy::statMax(), (int) $soldier->{$stat} + (int) config('reforger.war.economy.drill_step', 10));
        $soldier->spent += $cost;
        $soldier->save();
    }

    private function debit(WarEnlistment $enlistment, int $cost, WarSupplyReason $reason): void
    {
        if ($enlistment->supplies < $cost) {
            throw ValidationException::withMessages([
                'supplies' => ['Not enough supplies.'],
            ]);
        }

        $enlistment->supplies -= $cost;
        $enlistment->save();

        WarSupplyEntry::query()->create([
            'session_id' => $enlistment->session_id,
            'commander_id' => $enlistment->commander_id,
            'amount' => -$cost,
            'reason' => $reason,
            'balance' => $enlistment->supplies,
        ]);
    }
}
