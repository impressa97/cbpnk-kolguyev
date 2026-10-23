<?php

namespace App\Actions\Reforger;

use App\Models\User;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Support\WarEconomy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Squad settings and people, as the game's commanding menu has them:
 *
 *   combat    hold fire, return fire, fire at will (the vanilla combat-mode commands)
 *   transfer  one man to another of your squads (the group-membership change behind the
 *             game's recruit and remove commands); he leaves one group and joins the other
 *   rename    the commander's own name for a squad (empty: its callsign again)
 *   form      the squad's formation and pace (the game's group AI settings,
 *             SCR_AIGroupFormationSetting and SCR_AIGroupCharactersMovementSpeedSetting)
 *   split     the men in one kind of seat (the passengers: the dismounts, or the crew) into the
 *             commander's first empty squad, so a vehicle's crew and its dismounts take
 *             orders apart; the new squad starts where they are
 *   posture   one man's stance and pace, the game's own per-soldier AI orders
 *             (SCR_AIOrder_Stance, SCR_AIOrder_MovementType; auto hands it back to his AI)
 */
final class CommandWarSquadAction
{
    public const COMBAT = ['hold', 'return', 'free'];

    public const STANCE = ['auto', 'stand', 'crouch', 'prone'];

    public const PACE = ['auto', 'walk', 'run', 'sprint'];

    public const FORMATION = ['auto', 'wedge', 'line', 'column', 'staggered'];

    public function __construct(
        private readonly ResolveWarSessionAction $sessions,
    ) {}

    /**
     * How ready the squad is for contact, the game's threat states: normal (the AI's own),
     * alert (vigilant: it spots sooner, never surprised) or ready (alerted: it also moves
     * crouched, weapons up). The mod holds each man there (CBPNK_WarBridge.KeepAlert).
     */
    public const ALERT = ['normal', 'alert', 'ready'];

    public function alert(User $player, int $squadId, string $level): WarSquad
    {
        if (! in_array($level, self::ALERT, true)) {
            throw ValidationException::withMessages(['level' => ['Normal, alert or ready.']]);
        }
        $squad = $this->own($player, $squadId);
        $squad->alert = $level;
        $squad->save();

        return $squad;
    }

    public function combat(User $player, int $squadId, string $mode): WarSquad
    {
        if (! in_array($mode, self::COMBAT, true)) {
            throw ValidationException::withMessages(['mode' => ['Hold fire, return fire, or fire at will.']]);
        }
        $squad = $this->own($player, $squadId);
        $squad->combat = $mode;
        $squad->save();

        return $squad;
    }

    public function rename(User $player, int $squadId, string $name): WarSquad
    {
        $name = trim($name);
        if ($name !== '') {
            WarEconomy::assertPerson($name, '');
        }
        $squad = $this->own($player, $squadId);
        $squad->name = $name === '' ? null : $name;
        $squad->save();

        return $squad;
    }

    public function form(User $player, int $squadId, ?string $formation, ?string $pace): WarSquad
    {
        $squad = $this->own($player, $squadId);
        if ($formation !== null) {
            $squad->formation = $formation;
        }
        if ($pace !== null) {
            $squad->pace_order = $pace;
        }
        $squad->save();

        return $squad;
    }

    public function transfer(User $player, int $soldierId, int $squadId): WarSoldier
    {
        return DB::transaction(function () use ($player, $soldierId, $squadId) {
            $into = $this->own($player, $squadId, lock: true);
            $soldier = WarSoldier::query()
                ->whereKey($soldierId)
                ->where('commander_id', $player->id)
                ->whereNull('fallen_at')
                ->lockForUpdate()
                ->first();
            if ($soldier === null) {
                throw ValidationException::withMessages(['soldier_id' => ['That soldier is not yours to move.']]);
            }
            if ($soldier->squad_id === $into->id) {
                return $soldier;
            }
            $from = WarSquad::query()->whereKey($soldier->squad_id)->lockForUpdate()->first();
            if ($from === null || $from->session_id !== $into->session_id || $from->managed) {
                throw ValidationException::withMessages(['soldier_id' => ['HQ runs his squad; change the task instead.']]);
            }
            $cap = (int) config('reforger.war.economy.max_strength', 12);
            if ($this->strength($into) >= $cap) {
                throw ValidationException::withMessages(['squad_id' => ["That squad is full ({$cap})."]]);
            }

            $soldier->squad_id = $into->id;
            $soldier->save();
            foreach ([$from, $into] as $squad) {
                $squad->strength = $this->strength($squad);
                $squad->alive = min($squad->alive, $squad->strength);
                $squad->save();
            }

            return $soldier;
        }, 3);
    }

