<?php

namespace App\Actions\Reforger;

use App\Enums\WarOrderKind;
use App\Enums\WarOrderStatus;
use App\Enums\WarSupplyReason;
use App\Models\WarEnlistment;
use App\Models\WarOrder;
use App\Models\WarSession;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Models\WarSupplyEntry;
use App\Models\WarTask;
use App\Support\WarCondition;
use App\Support\WarEconomy;
use App\Support\WarLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HQ only does supporting work: securing ground (hold a point, patrol a stretch of your own
 * rear), supply, evacuation, and fire on call. It never attacks and never runs operations;
 * recon, sabotage, and raids are for the squads the commander leads.
 *
 * HQ runs the squads it raised for standing tasks, so the commander does not have to:
 *
 *   no squad yet        raise one at home from the HQ budget (riflemen, a gunner, a medic)
 *   anyone hurt         the squad's medic treats him, as the game's AI does by itself
 *   worn out            (morale under hq.rotate_morale, or fewer than half left) walk home; there
 *                       the whole squad goes to the reserve (SupplyWarSquadAction::arrivedHome)
 *   all at base         wait until they are fit, refill losses (a rifleman's price each), send them back
 *   on the island       hold: defend the point. patrol: walk A → B → A, a new leg when one is done
 *   time up or cancelled   the squad is brought home and stood down
 */
final class RunWarHqAction
{
    public function run(): void
    {
        DB::transaction(function () {
            $tasks = WarTask::query()
                ->whereIn('status', ['active', 'cancelled'])
                ->whereIn('session_id', WarSession::query()->whereNull('closed_at')->pluck('id'))
                ->lockForUpdate()
                ->get();

            foreach ($tasks as $task) {
                // HQ works on request and for a while: when the time is up it calls the squad in.
                $due = $task->status === 'active' && $task->ends_at !== null && $task->ends_at->isPast();
                $task->status === 'cancelled' || $due ? $this->standDown($task, $due) : $this->tend($task);
            }
        });
    }

    private function tend(WarTask $task): void
    {
        $enlistment = WarEnlistment::query()->where('session_id', $task->session_id)->where('commander_id', $task->commander_id)->lockForUpdate()->first();
        if ($enlistment === null) {
            return;
        }

        $squad = WarSquad::query()->where('task_id', $task->id)->lockForUpdate()->first();
        if ($squad === null) {
            $this->raise($task, $enlistment);

            return;
        }

        $living = WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->lockForUpdate()->get();
        $field = $living->where('location', 'field')->values();

        if ($field->isEmpty()) {
            $this->refit($task, $squad, $living, $enlistment);

            return;
        }

        $homeward = WarOrder::query()
            ->where('squad_id', $squad->id)
            ->where('kind', WarOrderKind::Rest->value)
            ->whereIn('status', [WarOrderStatus::Pending, WarOrderStatus::Acked])
            ->exists();
        if ($homeward) {
            return;
        }
        $worn = $field->count() * 2 < $task->size
            || (float) $field->avg(fn (WarSoldier $s) => (float) $s->morale) < (float) config('reforger.war.hq.rotate_morale', 35);
        if ($worn) {
            [$hx, $hz] = $squad->side->muster();
            $this->order($task, $squad, WarOrderKind::Rest, $hx, $hz);
            $this->note($task, 'Worn out. Walking home to rotate.');

            return;
        }

        $this->keepOrders($task, $squad);
    }

    /** Raise the task's squad at home from the HQ budget. */
    private function raise(WarTask $task, WarEnlistment $enlistment): void
    {
        $budget = (int) config('reforger.war.hq.budget', 8);
        $inUse = WarSoldier::query()
            ->whereNull('fallen_at')
            ->whereHas('squad', fn ($q) => $q->where('session_id', $task->session_id)->where('commander_id', $task->commander_id)->where('managed', true))
            ->count();
        if ($inUse + $task->size > $budget) {
            $this->note($task, "Waiting for HQ soldiers: {$inUse} of {$budget} are already out on other tasks.");

            return;
        }
        if (WarEconomy::fieldStrength($task->session_id) + $task->size > (int) config('reforger.ai_limit', 192)) {
            $this->note($task, 'Waiting for room under the server AI cap.');

            return;
        }

        $side = $enlistment->side;
        [$x, $z] = $side->muster();
        $squad = WarSquad::query()->create([
            'session_id' => $task->session_id,
            'commander_id' => $task->commander_id,
            'side' => $side,
            'strength' => $task->size,
            'alive' => $task->size,
            'tier' => 0,
            'pos_x' => $x,
            'pos_z' => $z,
            'task_id' => $task->id,
            'managed' => true,
        ]);

        $taken = WarSoldier::query()->where('session_id', $task->session_id)->where('commander_id', $task->commander_id)->whereNull('fallen_at')->pluck('name')->all();
        foreach ($this->roles($side->value, $task->size) as $role) {
            $taken[] = $this->enlist($squad, $role, $taken);
        }

        $this->note($task, 'Squad raised at home. Moving out.');
        $this->keepOrders($task, $squad);
    }

    /**
     * Everyone is at base: send back whoever is fit, refill the dead, and go again.
     *
     * @param  Collection<int, WarSoldier>  $living
     */
    private function refit(WarTask $task, WarSquad $squad, Collection $living, WarEnlistment $enlistment): void
    {
        $recovering = $living->filter(fn (WarSoldier $s) => $s->ready_at !== null && $s->ready_at->isFuture());
        if ($recovering->isNotEmpty()) {
            $next = $recovering->min('ready_at');
            $this->note($task, 'Squad recovering at base until '.$next->format('H:i').'.');

            return;
        }

        $missing = max(0, $task->size - $living->count());
        $price = WarEconomy::role('rifleman')['cost'];
        $taken = WarSoldier::query()->where('session_id', $task->session_id)->where('commander_id', $task->commander_id)->whereNull('fallen_at')->pluck('name')->all();
        for ($i = 0; $i < $missing; $i++) {
            if ($enlistment->supplies < $price) {
                break;
            }
            $enlistment->supplies -= $price;
            $enlistment->save();
            WarSupplyEntry::query()->create([
                'session_id' => $task->session_id,
                'commander_id' => $task->commander_id,
                'amount' => -$price,
                'reason' => WarSupplyReason::Recruit,
                'balance' => $enlistment->supplies,
            ]);
            $taken[] = $this->enlist($squad, 'rifleman', $taken);
        }

        [$hx, $hz] = $squad->side->muster();
        foreach ($living as $soldier) {
            $soldier->location = 'field';
            $soldier->ready_at = null;
            $soldier->health = 100;
            $soldier->stamina = 100;
            $soldier->bleeding = false;
            $soldier->down = false;
            $soldier->threat = 'safe';
            $soldier->condition_at = now();
            $soldier->save();
        }

        $squad->pos_x = $hx;
        $squad->pos_z = $hz;
        $squad->strength = WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->count();
        $squad->alive = $squad->strength;
        $squad->save();

        $short = $task->size - $squad->strength;
        $this->note($task, $short > 0 ? "Back on task, {$short} short: HQ needs supplies to replace losses." : 'Rested and back on task.');
        $this->keepOrders($task, $squad);
    }

    /**
     * Make sure the squad is doing its job with one standing order the game keeps by itself:
     * hold is a defend waypoint, a patrol is the game's own patrol cycle between the two ends
     * (neither ever completes, so HQ only reissues one that was lost).
     */
    private function keepOrders(WarTask $task, WarSquad $squad): void
    {
        $latest = WarOrder::query()->where('squad_id', $squad->id)->orderByDesc('id')->first();
        $open = $latest !== null && in_array($latest->status, [WarOrderStatus::Pending, WarOrderStatus::Acked], true);
        $kind = $task->kind === 'hold' ? WarOrderKind::Defend : WarOrderKind::Patrol;

        if ($open && $latest->kind === $kind->value) {
            return;
        }
        $second = $kind === WarOrderKind::Patrol && $task->b_x !== null && $task->b_z !== null
            ? [(float) $task->b_x, (float) $task->b_z]
            : null;
        $this->order($task, $squad, $kind, (float) $task->a_x, (float) $task->a_z, $second);
    }

    /** @param  array{0: float, 1: float}|null  $second */
    private function order(WarTask $task, WarSquad $squad, WarOrderKind $kind, float $x, float $z, ?array $second = null): void
    {
        WarOrder::query()
            ->where('squad_id', $squad->id)
            ->whereIn('status', [WarOrderStatus::Pending, WarOrderStatus::Acked])
            ->update(['status' => WarOrderStatus::Superseded]);

        WarOrder::query()->create([
            'session_id' => $task->session_id,
            'squad_id' => $squad->id,
            'commander_id' => $task->commander_id,
            'kind' => $kind->value,
            'status' => WarOrderStatus::Pending,
            'pos_x' => round($x, 2),
            'pos_z' => round($z, 2),
            'pos2_x' => $second !== null ? round($second[0], 2) : null,
            'pos2_z' => $second !== null ? round($second[1], 2) : null,
        ]);
    }

    /** Cancelled or done: bring the squad home off the island and stand it down. */
    private function standDown(WarTask $task, bool $timeUp = false): void
    {
        $squad = WarSquad::query()->where('task_id', $task->id)->lockForUpdate()->first();
        if ($squad === null) {
            $task->status = 'closed';
            $task->save();

            return;
        }

        RequestWarSupportAction::standDownOrders($squad->id);
        $gone = WarSoldier::query()
            ->where('squad_id', $squad->id)
            ->whereNull('fallen_at')
            ->update(['fallen_at' => now(), 'loss' => 'discharged', 'location' => 'reserve']);
        WarLog::write('squad.discharged', 'HQ sq '.$squad->id.' stood down: '.$gone.' men off the island', WarLog::squad($squad));
        $squad->strength = 0;
        $squad->alive = 0;
        $squad->task_id = null;
        $squad->save();

        $task->status = 'closed';
        $task->note = $timeUp ? 'Time is up. The squad went back to HQ.' : 'Stood down. The squad went back to HQ.';
        $task->save();
    }

    /** @return list<string> */
    private function roles(string $side, int $size): array
    {
        $pick = fn (array $wanted) => collect($wanted)->first(fn (string $id) => in_array($side, WarEconomy::role($id)['sides'], true));
        $roles = array_fill(0, $size, 'rifleman');
        $roles[0] = $pick(['squad_leader', 'team_leader']) ?? 'rifleman';
        if ($size >= 4) {
            $roles[1] = $pick(['autorifle', 'machinegun']) ?? 'rifleman';
            $roles[$size - 1] = $pick(['medic']) ?? 'rifleman';
        }

        return $roles;
    }

    /** @param  list<string>  $taken */
    private function enlist(WarSquad $squad, string $role, array $taken): string
    {
        $spec = WarEconomy::role($role);
        $name = WarEconomy::unusedName($squad->side->value, $taken);
        WarSoldier::query()->create([
            'session_id' => $squad->session_id,
            'squad_id' => $squad->id,
            'commander_id' => $squad->commander_id,
            'role' => $role,
            'name' => $name,
            'note' => '',
            'aim' => $spec['aim'],
            'grit' => $spec['grit'],
            'fieldcraft' => $spec['fieldcraft'],
            'spent' => 0,
            'service' => 0,
            'joined_at' => now(),
            'service_at' => now(),
            'morale' => WarCondition::baseMorale(0),
            'condition_at' => now(),
        ]);

        return $name;
    }

    private function note(WarTask $task, string $note): void
    {
        if ($task->note !== $note) {
            $task->note = $note;
            $task->save();
        }
    }
}
