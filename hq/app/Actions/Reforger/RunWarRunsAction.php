<?php

namespace App\Actions\Reforger;

use App\Enums\WarOrderKind;
use App\Enums\WarOrderStatus;
use App\Enums\WarSupplyReason;
use App\Models\User;
use App\Models\WarEnlistment;
use App\Models\WarOrder;
use App\Models\WarRun;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Models\WarSupplyEntry;
use App\Models\WarSupport;
use App\Models\WarVehicle;
use App\Support\WarCondition;
use App\Support\WarEconomy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HQ runs: HQ sends one of its drivers in one of its vehicles to a commander's squad, and every
 * step is the game's own (get in, drive, get out); nothing is flown or handed out by magic.
 *
 *   supply     an ammunition truck drives to the squad; the squad rearms once it is within
 *              war.runs.reach of it, then the truck drives home
 *   ambulance  an ambulance drives to the squad; its wounded within reach join the driver's
 *              group and board, are driven home, and the hospital takes them (back on their own
 *              squad's roll, recovering)
 *   transport  a truck drives to the squad, the squad boards, is driven to the point, gets
 *              out, and the truck drives home: a lift for less than a vehicle of one's own
 *
 * The HQ vehicle is not one of the commander's (it does not count toward his limit); driver
 * and vehicle go back to HQ after the run. A run whose vehicle burns or whose driver falls is
 * aborted; a squad that does not come to the vehicle within war.runs.wait seconds is left.
 */
final class RunWarRunsAction
{
    public const KINDS = ['supply', 'ambulance', 'transport'];

    private const TO_TRUCK = 'To the HQ ammo truck.';

    /** Seconds the HQ truck waits for the last man out of the back before the driver walks home. */
    private const UNLOAD_GIVE_UP = 900;

    /** Times a driver who left his vehicle is put back at the wheel in one run. */
    private const MAX_TRIES = 2;

    public function __construct(
        private readonly ResolveWarSessionAction $sessions,
        private readonly AccrueWarSuppliesAction $accrue,
    ) {}

    public function request(User $player, string $kind, int $squadId, ?float $x = null, ?float $z = null): WarRun
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['run' => ['HQ does not run that.']]);
        }
        $this->sessions->closeDue();

        return DB::transaction(function () use ($player, $kind, $squadId, $x, $z) {
            $session = $this->sessions->current();
            $enlistment = $session === null ? null : WarEnlistment::query()->where('session_id', $session->id)->where('commander_id', $player->id)->lockForUpdate()->first();
            if ($enlistment === null) {
                throw ValidationException::withMessages(['run' => ['Enlist a side first.']]);
            }
            $squad = WarSquad::query()->whereKey($squadId)->where('session_id', $session->id)->where('commander_id', $player->id)->where('managed', false)->first();
            $field = $squad === null ? collect() : WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->where('location', 'field')->get();
            if ($squad === null || $field->isEmpty()) {
                throw ValidationException::withMessages(['run' => ['Pick one of your squads on the island.']]);
            }
            if (WarRun::query()->where('squad_id', $squad->id)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['run' => ['HQ is already sending a vehicle to that squad.']]);
            }
            if (WarRun::query()->where('commander_id', $player->id)->where('session_id', $session->id)->where('status', 'active')->count() >= (int) config('reforger.war.runs.per_commander', 2)) {
                throw ValidationException::withMessages(['run' => ['HQ has no more drivers to spare for you right now.']]);
            }
            if ($kind === 'ambulance' && ! $field->contains(fn (WarSoldier $s) => WarCondition::isWounded($s))) {
                throw ValidationException::withMessages(['run' => ['Nobody in that squad is wounded.']]);
            }
            if ($kind === 'transport') {
                $size = (float) config('reforger.war.world_size', 12800);
                if ($x === null || $z === null || $x < 0 || $z < 0 || $x > $size || $z > $size) {
                    throw ValidationException::withMessages(['run' => ['Mark where they are to be driven.']]);
                }
                if (hypot($x - (float) $squad->pos_x, $z - (float) $squad->pos_z) < 300) {
                    throw ValidationException::withMessages(['run' => ['That is a walk: mark a point farther off.']]);
                }
                if ($field->count() > (int) config('reforger.war.runs.seats', 12)) {
                    throw ValidationException::withMessages(['run' => ['The truck takes at most '.config('reforger.war.runs.seats', 12).' men.']]);
                }
            }

            $cost = (int) config("reforger.war.runs.cost.{$kind}", 20);
            $this->accrue->accrue($enlistment);
            if ($enlistment->supplies < $cost) {
                throw ValidationException::withMessages(['supplies' => ['Not enough supplies for that.']]);
            }
            $enlistment->supplies -= $cost;
            $enlistment->save();
            WarSupplyEntry::query()->create([
                'session_id' => $session->id,
                'commander_id' => $player->id,
                'amount' => -$cost,
                'reason' => WarSupplyReason::Support,
                'balance' => $enlistment->supplies,
            ]);

            [$hx, $hz] = $enlistment->side->muster();
            [$vx, $vz] = self::bay($hx, $hz, WarRun::query()->where('session_id', $session->id)->count());
            $vehicle = WarVehicle::query()->create([
                'session_id' => $session->id,
                'squad_id' => null,
                'commander_id' => $player->id,
                'kind' => $kind === 'ambulance' ? 'ambulance' : 'truck',
                'side' => $enlistment->side,
                'status' => 'ordered',
                'pos_x' => $vx,
                'pos_z' => $vz,
                'cost' => 0,
                'hq' => true,
            ]);
            $crew = WarSquad::query()->create([
                'session_id' => $session->id,
                'commander_id' => $player->id,
                'side' => $enlistment->side,
                'strength' => 1,
                'alive' => 1,
                'tier' => 0,
                'pos_x' => $hx,
                'pos_z' => $hz,
                'managed' => true,
                'combat' => 'hold',
            ]);
            $spec = WarEconomy::role('rifleman');
            $taken = WarSoldier::query()->where('session_id', $session->id)->where('commander_id', $player->id)->whereNull('fallen_at')->pluck('name')->all();
            WarSoldier::query()->create([
                'session_id' => $session->id,
                'squad_id' => $crew->id,
                'commander_id' => $player->id,
                'role' => 'rifleman',
                'name' => WarEconomy::unusedName($enlistment->side->value, $taken),
                'note' => 'HQ driver',
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

            $run = WarRun::query()->create([
                'session_id' => $session->id,
                'commander_id' => $player->id,
                'kind' => $kind,
                'squad_id' => $squad->id,
                'crew_squad_id' => $crew->id,
                'vehicle_id' => $vehicle->id,
                'stage' => 'out',
                'stage_at' => now(),
                'pick_x' => $squad->pos_x,
                'pick_z' => $squad->pos_z,
                'drop_x' => $kind === 'transport' ? round((float) $x, 2) : null,
                'drop_z' => $kind === 'transport' ? round((float) $z, 2) : null,
                'cost' => $cost,
                'note' => 'On its way.',
                'ends_at' => now()->addMinutes((int) config('reforger.war.runs.minutes', 30)),
            ]);

            // Get in at home, then drive to the squad (a chain: the drive waits for the driver).
            $this->order($run, $crew->id, WarOrderKind::Mount, $vx, $vz, $vehicle->id);
            $this->order($run, $crew->id, WarOrderKind::Move, (float) $squad->pos_x, (float) $squad->pos_z, null, WarOrderStatus::Queued);

            return $run;
        }, 3);
    }

    /** Every bridge tick: move each run on a stage when the game says the step is done. */
    public function tend(): void
    {
        DB::transaction(function () {
            foreach (WarRun::query()->where('status', 'active')->lockForUpdate()->get() as $run) {
                $this->step($run);
            }
        }, 3);
    }

    private function step(WarRun $run): void
    {
        $vehicle = WarVehicle::query()->whereKey($run->vehicle_id)->first();
        $crew = $this->field($run->crew_squad_id);
        $driverLost = $crew->isEmpty() && $run->stage !== 'home';
        if ($vehicle === null || $vehicle->status === 'destroyed' || $driverLost) {
            $this->finish($run, 'aborted', $vehicle?->status === 'destroyed' ? 'The HQ vehicle was destroyed.' : 'The HQ driver was lost.');

            return;
        }
        $squad = WarSquad::query()->whereKey($run->squad_id)->first();
        $men = $this->field($run->squad_id);
        $since = $run->stage_at === null ? 0 : (int) $run->stage_at->diffInSeconds(now(), true);
        if ($this->stranded($run, $vehicle, $crew, $squad, $men, $since)) {
            return;
        }
        $timedOut = $run->ends_at->isPast();
        $near = fn (WarSoldier|WarSquad $who) => hypot((float) ($who->pos_x ?? $squad?->pos_x) - (float) $vehicle->pos_x, (float) ($who->pos_z ?? $squad?->pos_z) - (float) $vehicle->pos_z) <= (float) config('reforger.war.runs.reach', 60);

        switch ($run->stage) {
            case 'out':
                $drive = $this->last($run->crew_squad_id, WarOrderKind::Move);
                // A truck cannot always reach the very spot (a wood, a yard): close is there.
                $close = $vehicle->status === 'fielded' && $squad !== null
                    && hypot((float) $vehicle->pos_x - (float) $squad->pos_x, (float) $vehicle->pos_z - (float) $squad->pos_z) <= (float) config('reforger.war.runs.close', 120);
                if ($close || ($drive !== null && in_array($drive->status, [WarOrderStatus::Done, WarOrderStatus::Failed], true))) {
                    if ($close && $drive !== null && in_array($drive->status, [WarOrderStatus::Pending, WarOrderStatus::Acked], true)) {
                        $this->order($run, (int) $run->crew_squad_id, WarOrderKind::Halt, (float) $vehicle->pos_x, (float) $vehicle->pos_z);
                    }
                    $this->stage($run, 'arrived', 'At the squad.');
                } elseif ($timedOut) {
                    $this->goHome($run, 'Could not get there in time.');
                }
                break;

            case 'arrived':
                if ($squad === null || $men->isEmpty()) {
                    $this->goHome($run, 'Nobody left to serve.');
                    break;
                }
                if ($run->kind === 'supply') {
                    if (! $near($squad)) {
                        // The truck stops where it can; the squad walks over, then carries on.
                        $this->detour($run, $squad, (float) $vehicle->pos_x, (float) $vehicle->pos_z, self::TO_TRUCK);
                        $this->stage($run, 'meeting', 'Waiting for the squad at the truck.');
                    }
                }
                // no break: a squad already beside the truck rearms at once
            case 'meeting':
                if ($squad === null || $men->isEmpty()) {
                    $this->goHome($run, 'Nobody left to serve.');
                    break;
                }
                if ($run->kind === 'supply') {
                    if ($near($squad)) {
                        WarSupport::query()->create([
                            'session_id' => $run->session_id,
                            'commander_id' => $run->commander_id,
                            'squad_id' => $squad->id,
                            'kind' => 'resupply',
                            'status' => 'requested',
                            'pos_x' => $vehicle->pos_x,
                            'pos_z' => $vehicle->pos_z,
                            'cost' => 0,
                            'ready_at' => now(),
                            'note' => 'From the HQ truck.',
                        ]);
                        // Rearmed: the walk to the truck is over, and what it was doing comes back.
                        WarOrder::query()->where('squad_id', $squad->id)->where('kind', WarOrderKind::Move->value)->where('note', self::TO_TRUCK)
                            ->whereIn('status', [WarOrderStatus::Pending, WarOrderStatus::Acked])
                            ->update(['status' => WarOrderStatus::Done]);
                        $this->goHome($run, 'Ammunition handed out.');
                    } elseif ($since >= (int) config('reforger.war.runs.wait', 300)) {
                        $this->goHome($run, 'Nobody came to the truck.');
                    }
                    break;
                }
                if ($run->kind === 'ambulance') {
                    // Those who can walk get in on their own (the game's get-in walks them over);
                    // a man down is the medic's until he is up again.
                    $walk = (float) config('reforger.war.runs.walk', 300);
                    $wounded = $men->filter(fn (WarSoldier $s) => WarCondition::isWounded($s) && ! $s->down
                        && hypot((float) ($s->pos_x ?? $squad->pos_x) - (float) $vehicle->pos_x, (float) ($s->pos_z ?? $squad->pos_z) - (float) $vehicle->pos_z) <= $walk);
                    if ($wounded->isEmpty()) {
                        if ($since >= (int) config('reforger.war.runs.wait', 300)) {
                            $this->goHome($run, 'No wounded came to the ambulance.');
                        }
                        break;
                    }
                    // The wounded join the driver's group and get in, as the game's recruit and get-in do.
                    $riders = [];
                    foreach ($wounded as $s) {
                        $riders[(string) $s->id] = $s->squad_id;
                        $s->squad_id = (int) $run->crew_squad_id;
                        $s->save();
                    }
                    $run->riders = $riders;
                    $this->recount([(int) $run->squad_id, (int) $run->crew_squad_id]);
                    $this->order($run, (int) $run->crew_squad_id, WarOrderKind::Mount, (float) $vehicle->pos_x, (float) $vehicle->pos_z, $vehicle->id);
                    $this->stage($run, 'loading', 'Taking '.$wounded->count().' wounded aboard.');
                    break;
                }
                // transport: the squad gets into the HQ truck
                $this->order($run, $squad->id, WarOrderKind::Mount, (float) $vehicle->pos_x, (float) $vehicle->pos_z, $vehicle->id);
                $this->stage($run, 'loading', 'The squad is getting in.');
                break;

            case 'loading':
                $aboard = $run->kind === 'ambulance' ? $crew : $men;
                $all = $aboard->isNotEmpty() && $aboard->every(fn (WarSoldier $s) => $s->in_vehicle);
                if (! $all && $since < (int) config('reforger.war.runs.wait', 300)) {
                    break;
                }
                if ($run->kind === 'ambulance') {
                    $this->goHome($run, 'Driving the wounded to the hospital.');
                } else {
                    $this->order($run, (int) $run->crew_squad_id, WarOrderKind::Move, (float) $run->drop_x, (float) $run->drop_z);
                    $this->stage($run, 'carrying', 'Driving the squad.');
                }
                break;

            case 'carrying':
                $drive = $this->last($run->crew_squad_id, WarOrderKind::Move);
                $there = hypot((float) $vehicle->pos_x - (float) $run->drop_x, (float) $vehicle->pos_z - (float) $run->drop_z) <= (float) config('reforger.war.runs.close', 120) + 30;
                if ($there || ($drive !== null && in_array($drive->status, [WarOrderStatus::Done, WarOrderStatus::Failed], true)) || $timedOut) {
                    // Stop the truck where it got to before they get out (the game's halt).
                    if ($drive !== null && in_array($drive->status, [WarOrderStatus::Pending, WarOrderStatus::Acked], true)) {
                        $this->order($run, (int) $run->crew_squad_id, WarOrderKind::Halt, (float) $vehicle->pos_x, (float) $vehicle->pos_z);
                    }
                    if ($squad !== null && $men->isNotEmpty()) {
                        $this->order($run, $squad->id, WarOrderKind::Dismount, (float) $vehicle->pos_x, (float) $vehicle->pos_z);
                    }
                    $this->stage($run, 'unloading', $drive?->status === WarOrderStatus::Failed ? 'Could not get all the way: dropping them here.' : 'Dropping the squad.');
                }
                break;

            case 'unloading':
                // The truck goes home empty: whoever is still in the back is told to get out again
                // (an order of the commander's given meanwhile waits behind it), and only if they
                // will not for a long while does the driver walk home and leave them the truck.
                $out = $men->every(fn (WarSoldier $s) => ! $s->in_vehicle);
                if ($out) {
                    $this->goHome($run, 'Squad dropped off.');
                } elseif ($since >= self::UNLOAD_GIVE_UP) {
                    $this->goHome($run, 'Some would not get out: the driver walked home.', false);
                } elseif ($squad !== null) {
                    $told = $this->last($squad->id, WarOrderKind::Dismount);
                    $open = $told !== null && in_array($told->status, [WarOrderStatus::Pending, WarOrderStatus::Acked], true);
                    if (! $open && ($told === null || $told->created_at->lt(now()->subSeconds(45)))) {
                        $this->detour($run, $squad, (float) $vehicle->pos_x, (float) $vehicle->pos_z, 'Out of the HQ truck.', WarOrderKind::Dismount);
                    }
                }
                break;

            case 'home':
                $back = $this->last($run->crew_squad_id, WarOrderKind::Rest);
                if ($crew->isEmpty() || ($back !== null && in_array($back->status, [WarOrderStatus::Done, WarOrderStatus::Failed], true)) || $since >= 900) {
                    $this->finish($run, 'done', $run->note);
                }
                break;
        }
    }

    /**
     * Send the squad somewhere first and put what it was doing back behind it, in the same
     * order: once there, it carries on with its own orders.
     */
    private function detour(WarRun $run, WarSquad $squad, float $x, float $z, string $note, WarOrderKind $kind = WarOrderKind::Move): void
    {
        $open = WarOrder::query()->where('squad_id', $squad->id)
            ->whereIn('status', [WarOrderStatus::Queued, WarOrderStatus::Pending, WarOrderStatus::Acked])
            ->orderBy('id')
            ->get();
        $this->order($run, $squad->id, $kind, $x, $z)->update(['note' => $note]);
        foreach ($open as $order) {
            $copy = $order->replicate();
            $copy->status = WarOrderStatus::Queued;
            $copy->note = '';
            $copy->arrived_at = null;
            $copy->save();
        }
    }

    /**
     * The game takes a driver out of his vehicle when it cannot drive on (boxed in, no way
     * through: SCR_AIProcessFailedMovementResult), and he walks the rest. On a drive he is put
     * back at the wheel and the drive goes again, at most MAX_TRIES times; then a lift drops
     * the squad where the truck is and the run ends there.
     *
     * @param  Collection<int, WarSoldier>  $crew
     * @param  Collection<int, WarSoldier>  $men
     */
    private function stranded(WarRun $run, WarVehicle $vehicle, Collection $crew, ?WarSquad $squad, Collection $men, int $since): bool
    {
        if (! in_array($run->stage, ['out', 'carrying', 'home'], true) || $vehicle->status !== 'fielded' || $since < 30) {
            return false;
        }
        $drive = WarOrder::query()->where('squad_id', $run->crew_squad_id)->whereIn('status', [WarOrderStatus::Pending, WarOrderStatus::Acked])
            ->whereIn('kind', [WarOrderKind::Move->value, WarOrderKind::Rest->value])->latest('id')->first();
        $driving = $crew->contains(fn (WarSoldier $s) => $s->in_vehicle);
        if ($drive === null || $driving) {
            return false;
        }
        if ($run->tries < self::MAX_TRIES) {
            $run->tries++;
            $this->order($run, (int) $run->crew_squad_id, WarOrderKind::Mount, (float) $vehicle->pos_x, (float) $vehicle->pos_z, $vehicle->id);
            $this->order($run, (int) $run->crew_squad_id, WarOrderKind::from($drive->kind), (float) $drive->pos_x, (float) $drive->pos_z, null, WarOrderStatus::Queued);
            $this->stage($run, $run->stage, 'The truck got stuck: the driver is getting back in to try again.');

            return true;
        }
        if ($run->stage === 'carrying' && $squad !== null && $men->contains(fn (WarSoldier $s) => $s->in_vehicle)) {
            $this->order($run, $squad->id, WarOrderKind::Dismount, (float) $vehicle->pos_x, (float) $vehicle->pos_z);
            $this->stage($run, 'unloading', 'The truck cannot get through: dropping them here.');

            return true;
        }
        if ($run->stage !== 'home') {
            $this->goHome($run, 'The truck cannot get through.');

            return true;
        }

        return false;
    }

    /** A spot of its own around the spawn for the n-th HQ vehicle of the week (40-60 m out). */
    public static function bay(float $x, float $z, int $n): array
    {
        $angle = deg2rad(fmod($n * 137.5, 360.0));
        $radius = 40.0 + 10.0 * ($n % 3);

        return [round($x + cos($angle) * $radius, 2), round($z + sin($angle) * $radius, 2)];
    }

    private function goHome(WarRun $run, string $note, bool $drive = true): void
    {
        [$hx, $hz] = WarSquad::query()->whereKey($run->crew_squad_id)->value('side')?->muster() ?? [0.0, 0.0];
        // The squad that rode got the vehicle for its own group when it got in; the driver takes
        // it back (he is already at the wheel) and drives home in it, then home is where HQ
        // takes him and the vehicle off the island.
        $vehicle = WarVehicle::query()->whereKey($run->vehicle_id)->first();
        if ($drive && $vehicle !== null && $vehicle->status === 'fielded') {
            $this->order($run, (int) $run->crew_squad_id, WarOrderKind::Mount, (float) $vehicle->pos_x, (float) $vehicle->pos_z, $vehicle->id);
            $this->order($run, (int) $run->crew_squad_id, WarOrderKind::Rest, $hx, $hz, null, WarOrderStatus::Queued);
        } else {
            $this->order($run, (int) $run->crew_squad_id, WarOrderKind::Rest, $hx, $hz);
        }
        $this->stage($run, 'home', $note);
    }

    /**
     * The run is over: the wounded it brought home are back on their own squad's roll (in the
     * hospital), the driver and the vehicle go back to HQ.
     */
    private function finish(WarRun $run, string $status, string $note): void
    {
        foreach ($run->riders ?? [] as $soldierId => $squadId) {
            WarSoldier::query()->whereKey((int) $soldierId)->whereNull('fallen_at')->update(['squad_id' => $squadId]);
        }
        WarSoldier::query()->where('squad_id', $run->crew_squad_id)->whereNull('fallen_at')
            ->update(['fallen_at' => now(), 'loss' => 'discharged', 'location' => 'reserve']);
        $this->recount(array_values(array_unique(array_merge([(int) $run->crew_squad_id, (int) $run->squad_id], array_map('intval', array_values($run->riders ?? []))))));
        WarOrder::query()->where('squad_id', $run->crew_squad_id)->whereIn('status', [WarOrderStatus::Queued, WarOrderStatus::Pending, WarOrderStatus::Acked])
            ->update(['status' => WarOrderStatus::Superseded]);
        $vehicle = WarVehicle::query()->whereKey($run->vehicle_id)->first();
        if ($vehicle !== null && $vehicle->isActive()) {
            $vehicle->status = 'scrapped';
            $vehicle->lost_at = now();
            $vehicle->save();
        }
        $run->status = $status;
        $run->note = $note;
        $run->save();
    }

    private function stage(WarRun $run, string $stage, string $note): void
    {
        $run->stage = $stage;
        $run->stage_at = now();
        $run->note = $note;
        $run->save();
    }

    /** @return Collection<int, WarSoldier> */
    private function field(?int $squadId)
    {
        return $squadId === null ? collect() : WarSoldier::query()->where('squad_id', $squadId)->whereNull('fallen_at')->where('location', 'field')->get();
    }

    private function last(?int $squadId, WarOrderKind $kind): ?WarOrder
    {
        return $squadId === null ? null : WarOrder::query()->where('squad_id', $squadId)->where('kind', $kind->value)->latest('id')->first();
    }

    /** @param  list<int>  $squadIds */
    private function recount(array $squadIds): void
    {
        foreach (WarSquad::query()->whereKey($squadIds)->get() as $squad) {
            $squad->strength = WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->count();
            $squad->alive = WarSoldier::query()->where('squad_id', $squad->id)->whereNull('fallen_at')->where('location', 'field')->count();
            $squad->save();
        }
    }

    private function order(WarRun $run, int $squadId, WarOrderKind $kind, float $x, float $z, ?int $vehicleId = null, WarOrderStatus $status = WarOrderStatus::Pending): WarOrder
    {
        if ($status === WarOrderStatus::Pending) {
            WarOrder::query()->where('squad_id', $squadId)
                ->whereIn('status', [WarOrderStatus::Queued, WarOrderStatus::Pending, WarOrderStatus::Acked])
                ->update(['status' => WarOrderStatus::Superseded]);
        }

        return WarOrder::query()->create([
            'session_id' => $run->session_id,
            'squad_id' => $squadId,
            'commander_id' => $run->commander_id,
            'kind' => $kind->value,
            'status' => $status,
            'pos_x' => round($x, 2),
            'pos_z' => round($z, 2),
            'vehicle_id' => $vehicleId,
        ]);
    }
}
