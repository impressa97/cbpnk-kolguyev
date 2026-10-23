import type { WarCommand } from '@/lib/schemas/reforger';
import { describe, expect, it } from 'vitest';
import { diffFlashes, flashData, liveFlashes } from './flashes';

const desk = (over: Partial<WarCommand>) =>
    ({
        markers: [],
        fallen: [],
        vehicles: [],
        territory: [],
        places: [],
        orders: [],
        squads: [],
        support: [],
        contacts: [],
        soldiers: [],
        enlisted_side: 'us',
        ...over,
    }) as unknown as WarCommand;

describe('map flashes', () => {
    it('flashes a death, a lost point, an arrival, rounds landing and a new enemy where they happened', () => {
        const before = desk({
            markers: [{ id: 7, name: 'Cole', mine: true, x: 100, z: 200 }],
            territory: [{ place: 'mineraly', side: 'us' }],
            places: [{ id: 'mineraly', name: 'Mineraly', x: 6322, z: 8012 }],
            orders: [{ id: 3, squad_id: 1, kind: 'move', status: 'acked', x: 50, z: 60 }],
            support: [{ id: 4, name: 'Mortar barrage', status: 'requested', x: 900, z: 900 }],
        } as unknown as Partial<WarCommand>);
        const after = desk({
            fallen: [{ id: 7, loss: 'killed' }],
            territory: [{ place: 'mineraly', side: 'ussr' }],
            places: [{ id: 'mineraly', name: 'Mineraly', x: 6322, z: 8012 }],
            orders: [{ id: 3, squad_id: 1, kind: 'move', status: 'done', x: 50, z: 60 }],
            squads: [{ id: 1, label: 'Alpha' }],
            support: [{ id: 4, name: 'Mortar barrage', status: 'done', x: 900, z: 900 }],
            contacts: [{ id: 9, side: 'ussr', kind: 'inf', how: 'seen', x: 3000, z: 3000 }],
        } as unknown as Partial<WarCommand>);

        const texts = diffFlashes(before, after, 1000).map((f) => f.text);
        expect(texts).toEqual(['✝ Cole', 'Mineraly taken by USSR', 'Alpha · done', 'Mortar barrage · on target', 'Enemy infantry seen']);
        expect(diffFlashes(null, after, 1000)).toEqual([]);
    });

    it('fades flashes out and drops them after 25 s', () => {
        const flashes = diffFlashes(
            desk({ markers: [{ id: 7, name: 'Cole', mine: true, x: 1, z: 1 }] } as unknown as Partial<WarCommand>),
            desk({ fallen: [{ id: 7, loss: 'killed' }] } as unknown as Partial<WarCommand>),
            0,
        );
        expect(flashData(flashes, 12_500).features[0].properties.fade).toBeCloseTo(0.5);
        expect(liveFlashes(flashes, 26_000)).toEqual([]);
    });

    it('alerts the commander when a squad comes under fire, a man goes down, or an order fails', () => {
        const squad = { id: 1, label: 'Alpha', mine: true, managed: false, x: 10, z: 20 };
        const man = (flags: string[], down: boolean, kills = 0) => ({ id: 5, name: 'Hale', squad_id: 1, location: 'field', flags, down, kills });
        const before = desk({
            squads: [squad],
            soldiers: [man([], false)],
            orders: [{ id: 3, squad_id: 1, kind: 'mount', status: 'acked', x: 50, z: 60 }],
        } as unknown as Partial<WarCommand>);
        const after = desk({
            squads: [squad],
            soldiers: [man(['under_fire'], true, 1)],
            markers: [{ id: 5, name: 'Hale', mine: true, x: 11, z: 21 }],
            orders: [{ id: 3, squad_id: 1, kind: 'mount', status: 'failed', x: 50, z: 60 }],
        } as unknown as Partial<WarCommand>);

        const alerts = diffFlashes(before, after, 1000).filter((f) => f.alert);
        expect(alerts.map((f) => f.text)).toEqual(['Alpha · order failed', 'Hale killed an enemy', 'Alpha under fire', 'Hale down']);
        expect(alerts[2]).toMatchObject({ x: 10, z: 20, tone: 'bad' });
    });
});
