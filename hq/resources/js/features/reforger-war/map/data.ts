import { KOLGUYEV_LOCATIONS } from '@/lib/generated/kolguyev-locations';
import type { WarCommand, WarOrderKind } from '@/lib/schemas/reforger';
import type { Feature, FeatureCollection, Geometry } from 'geojson';
import { formatDistance, formatEta } from '../map-geometry';
import { soldierDoing } from '../war-format';
import { toLngLat, WORLD } from './projection';
import { LOCATION_RANK } from './style';
import { HQ_HEX, SIDE_HEX } from './symbols';

type Collection = FeatureCollection<Geometry, Record<string, unknown>>;

function point(x: number, z: number, properties: Record<string, unknown>): Feature<Geometry, Record<string, unknown>> {
    return { type: 'Feature', properties, geometry: { type: 'Point', coordinates: toLngLat(x, z) } };
}

function collection(features: Array<Feature<Geometry, Record<string, unknown>>>): Collection {
    return { type: 'FeatureCollection', features };
}

/** 100 m grid; every kilometre line is major. Static. */
export function gridData(): Collection {
    const features: Array<Feature<Geometry, Record<string, unknown>>> = [];
    for (let m = 0; m <= WORLD; m += 100) {
        const major = m % 1000 === 0;
        features.push({
            type: 'Feature',
            properties: { major },
            geometry: { type: 'LineString', coordinates: [toLngLat(m, 0), toLngLat(m, WORLD)] },
        });
        features.push({
            type: 'Feature',
            properties: { major },
            geometry: { type: 'LineString', coordinates: [toLngLat(0, m), toLngLat(WORLD, m)] },
        });
    }

    return collection(features);
}

/** Map labels from the game data. Static. */
export function locationData(): Collection {
    return collection(KOLGUYEV_LOCATIONS.map((l) => point(l.x, l.z, { name: l.name, kind: l.kind, rank: LOCATION_RANK[l.kind] ?? 6 })));
}

/**
 * One marker per place, standing exactly on its game coordinates: the shape says what it is
 * (base, relay, harbour, objective, home), the colour who holds it now, a dashed edge who is
 * taking it. A point whose income is cut by an enemy recon is drawn faded.
 */
export function placeData(war: WarCommand, aim: readonly string[] = []): Collection {
    const control = new Map(war.territory.map((c) => [c.place, c]));

    return collection(
        war.places.map((p) => {
            const held = control.get(p.id);
            const holder = p.kind === 'home' ? p.side : (held?.side ?? null);
            const icon =
                p.kind === 'home'
                    ? `home:${p.side ?? 'none'}`
                    : `place:${p.kind}:${holder ?? 'none'}${held?.claim_side ? `:claim-${held.claim_side}` : ''}`;

            return point(p.x, p.z, {
                name: p.name,
                kind: p.kind,
                icon,
                income: held ? held.income : null,
                cut: held ? held.interdicted_until !== null : false,
                // A place the operation being aimed can be aimed at.
                aim: aim.includes(p.kind),
            });
        }),
    );
}

export function objectiveData(war: WarCommand): Collection {
    return collection(war.objectives.filter((o) => o.status === 'open').map((o) => point(o.x, o.z, { id: o.id, title: o.title, reward: o.reward })));
}

/**
 * Squads of your side that have anyone in them: yours by their name, HQ's detachments as "HQ",
 * allies' with their commander's name. Empty squads are only names and stay off the map.
 */
export function squadData(war: WarCommand, selectedId: number | null): Collection {
    return collection(
        war.squads
            .filter((squad) => squad.strength > 0)
            .map((squad) => {
                const role = squad.mine ? (squad.managed ? 'hq' : 'mine') : 'ally';
                const label = squadLabel(war, squad.id);
                const selected = squad.id === selectedId;
                // Motorised: most of the men in the field ride in a vehicle.
                const men = war.markers.filter((m) => m.squad_id === squad.id);
                const motorised = men.length > 0 && men.filter((m) => m.in_vehicle).length * 2 > men.length;
                // Nobody in the field: the squad exists, but only at base (recovering or wiped out).
                const empty = squad.condition.field === 0;

                return point(squad.x, squad.z, {
                    id: squad.id,
                    mine: squad.mine,
                    // Yours to command: not allies', not HQ's.
                    commandable: squad.mine && !squad.managed,
                    selected,
                    empty,
                    label: empty
                        ? `${label} · at base`
                        : `${label} ${squad.condition.field}${squad.doing && squad.doing !== 'idle' ? ` · ${DOING_SHORT[squad.doing] ?? ''}` : ''}`,
                    icon: `unit:${squad.side}:${role}${motorised ? ':mot' : ''}${selected ? ':sel' : ''}`,
                });
            }),
    );
}

