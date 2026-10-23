import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { WarCommand } from '@/lib/schemas/reforger';
import { uiText } from '@/lib/ui-tokens';
import { usePage } from '@inertiajs/react';
import { Radio, RadioTower, Shield } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { SquadList } from './army-panel';
import { CommandPanel, SquadCare, type Draft } from './command-panel';
import { HqDesk } from './hq-desk';
import { HqHeader } from './hq-header';
import { KolguyevMap } from './kolguyev-map';
import {
    HQ_LIFT,
    aimAttack,
    aimOperation,
    fireBlocked,
    operation,
    orderForKey,
    orderPayload,
    orderSpec,
    placeMark,
    type OrderOptions,
    type SquadOrderKind,
} from './lib/orders';
import { useFeedLight, useWarLive } from './lib/use-war-live';
import { warApi } from './lib/war-api';
import { diffFlashes } from './map/flashes';
import { SidePicker } from './side-picker';
import { WarFeed } from './war-feed';

type Point = { x: number; z: number };

const NO_MARKS: Draft['marks'] = { a: null, b: null, legs: [] };
const FIRST_DRAFT: Draft = {
    kind: 'move',
    hq: false,
    fire: null,
    marks: NO_MARKS,
    vehicleId: null,
    followId: null,
    route: false,
    ride: null,
    // A crew driving its vehicle somewhere stays in it; riding there from foot turns this on.
    getOut: false,
};

/**
 * Kolguyev HQ. The map only points, the command panel decides, and only its buttons send:
 *
 *   pick       a squad row (or keys 1–0): the map flies there. A man on the map: his own card.
 *   choose     an order in the palette, or HQ fire.
 *   point      click (tap) the map to mark; a mark can be moved by clicking again.
 *   send       the panel's button, or Then to chain it behind what the squad is doing.
 *
 * One line of status over the map; the map at full width with the operational console over its
 * bottom edge (pick, orders, HQ fire, send); under it the squad manager (men, care,
 * reinforcements) beside Headquarters and the radio. Only what your side knows is on the page.
 */
