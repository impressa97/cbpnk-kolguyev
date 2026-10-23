<?php

namespace App\Http\Controllers;

use App\Actions\Reforger\CommandWarSquadAction;
use App\Actions\Reforger\EnlistWarSideAction;
use App\Actions\Reforger\IssueWarMoveOrderAction;
use App\Actions\Reforger\RunWarRunsAction;
use App\Actions\Reforger\SupplyWarSquadAction;
use App\Actions\Reforger\UpgradeWarSquadAction;
use App\Enums\WarOrderKind;
use App\Enums\WarSide;
use App\Support\WarEconomy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Website HQ for the Kolguyev war: enlist, then order your squad. */
class ReforgerWarController extends Controller
{
    public function enlist(Request $request, EnlistWarSideAction $enlist): RedirectResponse
    {
        $data = $request->validate([
            'side' => ['required', Rule::enum(WarSide::class)],
        ]);

        $enlist->enlist($request->user(), WarSide::from($data['side']));

        return back();
    }

    public function order(Request $request, IssueWarMoveOrderAction $orders): RedirectResponse
    {
        $size = (int) config('reforger.war.world_size', 12800);
        $data = $request->validate([
            'x' => ['required', 'numeric', 'min:0', 'max:'.$size],
            'z' => ['required', 'numeric', 'min:0', 'max:'.$size],
            'x2' => ['nullable', 'required_with:z2', 'numeric', 'min:0', 'max:'.$size],
            'z2' => ['nullable', 'required_with:x2', 'numeric', 'min:0', 'max:'.$size],
            'squad_id' => ['nullable', 'integer'],
            'soldier_id' => ['nullable', 'integer'],
            'vehicle_id' => ['nullable', 'integer'],
            'follow_squad_id' => ['nullable', 'integer'],
            'route' => ['nullable', 'array', 'max:'.IssueWarMoveOrderAction::MAX_LEGS],
            'route.*.x' => ['required', 'numeric', 'min:0', 'max:'.$size],
            'route.*.z' => ['required', 'numeric', 'min:0', 'max:'.$size],
            'kind' => ['nullable', Rule::enum(WarOrderKind::class)],
            // Wait behind the squad's open orders instead of replacing them.
            'queue' => ['sometimes', 'boolean'],
            // More orders to wait behind this one, in turn (board, drive, get out).
            'then' => ['sometimes', 'array', 'max:'.IssueWarMoveOrderAction::MAX_QUEUE],
            'then.*.kind' => ['required', Rule::enum(WarOrderKind::class)],
            'then.*.x' => ['required', 'numeric', 'min:0', 'max:'.$size],
            'then.*.z' => ['required', 'numeric', 'min:0', 'max:'.$size],
            'then.*.vehicle_id' => ['nullable', 'integer'],
            'then.*.route' => ['nullable', 'array', 'max:'.IssueWarMoveOrderAction::MAX_LEGS],
            'then.*.route.*.x' => ['required', 'numeric', 'min:0', 'max:'.$size],
            'then.*.route.*.z' => ['required', 'numeric', 'min:0', 'max:'.$size],
        ]);

        if (! empty($data['then'])) {
            $first = [
                'kind' => WarOrderKind::tryFrom((string) ($data['kind'] ?? '')) ?? WarOrderKind::Move,
                'x' => (float) $data['x'],
                'z' => (float) $data['z'],
                'second' => isset($data['x2'], $data['z2']) ? [(float) $data['x2'], (float) $data['z2']] : null,
                'vehicle_id' => isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
                'follow_squad_id' => isset($data['follow_squad_id']) ? (int) $data['follow_squad_id'] : null,
                'route' => array_map(fn (array $p) => [(float) $p['x'], (float) $p['z']], $data['route'] ?? []),
            ];
            $orders->chain($request->user(), (int) ($data['squad_id'] ?? 0), [
                $first,
                ...array_map(fn (array $step) => [
                    'kind' => WarOrderKind::from($step['kind']),
                    'x' => (float) $step['x'],
                    'z' => (float) $step['z'],
                    'vehicle_id' => isset($step['vehicle_id']) ? (int) $step['vehicle_id'] : null,
                    'route' => array_map(fn (array $p) => [(float) $p['x'], (float) $p['z']], $step['route'] ?? []),
                ], $data['then']),
            ], (bool) ($data['queue'] ?? false));

            return back();
        }

        $orders->move(
            $request->user(),
            (float) $data['x'],
            (float) $data['z'],
            isset($data['squad_id']) ? (int) $data['squad_id'] : null,
            WarOrderKind::tryFrom((string) ($data['kind'] ?? '')) ?? WarOrderKind::Move,
            isset($data['soldier_id']) ? (int) $data['soldier_id'] : null,
            isset($data['x2'], $data['z2']) ? [(float) $data['x2'], (float) $data['z2']] : null,
            isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
            isset($data['follow_squad_id']) ? (int) $data['follow_squad_id'] : null,
            array_map(fn (array $p) => [(float) $p['x'], (float) $p['z']], $data['route'] ?? []),
            (bool) ($data['queue'] ?? false),
        );

        return back();
    }

