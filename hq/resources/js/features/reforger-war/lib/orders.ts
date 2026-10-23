import type { WarCommand, WarOperation, WarOrderKind } from '@/lib/schemas/reforger';
import type { WarOrderRequest, WarOrderStep } from './war-api';

type Squad = WarCommand['squads'][number];
type Point = { x: number; z: number };

/**
 * What a squad order needs: nothing, one mark, both ends of a beat, one of your vehicles, another
 * of your squads, or a known enemy (a mark that snaps to the nearest contact).
 */
type MarkNeed = 'none' | 'one' | 'two' | 'vehicle' | 'squad' | 'contact';

/**
 * The squad orders a commander gives from the map: the game's commanding-menu commands
 * (Configs/Commanding/Commands.conf), going home, and the operations HQ judges. Treatment is a
 * soldier's order, given from his card. Operations take their words from war.operations.
 */
export const SQUAD_ORDERS: Record<Exclude<WarOrderKind, 'heal'>, { label: string; help: string; mark: MarkNeed }> = {
    move: {
        label: 'Move',
        help: 'Go to the mark (driven if they are aboard). They stop to fight what they meet. Route: tap more points.',
        mark: 'one',
    },
    move_forced: { label: 'Rush', help: 'Go to the mark without stopping to fight on the way. Route: tap more points.', mark: 'one' },
    follow: { label: 'Follow', help: 'Follow another of your squads, keeping with its leader (the game’s follow command).', mark: 'squad' },
    attack: {
        label: 'Attack',
        help: 'Attack a known enemy: tap his contact. If the squad cannot see him, it goes to hunt him there.',
        mark: 'contact',
    },
    watch: {
        label: 'Look at',
        help: 'Everyone turns to watch the mark (the game’s look-at command); what the squad is doing goes on.',
        mark: 'one',
    },
    smoke: {
        label: 'Smoke',
        help: 'Smoke between the squad and the enemies it sees. Half the men throw, the rest keep fighting; the order under way goes on.',
        mark: 'none',
    },
    halt: { label: 'Halt', help: 'Stop where they are: the game clears the squad’s orders.', mark: 'none' },
    rest: {
        label: 'Home',
        help: 'Back to your side’s home (driven if they are aboard). There the hospital takes the wounded and the rest rearm.',
        mark: 'none',
    },
    defend: { label: 'Defend', help: 'Take cover around the mark and hold it until the next order.', mark: 'one' },
    search: { label: 'Search', help: 'Search and destroy within 100 m of the mark.', mark: 'one' },
    suppress: { label: 'Suppress', help: 'Pin the mark down with fire. Sets the squad to fire at will.', mark: 'one' },
    patrol: { label: 'Patrol', help: 'Walk a beat between two marks until the next order.', mark: 'two' },
    mount: { label: 'Board', help: 'Get into one of your vehicles (tap it on the map or pick it). Later moves are driven.', mark: 'vehicle' },
    dismount: { label: 'Dismount', help: 'Everyone out of the vehicle.', mark: 'none' },
    recon: { label: 'Scout', help: '', mark: 'one' },
    sabotage: { label: 'Sabotage', help: '', mark: 'one' },
    raid: { label: 'Raid', help: '', mark: 'one' },
};

export type SquadOrderKind = keyof typeof SQUAD_ORDERS;

/** A key for each common order (the picked squad's palette); 1–0 pick squads, Enter sends. */
export const ORDER_KEYS: Partial<Record<SquadOrderKind, string>> = {
    move: 'm',
    move_forced: 'r',
    defend: 'd',
    search: 's',
    attack: 'a',
    follow: 'f',
    patrol: 'p',
    halt: 'h',
    mount: 'b',
    dismount: 'g',
    watch: 'w',
    smoke: 'k',
    rest: 'x',
};

/** The order a key picks, if any. */
export function orderForKey(key: string): SquadOrderKind | null {
    const k = key.toLowerCase();
    const hit = (Object.entries(ORDER_KEYS) as Array<[SquadOrderKind, string]>).find(([, value]) => value === k);

    return hit ? hit[0] : null;
}

/** Orders given on top of the one under way, which goes on: not the squad's order, not in a chain. */
const ON_TOP: ReadonlyArray<WarOrderKind> = ['smoke', 'watch'];

