<?php

namespace App\Observers;

use App\Models\WarAiPlan;
use App\Models\WarContact;
use App\Models\WarControl;
use App\Models\WarOrder;
use App\Models\WarRun;
use App\Models\WarSoldier;
use App\Models\WarSquad;
use App\Models\WarSupport;
use App\Models\WarVehicle;
use App\Support\WarLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the war's log as the models change: orders given and how they ended, HQ support and
 * lifts, AI plans, squads raised and their readiness, soldiers joining, wounded, killed, places
 * taken, vehicles, new sightings. Only what changed is written; a soldier saved every tick with
 * his pulse and stamina writes nothing.
 *
 * Bulk updates (query()->update) pass it by; the actions that matter log those themselves.
 */
final class WarLogObserver
{
    public function created(Model $model): void
    {
        match (true) {
            $model instanceof WarOrder => $this->orderGiven($model),
            $model instanceof WarSupport => WarLog::write('support.requested', $model->kind.' requested'.($model->squad_id ? ' for sq '.$model->squad_id : '').WarLog::at($model->pos_x, $model->pos_z).', '.$model->cost.' cr',
                ['session_id' => $model->session_id, 'commander_id' => $model->commander_id, 'squad_id' => $model->squad_id, 'ref' => 'support:'.$model->id, 'x' => $model->pos_x, 'z' => $model->pos_z],
                ['kind' => $model->kind, 'vehicle' => $model->vehicle_id, 'ready_at' => $model->ready_at->toIso8601String()]),
            $model instanceof WarRun => WarLog::write('run.ordered', $model->kind.' run for sq '.$model->squad_id.' from '.round((float) $model->pick_x).','.round((float) $model->pick_z)
                .($model->drop_x !== null ? ' to '.round((float) $model->drop_x).','.round((float) $model->drop_z) : ''),
                ['session_id' => $model->session_id, 'commander_id' => $model->commander_id, 'squad_id' => $model->squad_id, 'other_id' => $model->crew_squad_id, 'ref' => 'run:'.$model->id, 'x' => $model->pick_x, 'z' => $model->pick_z],
                ['kind' => $model->kind, 'vehicle' => $model->vehicle_id, 'crew_squad' => $model->crew_squad_id, 'stage' => $model->stage, 'cost' => $model->cost]),
            $model instanceof WarAiPlan => WarLog::write('ai.plan', $model->side->value.' AI plans '.$model->kind.($model->place ? ' on '.$model->place : '').($model->squad_id ? ' with sq '.$model->squad_id : '').WarLog::at($model->pos_x, $model->pos_z),
                ['session_id' => $model->session_id, 'side' => $model->side->value, 'squad_id' => $model->squad_id, 'ref' => 'plan:'.$model->id, 'x' => $model->pos_x, 'z' => $model->pos_z],
                ['kind' => $model->kind, 'place' => $model->place, 'status' => $model->status, 'vehicle' => $model->vehicle_id]),
            $model instanceof WarSquad => WarLog::write('squad.raised', 'sq '.$model->id.' raised ('.$model->side->value.($model->commander_id === null ? ', AI' : '').', tier '.$model->tier.', '.$model->strength.' men)',
                WarLog::squad($model), ['tier' => $model->tier, 'strength' => $model->strength, 'managed' => $model->managed]),
            $model instanceof WarSoldier => WarLog::write('soldier.joined', WarLog::who($model).' joined',
                WarLog::soldier($model), ['role' => $model->role, 'aim' => $model->aim, 'grit' => $model->grit, 'fieldcraft' => $model->fieldcraft]),
            $model instanceof WarVehicle => WarLog::write('vehicle.ordered', $model->kind.' #'.$model->id.' for '.$model->side->value.($model->squad_id ? ' sq '.$model->squad_id : '').($model->hq ? ' (HQ)' : ''),
                ['session_id' => $model->session_id, 'side' => $model->side->value, 'commander_id' => $model->commander_id, 'squad_id' => $model->squad_id, 'ref' => 'vehicle:'.$model->id, 'x' => $model->pos_x, 'z' => $model->pos_z],
                ['kind' => $model->kind, 'cost' => $model->cost]),
            $model instanceof WarContact => WarLog::write('contact.new', 'sq '.$model->squad_id.' '.$model->how.' '.$model->side->value.' '.$model->kind.($model->target_id ? ' #'.$model->target_id : '').WarLog::at($model->pos_x, $model->pos_z),
                ['session_id' => $model->session_id, 'side' => $model->side->value, 'squad_id' => $model->squad_id, 'other_id' => $model->target_id, 'x' => $model->pos_x, 'z' => $model->pos_z],
                ['kind' => $model->kind, 'how' => $model->how, 'heading' => $model->heading]),
            default => null,
        };
    }