    /**
     * Part a squad in a vehicle: its passengers (or its crew) become a squad of their own; with
     * `unload` that new squad is told to get out at once, so the dismounts leave and the crew
     * keeps the vehicle.
     */
    public function split(Request $request, int $squad, CommandWarSquadAction $command, IssueWarMoveOrderAction $orders): RedirectResponse
    {
        $data = $request->validate([
            'part' => ['required', Rule::in(['passengers', 'crew'])],
            'unload' => ['sometimes', 'boolean'],
        ]);
        $player = $request->user();
        $into = $command->split($player, $squad, $data['part']);
        if ((bool) ($data['unload'] ?? false)) {
            $orders->move($player, (float) $into->pos_x, (float) $into->pos_z, $into->id, WarOrderKind::Dismount);
        }

        return back();
    }

    /** Refuel and repair one of your vehicles: at home free, beside another of your trucks for supplies. */
    public function service(Request $request, int $vehicle, SupplyWarSquadAction $logistics): RedirectResponse
    {
        $logistics->service($request->user(), $vehicle);

        return back();
    }

    /** An HQ run for one of your squads: an ammunition truck, an ambulance, or a lift to a point. */
    public function run(Request $request, RunWarRunsAction $runs): RedirectResponse
    {
        $size = (int) config('reforger.war.world_size', 12800);
        $data = $request->validate([
            'kind' => ['required', Rule::in(RunWarRunsAction::KINDS)],
            'squad_id' => ['required', 'integer'],
            'x' => ['nullable', 'numeric', 'min:0', 'max:'.$size],
            'z' => ['nullable', 'numeric', 'min:0', 'max:'.$size],
        ]);
        $runs->request(
            $request->user(),
            $data['kind'],
            (int) $data['squad_id'],
            isset($data['x']) ? (float) $data['x'] : null,
            isset($data['z']) ? (float) $data['z'] : null,
        );

        return back();
    }

    /** One man into an empty squad of his own, where he is. */
    public function detach(Request $request, int $soldier, CommandWarSquadAction $command): RedirectResponse
    {
        $command->detach($request->user(), $soldier);

        return back();
    }

    /** Take a waiting order out of a squad's chain. */
    public function cancelOrder(Request $request, int $order, IssueWarMoveOrderAction $orders): RedirectResponse
    {
        $orders->cancel($request->user(), $order);

        return back();
    }

    /** Rearm (at home, or at a truck for supplies), or hand the wounded to the hospital at home. */
    public function logistics(Request $request, int $squad, SupplyWarSquadAction $logistics): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['rearm', 'hospital'])]]);
        $player = $request->user();
        $data['action'] === 'rearm' ? $logistics->rearm($player, $squad) : $logistics->hospital($player, $squad);

        return back();
    }

    /** Hold fire, return fire, fire at will: the squad's standing combat mode. */
    public function combat(Request $request, int $squad, CommandWarSquadAction $command): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(CommandWarSquadAction::COMBAT)]]);
        $command->combat($request->user(), $squad, $data['mode']);

        return back();
    }

    /** How ready the squad is for contact: normal, alert or ready. */
    public function alert(Request $request, int $squad, CommandWarSquadAction $command): RedirectResponse
    {
        $data = $request->validate(['level' => ['required', Rule::in(CommandWarSquadAction::ALERT)]]);
        $command->alert($request->user(), $squad, $data['level']);

        return back();
    }

    /** The commander's own name for one of his squads (empty: back to its callsign). */
    public function rename(Request $request, int $squad, CommandWarSquadAction $command): RedirectResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:24']]);
        $command->rename($request->user(), $squad, (string) ($data['name'] ?? ''));

        return back();
    }

    /** The squad's formation and pace: the game's group AI settings. */
    public function form(Request $request, int $squad, CommandWarSquadAction $command): RedirectResponse
    {
        $data = $request->validate([
            'formation' => ['nullable', Rule::in(CommandWarSquadAction::FORMATION)],
            'pace' => ['nullable', Rule::in(CommandWarSquadAction::PACE)],
        ]);
        $command->form($request->user(), $squad, $data['formation'] ?? null, $data['pace'] ?? null);

        return back();
    }

    /** One man's stance and pace: the game's per-soldier AI orders. */
    public function posture(Request $request, int $soldier, CommandWarSquadAction $command): RedirectResponse
    {
        $data = $request->validate([
            'stance' => ['nullable', Rule::in(CommandWarSquadAction::STANCE)],
            'pace' => ['nullable', Rule::in(CommandWarSquadAction::PACE)],
        ]);
        $command->posture($request->user(), $soldier, $data['stance'] ?? null, $data['pace'] ?? null);

        return back();
    }

    /** One man to another of your squads. */
    public function transfer(Request $request, int $soldier, CommandWarSquadAction $command): RedirectResponse
    {
        $data = $request->validate(['squad_id' => ['required', 'integer']]);
        $command->transfer($request->user(), $soldier, (int) $data['squad_id']);

        return back();
    }

    public function upgrade(Request $request, UpgradeWarSquadAction $upgrade): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['recruit', 'order', 'tune', 'lose', 'dismiss', 'remember', 'train', 'drill'])],
            'stat' => ['required_if:kind,drill', 'nullable', Rule::in(UpgradeWarSquadAction::DRILLS)],
            'squad_id' => ['nullable', 'integer'],
            'role' => ['required_if:kind,order,tune', 'nullable', 'string', Rule::in(WarEconomy::roleIds())],
            'soldier_id' => ['required_if:kind,tune,lose,dismiss,remember,drill', 'nullable', 'integer'],
            'name' => ['required_if:kind,remember', 'nullable', 'string', 'max:24'],
            'note' => ['nullable', 'string', 'max:80'],
        ]);

        $upgrade->apply($request->user(), $data);

        return back();
    }
}
