<?php

namespace App\Actions\Reforger;

use App\Support\WarLock;

use App\Models\WarEnlistment;
use App\Models\WarSession;
use App\Models\WarSquad;
use App\Support\WarEconomy;
use Illuminate\Support\Facades\DB;

/** Close a week when its real end has passed, and open the next one on enlistment. */
final class ResolveWarSessionAction
{
    public function __construct(
        private readonly AccrueWarSuppliesAction $supplies,
        private readonly AccrueWarServiceAction $service,
    ) {}

    public function closeDue(): void
    {
        WarLock::run(fn () => DB::transaction(function () {

            $open = WarSession::query()->whereNull('closed_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($open === null || $open->ends_at->greaterThan(now())) {
                return;
            }

            $enlistments = WarEnlistment::query()->where('session_id', $open->id)->lockForUpdate()->get();
            foreach ($enlistments as $enlistment) {
                $this->supplies->accrue($enlistment, $open->ends_at);
            }

            $squads = WarSquad::query()->where('session_id', $open->id)->lockForUpdate()->get();
            foreach ($squads as $squad) {
                $this->service->accrue($squad, $open->ends_at);
            }

            $open->closed_at = $open->ends_at;
            $open->save();
        }));
    }

    public function current(): ?WarSession
    {
        $session = WarSession::query()->whereNull('closed_at')->orderByDesc('id')->first();

        return $session !== null && $session->isOpen() ? $session : null;
    }

    public function start(): WarSession
    {
        $start = now();

        return WarSession::query()->create([
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(WarEconomy::sessionDays()),
        ]);
    }

    public function display(): ?WarSession
    {
        return $this->current() ?? WarSession::query()->orderByDesc('id')->first();
    }
}
