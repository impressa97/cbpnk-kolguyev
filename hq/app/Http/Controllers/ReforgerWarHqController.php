<?php

namespace App\Http\Controllers;

use App\Actions\Reforger\ManageWarTaskAction;
use App\Actions\Reforger\OrderWarVehicleAction;
use App\Actions\Reforger\RequestWarSupportAction;
use App\Support\WarEconomy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Kolguyev HQ desk: motor pool, support requests, and standing tasks. */
class ReforgerWarHqController extends Controller
{
    public function vehicle(Request $request, OrderWarVehicleAction $pool): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(array_keys(config('reforger.war.vehicles', [])))],
        ]);

        $pool->order($request->user(), $data['kind']);

        return back();
    }

    public function scrap(Request $request, int $vehicle, OrderWarVehicleAction $pool): RedirectResponse
    {
        $pool->scrap($request->user(), $vehicle);

        return back();
    }

    public function support(Request $request, RequestWarSupportAction $hq): RedirectResponse
    {
        $size = (int) config('reforger.war.world_size', 12800);
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in([...WarEconomy::supportIds(), 'deploy'])],
            'squad_id' => ['nullable', 'integer'],
            'x' => ['nullable', 'numeric', 'min:0', 'max:'.$size],
            'z' => ['nullable', 'numeric', 'min:0', 'max:'.$size],
        ]);

        $hq->request($request->user(), [
            'kind' => $data['kind'],
            'squad_id' => isset($data['squad_id']) ? (int) $data['squad_id'] : null,
            'x' => isset($data['x']) ? (float) $data['x'] : null,
            'z' => isset($data['z']) ? (float) $data['z'] : null,
        ]);

        return back();
    }

    public function cancelSupport(Request $request, int $support, RequestWarSupportAction $hq): RedirectResponse
    {
        $hq->cancel($request->user(), $support);

        return back();
    }

    public function task(Request $request, ManageWarTaskAction $tasks): RedirectResponse
    {
        $size = (int) config('reforger.war.world_size', 12800);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['hold', 'patrol'])],
            'size' => ['required', 'integer'],
            'label' => ['nullable', 'string', 'max:64'],
            'minutes' => ['nullable', 'integer', Rule::in(config('reforger.war.hq.durations', [30, 60, 120]))],
            'a_x' => ['required', 'numeric', 'min:0', 'max:'.$size],
            'a_z' => ['required', 'numeric', 'min:0', 'max:'.$size],
            'b_x' => ['nullable', 'required_if:kind,patrol', 'numeric', 'min:0', 'max:'.$size],
            'b_z' => ['nullable', 'required_if:kind,patrol', 'numeric', 'min:0', 'max:'.$size],
        ]);

        $tasks->create($request->user(), [
            'kind' => $data['kind'],
            'size' => (int) $data['size'],
            'label' => $data['label'] ?? '',
            'minutes' => (int) ($data['minutes'] ?? config('reforger.war.hq.durations.0', 30)),
            'a_x' => (float) $data['a_x'],
            'a_z' => (float) $data['a_z'],
            'b_x' => isset($data['b_x']) ? (float) $data['b_x'] : null,
            'b_z' => isset($data['b_z']) ? (float) $data['b_z'] : null,
        ]);

        return back();
    }

    public function cancelTask(Request $request, int $task, ManageWarTaskAction $tasks): RedirectResponse
    {
        $tasks->cancel($request->user(), $task);

        return back();
    }
}