    /**
     * One man into the commander's first empty squad, where he is: a straggler left far behind
     * becomes the leader of his own group and takes orders himself (the game's remove/recruit).
     */
    public function detach(User $player, int $soldierId): WarSquad
    {
        return DB::transaction(function () use ($player, $soldierId) {
            $soldier = WarSoldier::query()->whereKey($soldierId)->where('commander_id', $player->id)->whereNull('fallen_at')->where('location', 'field')->lockForUpdate()->first();
            if ($soldier === null) {
                throw ValidationException::withMessages(['soldier_id' => ['That soldier is not yours on the island.']]);
            }
            $from = WarSquad::query()->whereKey($soldier->squad_id)->lockForUpdate()->first();
            if ($from === null || $from->managed) {
                throw ValidationException::withMessages(['soldier_id' => ['HQ runs his squad.']]);
            }
            $into = WarSquad::query()->where('session_id', $from->session_id)->where('commander_id', $player->id)->where('managed', false)->where('strength', 0)->orderBy('id')->lockForUpdate()->first();
            if ($into === null) {
                throw ValidationException::withMessages(['soldier_id' => ['No empty squad to put him in: all ten have men.']]);
            }
            $soldier->squad_id = $into->id;
            $soldier->save();
            $into->pos_x = $soldier->pos_x ?? $from->pos_x;
            $into->pos_z = $soldier->pos_z ?? $from->pos_z;
            $into->combat = $from->combat;
            foreach ([$from, $into] as $squad) {
                $squad->strength = $this->strength($squad);
                $squad->alive = WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->where('location', 'field')->count();
                $squad->save();
            }

            return $into;
        }, 3);
    }

    /** Seats a vehicle's crew sits in; everyone else aboard is a passenger. */
    public const CREW_SEATS = ['driver', 'gunner', 'commander'];

    /**
     * The squad's men aboard in one kind of seat go to the commander's first empty squad.
     *
     * @param  'passengers'|'crew'  $part
     */
    public function split(User $player, int $squadId, string $part): WarSquad
    {
        return DB::transaction(function () use ($player, $squadId, $part) {
            $from = $this->own($player, $squadId, lock: true);
            if ($from->managed) {
                throw ValidationException::withMessages(['squad_id' => ['HQ runs that squad.']]);
            }
            $field = WarSoldier::query()->where('squad_id', $from->id)->whereNull('fallen_at')->where('location', 'field')->lockForUpdate()->get();
            $men = $field->filter(fn (WarSoldier $s) => $s->in_vehicle
                && ($part === 'crew' ? in_array($s->seat, self::CREW_SEATS, true) : ! in_array($s->seat, self::CREW_SEATS, true)))->values();
            if ($men->isEmpty()) {
                throw ValidationException::withMessages(['squad_id' => [$part === 'crew' ? 'Nobody of that squad is crewing a vehicle.' : 'Nobody of that squad rides as a passenger.']]);
            }
            if ($men->count() === $field->count()) {
                throw ValidationException::withMessages(['squad_id' => ['That is the whole squad: give it the order itself.']]);
            }

            $into = WarSquad::query()
                ->where('session_id', $from->session_id)
                ->where('commander_id', $player->id)
                ->where('managed', false)
                ->where('strength', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($into === null) {
                throw ValidationException::withMessages(['squad_id' => ['No empty squad to put them in: all ten have men.']]);
            }

            foreach ($men as $soldier) {
                $soldier->squad_id = $into->id;
                $soldier->save();
            }
            // The new squad is where its men are, not at home.
            $into->pos_x = round((float) $men->avg(fn (WarSoldier $s) => (float) ($s->pos_x ?? $from->pos_x)), 2);
            $into->pos_z = round((float) $men->avg(fn (WarSoldier $s) => (float) ($s->pos_z ?? $from->pos_z)), 2);
            $into->combat = $from->combat;
            foreach ([$from, $into] as $squad) {
                $squad->strength = $this->strength($squad);
                $squad->alive = WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->where('location', 'field')->count();
                $squad->save();
            }

            return $into;
        }, 3);
    }

    public function posture(User $player, int $soldierId, ?string $stance, ?string $pace): WarSoldier
    {
        $soldier = WarSoldier::query()
            ->with('squad')
            ->whereKey($soldierId)
            ->where('commander_id', $player->id)
            ->whereNull('fallen_at')
            ->where('location', 'field')
            ->first();
        if ($soldier === null || $soldier->squad === null || $soldier->squad->managed) {
            throw ValidationException::withMessages(['soldier_id' => ['That soldier is not yours to command on the island.']]);
        }
        if ($stance !== null) {
            $soldier->stance_order = $stance;
        }
        if ($pace !== null) {
            $soldier->pace_order = $pace;
        }
        $soldier->save();

        return $soldier;
    }

    private function own(User $player, int $squadId, bool $lock = false): WarSquad
    {
        $session = $this->sessions->current();
        if ($session === null) {
            throw ValidationException::withMessages(['side' => ['This week is over.']]);
        }
        $query = WarSquad::query()
            ->whereKey($squadId)
            ->where('commander_id', $player->id)
            ->where('session_id', $session->id);
        $squad = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($squad === null) {
            throw ValidationException::withMessages(['squad_id' => ['That is not one of your squads this week.']]);
        }
        if ($squad->managed) {
            throw ValidationException::withMessages(['squad_id' => ['HQ runs that squad for a task. Change the task instead.']]);
        }

        return $squad;
    }

    private function strength(WarSquad $squad): int
    {
        return WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->count();
    }
}
