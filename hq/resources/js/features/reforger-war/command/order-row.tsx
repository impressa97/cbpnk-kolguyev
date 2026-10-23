import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Toggle } from '@/components/ui/toggle';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { WarCommand } from '@/lib/schemas/reforger';
import { uiText } from '@/lib/ui-tokens';
import { ListPlus, LogOut, Route, Send, Swords, X } from 'lucide-react';
import {
    assaultPayload,
    boardable,
    followable,
    HQ_LIFT,
    logistics,
    MAX_LEGS,
    orderBlocked,
    orderPayload,
    orderSpec,
    RIDES,
    runFor,
    type Marks,
    type OrderOptions,
    type SquadOrderKind,
} from '../lib/orders';
import { useWarSending, warApi } from '../lib/war-api';
import { bearing, distance, formatDistance, formatEta, gridRef } from '../map-geometry';
import type { Draft, Squad } from './types';

/** Where the order goes and how: the target, the vehicle or squad it names, riding, a route; send or chain it. */
export function OrderRow({
    war,
    squad,
    draft,
    chained,
    onOptions,
    onClearMarks,
    onSend,
}: {
    war: WarCommand;
    squad: Squad;
    draft: Draft;
    chained: boolean;
    onOptions: (patch: Partial<OrderOptions>) => void;
    onClearMarks: () => void;
    onSend: (append: boolean) => void;
}) {
    const { kind, marks } = draft;
    const sending = useWarSending();
    const spec = orderSpec(war, kind);
    const vehicles = boardable(war, squad);
    const leaders = followable(war, squad);
    const need = logistics(war, squad);
    const now = orderPayload(war, squad, kind, marks, draft);
    const assault = assaultPayload(war, squad, marks.a);
    const then = orderPayload(war, squad, kind, marks, draft, true);
    const moving = kind === 'move' || kind === 'move_forced';
    const rides = RIDES.includes(kind) && need.aboard === 0 && (vehicles.length > 0 || kind === 'move' || kind === 'move_forced');
    const lift = rides && draft.ride === HQ_LIFT && moving;
    const riding = rides && vehicles.some((v) => v.id === draft.ride);
    const drives = riding || need.aboard > 0;
    // Room in the vehicle being boarded or ridden: whoever does not fit stays on foot.
    const into =
        kind === 'mount'
            ? (vehicles.find((v) => v.id === draft.vehicleId) ?? vehicles[0])
            : riding
              ? vehicles.find((v) => v.id === draft.ride)
              : undefined;
    const field = squad.condition.field;
    const room = into && into.seats !== null ? Math.max(0, into.seats - into.crew) : null;
    const short = room !== null && room < field ? `${into!.model}: room for ${room}, ${field - room} of ${squad.label} stay on foot.` : null;

    return (
        <div className="flex flex-col gap-1.5">
            {short ? <p className="px-1 text-xs text-map-warning">{short}</p> : null}
            <div className="flex flex-wrap items-center gap-1.5">
                {spec.mark === 'vehicle' && vehicles.length > 0 ? (
                    <NativeSelect
                        className="min-w-0 flex-1"
                        size="sm"
                        value={draft.vehicleId ?? vehicles[0].id}
                        onChange={(e) => onOptions({ vehicleId: Number(e.target.value) })}
                        aria-label="Vehicle to board"
                    >
                        {vehicles.map((v) => (
                            <NativeSelectOption key={v.id} value={v.id}>
                                {v.model} · {formatDistance(v.metres)} · hull {v.health}% · fuel {v.fuel}% · {v.crew}/{v.seats ?? '?'}
                            </NativeSelectOption>
                        ))}
                    </NativeSelect>
                ) : spec.mark === 'squad' && leaders.length > 0 ? (
                    <NativeSelect
                        className="min-w-0 flex-1"
                        size="sm"
                        value={draft.followId ?? leaders[0].id}
                        onChange={(e) => onOptions({ followId: Number(e.target.value) })}
                        aria-label="Squad to follow"
                    >
                        {leaders.map((s) => (
                            <NativeSelectOption key={s.id} value={s.id}>
                                {s.label} · {formatDistance(distance(squad.x, squad.z, s.x, s.z))}
                            </NativeSelectOption>
                        ))}
                    </NativeSelect>
                ) : (
                    <p className="min-w-40 flex-1 px-1 text-xs leading-snug">
                        <Target
                            war={war}
                            squad={squad}
                            kind={kind}
                            marks={marks}
                            error={'error' in now ? now.error : null}
                            driven={drives && moving}
                        />
                    </p>
                )}
                {marks.a ? (
                    <Button type="button" size="icon-sm" variant="ghost" aria-label="Clear the mark" onClick={onClearMarks}>
                        <X />
                    </Button>
                ) : null}
            </div>
            <div className="flex flex-wrap items-center justify-end gap-1.5">
                {rides ? (
                    <NativeSelect
                        size="sm"
                        className="max-w-44"
                        value={riding || lift ? (draft.ride ?? '') : ''}
                        onChange={(e) => {
                            const ride = e.target.value === '' ? null : Number(e.target.value);
                            // Riding somewhere on foot's behalf: out at the end; on foot: nothing to get out of.
                            onOptions({ ride, getOut: ride !== null });
                        }}
                        aria-label="How they get there"
                        title="Board one of your vehicles first and drive there"
                    >
                        <NativeSelectOption value="">On foot</NativeSelectOption>
                        {moving && !runFor(war, squad.id) ? (
                            <NativeSelectOption value={HQ_LIFT}>By HQ truck · {war.run_costs.transport}</NativeSelectOption>
                        ) : null}
                        {vehicles.map((v) => (
                            <NativeSelectOption key={v.id} value={v.id}>
                                By {v.model} · {formatDistance(v.metres)}
                            </NativeSelectOption>
                        ))}
                    </NativeSelect>
                ) : null}
                {drives && moving ? (
                    <Toggle
                        size="sm"
                        variant="outline"
                        pressed={draft.getOut}
                        onPressedChange={(getOut) => onOptions({ getOut })}
                        aria-label="Everyone out when they get there"
                    >
                        <LogOut /> Out
                    </Toggle>
                ) : null}
                {moving ? (
                    <Toggle
                        size="sm"
                        variant="outline"
                        pressed={draft.route}
                        onPressedChange={(route) => onOptions({ route })}
                        aria-label={`Draw a route: every click after the first adds a point (up to ${MAX_LEGS + 1})`}
                    >
                        <Route /> {draft.route && marks.legs.length > 0 ? `+${marks.legs.length}` : null}
                    </Toggle>
                ) : null}
                {marks.a && !('error' in now) ? (
                    <Badge
                        variant="outline"
                        className="border-map-warning/50 bg-map-warning/10 text-map-warning"
                        title="Drawn up on the map, not sent yet"
                    >
                        Not sent
                    </Badge>
                ) : null}
                {(kind === 'attack' || kind === 'search' || moving) && marks.a ? (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span className="flex">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={'error' in assault || sending}
                                    aria-label={`Assault with ${squad.label}`}
                                    onClick={() => 'payload' in assault && warApi.order(assault.payload, { onSuccess: onClearMarks })}
                                >
                                    <Swords /> Assault
                                </Button>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent className="max-w-72">
                            {'error' in assault
                                ? assault.error
                                : 'Rush to a line 150 m short of the mark (no stopping to fight on the way), then search and destroy at the mark. Set formation and Free fire first.'}
                        </TooltipContent>
                    </Tooltip>
                ) : null}
                {chained && kind !== 'smoke' && kind !== 'watch' ? (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span className="flex">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={'error' in then || sending}
                                    aria-label={`Then ${spec.label}`}
                                    onClick={() => onSend(true)}
                                >
                                    <ListPlus /> Then
                                </Button>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent className="max-w-72">
                            {'error' in then ? then.error : 'Add it to the chain: it starts once the game completes the order before it.'}
                        </TooltipContent>
                    </Tooltip>
                ) : null}
                <Button
                    type="button"
                    size="sm"
                    className="tracking-wide uppercase"
                    disabled={'error' in now || sending}
                    title={chained ? 'Replaces what the squad is doing and its chain' : undefined}
                    aria-label={`Send ${squad.label}: ${spec.label}`}
                    onClick={() => onSend(false)}
                >
                    <Send /> {spec.label}
                    {riding ? ' by car' : ''}
                </Button>
            </div>
        </div>
    );
}