/** What the game has a squad doing, in a word for its map label. */
const DOING_SHORT: Partial<Record<string, string>> = {
    own: 'own',
    move: 'moving',
    move_forced: 'rushing',
    defend: 'defending',
    search: 'searching',
    suppress: 'suppressing',
    heal: 'treating',
    mount: 'boarding',
    dismount: 'dismounting',
    patrol: 'patrolling',
    rest: 'going home',
    recon: 'scouting',
    sabotage: 'sabotage',
    raid: 'raid',
    smoke: 'smoke',
    watch: 'look at',
    follow: 'following',
    attack: 'attacking',
};

/** A squad's name on the map: yours as you named it, allies' with their commander's name. */
function squadLabel(war: WarCommand, squadId: number): string {
    const squad = war.squads.find((s) => s.id === squadId);
    if (!squad) {
        return '';
    }

    return squad.mine ? squad.label : `${squad.name} · ${squad.label}`;
}

const TONE = { ok: '#2f6b33', worn: '#a8650c', bad: '#b8332a', down: '#5a1712' } as const;

/** How a man is, in a few words for the label beside him, and the colour of his ring. */
function soldierState(m: WarCommand['markers'][number]): { text: string; tone: string } {
    if (m.down) {
        return { text: `${m.health}% · down`, tone: TONE.down };
    }
    const bits = [`${m.health}%`];
    if (m.bleeding) {
        bits.push('bleeding');
    }
    if (m.threat === 'threatened') {
        bits.push('under fire');
    } else if (m.threat === 'alerted') {
        bits.push('alert');
    }
    // What the game has him doing, his stance, his seat.
    const doing = soldierDoing(m);
    if (doing) {
        bits.push(doing);
    } else if (m.in_vehicle) {
        bits.push('aboard');
    }
    const tone = m.bleeding || m.health < 30 ? TONE.bad : m.health < 60 ? TONE.worn : TONE.ok;

    return { text: bits.join(' · '), tone };
}

/**
 * Every man of your side where the game last saw him, with his state beside him. His ring
 * shows his health; a man the medic is on his way to has a white core.
 */
export function soldierData(war: WarCommand, selectedSquadId: number | null, selectedSoldierId: number | null): Collection {
    const patients = new Set(
        war.orders.filter((o) => o.kind === 'heal' && (o.status === 'pending' || o.status === 'acked')).map((o) => o.soldier_id),
    );

    return collection(
        war.markers
            .filter((m) => !m.seat || m.id === selectedSoldierId)
            .map((m) => {
                const state = soldierState(m);

                const colours = m.hq ? HQ_HEX : SIDE_HEX[m.side];

                return point(m.x, m.z, {
                    id: m.id,
                    squad_id: m.squad_id,
                    commandable: m.mine && !m.hq,
                    name: m.name,
                    state: state.text,
                    tone: state.tone,
                    mine: m.mine,
                    treated: patients.has(m.id),
                    active: m.id === selectedSoldierId,
                    selected: m.id === selectedSoldierId || m.squad_id === selectedSquadId,
                    // Where he looks: a wedge turned to his bearing.
                    heading: m.heading ?? -1,
                    look: m.mine || m.side === war.enlisted_side ? 'look:own' : 'look:enemy',
                    // What he does in a fight, over his dot; riflemen carry no glyph.
                    role_icon: m.role === 'rifle' ? '' : `role:${m.role}:${m.hq ? 'hq' : m.mine ? 'mine' : 'ally'}`,
                    fill: patients.has(m.id) ? '#ffffff' : colours.fill,
                    stroke: m.down || m.bleeding || m.health < 60 ? state.tone : m.mine ? colours.stroke : '#6b6559',
                });
            }),
    );
}