    public function updated(Model $model): void
    {
        match (true) {
            $model instanceof WarOrder => $this->orderChanged($model),
            $model instanceof WarSupport => $model->wasChanged('status')
                ? WarLog::write('support.'.$model->status, $model->kind.' '.$model->status.(filled($model->note) ? ': '.$model->note : ''),
                    ['session_id' => $model->session_id, 'commander_id' => $model->commander_id, 'squad_id' => $model->squad_id, 'ref' => 'support:'.$model->id, 'x' => $model->pos_x, 'z' => $model->pos_z])
                : null,
            $model instanceof WarRun => $this->runChanged($model),
            $model instanceof WarAiPlan => $model->wasChanged('status')
                ? WarLog::write('ai.'.$model->status, $model->side->value.' AI '.$model->kind.' '.$model->status.($model->squad_id ? ' (sq '.$model->squad_id.')' : ''),
                    ['session_id' => $model->session_id, 'side' => $model->side->value, 'squad_id' => $model->squad_id, 'ref' => 'plan:'.$model->id, 'x' => $model->pos_x, 'z' => $model->pos_z])
                : null,
            $model instanceof WarSquad => $this->squadChanged($model),
            $model instanceof WarSoldier => $this->soldierChanged($model),
            $model instanceof WarControl => $this->placeChanged($model),
            $model instanceof WarVehicle => $model->wasChanged('status')
                ? WarLog::write('vehicle.'.$model->status, $model->kind.' #'.$model->id.' '.$model->status.' (health '.$model->health.', crew '.$model->crew.')',
                    ['session_id' => $model->session_id, 'side' => $model->side->value, 'commander_id' => $model->commander_id, 'squad_id' => $model->squad_id, 'ref' => 'vehicle:'.$model->id, 'x' => $model->pos_x, 'z' => $model->pos_z])
                : null,
            default => null,
        };
    }

    private function orderGiven(WarOrder $order): void
    {
        $by = $order->commander_id === null ? 'AI' : 'commander '.$order->commander_id;
        $text = 'sq '.$order->squad_id.' '.$order->kind.WarLog::at($order->pos_x, $order->pos_z).' ('.$by.', '.$order->status->value.')';
        if (filled($order->note)) {
            $text .= ': '.$order->note;
        }
        WarLog::write('order.given', $text, [
            'session_id' => $order->session_id,
            'side' => $order->squad?->side->value,
            'commander_id' => $order->commander_id,
            'squad_id' => $order->squad_id,
            'soldier_id' => $order->soldier_id,
            'other_id' => $order->follow_squad_id ?? $order->vehicle_id,
            'order_id' => $order->id,
            'x' => $order->pos_x,
            'z' => $order->pos_z,
        ], array_filter([
            'kind' => $order->kind,
            'status' => $order->status->value,
            'target' => $order->target,
            'vehicle' => $order->vehicle_id,
            'follow' => $order->follow_squad_id,
            'legs' => $order->route === null ? null : count($order->route),
            'attempt' => $order->attempt ?: null,
        ], fn ($v) => $v !== null));
    }

