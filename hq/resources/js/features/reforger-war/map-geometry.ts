/**
 * Kolguyev ground maths in metres (X east, Z north). Screen projection lives in map/projection.ts.
 */

/** Infantry pace the AI keeps on a move waypoint, roughly a jog across mixed ground. */
const INFANTRY_METRES_PER_SECOND = 2.2;

/**
 * Reforger map grid reference: three digits east, three digits north, 100 m squares
 * (the in-game map and the Conflict HUD use the same scheme).
 */
export function gridRef(x: number, z: number): string {
    const east = Math.max(0, Math.floor(x / 100));
    const north = Math.max(0, Math.floor(z / 100));

    return `${String(east).padStart(3, '0')} ${String(north).padStart(3, '0')}`;
}

export function distance(ax: number, az: number, bx: number, bz: number): number {
    return Math.hypot(bx - ax, bz - az);
}

export function formatDistance(metres: number): string {
    if (metres >= 1000) {
        return `${(metres / 1000).toFixed(metres >= 10000 ? 0 : 1)} km`;
    }

    return `${Math.round(metres)} m`;
}

/** Compass bearing in degrees, 0 = north, clockwise — what the in-game compass shows. */
export function bearing(ax: number, az: number, bx: number, bz: number): number {
    const degrees = (Math.atan2(bx - ax, bz - az) * 180) / Math.PI;

    return Math.round((degrees + 360) % 360);
}

/** AI driving on Kolguyev's roads and tracks, all told: about 30 km/h. */
const DRIVEN_METRES_PER_SECOND = 8;

export function formatEta(metres: number, driven = false): string {
    const minutes = Math.max(1, Math.round(metres / (driven ? DRIVEN_METRES_PER_SECOND : INFANTRY_METRES_PER_SECOND) / 60));
    if (minutes < 60) {
        return `~${minutes} min`;
    }

    return `~${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}