const CELL = 100;
const CELLS = Math.ceil(WORLD / CELL);

/**
 * How far your side sees, from the island's weather the server reports: 450 m by day, 200 m
 * at night, less in fog. A rough stand-in for the game's own perception, for the map only.
 */
export function sightMetres(war: WarCommand): number {
    const world = war.world;
    if (!world) {
        return 400;
    }
    const night = world.hour < world.sunrise || world.hour >= world.sunset;

    return Math.round((night ? 200 : 450) * Math.max(0.35, 1 - world.fog / 150));
}

/**
 * Which 100 m cells someone of your side sees now: your men, HQ's and your allies'. The page
 * remembers when each cell was last seen (`memory`, cell index -> ms), for the half-lit fog.
 */
export function seenCells(war: WarCommand, memory: Map<number, number>, now: number): Set<number> {
    const r = sightMetres(war);
    const span = Math.ceil(r / CELL);
    const seen = new Set<number>();
    for (const m of war.markers) {
        const cx = Math.floor(m.x / CELL);
        const cz = Math.floor(m.z / CELL);
        for (let dx = -span; dx <= span; dx++) {
            for (let dz = -span; dz <= span; dz++) {
                const x = cx + dx;
                const z = cz + dz;
                if (x < 0 || z < 0 || x >= CELLS || z >= CELLS) {
                    continue;
                }
                if (Math.hypot((x + 0.5) * CELL - m.x, (z + 0.5) * CELL - m.z) <= r) {
                    const i = z * CELLS + x;
                    seen.add(i);
                    memory.set(i, now);
                }
            }
        }
    }

    return seen;
}

/**
 * The fog: every 100 m cell nobody of your side sees, merged into runs along each row. Cells seen
 * in the last `rememberMs` are lighter than ones never seen since the page opened.
 */
export function fogData(seen: Set<number>, memory: Map<number, number>, now: number, rememberMs = 15 * 60_000): Collection {
    const features: Array<Feature<Geometry, Record<string, unknown>>> = [];
    for (let z = 0; z < CELLS; z++) {
        // A run of cells of the same level along the row: 0 seen (no fog), 1 seen lately, 2 not.
        let start = 0;
        let level = 0;
        for (let x = 0; x <= CELLS; x++) {
            const i = z * CELLS + x;
            const cell = x === CELLS || seen.has(i) ? 0 : now - (memory.get(i) ?? -Infinity) < rememberMs ? 1 : 2;
            if (cell === level) {
                continue;
            }
            if (level > 0) {
                const ring = [
                    toLngLat(start * CELL, z * CELL),
                    toLngLat(x * CELL, z * CELL),
                    toLngLat(x * CELL, (z + 1) * CELL),
                    toLngLat(start * CELL, (z + 1) * CELL),
                    toLngLat(start * CELL, z * CELL),
                ];
                features.push({ type: 'Feature', properties: { level }, geometry: { type: 'Polygon', coordinates: [ring] } });
            }
            start = x;
            level = cell;
        }
    }

    return collection(features);
}

/** A circle of `metres` around a point, as a polygon (the projection is linear in metres). */
function circle(x: number, z: number, metres: number, properties: Record<string, unknown>): Feature<Geometry, Record<string, unknown>> {
    const ring: Array<[number, number]> = [];
    for (let i = 0; i <= 64; i++) {
        const a = (i / 64) * Math.PI * 2;
        ring.push(toLngLat(x + Math.cos(a) * metres, z + Math.sin(a) * metres));
    }

    return { type: 'Feature', properties, geometry: { type: 'Polygon', coordinates: [ring] } };
}

/** Every control point's capture zone. Static per page. */
export function zoneData(war: WarCommand): Collection {
    return collection(war.places.filter((p) => p.kind !== 'home').map((p) => circle(p.x, p.z, war.capture_radius, { name: p.name })));
}

const REACH = '#1f63b8';
const DANGER = '#b8332a';