export const ORDER_GROUPS: Array<{ label: string; kinds: SquadOrderKind[] }> = [
    { label: 'Move', kinds: ['move', 'move_forced', 'follow', 'halt', 'rest'] },
    { label: 'Fight', kinds: ['attack', 'defend', 'search', 'suppress', 'smoke', 'watch', 'patrol', 'recon'] },
    { label: 'Vehicle', kinds: ['mount', 'dismount'] },
    { label: 'Special', kinds: ['sabotage', 'raid'] },
];

export function operation(war: WarCommand, kind: WarOrderKind): WarOperation | null {
    return war.operations.find((op) => op.id === kind) ?? null;
}

/** The order's name and help, the operations' from the server's rules. */
export function orderSpec(war: WarCommand, kind: SquadOrderKind): { label: string; help: string; mark: MarkNeed } {
    const spec = SQUAD_ORDERS[kind];
    const op = operation(war, kind);

    return op ? { ...spec, label: op.label, help: op.help } : spec;
}

/**
 * Where an operation's mark really points, as the server will take it: the nearest place of
 * its target kinds within `snap` m. Null place: free recon at the mark. Error: nothing in reach
 * for an operation that needs a target.
 */
export function aimOperation(
    war: WarCommand,
    op: WarOperation,
    at: Point,
): { x: number; z: number; place: WarCommand['places'][number] | null } | { error: string } {
    let best: { d: number; place: WarCommand['places'][number] } | null = null;
    for (const place of war.places) {
        if (!op.targets.includes(place.kind)) {
            continue;
        }
        const d = Math.hypot(place.x - at.x, place.z - at.z);
        if (d <= op.snap && (best === null || d < best.d)) {
            best = { d, place };
        }
    }
    if (best) {
        return { x: best.place.x, z: best.place.z, place: best.place };
    }
    if (op.required) {
        return { error: `${op.label}: mark a ${op.targets.map((k) => TARGET_WORD[k] ?? k).join(' or ')} (within ${op.snap} m).` };
    }

    return { x: at.x, z: at.z, place: null };
}

const TARGET_WORD: Record<string, string> = { relay: 'relay tower', harbour: 'harbour', base: 'base', objective: 'objective', town: 'town' };

/** Why the squad cannot take this order now, or null. Mirrors the server's checks. */
export function orderBlocked(war: WarCommand, squad: Squad, kind: SquadOrderKind): string | null {
    if (squad.condition.field === 0) {
        return 'Nobody from this squad is on the island.';
    }
    if (kind === 'mount' && !hasVehicle(war)) {
        return 'You have no vehicle on the island. Draw one from the motor pool.';
    }
    if (kind === 'dismount' && !war.soldiers.some((s) => s.squad_id === squad.id && s.location === 'field' && s.in_vehicle)) {
        return 'Nobody from this squad is aboard.';
    }
    if (kind === 'follow' && followable(war, squad).length === 0) {
        return 'No other squad of yours is on the island.';
    }
    if (kind === 'attack' && war.contacts.length === 0) {
        return 'Your side knows of no enemy to attack.';
    }
    const op = operation(war, kind);
    if (op?.needs && !war.soldiers.some((s) => s.squad_id === squad.id && s.location === 'field' && s.role.includes(op.needs!))) {
        return `Needs a ${op.needs} in the squad.`;
    }

    return null;
}

/** Whether the commander has a vehicle on the island to board. */
function hasVehicle(war: WarCommand): boolean {
    return war.vehicles.some((v) => v.status === 'fielded' && !v.hq && v.x !== null && v.z !== null);
}

/** Your other squads in the field: what Follow can follow. */
export function followable(war: WarCommand, squad: Squad): Squad[] {
    return war.squads.filter((s) => s.mine && s.id !== squad.id && s.condition.field > 0);
}

/** An attack's mark snaps to the nearest enemy contact within 150 m, as the server takes it. */
export function aimAttack(war: WarCommand, at: Point): { x: number; z: number; contact: WarCommand['contacts'][number] } | null {
    let best: { d: number; contact: WarCommand['contacts'][number] } | null = null;
    for (const contact of war.contacts) {
        const d = Math.hypot(contact.x - at.x, contact.z - at.z);
        if (d <= 150 && (best === null || d < best.d)) {
            best = { d, contact };
        }
    }

    return best ? { x: best.contact.x, z: best.contact.z, contact: best.contact } : null;
}

/** Points after the first a move's route may take (IssueWarMoveOrderAction::MAX_LEGS). */
export const MAX_LEGS = 6;

type Vehicle = WarCommand['vehicles'][number];

