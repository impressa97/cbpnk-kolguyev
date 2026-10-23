<?php

namespace App\Actions\Reforger;

use App\Enums\WarOrderKind;
use App\Enums\WarOrderStatus;
use App\Models\WarOrder;
use App\Models\WarSoldier;
use App\Support\WarLog;
use Illuminate\Support\Facades\DB;

/**
 * Squads' order chains: once the game completes a squad's order, the next one waiting in its
 * chain goes to the game. A failed order stops the chain (boarding a burnt-out truck and then
 * driving it makes no sense), and so does a squad with nobody left on the island.
 *
 * The game calls a get-in done when the leader is in, and a get-out when he is out: the rest of
 * the squad may still be walking to the vehicle. A drive sent then leaves them running after it.
 * So after a board the next order waits until every man of the squad in the field is aboard,
 * after a dismount until nobody is, at most SETTLE_SECONDS; then it goes anyway.
 */
final class AdvanceWarOrdersAction
{
    public const SETTLE_SECONDS = 150;

    public function advance(): void
    {
        DB::transaction(function () {
            $squads = WarOrder::query()->where('status', WarOrderStatus::Queued)->distinct()->pluck('squad_id');

            foreach ($squads as $squadId) {
                $open = WarOrder::query()
                    ->where('squad_id', $squadId)
                    ->whereIn('status', [WarOrderStatus::Queued, WarOrderStatus::Pending, WarOrderStatus::Acked])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($open->contains(fn (WarOrder $o) => $o->status !== WarOrderStatus::Queued)) {
                    continue;
                }

                $next = $open->first();
                $before = WarOrder::query()
                    ->where('squad_id', $squadId)
                    ->where('id', '<', $next->id)
                    ->where('status', '!=', WarOrderStatus::Queued)
                    ->orderByDesc('id')
                    ->first();
                $broken = $before?->status === WarOrderStatus::Failed
                    || ! $next->squad()->whereHas('soldiers', fn ($q) => $q->whereNull('fallen_at')->where('location', 'field'))->exists();

                if ($broken) {
                    WarLog::write('order.chain_stopped', 'sq '.$squadId.': '.$open->count().' queued orders dropped, '.($before?->status === WarOrderStatus::Failed ? 'order #'.$before->id.' failed' : 'nobody left'),
                        ['session_id' => $next->session_id, 'commander_id' => $next->commander_id, 'squad_id' => $squadId, 'order_id' => $before?->id], ['orders' => $open->pluck('id')->all()]);
                    WarOrder::query()->whereKey($open->pluck('id'))->update([
                        'status' => WarOrderStatus::Superseded,
                        'note' => $before?->status === WarOrderStatus::Failed ? 'The order before it failed: the chain stopped.' : 'Nobody left to carry it out.',
                    ]);

                    continue;
                }

                if ($before !== null && ! $this->settled($before)) {
                    continue;
                }

                $next->status = WarOrderStatus::Pending;
                $next->save();
            }
        });
    }

    /** Whether the squad has finished what a done board or dismount is about. */
    private function settled(WarOrder $before): bool
    {
        if ($before->status !== WarOrderStatus::Done || ! in_array($before->kind, [WarOrderKind::Mount->value, WarOrderKind::Dismount->value], true)) {
            return true;
        }
        if ($before->updated_at !== null && $before->updated_at->lt(now()->subSeconds(self::SETTLE_SECONDS))) {
            return true;
        }
        $field = WarSoldier::query()->where('squad_id', $before->squad_id)->whereNull('fallen_at')->where('location', 'field')->get();

        return $before->kind === WarOrderKind::Mount->value
            ? $field->every(fn (WarSoldier $s) => $s->in_vehicle)
            : $field->every(fn (WarSoldier $s) => ! $s->in_vehicle);
    }
}