/**
 * What the order being drawn up or the fire mission being aimed covers: an order's own reach
 * at its mark (search sweep, capture zone, smoke throw, planting distance, raid area), and a
 * fire mission's danger-close rings at the spot, so you see who of yours is inside.
 */
export function ringData(
    war: WarCommand,
    draft: { kind: WarOrderKind; a: Point | null; squad: Point | null },
    fireAt: (Point & { id?: string }) | null,
): Collection {
    const features: Array<Feature<Geometry, Record<string, unknown>>> = [];
    const { kind, a, squad } = draft;
    if (a) {
        const place = war.places.find((p) => p.kind !== 'home' && Math.hypot(p.x - a.x, p.z - a.z) <= war.capture_radius);
        if (kind === 'search') {
            features.push(circle(a.x, a.z, 100, { colour: REACH, danger: false, label: 'search 100 m' }));
        } else if ((kind === 'defend' || kind === 'move' || kind === 'move_forced') && place) {
            features.push(circle(place.x, place.z, war.capture_radius, { colour: REACH, danger: false, label: `${place.name} · capture zone` }));
        } else if (kind === 'sabotage') {
            features.push(circle(a.x, a.z, 15, { colour: DANGER, danger: true, label: 'sapper plants within 15 m' }));
        } else if (kind === 'raid') {
            const op = war.operations.find((o) => o.id === 'raid');
            features.push(circle(a.x, a.z, op?.radius ?? 75, { colour: REACH, danger: false, label: `raid ${op?.radius ?? 75} m` }));
        }
    }
    if (kind === 'smoke' && squad) {
        features.push(circle(squad.x, squad.z, 40, { colour: REACH, danger: false, label: 'smoke throw 40 m' }));
    }
    if (fireAt) {
        for (const offer of war.support_catalog.filter((o) => o.danger_close > 0 && (!fireAt.id || o.id === fireAt.id))) {
            features.push(
                circle(fireAt.x, fireAt.z, offer.danger_close, {
                    colour: DANGER,
                    danger: true,
                    label: `${offer.name}: danger close ${offer.danger_close} m`,
                }),
            );
        }
    }

    return collection(features);
}

/**
 * Your vehicles where the game last saw them, and how they are: hull, fuel, seats taken. One on
 * its way from the motor pool shows at the spawn. The one picked to board is marked; a damaged or
 * dry one is drawn in warning colours.
 */
export function vehicleData(war: WarCommand, pickedId: number | null = null): Collection {
    const side = war.enlisted_side ?? 'us';

    return collection(
        war.vehicles
            .filter((v) => (v.status === 'fielded' || v.status === 'ordered') && v.x !== null && v.z !== null)
            .map((v) => {
                const state = v.status === 'ordered' ? 'on its way' : `hull ${v.health}% · fuel ${v.fuel}% · ${v.crew}/${v.seats ?? '?'} seats`;
                const label = `${v.id === pickedId ? '▸ ' : ''}${v.model} · ${state}`;
                const tone =
                    v.status === 'ordered'
                        ? '#6b6559'
                        : v.health < 40 || v.fuel < 15
                          ? '#b8332a'
                          : v.health < 70 || v.fuel < 30
                            ? '#a8650c'
                            : '#2b2822';

                return point(v.x!, v.z!, { id: v.id, model: label, tone, crew: v.crew, picked: v.id === pickedId, icon: `veh:${side}:${v.kind}` });
            }),
    );
}

/** What a contact is, in a word for its label: the role of a man seen, the kind of a vehicle. */
export function contactWhat(c: WarCommand['contacts'][number]): string {
    if (c.kind === 'veh') {
        return c.vehicle ? (VEHICLE_WORD[c.vehicle] ?? c.vehicle) : c.how === 'heard' ? 'vehicle heard' : 'vehicle';
    }
    if (c.how === 'heard') {
        return 'heard';
    }

    return c.role ? ROLE_WORD[c.role] : 'infantry';
}

export const ROLE_WORD: Record<NonNullable<WarCommand['contacts'][number]['role']>, string> = {
    leader: 'leader',
    mg: 'MG',
    at: 'AT',
    marksman: 'marksman',
    medic: 'medic',
    sapper: 'sapper',
    radio: 'radio',
    grenadier: 'grenadier',
    scout: 'scout',
    rifle: 'rifleman',
};