/** The commander's vehicles on the island, nearest to the squad first: what Board can take. */
export function boardable(war: WarCommand, squad: Squad): Array<Vehicle & { metres: number }> {
    return war.vehicles
        .filter((v) => v.status === 'fielded' && !v.hq && v.x !== null && v.z !== null)
        .map((v) => ({ ...v, metres: Math.hypot(v.x! - squad.x, v.z! - squad.z) }))
        .sort((a, b) => a.metres - b.metres);
}

export type Marks = { a: Point | null; b: Point | null; legs: Point[] };

/**
 * Where the next tap on the map goes for this order: a patrol takes its two ends in turn (a
 * third tap starts the beat again); a move drawing a route adds its next point; everything else
 * moves its one mark.
 */
export function placeMark(kind: WarOrderKind, marks: Marks, at: Point, route = false): Marks {
    if (kind === 'patrol') {
        return marks.a && !marks.b ? { a: marks.a, b: at, legs: [] } : { a: at, b: null, legs: [] };
    }
    if (route && (kind === 'move' || kind === 'move_forced') && marks.a) {
        // A full route keeps what it has: a stray tap must not throw it away.
        return marks.legs.length < MAX_LEGS ? { ...marks, legs: [...marks.legs, at] } : marks;
    }

    return { a: at, b: null, legs: [] };
}

/** Orders that go on until the next one: nothing can wait in a chain behind them. */
const OPEN_ENDED: ReadonlyArray<WarOrderKind> = ['defend', 'patrol', 'follow'];

/** Orders a squad can ride to in one of your vehicles: board, drive there, (get out). */
export const RIDES: ReadonlyArray<SquadOrderKind> = ['move', 'move_forced', 'rest'];

type Order = WarCommand['orders'][number];

/**
 * The squad's orders under way and waiting, in turn: the one the game has (sent or under way)
 * and its chain behind it.
 */
export function chainNow(war: WarCommand, squadId: number): Order[] {
    return war.orders
        .filter(
            (o) => o.squad_id === squadId && !ON_TOP.includes(o.kind) && (o.status === 'queued' || o.status === 'pending' || o.status === 'acked'),
        )
        .sort((a, b) => a.id - b.id);
}

/** Why nothing can be added after the squad's chain, or null. */
export function chainBlocked(war: WarCommand, squadId: number): string | null {
    const tail = chainNow(war, squadId).at(-1);
    if (tail && OPEN_ENDED.includes(tail.kind)) {
        return `${SQUAD_ORDERS[tail.kind as SquadOrderKind]?.label ?? tail.kind} goes on until the next order: nothing after it would start.`;
    }
    if (chainNow(war, squadId).filter((o) => o.status === 'queued').length >= MAX_QUEUE) {
        return `At most ${MAX_QUEUE} orders wait in a chain.`;
    }

    return null;
}

/** Orders that may wait behind the one under way (IssueWarMoveOrderAction::MAX_QUEUE). */
export const MAX_QUEUE = 6;

type Logistics = {
    /** Within the home radius: the hospital and the arsenal. */
    home: boolean;
    homeMetres: number;
    /** One of your ammo vehicles within reach, else the nearest one anywhere. */
    truck: (Vehicle & { metres: number }) | null;
    truckInReach: boolean;
    wounded: number;
    /** Wounded who can still walk: the ones an ambulance takes. */
    walkingWounded: number;
    lowAmmo: number;
    /** A rearm under way. */
    rearming: boolean;
    aboard: number;
    /** Someone in the squad is being shot at. */
    underFire: boolean;
};

/** Where this squad can get its wounded seen to and its magazines, and what it needs. */
export function logistics(war: WarCommand, squad: Squad): Logistics {
    const home = war.sides.find((s) => s.id === squad.side)?.home ?? { x: squad.x, z: squad.z };
    const homeMetres = Math.hypot(home.x - squad.x, home.z - squad.z);
    const truck =
        war.vehicles
            .filter((v) => v.status === 'fielded' && !v.hq && v.x !== null && v.z !== null && war.logistics.ammo_vehicles.includes(v.kind))
            .map((v) => ({ ...v, metres: Math.hypot(v.x! - squad.x, v.z! - squad.z) }))
            .sort((a, b) => a.metres - b.metres)[0] ?? null;
    const men = war.soldiers.filter((s) => s.squad_id === squad.id && s.location === 'field');

    return {
        home: homeMetres <= war.logistics.home_radius,
        homeMetres,
        truck,
        truckInReach: truck !== null && truck.metres <= war.logistics.truck_radius,
        wounded: men.filter((s) => s.down || s.bleeding || s.health < 60).length,
        walkingWounded: men.filter((s) => !s.down && (s.bleeding || s.health < 60)).length,
        lowAmmo: men.filter((s) => s.flags.includes('low_ammo')).length,
        rearming: war.support.some((r) => r.kind === 'resupply' && r.squad_id === squad.id && (r.status === 'requested' || r.status === 'waiting')),
        aboard: men.filter((s) => s.in_vehicle).length,
        underFire: men.some((s) => s.flags.includes('under_fire')),
    };
}