/** What the order will do and where: the prompt to mark, or the mark's range, bearing and walk. */
function Target({
    war,
    squad,
    kind,
    marks,
    error,
    driven,
}: {
    war: WarCommand;
    squad: Squad;
    kind: SquadOrderKind;
    marks: Marks;
    error: string | null;
    driven: boolean;
}) {
    const spec = orderSpec(war, kind);
    const op = war.operations.find((o) => o.id === kind);
    if (spec.mark === 'none') {
        return error ? <span className={uiText.danger}>{error}</span> : <span className="text-muted-foreground">{spec.help}</span>;
    }
    if (!marks.a) {
        const blocked = orderBlocked(war, squad, kind);
        if (blocked) {
            return <span className={uiText.danger}>{blocked}</span>;
        }
        const where = op?.required
            ? `near a highlighted ${op.targets.join(' or ')}`
            : kind === 'patrol'
              ? 'one end of the beat'
              : kind === 'attack'
                ? 'an enemy contact'
                : 'the target';

        return <span className="text-muted-foreground">Click the map: {where}.</span>;
    }
    if (spec.mark === 'two' && !marks.b) {
        return <span className="text-muted-foreground">Now click the other end of the beat.</span>;
    }
    if (error) {
        return <span className={uiText.danger}>{error}</span>;
    }
    const a = marks.a;
    const place = war.places.find((p) => p.x === a.x && p.z === a.z);
    const metres = distance(squad.x, squad.z, a.x, a.z);

    return (
        <span>
            <span className="font-medium">{place?.name ?? gridRef(a.x, a.z)}</span>
            <span className="text-muted-foreground">
                {' '}
                · {formatDistance(metres)} {bearing(squad.x, squad.z, a.x, a.z)}° · {formatEta(metres, driven)} {driven ? 'driven' : 'on foot'}
                {marks.b ? ` · beat ${formatDistance(distance(a.x, a.z, marks.b.x, marks.b.z))}` : ''}
            </span>
        </span>
    );
}
