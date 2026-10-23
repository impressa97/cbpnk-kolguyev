import type { WarCommand, WarOperation } from '@/lib/schemas/reforger';
import { describe, expect, it } from 'vitest';
import {
    aimAttack,
    aimOperation,
    assaultPayload,
    boardable,
    chainBlocked,
    chainNow,
    followable,
    logistics,
    MAX_LEGS,
    ORDER_KEYS,
    orderBlocked,
    orderForKey,
    orderPayload,
    orderSpec,
    placeMark,
} from './orders';

const raid: WarOperation = {
    id: 'raid',
    label: 'Raid',
    radius: 75,
    hold: 120,
    targets: ['harbour'],
    snap: 400,
    required: true,
    needs: null,
    help: 'Hold a harbour.',
};
const recon: WarOperation = {
    id: 'recon',
    label: 'Recon',
    radius: 75,
    hold: 180,
    targets: ['base', 'objective'],
    snap: 150,
    required: false,
    needs: null,
    help: 'Watch.',
};
const sabotage: WarOperation = {
    id: 'sabotage',
    label: 'Sabotage',
    radius: 50,
    hold: 120,
    targets: ['relay'],
    snap: 400,
    required: true,
    needs: 'sapper',
    help: 'Blow it.',
};

const squad = { id: 1, vehicle_id: null, condition: { field: 4 } } as unknown as WarCommand['squads'][number];

function war(over: Partial<WarCommand> = {}): WarCommand {
    return {
        places: [
            { id: 'dock', name: 'Dock', side: null, kind: 'harbour', x: 1000, z: 1000 },
            { id: 'town', name: 'Town', side: null, kind: 'objective', x: 3000, z: 3000 },
        ],
        operations: [recon, sabotage, raid],
        vehicles: [],
        soldiers: [{ squad_id: 1, location: 'field', role: 'rifleman', in_vehicle: false }],
        ...over,
    } as unknown as WarCommand;
}

describe('squad orders', () => {
    it('snaps an operation to its target the way the server will', () => {
        expect(aimOperation(war(), raid, { x: 1200, z: 1100 })).toMatchObject({ x: 1000, z: 1000, place: { id: 'dock' } });
        expect(aimOperation(war(), raid, { x: 2000, z: 2000 })).toHaveProperty('error');
        // Recon may go anywhere; near a point it watches the point.
        expect(aimOperation(war(), recon, { x: 2000, z: 2000 })).toEqual({ x: 2000, z: 2000, place: null });
        expect(aimOperation(war(), recon, { x: 3100, z: 3000 })).toMatchObject({ place: { id: 'town' } });
    });

    it('takes the operations’ words from the server', () => {
        expect(orderSpec(war(), 'raid')).toMatchObject({ label: 'Raid', help: 'Hold a harbour.', mark: 'one' });
        expect(orderSpec(war(), 'patrol').mark).toBe('two');
    });

    it('says why a squad cannot take an order, as the server would refuse it', () => {
        expect(orderBlocked(war(), squad, 'sabotage')).toBe('Needs a sapper in the squad.');
        expect(orderBlocked(war(), squad, 'mount')).toBe('You have no vehicle on the island. Draw one from the motor pool.');
        expect(orderBlocked(war(), squad, 'dismount')).toBe('Nobody from this squad is aboard.');
        expect(orderBlocked(war(), squad, 'move')).toBeNull();
        expect(orderBlocked(war(), { ...squad, condition: { ...squad.condition, field: 0 } }, 'move')).toBe(
            'Nobody from this squad is on the island.',
        );
    });

    it('offers any of your vehicles on the island to board, nearest first', () => {
        const at = (id: number, x: number) => ({ id, status: 'fielded', x, z: 0, model: 'M923A1', crew: 0, seats: 14 });
        const fleet = war({ vehicles: [at(1, 900), at(2, 100), { ...at(3, 50), status: 'ordered' }] } as unknown as Partial<WarCommand>);
        const here = { ...squad, x: 0, z: 0 };
        expect(boardable(fleet, here).map((v) => v.id)).toEqual([2, 1]);
        expect(orderBlocked(fleet, here, 'mount')).toBeNull();
    });

    it('takes a patrol’s two ends in turn and starts again on a third tap', () => {
        const a = { x: 1, z: 1 };
        const b = { x: 2, z: 2 };
        const c = { x: 3, z: 3 };
        expect(placeMark('patrol', { a: null, b: null, legs: [] }, a)).toEqual({ a, b: null, legs: [] });
        expect(placeMark('patrol', { a, b: null, legs: [] }, b)).toEqual({ a, b, legs: [] });
        expect(placeMark('patrol', { a, b, legs: [] }, c)).toEqual({ a: c, b: null, legs: [] });
        expect(placeMark('move', { a, b: null, legs: [] }, c)).toEqual({ a: c, b: null, legs: [] });
    });

    it('draws a move’s route point by point, up to the limit', () => {
        const at = (n: number) => ({ x: n, z: n });
        let marks = placeMark('move', { a: null, b: null, legs: [] }, at(1), true);
        for (let i = 2; i <= MAX_LEGS + 3; i++) {
            marks = placeMark('move', marks, at(i), true);
        }
        expect(marks.a).toEqual(at(1));
        expect(marks.legs).toHaveLength(MAX_LEGS);
        // Without the route switch a tap moves the one mark.
        expect(placeMark('move', marks, at(9))).toEqual({ a: at(9), b: null, legs: [] });
    });

    it('aims an attack at the nearest enemy contact, and follows only your other squads in the field', () => {
        const contact = { id: 7, side: 'ussr', x: 500, z: 500, kind: 'inf', how: 'seen', seen_at: '', age_s: 1 };
        const field = war({ contacts: [contact] } as unknown as Partial<WarCommand>);
        expect(aimAttack(field, { x: 560, z: 540 })).toMatchObject({ x: 500, z: 500 });
        expect(aimAttack(field, { x: 900, z: 900 })).toBeNull();

        const mine = (id: number, field: number) => ({ id, mine: true, managed: false, x: 0, z: 0, condition: { field } });
        const squads = war({ squads: [mine(1, 4), mine(2, 3), mine(3, 0), { ...mine(4, 4), mine: false }] } as unknown as Partial<WarCommand>);
        expect(followable(squads, mine(1, 4) as unknown as WarCommand['squads'][number]).map((s) => s.id)).toEqual([2]);
    });
});