/** How an order goes: the picked vehicle / squad, a drawn route, riding there, getting out. */
export type OrderOptions = {
    vehicleId: number | null;
    followId: number | null;
    route: boolean;
    /** A vehicle to board first and drive there in (moves and the way home). */
    ride: number | null;
    /** Everyone out on arrival when they drive. */
    getOut: boolean;
};

/**
 * The request for this order, exactly as the console would send it, or why it cannot go: the
 * same checks as the server. A ride is board, then the order, then (for a move) get out; the
 * whole chain goes in one request. `append` puts it behind what the squad is doing.
 */
export function orderPayload(
    war: WarCommand,
    squad: Squad,
    kind: SquadOrderKind,
    marks: Marks,
    options: OrderOptions,
    append = false,
): { payload: WarOrderRequest } | { error: string } {
    const spec = orderSpec(war, kind);
    const blocked = orderBlocked(war, squad, kind);
    if (blocked) {
        return { error: blocked };
    }
    if (append) {
        const why = chainBlocked(war, squad.id);
        if (why) {
            return { error: why };
        }
    }
    const vehicles = boardable(war, squad);
    const vehicle = vehicles.find((v) => v.id === options.vehicleId) ?? vehicles[0] ?? null;
    const leaders = followable(war, squad);
    const leader = leaders.find((s) => s.id === options.followId) ?? leaders[0] ?? null;
    let at: Point | null;
    if (spec.mark === 'vehicle') {
        at = vehicle ? { x: vehicle.x!, z: vehicle.z! } : null;
    } else if (spec.mark === 'squad') {
        at = leader ? { x: leader.x, z: leader.z } : null;
    } else if (spec.mark === 'none') {
        at = { x: squad.x, z: squad.z };
    } else {
        at = marks.a;
    }
    if (!at) {
        return {
            error:
                spec.mark === 'one' || spec.mark === 'two' ? 'Mark the map first.' : spec.mark === 'contact' ? 'Pick an enemy contact.' : spec.help,
        };
    }
    if (spec.mark === 'two' && !marks.b) {
        return { error: 'Mark the other end of the beat.' };
    }
    if (kind === 'attack') {
        const enemy = aimAttack(war, at);
        if (!enemy) {
            return { error: 'No known enemy there: pick one of the enemy contacts.' };
        }
        at = { x: enemy.x, z: enemy.z };
    }
    const op = operation(war, kind);
    if (op) {
        const aimed = aimOperation(war, op, at);
        if ('error' in aimed) {
            return { error: aimed.error };
        }
        at = { x: aimed.x, z: aimed.z };
    }

    const legs = options.route && (kind === 'move' || kind === 'move_forced') ? marks.legs : [];
    const end = legs.at(-1) ?? at;
    const order: WarOrderStep = { kind, x: at.x, z: at.z };
    if (spec.mark === 'two' && marks.b) {
        Object.assign(order, { x2: marks.b.x, z2: marks.b.z });
    }
    if (spec.mark === 'vehicle' && vehicle) {
        order.vehicle_id = vehicle.id;
    } else if (spec.mark === 'squad' && leader) {
        order.follow_squad_id = leader.id;
    } else if (legs.length > 0) {
        order.route = legs;
    }

    const aboard = war.soldiers.some((s) => s.squad_id === squad.id && s.location === 'field' && s.in_vehicle);
    const ride = RIDES.includes(kind) && !aboard ? (vehicles.find((v) => v.id === options.ride) ?? null) : null;
    const steps: WarOrderStep[] = ride ? [{ kind: 'mount', vehicle_id: ride.id, x: ride.x!, z: ride.z! }, order] : [order];
    if (options.getOut && (ride || aboard) && (kind === 'move' || kind === 'move_forced')) {
        steps.push({ kind: 'dismount', x: end.x, z: end.z });
    }
    const [first, ...then] = steps;

    return { payload: { squad_id: squad.id, ...first, ...(then.length > 0 ? { then } : {}), ...(append ? { queue: true } : {}) } };
}

