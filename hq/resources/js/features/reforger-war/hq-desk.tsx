import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Item } from '@/components/ui/item';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { WarCommand } from '@/lib/schemas/reforger';
import { uiText } from '@/lib/ui-tokens';
import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';
import { Anchor, Binoculars, Bomb, Crosshair, Flag, LifeBuoy, Route, Shield, Star, Truck, type LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { ServiceButton } from './command-panel';
import type { SquadOrderKind } from './lib/orders';
import { warApi } from './lib/war-api';
import { formatDistance, gridRef } from './map-geometry';
import { countdown, DOING } from './war-format';

type Point = { x: number; z: number };

const OBJECTIVE_ICON: Record<WarCommand['objectives'][number]['kind'], LucideIcon> = {
    capture: Flag,
    supply_run: Truck,
    interdict: Binoculars,
    sabotage: Bomb,
    raid: Anchor,
};

const STATUS_TONE: Record<string, string> = {
    requested: 'border-map-accent/40 bg-map-accent/10 text-map-accent',
    waiting: 'border-map-warning/40 bg-map-warning/15 text-map-warning',
    done: 'border-map-success/40 bg-map-success/10 text-map-success',
    aborted: 'border-destructive/40 bg-destructive/10 text-destructive',
    failed: 'border-destructive/40 bg-destructive/10 text-destructive',
    cancelled: '',
};

const STATUS_TEXT: Record<string, string> = {
    requested: 'on the way',
    waiting: 'circling: LZ hot',
    done: 'done',
    aborted: 'called off',
    failed: 'failed',
    cancelled: 'cancelled',
};

/**
 * One place to ask HQ for anything: see its objectives (bounties), call support for a squad or
 * on the mark, hand it standing tasks (secure a point, patrol your rear), and draw vehicles.
 */
export function HqDesk({
    war,
    pin,
    onFocus,
    onPursue,
}: {
    war: WarCommand;
    pin: Point | null;
    onFocus: (point: Point) => void;
    /** Set up the order that completes an objective, on the map. */
    onPursue: (point: Point, kind: SquadOrderKind) => void;
}) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const open = war.session.open && war.enlisted_side !== null;
    const openObjectives = war.objectives.filter((o) => o.status === 'open');
    const live = war.support.filter((s) => s.status === 'requested' || s.status === 'waiting');

    return (
        <Card className="gap-3">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-sm tracking-widest uppercase">
                    <Shield className="size-4" /> Headquarters
                </CardTitle>
                <CardDescription>
                    HQ backs you up: bounties to chase, fire on call, squads of its own to secure ground for a while, and the motor pool. The
                    fighting, the operations and the logistics are yours.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Tabs defaultValue="objectives">
                    <TabsList className="w-full">
                        <TabsTrigger value="objectives" title="Objectives">
                            <Star /> {openObjectives.length > 0 ? openObjectives.length : null}
                        </TabsTrigger>
                        <TabsTrigger value="support" title="Fire support">
                            <LifeBuoy /> {live.length > 0 ? live.length : null}
                        </TabsTrigger>
                        <TabsTrigger value="tasks" title="Standing tasks">
                            <Route /> {war.tasks.length > 0 ? war.tasks.length : null}
                        </TabsTrigger>
                        <TabsTrigger value="pool" title="Motor pool">
                            <Truck />
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="objectives" className="flex flex-col gap-2 pt-3">
                        <Objectives war={war} onFocus={onFocus} onPursue={onPursue} />
                    </TabsContent>

                    <TabsContent value="support" className="flex flex-col gap-3 pt-3">
                        <Support war={war} onFocus={onFocus} />
                    </TabsContent>

                    <TabsContent value="tasks" className="flex flex-col gap-3 pt-3">
                        <Tasks war={war} pin={pin} enabled={open} onFocus={onFocus} />
                    </TabsContent>

                    <TabsContent value="pool" className="flex flex-col gap-3 pt-3">
                        <MotorPool war={war} enabled={open} onFocus={onFocus} />
                    </TabsContent>
                </Tabs>
                {errors.support || errors.vehicle || errors.task || errors.size || errors.b_x ? (
                    <p className={cn('mt-2', uiText.danger)}>{errors.support ?? errors.vehicle ?? errors.task ?? errors.size ?? errors.b_x}</p>
                ) : null}
            </CardContent>
        </Card>
    );
}

/** The squad order that completes each kind of objective. */
const OBJECTIVE_ORDER: Record<WarCommand['objectives'][number]['kind'], SquadOrderKind> = {
    capture: 'defend',
    supply_run: 'move',
    interdict: 'recon',
    sabotage: 'sabotage',
    raid: 'raid',
};

