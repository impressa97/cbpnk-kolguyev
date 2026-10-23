import type { Feature, FeatureCollection, Geometry, Point } from 'geojson';
import type { GeoJSONSource, Map as MapLibreMap } from 'maplibre-gl';

type Collection = FeatureCollection<Geometry, Record<string, unknown>>;

/**
 * Point sources that glide: when a bridge tick moves a squad or a soldier, his marker travels
 * from where it is drawn now to the new spot over `ms`, instead of jumping. Features are matched
 * by their `id` property; newcomers appear in place.
 */
export class GlidingSource {
    private shown = new Map<string, [number, number]>();
    private frame = 0;

    constructor(
        private readonly map: MapLibreMap,
        private readonly sourceId: string,
        private readonly ms = 1600,
    ) {}

    set(data: Collection): void {
        cancelAnimationFrame(this.frame);
        const from = new Map(this.shown);
        const to = new Map<string, [number, number]>();
        for (const feature of data.features) {
            const key = keyOf(feature);
            if (key !== null && feature.geometry.type === 'Point') {
                to.set(key, feature.geometry.coordinates as [number, number]);
            }
        }
        const moving = [...to].some(([key, end]) => {
            const start = from.get(key);

            return start !== undefined && (start[0] !== end[0] || start[1] !== end[1]);
        });
        if (!moving || document.hidden) {
            this.draw(data, to);

            return;
        }

        const started = performance.now();
        const step = (now: number) => {
            const t = Math.min(1, (now - started) / this.ms);
            const ease = t * (2 - t);
            const at = new Map<string, [number, number]>();
            for (const [key, end] of to) {
                const start = from.get(key) ?? end;
                at.set(key, [start[0] + (end[0] - start[0]) * ease, start[1] + (end[1] - start[1]) * ease]);
            }
            this.draw(data, at);
            if (t < 1) {
                this.frame = requestAnimationFrame(step);
            }
        };
        this.frame = requestAnimationFrame(step);
    }

    stop(): void {
        cancelAnimationFrame(this.frame);
    }

    private draw(data: Collection, at: Map<string, [number, number]>): void {
        const features = data.features.map((feature): Feature<Geometry, Record<string, unknown>> => {
            const key = keyOf(feature);
            const spot = key === null ? undefined : at.get(key);

            return spot ? { ...feature, geometry: { type: 'Point', coordinates: spot } satisfies Point } : feature;
        });
        this.shown = at;
        (this.map.getSource(this.sourceId) as GeoJSONSource | undefined)?.setData({ type: 'FeatureCollection', features });
    }
}

function keyOf(feature: Feature<Geometry, Record<string, unknown>>): string | null {
    const id = feature.properties?.id;

    return typeof id === 'number' || typeof id === 'string' ? String(id) : null;
}