const VEHICLE_WORD: Record<string, string> = {
    jeep: 'car',
    utility: 'utility car',
    jeep_mg: 'armed car',
    truck: 'truck',
    ambulance: 'ambulance',
    armed_car: 'armoured car',
    apc: 'APC',
};

/**
 * Enemies your side knows: a diamond each, dashed when only heard, with what it is inside it
 * (role of a man seen, kind of a vehicle) and beside it with how old the report is.
 */
export function contactData(war: WarCommand): Collection {
    return collection(
        war.contacts.map((c) =>
            point(c.x, c.z, {
                id: c.id,
                icon: `contact:${c.side}:${c.kind}:${c.how}${c.kind === 'veh' ? (c.vehicle ? `:${c.vehicle}` : '') : c.role ? `:${c.role}` : ''}`,
                fade: Math.max(0.35, 1 - c.age_s / 900),
                side: c.side,
                age: `${contactWhat(c)} · ${c.age_s < 60 ? 'now' : `${Math.round(c.age_s / 60)} min ago`}`,
                heading: c.heading ?? -1,
            }),
        ),
    );
}

/**
 * Who is fighting whom: a line from each man of ours to the enemy the game has him fighting
 * (his current target), and a ring on that enemy.
 */
export function fireData(war: WarCommand): Collection {
    return collection(
        war.markers.flatMap((m) =>
            m.target
                ? [
                      {
                          type: 'Feature' as const,
                          properties: { id: m.id },
                          geometry: { type: 'LineString' as const, coordinates: [toLngLat(m.x, m.z), toLngLat(m.target.x, m.target.z)] },
                      },
                      point(m.target.x, m.target.z, { id: m.id, end: true }),
                  ]
                : [],
        ),
    );
}

/** Each contact's trail: the spots it was seen at before, to where it is, so you see its way. */
export function trailData(war: WarCommand): Collection {
    return collection(
        war.contacts
            .filter((c) => c.trail.length > 0)
            .map((c) => ({
                type: 'Feature' as const,
                properties: { colour: SIDE_HEX[c.side].stroke, fade: Math.max(0.3, 1 - c.age_s / 900) },
                geometry: { type: 'LineString' as const, coordinates: [...c.trail, { x: c.x, z: c.z }].map((p) => toLngLat(p.x, p.z)) },
            })),
    );
}

/** Lines from each squad to its open order, and the order's target mark. */
const ORDER_WORD: Record<string, string> = {
    move: 'Move',
    move_forced: 'Rush',
    defend: 'Defend',
    search: 'Search',
    suppress: 'Suppress',
    heal: 'Treat',
    rest: 'Home',
    mount: 'Board',
    dismount: 'Dismount',
    patrol: 'Patrol',
    recon: 'Scout',
    sabotage: 'Sabotage',
    raid: 'Raid',
    follow: 'Follow',
    attack: 'Attack',
};

const STATUS_WORD: Record<string, string> = { pending: 'sent', acked: 'under way', done: 'done', failed: 'failed', queued: 'next' };

/**
 * Each squad's current order (its latest, unless a newer one replaced it) as its waypoint: a
 * line from the squad to the mark while it is sent or under way (for treatment, to the wounded
 * man; for a patrol, along its beat), and the mark labelled with who, what, and how it stands
 * in the game: sent (not picked up yet), under way, done, failed.
 */