type Offer = WarCommand['support_catalog'][number];

/** Why HQ would refuse this fire mission at that spot now, or null: reloading, money, danger close. */
export function fireBlocked(war: WarCommand, offer: Offer, at: Point | null): string | null {
    if (offer.ready_at !== null && new Date(offer.ready_at).getTime() > Date.now()) {
        return 'The battery is reloading.';
    }
    if ((war.economy.supplies ?? 0) < offer.cost) {
        return 'Not enough supplies.';
    }
    if (at && offer.danger_close > 0) {
        // As the server judges it: any of your side's men or squads, HQ's included.
        const side = war.enlisted_side;
        const near = [
            ...war.markers.filter((m) => m.side === side).map((m) => ({ name: m.name, x: m.x, z: m.z })),
            ...war.squads.filter((s) => s.side === side && s.condition.field > 0).map((s) => ({ name: s.label, x: s.x, z: s.z })),
        ]
            .map((p) => ({ ...p, metres: Math.hypot(p.x - at.x, p.z - at.z) }))
            .filter((p) => p.metres < offer.danger_close)
            .sort((a, b) => a.metres - b.metres)[0];
        if (near) {
            return `Danger close: ${near.name} is ${Math.round(near.metres)} m away.`;
        }
    }

    return null;
}

/**
 * Where this vehicle can be refuelled and repaired now: at home (free), beside another of your
 * trucks or carriers (for supplies), or nowhere (drive it to one). `busy`: a service under way.
 */
export function serviceAt(war: WarCommand, vehicle: Vehicle): { where: 'home' | 'truck' | null; cost: number; busy: boolean } {
    const busy = war.support.some((r) => r.kind === 'service' && r.vehicle_id === vehicle.id && (r.status === 'requested' || r.status === 'waiting'));
    if (vehicle.x === null || vehicle.z === null) {
        return { where: null, cost: 0, busy };
    }
    const home = war.sides.find((s) => s.id === war.enlisted_side)?.home;
    if (home && Math.hypot(home.x - vehicle.x, home.z - vehicle.z) <= war.logistics.home_radius) {
        return { where: 'home', cost: 0, busy };
    }
    const truck = war.vehicles.some(
        (v) =>
            v.id !== vehicle.id &&
            v.status === 'fielded' &&
            !v.hq &&
            v.x !== null &&
            v.z !== null &&
            war.logistics.ammo_vehicles.includes(v.kind) &&
            Math.hypot(v.x - vehicle.x!, v.z - vehicle.z!) <= war.logistics.truck_radius,
    );

    return truck ? { where: 'truck', cost: war.logistics.service_cost, busy } : { where: null, cost: 0, busy };
}

/** How far short of the target an assault's rush stops, to shake out before the sweep. */
export const ASSAULT_STAND_OFF = 150;

/**
 * An assault in the game's own commands: a rush (no stopping to fight on the way) to a line
 * ASSAULT_STAND_OFF short of the mark, then search and destroy at the mark. One request.
 */
export function assaultPayload(war: WarCommand, squad: Squad, at: Point | null): { payload: WarOrderRequest } | { error: string } {
    if (!at) {
        return { error: 'Mark the target first.' };
    }
    for (const kind of ['move_forced', 'search'] as const) {
        const blocked = orderBlocked(war, squad, kind);
        if (blocked) {
            return { error: blocked };
        }
    }
    const metres = Math.hypot(at.x - squad.x, at.z - squad.z);
    const sweep: WarOrderStep = { kind: 'search', x: at.x, z: at.z };
    if (metres <= ASSAULT_STAND_OFF + 50) {
        return { payload: { squad_id: squad.id, ...sweep } };
    }
    const k = (metres - ASSAULT_STAND_OFF) / metres;
    const line = { x: Math.round(squad.x + (at.x - squad.x) * k), z: Math.round(squad.z + (at.z - squad.z) * k) };

    return { payload: { squad_id: squad.id, kind: 'move_forced', x: line.x, z: line.z, then: [sweep] } };
}

/** The value of the ride select that asks HQ for a lift instead of one of your vehicles. */
export const HQ_LIFT = -1;

/** The HQ run under way for this squad, if any. */
export function runFor(war: WarCommand, squadId: number): WarCommand['runs'][number] | null {
    return war.runs.find((r) => r.squad_id === squadId && r.status === 'active') ?? null;
}