export function WarCommandPanel({ war: initial }: { war: WarCommand }) {
    // Live: the page polls GET /desk and swaps this commander's desk in place.
    const { war, link } = useWarLive(initial);
    const light = useFeedLight(war.feed, link);
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [draft, setDraft] = useState<Draft>(FIRST_DRAFT);
    const [squadId, setSquadId] = useState<number | null>(null);
    const [soldierId, setSoldierId] = useState<number | null>(null);
    const [focus, setFocus] = useState<{ x: number; z: number; key: number } | null>(null);
    const [tab, setTab] = useState('hq');
    const enlisted = war.enlisted_side;
    const weekOpen = war.session.open;
    const own = war.squads.filter((squad) => squad.mine && !squad.managed);
    // HQ detachments run themselves; the commander picks among his own squads.
    const selected = own.find((squad) => squad.id === squadId) ?? own.find((squad) => squad.strength > 0) ?? own[0] ?? null;
    // A man picked on the map or in the roster takes orders on his own, from his squad leader.
    const soldier =
        soldierId === null ? null : (war.soldiers.find((s) => s.id === soldierId && s.squad_id === selected?.id && s.location === 'field') ?? null);
    const canMark = weekOpen && enlisted !== null;
    const { kind, marks } = draft;
    const op = operation(war, kind);

    // What needs the commander now, wherever he is looking: a man killed or down, a squad under
    // fire, a vehicle lost, an order failed, a point changing hands. Show takes the map there.
    const lastDesk = useRef<WarCommand | null>(null);
    useEffect(() => {
        const before = lastDesk.current;
        lastDesk.current = war;
        if (before) {
            for (const e of war.events.filter((x) => x.kind === 'threat' && !before.events.some((b) => b.id === x.id))) {
                toast.warning(e.text, { id: e.id, duration: 12000 });
            }
        }
        for (const f of diffFlashes(before, war, Date.now()).filter((x) => x.alert)) {
            const show = { label: 'Show', onClick: () => setFocus({ x: f.x, z: f.z, key: Date.now() }) };
            if (f.tone === 'bad') {
                toast.error(f.text, { id: f.id, action: show, duration: 8000 });
            } else {
                toast.success(f.text, { id: f.id, action: show, duration: 6000 });
            }
        }
    }, [war]);

    const setMarks = (next: Draft['marks'] | ((current: Draft['marks']) => Draft['marks'])) =>
        setDraft((d) => ({ ...d, marks: typeof next === 'function' ? next(d.marks) : next }));
    const focusOn = (point: Point) => setFocus({ ...point, key: Date.now() });

    function pickSquad(id: number) {
        setSquadId(id);
        setSoldierId(null);
    }

    /** Picked from a list: the map goes to the squad too. */
    function goToSquad(id: number) {
        pickSquad(id);
        const squad = own.find((s) => s.id === id);
        if (squad && squad.condition.field > 0) {
            focusOn(squad);
        }
    }

    // Picking a man picks his squad: orders still go to the whole squad, as in the game.
    function pickSoldier(nextSquad: number, nextSoldier: number | null) {
        setSquadId(nextSquad);
        setSoldierId(nextSoldier);
    }

    /**
     * A mark lands where the server will take it: an operation's on its place, an attack's on
     * the enemy contact.
     */
    function aimed(k: SquadOrderKind, at: Point): Point {
        if (k === 'attack') {
            const enemy = aimAttack(war, at);

            return enemy ? { x: enemy.x, z: enemy.z } : at;
        }
        const rules = operation(war, k);
        const result = rules ? aimOperation(war, rules, at) : null;

        return result && !('error' in result) ? { x: result.x, z: result.z } : at;
    }

    function place(x: number, z: number) {
        if (draft.hq) {
            setMarks({ a: { x, z }, b: null, legs: [] });

            return;
        }
        setMarks((current) => placeMark(kind, current, aimed(kind, { x, z }), draft.route));
    }

    function pickKind(next: SquadOrderKind) {
        // A beat's second end means nothing to other orders; a mark re-aims for an operation.
        setDraft((d) => ({
            ...d,
            kind: next,
            hq: false,
            fire: null,
            marks: {
                a: d.marks.a ? aimed(next, d.marks.a) : null,
                b: next === 'patrol' ? d.marks.b : null,
                legs: next === 'move' || next === 'move_forced' ? d.marks.legs : [],
            },
        }));
    }

    function setOptions(patch: Partial<OrderOptions>) {
        setDraft((d) => ({
            ...d,
            ...patch,
            // Leaving route drawing drops its points.
            marks: patch.route === false ? { ...d.marks, legs: [] } : d.marks,
        }));
    }

    /**
     * Your vehicle tapped on the map: with Board picked it is the one to get into; otherwise the
     * tap is a mark there like any other (the picked order does not change under you).
     */
    function tapVehicle(id: number, x: number, z: number) {
        if (!draft.hq && kind === 'mount') {
            setOptions({ vehicleId: id });

            return;
        }
        place(x, z);
    }

    /**
     * Send the drawn-up order: only ever from the panel's button, so nothing on the map sends
     * anything by accident. The button is greyed (with the reason) while the order cannot go.
     */
    function send(append: boolean) {
        if (!selected) {
            return;
        }
        // By HQ truck: HQ runs the lift (pick up here, drive to the mark, drop them off).
        if (draft.ride === HQ_LIFT && (kind === 'move' || kind === 'move_forced') && marks.a) {
            warApi.run(
                { kind: 'transport', squad_id: selected.id, x: marks.a.x, z: marks.a.z },
                { onSuccess: () => setDraft((d) => ({ ...d, ride: null, marks: NO_MARKS })) },
            );

            return;
        }
        const result = orderPayload(war, selected, kind, marks, draft, append);
        if ('error' in result) {
            return;
        }
        warApi.order(result.payload, { onSuccess: () => setMarks(NO_MARKS) });
    }

    /** Call the HQ fire being aimed on its mark: only from the panel's button. */
    function callFire() {
        const offer = war.support_catalog.find((o) => o.id === draft.fire);
        if (!offer || !marks.a || fireBlocked(war, offer, marks.a)) {
            return;
        }
        warApi.support({ kind: offer.id, x: marks.a.x, z: marks.a.z }, { onSuccess: () => setDraft((d) => ({ ...d, fire: null, marks: NO_MARKS })) });
    }

    // Keys 1 to 0 pick your squads in order, a letter picks an order (ORDER_KEYS), Enter sends
    // it and Shift+Enter chains it behind what the squad is doing; Esc drops the mark, HQ fire
    // and the picked man.
    const keys = useRef<(event: KeyboardEvent) => void>(() => {});
    keys.current = (event: KeyboardEvent) => {
        const typing =
            event.target instanceof HTMLElement && (event.target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName));
        if (typing || event.ctrlKey || event.metaKey || event.altKey || !enlisted || !weekOpen) {
            return;
        }
        if (/^[0-9]$/.test(event.key)) {
            const squad = own[(Number(event.key) + 9) % 10];
            if (squad) {
                goToSquad(squad.id);
            }
        } else if (event.key === 'Enter' && !draft.hq && selected) {
            event.preventDefault();
            send(event.shiftKey);
        } else if (orderForKey(event.key) && !event.shiftKey) {
            pickKind(orderForKey(event.key)!);
        } else if (event.key === 'Escape') {
            setDraft((d) => ({ ...d, fire: null, marks: NO_MARKS }));
            setSoldierId(null);
        }
    };
    useEffect(() => {
        const listen = (event: KeyboardEvent) => keys.current(event);
        window.addEventListener('keydown', listen);

        return () => window.removeEventListener('keydown', listen);
    }, []);

    /** An HQ objective, picked: go there with the order that completes it. */
    function pursue(point: Point, k?: SquadOrderKind) {
        focusOn(point);
        if (k && canMark) {
            setDraft((d) => ({ ...d, kind: k, hq: false, fire: null, marks: { a: aimed(k, point), b: null, legs: [] } }));
        }
    }

    const fireOffer = draft.hq && draft.fire ? war.support_catalog.find((o) => o.id === draft.fire) : undefined;
    const markLabel = draft.hq
        ? fireOffer
            ? `HQ · ${fireOffer.name} · not called`
            : 'HQ fire · not called'
        : selected && marks.a
          ? `${selected.label} · ${orderSpec(war, kind).label} · not sent`
          : '';
    const props = {
        war,
        squads: own,
        selected,
        soldier,
        draft,
        onKind: pickKind,
        onHq: (on: boolean) => setDraft((d) => ({ ...d, hq: on, fire: on ? d.fire : null, marks: NO_MARKS })),
        onFire: (id: string | null) => setDraft((d) => ({ ...d, fire: id })),
        onOptions: setOptions,
        onClearMarks: () => setMarks(NO_MARKS),
        onSelectSquad: goToSquad,
        onSelectSoldier: (squad: number, id: number) => {
            pickSoldier(squad, id);
            const at = war.markers.find((m) => m.id === id);
            if (at) {
                focusOn(at);
            }
        },
        onLeaveSoldier: () => selected && pickSoldier(selected.id, null),
        onFocus: focusOn,
        onSend: (append: boolean) => send(append),
        onCallFire: callFire,
    };

    return (
        <TooltipProvider delayDuration={200}>
            <div className="flex flex-col gap-3">
                {enlisted === null || !weekOpen ? <SidePicker war={war} /> : null}
                {errors.side ? <p className={uiText.danger}>{errors.side}</p> : null}
                {war.intel.blind_until ? (
                    <p className="flex items-center gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                        <RadioTower className="size-4" /> Relay link down after enemy sabotage. No contact reports until{' '}
                        {new Date(war.intel.blind_until).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}.
                    </p>
                ) : null}

                <HqHeader war={war} />

                {/* The map: operational. Where everyone is and what they see; orders and fire. */}
                <Card className="h-[82svh] min-h-96 gap-0 overflow-hidden p-0 lg:h-[calc(100svh-4.5rem)]">
                    <CardContent className="h-full p-0">
                        <KolguyevMap
                            war={war}
                            selectedSquadId={selected?.id ?? null}
                            selectedSoldierId={soldier?.id ?? null}
                            marks={marks}
                            orderKind={kind}
                            markLabel={markLabel}
                            aim={op && !soldier && !draft.hq ? op.targets : undefined}
                            canPlace={canMark}
                            onPlace={place}
                            onClearPin={() => setMarks(NO_MARKS)}
                            onSelectSquad={pickSquad}
                            onSelectSoldier={pickSoldier}
                            onSelectVehicle={canMark ? tapVehicle : undefined}
                            fire={draft.hq ? (draft.fire ?? 'none') : null}
                            pickedVehicleId={kind === 'mount' && !draft.hq ? draft.vehicleId : null}
                            focus={focus}
                            light={light}
                        >
                            {enlisted && weekOpen ? <CommandPanel {...props} /> : null}
                        </KolguyevMap>
                    </CardContent>
                </Card>

                {/* Under the map: managing the squads (men, care, reinforcements), then HQ and the radio. */}
                {enlisted ? (
                    <div className="grid grid-cols-[minmax(0,1fr)] gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
                        <Card className="gap-3 p-3" data-testid="squad-manager">
                            {selected && selected.condition.field > 0 && weekOpen ? (
                                <SquadCare war={war} squad={selected} onHome={() => pickKind('rest')} />
                            ) : null}
                            <SquadList
                                war={war}
                                squads={own}
                                selectedId={selected?.id ?? null}
                                activeSoldierId={soldier?.id ?? null}
                                onSelect={goToSquad}
                                onSelectSoldier={props.onSelectSoldier}
                            />
                        </Card>
                        <Tabs value={tab} onValueChange={setTab} className="gap-3">
                            <TabsList className="w-full">
                                <TabsTrigger value="hq">
                                    <Shield /> Headquarters
                                </TabsTrigger>
                                <TabsTrigger value="log">
                                    <Radio /> Radio
                                </TabsTrigger>
                            </TabsList>
                            <TabsContent value="hq">
                                <HqDesk war={war} pin={marks.a} onFocus={focusOn} onPursue={pursue} />
                            </TabsContent>
                            <TabsContent value="log">
                                <WarFeed events={war.events} />
                            </TabsContent>
                        </Tabs>
                    </div>
                ) : (
                    <WarFeed events={war.events} />
                )}
            </div>
        </TooltipProvider>
    );
}