function Objectives({
    war,
    onFocus,
    onPursue,
}: {
    war: WarCommand;
    onFocus: (point: Point) => void;
    onPursue: (point: Point, kind: SquadOrderKind) => void;
}) {
    if (war.objectives.length === 0) {
        return <p className="text-sm text-muted-foreground">Enlist to receive HQ objectives.</p>;
    }

    return (
        <>
            <p className="text-xs text-muted-foreground">
                Your side earns {war.income.base}/h, plus {war.income.territory}/h from the ground it holds. Every objective pays the commander whose
                squad completes it.
            </p>
            <ul className="flex flex-col gap-1.5">
                {war.objectives.map((objective) => (
                    <Item
                        asChild
                        key={objective.id}
                        variant="outline"
                        size="sm"
                        className={cn('flex-nowrap items-stretch gap-1 p-0', objective.status !== 'open' && 'opacity-60')}
                    >
                        <li>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => onFocus({ x: objective.x, z: objective.z })}
                                className="h-auto min-w-0 flex-1 items-start justify-start gap-3 px-3 py-2 text-left font-normal whitespace-normal"
                            >
                                {(() => {
                                    const Icon = OBJECTIVE_ICON[objective.kind];

                                    return <Icon className="mt-0.5 size-4 shrink-0 text-map-warning" />;
                                })()}
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="text-sm font-medium">{objective.title}</span>
                                    <span className="text-xs text-muted-foreground">
                                        <span className="font-mono">{gridRef(objective.x, objective.z)}</span>
                                        {objective.status === 'open'
                                            ? ` · ${objective.stage === 'loaded' ? 'cargo aboard, head home' : objective.progress_at ? 'in progress' : 'open'} · ${countdown(
                                                  Math.max(0, (new Date(objective.expires_at).getTime() - Date.now()) / 1000),
                                              )} left`
                                            : objective.status === 'done'
                                              ? objective.mine
                                                  ? ' · done by you'
                                                  : ' · done by your side'
                                              : ' · lapsed'}
                                    </span>
                                </span>
                                <Badge variant="outline" className="shrink-0 border-map-warning/40 font-mono text-map-warning">
                                    +{objective.reward}
                                </Badge>
                            </Button>
                            {objective.status === 'open' ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="h-auto shrink-0 rounded-l-none border-l px-2 text-xs"
                                    title="Set up the order that completes it on the map"
                                    onClick={() =>
                                        // Cargo aboard: the run ends at home.
                                        objective.kind === 'supply_run' && objective.stage === 'loaded'
                                            ? onPursue(war.sides.find((s) => s.id === war.enlisted_side)?.home ?? objective, 'rest')
                                            : onPursue({ x: objective.x, z: objective.z }, OBJECTIVE_ORDER[objective.kind])
                                    }
                                >
                                    <Crosshair /> Go
                                </Button>
                            ) : null}
                        </li>
                    </Item>
                ))}
            </ul>
        </>
    );
}

/**
 * Fire on call is aimed from the command panel's palette (HQ fire), like any order; here are
 * its missions and how they went. One that has not landed yet can be called off for a refund.
 * HQ flies nobody out and drops nothing: wounded men and ammunition are logistics on the ground.
 */
