import { describe, expect, it } from 'vitest';
import { bearing, formatDistance, formatEta, gridRef } from './map-geometry';
import { metresPerPixel, toLngLat, toMetres, WORLD } from './map/projection';

describe('kolguyev map geometry', () => {
    it('writes Reforger grid references in 100 m squares', () => {
        expect(gridRef(1951, 7401)).toBe('019 074');
        expect(gridRef(11867, 10680)).toBe('118 106');
        expect(gridRef(0, 0)).toBe('000 000');
    });

    it('reads compass bearings clockwise from north', () => {
        expect(bearing(0, 0, 0, 100)).toBe(0);
        expect(bearing(0, 0, 100, 0)).toBe(90);
        expect(bearing(0, 0, 0, -100)).toBe(180);
        expect(bearing(0, 0, -100, 0)).toBe(270);
    });

    it('formats range and foot time', () => {
        expect(formatDistance(450)).toBe('450 m');
        expect(formatDistance(2400)).toBe('2.4 km');
        expect(formatEta(132)).toBe('~1 min');
        expect(formatEta(2.2 * 60 * 90)).toBe('~1 h 30 min');
    });

    it('lays the island over the whole Web Mercator square and back', () => {
        const [west] = toLngLat(0, 0);
        const [east] = toLngLat(WORLD, 0);
        const [, north] = toLngLat(0, WORLD);
        expect(west).toBeCloseTo(-180);
        expect(east).toBeCloseTo(180);
        expect(north).toBeCloseTo(85.0511, 3);

        for (const [x, z] of [
            [1951, 7401],
            [11867, 10680],
            [6400, 6400],
        ]) {
            const back = toMetres(...toLngLat(x, z));
            expect(back.x).toBeCloseTo(x, 3);
            expect(back.z).toBeCloseTo(z, 3);
        }
    });

    it('keeps metres per pixel exact at every zoom', () => {
        expect(metresPerPixel(0)).toBe(25);
        expect(metresPerPixel(4)).toBeCloseTo(1.5625);
    });
});
