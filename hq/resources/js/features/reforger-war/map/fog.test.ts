import type { WarCommand } from '@/lib/schemas/reforger';
import { describe, expect, it } from 'vitest';
import { fogData, seenCells, sightMetres } from './data';
import { toMetres } from './projection';

const war = (over: Partial<WarCommand>) => ({ markers: [], world: null, ...over }) as unknown as WarCommand;

describe('fog of war', () => {
    it('sees less at night and in fog', () => {
        const day = { hour: 12, sunrise: 6, sunset: 20, fog: 0 };
        expect(sightMetres(war({ world: day } as unknown as Partial<WarCommand>))).toBe(450);
        expect(sightMetres(war({ world: { ...day, hour: 23 } } as unknown as Partial<WarCommand>))).toBe(200);
        expect(sightMetres(war({ world: { ...day, fog: 75 } } as unknown as Partial<WarCommand>))).toBe(225);
    });

    it('clears the cells around your men and covers each row with runs that do not overlap', () => {
        const memory = new Map<number, number>();
        const seen = seenCells(war({ markers: [{ x: 6400, z: 6400 }] } as unknown as Partial<WarCommand>), memory, 1000);
        expect(seen.has(64 * 128 + 64)).toBe(true);
        expect(seen.has(0)).toBe(false);

        const fog = fogData(seen, memory, 1000);
        // Row 64 runs: fogged west, clear around the man, fogged east; together the row minus the seen cells.
        const row = fog.features.filter((f) => {
            const ring = (f.geometry as unknown as { coordinates: Array<Array<[number, number]>> }).coordinates[0];
            return Math.round(toMetres(ring[0][0], ring[0][1]).z) === 6400;
        });
        const width = row.reduce((sum, f) => {
            const ring = (f.geometry as unknown as { coordinates: Array<Array<[number, number]>> }).coordinates[0];
            return sum + Math.round(toMetres(ring[1][0], ring[1][1]).x - toMetres(ring[0][0], ring[0][1]).x);
        }, 0);
        const clear = [...seen].filter((i) => Math.floor(i / 128) === 64).length;
        expect(row.length).toBe(2);
        expect(width).toBe(12800 - clear * 100);
        // Never seen: the darker level.
        expect(row.every((f) => f.properties.level === 2)).toBe(true);
    });

    it('remembers cells seen lately as the lighter fog', () => {
        const memory = new Map<number, number>([[5, 0]]);
        const fog = fogData(new Set(), memory, 60_000);
        expect(fog.features.some((f) => f.properties.level === 1)).toBe(true);
    });
});