    private function orderChanged(WarOrder $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }
        $squad = $order->squad;
        $text = 'sq '.$order->squad_id.' '.$order->kind.' #'.$order->id.' '.$order->status->value;
        if (filled($order->note)) {
            $text .= ': '.$order->note;
        }
        WarLog::write('order.'.$order->status->value, $text, [
            'session_id' => $order->session_id,
            'side' => $squad?->side->value,
            'commander_id' => $order->commander_id,
            'squad_id' => $order->squad_id,
            'order_id' => $order->id,
            // Where the squad is now: for a failed move, where it got stuck.
            'x' => $squad === null ? $order->pos_x : $squad->pos_x,
            'z' => $squad === null ? $order->pos_z : $squad->pos_z,
        ], ['from' => $order->getOriginal('status') instanceof \BackedEnum ? $order->getOriginal('status')->value : $order->getOriginal('status'), 'doing' => $squad?->doing]);
    }

    private function runChanged(WarRun $run): void
    {
        if (! $run->wasChanged(['stage', 'status'])) {
            return;
        }
        $kind = $run->wasChanged('status') ? 'run.'.$run->status : 'run.stage';
        $text = $run->kind.' run #'.$run->id.' (sq '.$run->squad_id.') '.($run->wasChanged('status') ? $run->status : $run->getOriginal('stage').' → '.$run->stage);
        if (filled($run->note)) {
            $text .= ': '.$run->note;
        }
        $vehicle = $run->vehicle_id === null ? null : WarVehicle::query()->find($run->vehicle_id, ['id', 'pos_x', 'pos_z']);
        WarLog::write($kind, $text, [
            'session_id' => $run->session_id,
            'commander_id' => $run->commander_id,
            'squad_id' => $run->squad_id,
            'other_id' => $run->crew_squad_id,
            'ref' => 'run:'.$run->id,
            'x' => $vehicle?->pos_x,
            'z' => $vehicle?->pos_z,
        ], ['stage' => $run->stage, 'status' => $run->status, 'tries' => $run->tries, 'vehicle' => $run->vehicle_id, 'riders' => $run->riders]);
    }

    private function squadChanged(WarSquad $squad): void
    {
        foreach (['alert' => 'readiness', 'combat' => 'combat mode'] as $field => $label) {
            if ($squad->wasChanged($field)) {
                WarLog::write('squad.'.$field, 'sq '.$squad->id.' '.$label.' '.$squad->getOriginal($field).' → '.$squad->getAttribute($field), WarLog::squad($squad));
            }
        }
        if ($squad->wasChanged('commander_id')) {
            WarLog::write('squad.handover', 'sq '.$squad->id.' now '.($squad->commander_id === null ? 'the AI\'s' : 'commander '.$squad->commander_id.'\'s'), WarLog::squad($squad));
        }
    }

    private function soldierChanged(WarSoldier $soldier): void
    {
        if ($soldier->wasChanged('fallen_at') && $soldier->fallen_at !== null) {
            if ($soldier->loss === 'killed') {
                // The mod's DEATH line rewrites this row with who did it, when it comes.
                WarLog::write('soldier.killed', WarLog::who($soldier).' killed'.WarLog::at($soldier->pos_x, $soldier->pos_z), WarLog::soldier($soldier), [], 'death:'.$soldier->id);
            } else {
                WarLog::write('soldier.'.($soldier->loss ?? 'gone'), WarLog::who($soldier).' '.($soldier->loss ?? 'gone'), WarLog::soldier($soldier));
            }

            return;
        }
        if ($soldier->wasChanged('location')) {
            WarLog::write('soldier.'.$soldier->location, WarLog::who($soldier).($soldier->location === 'reserve' ? ' off the island (health '.$soldier->health.')' : ' back on the island'), WarLog::soldier($soldier));
        }
        if ($soldier->wasChanged('squad_id')) {
            WarLog::write('soldier.moved', WarLog::who($soldier).' moved from sq '.$soldier->getOriginal('squad_id'), [...WarLog::soldier($soldier), 'other_id' => $soldier->getOriginal('squad_id')]);
        }
        // Down: the moment a man is hit hard enough to drop.
        if ($soldier->wasChanged('down') && $soldier->down) {
            WarLog::write('soldier.down', WarLog::who($soldier).' down (health '.$soldier->health.($soldier->bleeding ? ', bleeding' : '').')'.WarLog::at($soldier->pos_x, $soldier->pos_z),
                [...WarLog::soldier($soldier), 'other_id' => $soldier->target_id]);
        }
        foreach (['aim', 'grit', 'fieldcraft', 'endurance'] as $trait) {
            if ($soldier->wasChanged($trait)) {
                WarLog::write('soldier.drilled', WarLog::who($soldier).' '.$trait.' '.$soldier->getOriginal($trait).' → '.$soldier->getAttribute($trait), WarLog::soldier($soldier));
            }
        }
    }

    private function placeChanged(WarControl $control): void
    {
        if ($control->wasChanged('side')) {
            WarLog::write('place.taken', $control->place.' taken by '.($control->side ?? 'nobody').' from '.($control->getOriginal('side') ?? 'nobody'),
                ['session_id' => $control->session_id, 'side' => $control->side, 'ref' => 'place:'.$control->place]);
        } elseif ($control->wasChanged('claim_side') && $control->claim_side !== null) {
            WarLog::write('place.claimed', $control->place.' being taken by '.$control->claim_side.' (held by '.($control->side ?? 'nobody').')',
                ['session_id' => $control->session_id, 'side' => $control->claim_side, 'ref' => 'place:'.$control->place]);
        }
    }
}