describe('chains and logistics', () => {
    const order = (id: number, kind: string, status: string) => ({ id, squad_id: 1, kind, status }) as unknown as WarCommand['orders'][number];

    it('lists what is under way and waiting, and stops a chain behind an order that never ends', () => {
        const orders = [order(1, 'move', 'done'), order(2, 'mount', 'acked'), order(3, 'smoke', 'acked'), order(4, 'move', 'queued')];
        expect(chainNow(war({ orders }), 1).map((o) => o.id)).toEqual([2, 4]);
        expect(chainBlocked(war({ orders }), 1)).toBeNull();
        expect(chainBlocked(war({ orders: [...orders, order(5, 'defend', 'queued')] }), 1)).toMatch(/goes on/);
    });

    it('rearms at home or beside a truck, and counts who needs what', () => {
        const base = {
            sides: [{ id: 'us', home: { x: 100, z: 100 } }],
            logistics: { home_radius: 200, truck_radius: 40, truck_cost: 10, magazines: 6, ammo_vehicles: ['truck'] },
            support: [],
            soldiers: [
                { squad_id: 1, location: 'field', health: 40, bleeding: false, down: false, flags: ['low_ammo'], in_vehicle: false },
                { squad_id: 1, location: 'field', health: 100, bleeding: false, down: false, flags: [], in_vehicle: true },
            ],
        } as unknown as Partial<WarCommand>;
        const at = (x: number, z: number) => ({ ...squad, side: 'us', x, z }) as WarCommand['squads'][number];

        expect(logistics(war(base), at(150, 150))).toMatchObject({ home: true, wounded: 1, lowAmmo: 1, aboard: 1, truck: null });
        const truck = { id: 9, kind: 'truck', status: 'fielded', x: 5020, z: 5000 };
        const jeep = { id: 8, kind: 'jeep', status: 'fielded', x: 5001, z: 5000 };
        const away = logistics(war({ ...base, vehicles: [jeep, truck] } as Partial<WarCommand>), at(5000, 5000));
        expect(away).toMatchObject({ home: false, truckInReach: true, truck: { id: 9 } });
    });
});

describe('the order the panel sends', () => {
    const at = { ...squad, x: 1000, z: 1000 } as WarCommand['squads'][number];
    const options = { vehicleId: null, followId: null, route: false, ride: null, getOut: true };
    const truck = { id: 9, kind: 'truck', status: 'fielded', x: 1010, z: 1000 };
    const marks = { a: { x: 2000, z: 2000 }, b: null, legs: [] };
    const desk = (over: object = {}) => war({ squads: [], orders: [], contacts: [], ...over } as Partial<WarCommand>);

    it('rides there by car: board, drive, get out, in one request', () => {
        const result = orderPayload(desk({ vehicles: [truck] }), at, 'move', marks, { ...options, ride: 9 });
        expect(result).toEqual({
            payload: {
                squad_id: 1,
                kind: 'mount',
                vehicle_id: 9,
                x: 1010,
                z: 1000,
                then: [
                    { kind: 'move', x: 2000, z: 2000 },
                    { kind: 'dismount', x: 2000, z: 2000 },
                ],
            },
        });
    });

    it('says why it cannot go: no mark, nobody to attack, a chain behind a defend', () => {
        expect(orderPayload(desk(), at, 'move', { a: null, b: null, legs: [] }, options)).toHaveProperty('error');
        expect(orderPayload(desk({ contacts: [{ x: 5000, z: 5000 }] }), at, 'attack', marks, options)).toHaveProperty('error');
        const defending = desk({ orders: [{ id: 1, squad_id: 1, kind: 'defend', status: 'acked' }] });
        expect(orderPayload(defending, at, 'move', marks, options, true)).toHaveProperty('error');
        expect(orderPayload(defending, at, 'move', marks, options)).toMatchObject({ payload: { kind: 'move' } });
    });
});

describe('an assault', () => {
    const at = { ...squad, x: 1000, z: 1000 } as WarCommand['squads'][number];
    const desk = war({ squads: [], orders: [], contacts: [] } as unknown as Partial<WarCommand>);

    it('rushes to a line 150 m short, then searches and destroys at the mark', () => {
        expect(assaultPayload(desk, at, { x: 1000, z: 2000 })).toEqual({
            payload: { squad_id: 1, kind: 'move_forced', x: 1000, z: 1850, then: [{ kind: 'search', x: 1000, z: 2000 }] },
        });
        expect(assaultPayload(desk, at, { x: 1000, z: 1100 })).toEqual({ payload: { squad_id: 1, kind: 'search', x: 1000, z: 1100 } });
        expect(assaultPayload(desk, at, null)).toHaveProperty('error');
    });
});

describe('order keys', () => {
    it('picks an order by its key, either case, and nothing for other keys', () => {
        expect(orderForKey('m')).toBe('move');
        expect(orderForKey('R')).toBe('move_forced');
        expect(orderForKey('q')).toBeNull();
    });

    it('gives no two orders the same key', () => {
        const keys = Object.values(ORDER_KEYS);
        expect(new Set(keys).size).toBe(keys.length);
    });
});