function Support({ war, onFocus }: { war: WarCommand; onFocus: (point: Point) => void }) {
    return (
        <>
            <p className="text-xs text-muted-foreground">
                Pick a fire mission under HQ fire in the command panel, mark the map where it lands and press Call fire. The wounded go home to the
                hospital by road; squads rearm at home or beside one of your trucks.
            </p>
            <ul className="grid gap-1 text-xs text-muted-foreground">
                {war.support_catalog.map((offer) => (
                    <li key={offer.id}>
                        <span className="font-medium text-foreground">{offer.name}</span> · {offer.cost} · lands in{' '}
                        {Math.round(offer.delay / 60) || 1} min
                        {offer.danger_close > 0 ? ` · not within ${offer.danger_close} m of your troops` : ''}
                        {offer.ready_at !== null && new Date(offer.ready_at).getTime() > Date.now()
                            ? ` · battery ready in ${countdown((new Date(offer.ready_at).getTime() - Date.now()) / 1000)}`
                            : ''}
                    </li>
                ))}
            </ul>
            {war.support.length > 0 ? (
                <ul className="flex flex-col gap-1 text-sm">
                    {war.support.map((request) => (
                        <li key={request.id} className="flex items-start gap-2">
                            <Button
                                type="button"
                                variant="link"
                                className="h-auto flex-1 flex-wrap justify-start gap-0 p-0 text-left font-normal whitespace-normal"
                                onClick={() => request.x !== null && request.z !== null && onFocus({ x: request.x, z: request.z })}
                            >
                                <span className="font-medium">{request.name}</span>
                                <Badge variant="outline" className={cn('ml-1.5', STATUS_TONE[request.status])}>
                                    {STATUS_TEXT[request.status] ?? request.status}
                                </Badge>
                                <span className="text-muted-foreground">
                                    {request.status === 'requested' && request.kind !== 'resupply'
                                        ? ` · ${countdown(Math.max(0, (new Date(request.ready_at).getTime() - Date.now()) / 1000))}`
                                        : ''}
                                    {request.note ? ` · ${request.note}` : ''}
                                </span>
                            </Button>
                            {request.status === 'requested' && new Date(request.ready_at).getTime() > Date.now() && request.kind !== 'resupply' ? (
                                <Button type="button" size="sm" variant="ghost" onClick={() => warApi.cancelSupport(request.id)}>
                                    Cancel
                                </Button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}
        </>
    );
}

function Tasks({ war, pin, enabled, onFocus }: { war: WarCommand; pin: Point | null; enabled: boolean; onFocus: (point: Point) => void }) {
    const [kind, setKind] = useState<'hold' | 'patrol'>('hold');
    const [size, setSize] = useState(war.hq.sizes[0] ?? 4);
    const [minutes, setMinutes] = useState(war.hq.durations[0] ?? 30);
    const [label, setLabel] = useState('');
    const [a, setA] = useState<Point | null>(null);
    const [b, setB] = useState<Point | null>(null);
    const free = war.hq.budget - war.hq.in_use;

    function create() {
        if (!a) {
            return;
        }
        warApi.task(
            { kind, size, minutes, label, a_x: a.x, a_z: a.z, b_x: b?.x, b_z: b?.z },
            {
                inline: true,
                onSuccess: () => {
                    setA(null);
                    setB(null);
                    setLabel('');
                },
            },
        );
    }

    return (
        <>
            <p className="text-xs text-muted-foreground">
                Ask HQ to secure ground for a while. It raises a detachment from its own {war.hq.budget} soldiers ({war.hq.in_use} out now), runs it
                on its own (treats, rotates, refills losses at a rifleman&apos;s price) and calls it in when the time is up. HQ detachments secure
                ground; they do not attack, and they take no orders from you.
            </p>

            {war.tasks.map((task) => (
                <Item key={task.id} variant="outline" size="sm" className="flex-nowrap items-start gap-3 px-3 py-2">
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-auto min-w-0 flex-1 flex-col items-start gap-0 p-0 text-left font-normal whitespace-normal hover:bg-transparent"
                        onClick={() => onFocus(task.at ?? task.a)}
                    >
                        <span className="text-sm font-medium">
                            {task.kind === 'hold' ? 'Secure' : 'Patrol'} {task.label || gridRef(task.a.x, task.a.z)}
                            {task.b ? ` ↔ ${gridRef(task.b.x, task.b.z)}` : ''}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {task.men > 0 ? `${task.men} of ${task.size} men out` : `${task.size} men`}
                            {task.doing && task.doing !== 'idle' ? ` · ${DOING[task.doing].toLowerCase()}` : ''}
                            {task.ends_at ? ` · ${countdown(Math.max(0, (new Date(task.ends_at).getTime() - Date.now()) / 1000))} left` : ''}
                        </span>
                        <span className="text-xs text-muted-foreground">{task.note}</span>
                    </Button>
                    <Button type="button" size="sm" variant="ghost" disabled={!enabled} onClick={() => warApi.cancelTask(task.id)}>
                        Stand down
                    </Button>
                </Item>
            ))}

            {enabled ? (
                <div className="flex flex-col gap-2 rounded-md border border-dashed p-3">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        className="w-full"
                        value={kind}
                        onValueChange={(v) => v && setKind(v as 'hold' | 'patrol')}
                        aria-label="Task type"
                    >
                        {(['hold', 'patrol'] as const).map((option) => (
                            <ToggleGroupItem key={option} value={option} className="flex-1">
                                {option === 'hold' ? 'Secure a point' : 'Patrol a route'}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" size="sm" variant="outline" disabled={!pin} onClick={() => pin && setA(pin)}>
                            {a ? `A ${gridRef(a.x, a.z)}` : 'Use mark as A'}
                        </Button>
                        {kind === 'patrol' ? (
                            <Button type="button" size="sm" variant="outline" disabled={!pin} onClick={() => pin && setB(pin)}>
                                {b ? `B ${gridRef(b.x, b.z)}` : 'Use mark as B'}
                            </Button>
                        ) : null}
                        {a && b && kind === 'patrol' ? (
                            <span className="text-xs text-muted-foreground">{formatDistance(Math.hypot(b.x - a.x, b.z - a.z))} each way</span>
                        ) : null}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            className="h-8 max-w-48"
                            value={label}
                            maxLength={64}
                            placeholder="Name (optional)"
                            onChange={(event) => setLabel(event.target.value)}
                        />
                        <NativeSelect value={minutes} onChange={(event) => setMinutes(Number(event.target.value))} aria-label="For how long">
                            {war.hq.durations.map((option) => (
                                <NativeSelectOption key={option} value={option}>
                                    for {option >= 60 ? `${option / 60} h` : `${option} min`}
                                </NativeSelectOption>
                            ))}
                        </NativeSelect>
                        <NativeSelect value={size} onChange={(event) => setSize(Number(event.target.value))} aria-label="Squad size">
                            {war.hq.sizes.map((option) => (
                                <NativeSelectOption key={option} value={option} disabled={option > free}>
                                    {option} men
                                </NativeSelectOption>
                            ))}
                        </NativeSelect>
                        <Button type="button" size="sm" disabled={!a || (kind === 'patrol' && !b) || size > free} onClick={create}>
                            Give HQ the task
                        </Button>
                    </div>
                </div>
            ) : null}
        </>
    );
}

/**
 * The motor pool as at a Conflict vehicle depot: a vehicle is delivered at your side's spawn,
 * on free ground, and belongs to none of your squads; any of them boards it from the console.
 */
function MotorPool({ war, enabled, onFocus }: { war: WarCommand; enabled: boolean; onFocus: (point: Point) => void }) {
    const supplies = war.economy.supplies ?? 0;
    const running = war.vehicles.filter((v) => !v.hq && (v.status === 'ordered' || v.status === 'fielded'));
    const lost = war.vehicles.filter((v) => v.status === 'destroyed');
    const full = running.length >= war.vehicle_limit;

    return (
        <>
            <p className="text-xs text-muted-foreground">
                Vehicles arrive at your side&apos;s spawn. In the console, a move or Home can go by car (board, drive, get out); trucks and carriers
                rearm squads beside them. Up to {war.vehicle_limit} at once.
            </p>
            {running.map((vehicle) => (
                <Item key={vehicle.id} variant="outline" size="sm" className="flex-col items-stretch gap-1.5 px-3 py-2">
                    <div className="flex items-baseline justify-between gap-2">
                        <Button
                            type="button"
                            variant="link"
                            className="h-auto p-0 text-left"
                            disabled={vehicle.x === null || vehicle.z === null}
                            onClick={() => vehicle.x !== null && vehicle.z !== null && onFocus({ x: vehicle.x, z: vehicle.z })}
                        >
                            {vehicle.model} <span className="text-xs font-normal text-muted-foreground">· {vehicle.name}</span>
                        </Button>
                        <span className="text-xs text-muted-foreground">
                            {vehicle.status === 'ordered'
                                ? 'being delivered at the spawn'
                                : vehicle.x !== null && vehicle.z !== null
                                  ? gridRef(vehicle.x, vehicle.z)
                                  : ''}
                        </span>
                    </div>
                    {vehicle.status === 'fielded' ? (
                        <div className="grid grid-cols-3 gap-2 text-xs">
                            <span>Hull {vehicle.health}%</span>
                            <span>Fuel {vehicle.fuel}%</span>
                            <span>
                                {vehicle.crew}/{vehicle.seats ?? '?'} aboard
                            </span>
                        </div>
                    ) : null}
                    {vehicle.status === 'fielded' ? <ServiceButton war={war} vehicle={vehicle} small={false} /> : null}
                    <Button
                        type="button"
                        size="xs"
                        variant="ghost"
                        className="self-start"
                        disabled={!enabled || vehicle.crew > 0}
                        title={vehicle.crew > 0 ? 'Get everyone out first' : undefined}
                        onClick={() => warApi.scrapVehicle(vehicle.id)}
                    >
                        Send it back to the pool (no refund)
                    </Button>
                </Item>
            ))}
            <ul className="grid gap-1.5">
                {war.motor_pool.map((entry) => (
                    <Item asChild key={entry.id} variant="outline" size="sm" className="flex-nowrap gap-3 px-3 py-2">
                        <li>
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="text-sm font-medium">
                                    {entry.model} <span className="text-xs text-muted-foreground">· {entry.name}</span>
                                </span>
                                <span className="text-xs text-muted-foreground">{entry.role}</span>
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={!enabled || full || supplies < entry.cost}
                                title={full ? `You run ${war.vehicle_limit} vehicles already` : undefined}
                                onClick={() => warApi.orderVehicle(entry.id, { inline: true })}
                            >
                                {entry.cost}
                            </Button>
                        </li>
                    </Item>
                ))}
            </ul>
            {lost.length > 0 ? <p className="text-xs text-muted-foreground">Lost: {lost.map((v) => v.model).join(', ')}.</p> : null}
        </>
    );
}
