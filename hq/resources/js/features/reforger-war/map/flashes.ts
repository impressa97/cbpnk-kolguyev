import type { WarCommand } from '@/lib/schemas/reforger';
import type { Feature, FeatureCollection, Geometry } from 'geojson';
import { toLngLat } from './projection';

/**
 * Something that just happened, shown where it happened for a short while. `alert`: worth
 * interrupting the commander for (a toast with the way there), not only a word on the map.
 */
export type MapFlash = { id: string; x: number; z: number; text: string; tone: 'bad' | 'good' | 'info'; at: number; alert?: boolean };

const LIFE_MS = 25_000;

/**
 * What changed between two desks, as flashes on the map: a man of yours killed, a vehicle lost,
 * a point changing hands, a squad of yours reaching its mark, HQ's rounds landing, a new enemy.
 * Only what your side knows; nothing for the first desk (no "before").
 */
export function diffFlashes(before: WarCommand | null, after: WarCommand, now: number): MapFlash[] {
    if (!before) {
        return [];
    }
    const out: MapFlash[] = [];

    const fallen = new Set(after.fallen.filter((s) => s.loss === 'killed').map((s) => s.id));
    for (const m of before.markers) {
        if (m.mine && fallen.has(m.id) && !before.fallen.some((s) => s.id === m.id)) {
            out.push({ id: `killed-${m.id}`, x: m.x, z: m.z, text: `✝ ${m.name}`, tone: 'bad', at: now, alert: true });
        }
    }

    for (const v of after.vehicles) {
        const was = before.vehicles.find((b) => b.id === v.id);
        if (was && was.status === 'fielded' && v.status === 'destroyed' && was.x !== null && was.z !== null) {
            out.push({ id: `wreck-${v.id}`, x: was.x, z: was.z, text: `✝ ${v.model}`, tone: 'bad', at: now, alert: true });
        }
    }

    for (const c of after.territory) {
        const was = before.territory.find((b) => b.place === c.place);
        const place = after.places.find((p) => p.id === c.place);
        if (place && was && was.side !== c.side && c.side !== null) {
            const ours = c.side === after.enlisted_side;
            out.push({
                id: `taken-${c.place}-${now}`,
                x: place.x,
                z: place.z,
                text: `${place.name} taken by ${c.side.toUpperCase()}`,
                tone: ours ? 'good' : 'bad',
                at: now,
                alert: true,
            });
        }
    }

    for (const o of after.orders) {
        const was = before.orders.find((b) => b.id === o.id);
        if (was && was.status !== 'done' && o.status === 'done' && o.kind !== 'smoke' && o.kind !== 'watch' && o.kind !== 'halt') {
            const squad = after.squads.find((s) => s.id === o.squad_id);
            out.push({ id: `done-${o.id}`, x: o.x, z: o.z, text: `${squad?.label ?? 'Squad'} · done`, tone: 'good', at: now });
        }
    }

    // An order the game could not carry out stops its chain: the commander has to know.
    for (const o of after.orders) {
        const was = before.orders.find((b) => b.id === o.id);
        if (was && was.status !== 'failed' && o.status === 'failed') {
            const squad = after.squads.find((s) => s.id === o.squad_id);
            out.push({
                id: `failed-${o.id}`,
                x: squad?.x ?? o.x,
                z: squad?.z ?? o.z,
                text: `${squad?.label ?? 'Squad'} · order failed`,
                tone: 'bad',
                at: now,
                alert: true,
            });
        }
    }

    // An enemy one of your men killed (his tally went up), where he is.
    for (const s of after.soldiers) {
        const was = before.soldiers.find((b) => b.id === s.id);
        const at = after.markers.find((m) => m.id === s.id);
        if (was && s.kills > was.kills && at) {
            const n = s.kills - was.kills;
            out.push({
                id: `kill-${s.id}-${s.kills}`,
                x: at.x,
                z: at.z,
                text: `${s.name} killed ${n === 1 ? 'an enemy' : `${n} enemies`}`,
                tone: 'good',
                at: now,
                alert: true,
            });
        }
    }

    // A squad of yours coming under fire, and a man of yours going down.
    for (const squad of after.squads.filter((s) => s.mine && !s.managed)) {
        const hot = (desk: WarCommand) =>
            desk.soldiers.some((s) => s.squad_id === squad.id && s.location === 'field' && s.flags.includes('under_fire'));
        if (hot(after) && !hot(before)) {
            out.push({
                id: `fire-on-${squad.id}-${now}`,
                x: squad.x,
                z: squad.z,
                text: `${squad.label} under fire`,
                tone: 'bad',
                at: now,
                alert: true,
            });
        }
    }
    for (const s of after.soldiers) {
        const was = before.soldiers.find((b) => b.id === s.id);
        const at = after.markers.find((m) => m.id === s.id);
        if (was && !was.down && s.down && at) {
            out.push({ id: `down-${s.id}-${now}`, x: at.x, z: at.z, text: `${s.name} down`, tone: 'bad', at: now, alert: true });
        }
    }

    for (const s of after.support) {
        const was = before.support.find((b) => b.id === s.id);
        if (was && was.status !== 'done' && s.status === 'done' && s.x !== null && s.z !== null) {
            out.push({ id: `fire-${s.id}`, x: s.x, z: s.z, text: `${s.name} · on target`, tone: 'info', at: now });
        }
    }

    for (const c of after.contacts) {
        const near = before.contacts.some((b) => b.side === c.side && Math.hypot(b.x - c.x, b.z - c.z) < 150);
        if (!near && c.how === 'seen') {
            out.push({
                id: `contact-${c.id}`,
                x: c.x,
                z: c.z,
                text: `Enemy ${c.kind === 'veh' ? 'vehicle' : 'infantry'} seen`,
                tone: 'bad',
                at: now,
            });
        }
    }

    return out;
}

/** The flashes still alive, newest first, for a symbol layer that fades them out. */
export function flashData(flashes: MapFlash[], now: number): FeatureCollection<Geometry, Record<string, unknown>> {
    const features: Array<Feature<Geometry, Record<string, unknown>>> = flashes
        .filter((f) => now - f.at < LIFE_MS)
        .map((f) => ({
            type: 'Feature',
            properties: {
                text: f.text,
                colour: f.tone === 'bad' ? '#b8332a' : f.tone === 'good' ? '#2f6b33' : '#1f63b8',
                fade: Math.max(0.15, 1 - (now - f.at) / LIFE_MS),
            },
            geometry: { type: 'Point', coordinates: toLngLat(f.x, f.z) },
        }));

    return { type: 'FeatureCollection', features };
}

export function liveFlashes(flashes: MapFlash[], now: number): MapFlash[] {
    return flashes.filter((f) => now - f.at < LIFE_MS);
}
