<?php

namespace App\Http\Controllers;

use App\Actions\Reforger\ExportWarOrdersAction;
use App\Actions\Reforger\IngestWarStateAction;
use App\Enums\WarOrderStatus;
use App\Support\WarBridgeCodec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Host bridge ↔ Reforger mod. Gated by the Reforger bridge secret. */
class ReforgerWarBridgeController extends Controller
{
    public function orders(Request $request, ExportWarOrdersAction $export): JsonResponse|Response
    {
        $bundle = $export->export();

        if ($request->query('format') === 'tsv') {
            return response(WarBridgeCodec::encodeOrders($bundle), 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        return response()->json($bundle);
    }

    public function state(Request $request, IngestWarStateAction $ingest): JsonResponse
    {
        $contentType = (string) $request->header('Content-Type', '');
        if (str_contains($contentType, 'application/json')) {
            $data = $request->validate([
                'squads' => ['present', 'array'],
                'squads.*.id' => ['required', 'integer'],
                'squads.*.x' => ['required', 'numeric'],
                'squads.*.z' => ['required', 'numeric'],
                'squads.*.alive' => ['required', 'integer', 'min:0'],
                'orders' => ['present', 'array'],
                'orders.*.id' => ['required', 'integer'],
                'orders.*.status' => ['required', Rule::in([
                    WarOrderStatus::Acked->value,
                    WarOrderStatus::Done->value,
                    WarOrderStatus::Failed->value,
                ])],
                'dead' => ['sometimes', 'array'],
                'dead.*' => ['integer'],
                'contacts' => ['sometimes', 'array'],
                'contacts.*.squad_id' => ['required', 'integer'],
                'contacts.*.side' => ['required', Rule::in(['us', 'ussr', 'fia'])],
                'contacts.*.x' => ['required', 'numeric'],
                'contacts.*.z' => ['required', 'numeric'],
                'contacts.*.kind' => ['required', Rule::in(['inf', 'veh'])],
                'contacts.*.how' => ['required', Rule::in(['seen', 'heard'])],
                'contacts.*.age' => ['required', 'integer', 'min:0'],
            ]);
            $ingest->apply(
                $this->jsonSquads($data['squads']),
                $this->jsonOrders($data['orders']),
                array_map('intval', $data['dead'] ?? []),
                array_map(fn (array $row) => [
                    'squad_id' => (int) $row['squad_id'],
                    'side' => (string) $row['side'],
                    'x' => (float) $row['x'],
                    'z' => (float) $row['z'],
                    'kind' => (string) $row['kind'],
                    'how' => (string) $row['how'],
                    'age' => (int) $row['age'],
                ], $data['contacts'] ?? []),
            );

            return response()->json(['ok' => true]);
        }

        $decoded = WarBridgeCodec::decodeState((string) $request->getContent());
        $ingest->ingest($decoded);

        return response()->json(['ok' => true]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: int, x: float, z: float, alive: int}>
     */
    private function jsonSquads(array $rows): array
    {
        return array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'x' => (float) $row['x'],
            'z' => (float) $row['z'],
            'alive' => (int) $row['alive'],
        ], $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: int, status: string}>
     */
    private function jsonOrders(array $rows): array
    {
        return array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'status' => $row['status'] instanceof WarOrderStatus ? $row['status']->value : (string) $row['status'],
        ], $rows);
    }
}