export function orderData(war: WarCommand, selectedId: number | null): { lines: Collection; targets: Collection } {
    const current = new Map<number, WarCommand['orders'][number]>();
    for (const order of war.orders) {
        const seen = current.get(order.squad_id);
        // Smoke rides on top of the order under way; the waypoint shown is that order's.
        if (
            order.kind !== 'smoke' &&
            order.kind !== 'watch' &&
            order.status !== 'superseded' &&
            order.status !== 'queued' &&
            (!seen || order.id > seen.id)
        ) {
            current.set(order.squad_id, order);
        }
    }
    const lines: Array<Feature<Geometry, Record<string, unknown>>> = [];
    const targets: Array<Feature<Geometry, Record<string, unknown>>> = [];
    for (const order of current.values()) {
        const squad = war.squads.find((s) => s.id === order.squad_id);
        if (!squad) {
            continue;
        }
        if (order.kind === 'halt') {
            continue;
        }
        const patient = order.kind === 'heal' && order.soldier_id !== null ? war.markers.find((m) => m.id === order.soldier_id) : undefined;
        // A follow points at the squad it follows, wherever it has gone since.
        const leader = order.kind === 'follow' && order.follow_squad_id !== null ? war.squads.find((s) => s.id === order.follow_squad_id) : undefined;
        const to = patient ?? (leader ? { x: leader.x, z: leader.z } : { x: order.x, z: order.z });
        const route = order.route.length > 0 && (order.kind === 'move' || order.kind === 'move_forced') ? order.route : [];
        const selected = squad.id === selectedId;
        const what = patient ? `Treat ${patient.name}` : (ORDER_WORD[order.kind] ?? order.kind);
        const beat = order.kind === 'patrol' && order.x2 !== null && order.z2 !== null;
        const status = order.status;
        const open = status === 'pending' || status === 'acked';
        // How far is left and how long it takes, on foot or driven if they are aboard.
        const path = [{ x: squad.x, z: squad.z }, to, ...route];
        const metres = path.slice(1).reduce((sum, p, i) => sum + Math.hypot(p.x - path[i].x, p.z - path[i].z), 0);
        const driven = war.markers.some((m) => m.squad_id === squad.id && m.in_vehicle);
        const eta =
            open && !beat && !patient && !leader && metres > 30
                ? `${formatDistance(metres)} · ${formatEta(metres, driven)}${driven ? ' driven' : ''}`
                : '';
        // A finished order needs no line: they are there (or it failed). The mark stays, faded.
        if (open || beat) {
            lines.push({
                type: 'Feature',
                properties: { selected, colour: SIDE_HEX[squad.side].stroke, soldier: patient !== undefined, status, eta },
                geometry: {
                    type: 'LineString',
                    coordinates: beat
                        ? [toLngLat(order.x, order.z), toLngLat(order.x2!, order.z2!)]
                        : [toLngLat(squad.x, squad.z), toLngLat(to.x, to.z), ...route.map((p) => toLngLat(p.x, p.z))],
                },
            });
        }
        targets.push(
            point(to.x, to.z, {
                icon: `target:${order.kind === 'defend' || order.kind === 'patrol' ? 'defend' : 'move'}`,
                selected,
                order_id: order.id,
                part: 'a',
                soldier: patient !== undefined,
                status,
                label: `${squadLabel(war, squad.id)} · ${what} · ${STATUS_WORD[status] ?? status}`,
            }),
        );
        if (beat) {
            targets.push(
                point(order.x2!, order.z2!, {
                    icon: 'target:defend',
                    selected,
                    order_id: order.id,
                    part: 'b',
                    soldier: false,
                    status,
                    label: '',
                }),
            );
        }
        for (const [i, leg] of route.entries()) {
            targets.push(
                point(leg.x, leg.z, {
                    icon: 'target:move',
                    selected,
                    order_id: order.id,
                    part: `leg:${i}`,
                    soldier: true,
                    status,
                    label: i === route.length - 1 ? 'end' : '',
                }),
            );
        }
    }

    // Each squad's chain: what it does after the order under way, one faint leg after another.
    for (const squad of war.squads) {
        const chain = war.orders.filter((o) => o.squad_id === squad.id && o.status === 'queued').sort((a, b) => a.id - b.id);
        if (chain.length === 0) {
            continue;
        }
        const now = current.get(squad.id);
        let from =
            now && (now.status === 'pending' || now.status === 'acked')
                ? (now.route[now.route.length - 1] ?? { x: now.x, z: now.z })
                : { x: squad.x, z: squad.z };
        const selected = squad.id === selectedId;
        for (const [i, order] of chain.entries()) {
            const vehicle = order.kind === 'mount' && order.vehicle_id !== null ? war.vehicles.find((v) => v.id === order.vehicle_id) : undefined;
            const to = vehicle && vehicle.x !== null && vehicle.z !== null ? { x: vehicle.x, z: vehicle.z } : { x: order.x, z: order.z };
            const path = [to, ...order.route];
            if (Math.hypot(to.x - from.x, to.z - from.z) > 5) {
                lines.push({
                    type: 'Feature',
                    properties: { selected, colour: SIDE_HEX[squad.side].stroke, soldier: false, status: 'queued', eta: '' },
                    geometry: { type: 'LineString', coordinates: [from, ...path].map((p) => toLngLat(p.x, p.z)) },
                });
            }
            targets.push(
                point(to.x, to.z, {
                    icon: `target:${order.kind === 'defend' || order.kind === 'patrol' ? 'defend' : 'move'}`,
                    selected,
                    order_id: order.id,
                    part: 'a',
                    soldier: true,
                    status: 'queued',
                    label: selected ? `${i + 2}. ${ORDER_WORD[order.kind] ?? order.kind}` : '',
                }),
            );
            from = path[path.length - 1];
        }
    }

    return { lines: collection(lines), targets: collection(targets) };
}

