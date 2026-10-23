/**
 * Kolguyev metres (X east, Z north, 0..12800 from the south-west corner) on MapLibre.
 *
 * The tile pyramid built by scripts/reforger/build-kolguyev-map.py lies exactly on the Web
 * Mercator pixel grid: our tile z/x/y is MapLibre's z/x/y, and the island fills the whole
 * world square. Metres therefore map linearly to Mercator pixels, so distances in metres per
 * screen pixel are exact at every zoom: metresPerPixel(zoom) = 12800 / (512 * 2^zoom).
 */

export const WORLD = 12800;
export const TILE_URL = '/geodata/kolguyev/{z}/{x}/{y}.webp';
/** The terrain's own colour map from above (scripts/reforger/build-kolguyev-satellite.py), same grid. */
export const SATELLITE_URL = '/geodata/kolguyev-sat/{z}/{x}/{y}.webp';
/** The picture from above goes one level deeper: z6 is the terrain's own 1 m colour map. */
export const SATELLITE_MAXZOOM = 6;
export const ROADS_URL = '/geodata/kolguyev/roads.geojson';
export const BUILDINGS_URL = '/geodata/kolguyev/buildings.geojson';
export const TERRAIN_URL = '/geodata/kolguyev/terrain.geojson';
export const LANDCOVER_URL = '/geodata/kolguyev/landcover.geojson';
export const TILE_MAXZOOM = 5;

export function toLngLat(x: number, z: number): [number, number] {
    const u = x / WORLD;
    const v = (WORLD - z) / WORLD;
    const lat = (Math.atan(Math.sinh(Math.PI * (1 - 2 * v))) * 180) / Math.PI;

    return [u * 360 - 180, lat];
}

export function toMetres(lng: number, lat: number): { x: number; z: number } {
    const u = (lng + 180) / 360;
    const rad = (lat * Math.PI) / 180;
    const v = (1 - Math.log(Math.tan(rad) + 1 / Math.cos(rad)) / Math.PI) / 2;

    return { x: u * WORLD, z: WORLD - v * WORLD };
}

export function metresPerPixel(zoom: number): number {
    return WORLD / (512 * 2 ** zoom);
}
