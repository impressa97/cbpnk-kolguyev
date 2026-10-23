<?php

namespace App\Actions\Reforger;

use App\Enums\WarSide;
use App\Models\User;
use App\Models\WarControl;
use App\Models\WarEnlistment;
use App\Models\WarTask;
use App\Support\WarTerritory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Requests to HQ: secure a point (hold) or a stretch of your own rear (patrol A–B) for a while
 * (hq.durations). HQ raises and runs the squad from its budget on its own and stands it down
 * when the time is up; see RunWarHqAction.
 */
final class ManageWarTaskAction
{
    public function __construct(
        private readonly ResolveWarSessionAction $sessions,
        private readonly RunWarHqAction $hq,
    ) {}

    /**
     * @param  array{kind: string, size: int, minutes?: int, label?: string|null, a_x: float, a_z: float, b_x?: float|null, b_z?: float|null}  $input
     */
    public function create(User $player, array $input): WarTask
    {
        $this->sessions->closeDue();

        $task = DB::transaction(function () use ($player, $input) {
            $session = $this->sessions->current();
            if ($session === null) {
                throw ValidationException::withMessages(['task' => ['This week is over.']]);
            }
            if (! WarEnlistment::query()->where('session_id', $session->id)->where('commander_id', $player->id)->exists()) {
                throw ValidationException::withMessages(['task' => ['Enlist a side first.']]);
            }

            $sizes = config('reforger.war.hq.sizes', [4, 6, 8]);
            if (! in_array((int) $input['size'], $sizes, true)) {
                throw ValidationException::withMessages(['size' => ['HQ sends squads of '.implode(', ', $sizes).'.']]);
            }
            if ($input['kind'] === 'patrol' && ($input['b_x'] === null || $input['b_z'] === null)) {
                throw ValidationException::withMessages(['b_x' => ['A patrol needs a second point.']]);
            }

            $side = WarEnlistment::query()->where('session_id', $session->id)->where('commander_id', $player->id)->value('side');
            foreach (array_filter([[$input['a_x'], $input['a_z']], $input['kind'] === 'patrol' ? [$input['b_x'], $input['b_z']] : null]) as [$x, $z]) {
                $enemy = $this->enemyGround($session->id, $side instanceof WarSide ? $side->value : (string) $side, (float) $x, (float) $z);
                if ($enemy !== null) {
                    throw ValidationException::withMessages([
                        'task' => ["{$enemy} is held by another side. HQ secures ground; taking it is your squads' job."],
                    ]);
                }
            }

            $open = WarTask::query()->where('session_id', $session->id)->where('commander_id', $player->id)->where('status', 'active')->count();
            if ($open >= 4) {
                throw ValidationException::withMessages(['task' => ['HQ is already running four tasks for you.']]);
            }

            return WarTask::query()->create([
                'session_id' => $session->id,
                'commander_id' => $player->id,
                'kind' => $input['kind'],
                'size' => (int) $input['size'],
                'label' => trim((string) ($input['label'] ?? '')),
                'a_x' => round((float) $input['a_x'], 2),
                'a_z' => round((float) $input['a_z'], 2),
                'b_x' => $input['kind'] === 'patrol' ? round((float) $input['b_x'], 2) : null,
                'b_z' => $input['kind'] === 'patrol' ? round((float) $input['b_z'], 2) : null,
                'note' => 'HQ is putting a squad together.',
                'ends_at' => now()->addMinutes((int) ($input['minutes'] ?? config('reforger.war.hq.durations.0', 30))),
            ]);
        }, 3);

        $this->hq->run();

        return $task->refresh();
    }

    /** Name of an enemy-held control point within capture range of the spot, if any. */
    private function enemyGround(int $sessionId, string $side, float $x, float $z): ?string
    {
        $radius = (float) config('reforger.war.territory.clear', 300);
        foreach (WarTerritory::points() as $place) {
            if (hypot($place['x'] - $x, $place['z'] - $z) > $radius) {
                continue;
            }
            $holder = WarControl::query()->where('session_id', $sessionId)->where('place', $place['id'])->value('side');
            if ($holder !== null && $holder !== $side) {
                return $place['name'];
            }
        }

        return null;
    }

    public function cancel(User $player, int $taskId): void
    {
        DB::transaction(function () use ($player, $taskId) {
            $task = WarTask::query()->whereKey($taskId)->where('commander_id', $player->id)->where('status', 'active')->lockForUpdate()->first();
            if ($task === null) {
                throw ValidationException::withMessages(['task' => ['That task is not running.']]);
            }

            $task->status = 'cancelled';
            $task->save();
        }, 3);

        $this->hq->run();
    }
}