/** HQ's own work: a point it holds, or the beat it patrols between two points. */
export function taskData(war: WarCommand): { lines: Collection; points: Collection } {
    const side = war.enlisted_side ?? 'us';
    const lines: Array<Feature<Geometry, Record<string, unknown>>> = [];
    const points: Array<Feature<Geometry, Record<string, unknown>>> = [];
    for (const task of war.tasks) {
        const label = `HQ · ${task.kind === 'hold' ? 'Hold' : 'Patrol'} · ${task.size}`;
        points.push(point(task.a.x, task.a.z, { label, kind: task.kind, colour: SIDE_HEX[side].stroke }));
        if (task.b) {
            lines.push({
                type: 'Feature',
                properties: { colour: SIDE_HEX[side].stroke },
                geometry: { type: 'LineString', coordinates: [toLngLat(task.a.x, task.a.z), toLngLat(task.b.x, task.b.z)] },
            });
            points.push(point(task.b.x, task.b.z, { label: '', kind: 'patrol-end', colour: SIDE_HEX[side].stroke }));
        }
    }

    return { lines: collection(lines), points: collection(points) };
}

export function supportData(war: WarCommand): Collection {
    return collection(
        war.support
            .filter((s) => (s.status === 'requested' || s.status === 'waiting') && s.x !== null && s.z !== null)
            .map((s) => point(s.x!, s.z!, { name: s.name, status: s.status })),
    );
}

/**
 * The order being drawn up: the mark (a patrol has two), labelled with what goes there, and a
 * dashed line from the squad to it, or along a patrol's beat.
 */
export function pinData(draft: {
    from: Point | null;
    a: Point | null;
    b: Point | null;
    legs?: Point[];
    kind: WarOrderKind;
    label: string;
    driven?: boolean;
}): Collection {
    const { from, a, b, kind, label } = draft;
    const legs = draft.legs ?? [];
    if (!a) {
        return collection([]);
    }
    const icon = `mark:${kind === 'defend' || kind === 'patrol' ? 'defend' : 'move'}`;
    const features = [point(a.x, a.z, { icon, label, part: 'a' })];
    if (b) {
        features.push(point(b.x, b.z, { icon, label: '', part: 'b' }));
    }
    for (const [i, leg] of legs.entries()) {
        features.push(point(leg.x, leg.z, { icon, label: String(i + 2), part: `leg:${i}` }));
    }
    const path = kind === 'patrol' ? (b ? [a, b] : null) : from ? [from, a, ...legs] : null;
    if (path) {
        const metres = path.slice(1).reduce((sum, p, i) => sum + Math.hypot(p.x - path[i].x, p.z - path[i].z), 0);
        const eta = kind === 'patrol' ? `beat ${formatDistance(metres)}` : `${formatDistance(metres)} · ${formatEta(metres, draft.driven ?? false)}`;
        features.unshift({
            type: 'Feature',
            properties: { eta },
            geometry: { type: 'LineString', coordinates: path.map((p) => toLngLat(p.x, p.z)) },
        });
    }

    return collection(features);
}

type Point = { x: number; z: number };
