import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { installMapLibreWorker } from '@/lib/map/maplibre-worker';
import type { WarCommand, WarOrderKind } from '@/lib/schemas/reforger';
import { cn } from '@/lib/utils';
import { Crosshair, Layers, Maximize, Radio } from 'lucide-react';
import { LngLat, Map as MapLibreMap, type GeoJSONSource, type MapMouseEvent } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import type { WarFeedLight } from './lib/use-war-live';
import { bearing, distance, formatDistance, gridRef } from './map-geometry';
import {
    contactData,
    fireData,
    fogData,
    gridData,
    locationData,
    objectiveData,
    orderData,
    pinData,
    placeData,
    ringData,
    seenCells,
    soldierData,
    squadData,
    supportData,
    taskData,
    trailData,
    vehicleData,
    zoneData,
} from './map/data';
import { diffFlashes, flashData, liveFlashes, type MapFlash } from './map/flashes';
import { GlidingSource } from './map/motion';
import { metresPerPixel, toLngLat, toMetres, WORLD } from './map/projection';
import { GROUND_LAYERS, groundPaint, kolguyevStyle, KSRC, LAYER_GROUPS, warLayers, type Ground, type LayerGroup } from './map/style';
import { provideSymbols } from './map/symbols';

const GROUP_LABEL: Record<LayerGroup, string> = {
    events: 'What just happened',
    fog: 'Fog of war',
    units: 'Units',
    contacts: 'Enemy contacts',
    orders: 'Orders and support',
    tasks: 'HQ tasks',
    objectives: 'HQ objectives',
    territory: 'Territory',
    bases: 'Bases and sites',
    locations: 'Place names',
    grid: 'Grid',
};

type MapOrderTarget = { x: number; z: number };

const GROUND_KEY = 'kolguyev-map-ground';

const NO_AIM: readonly string[] = [];

/** The island with its shores: what "whole island" frames. */
const ISLAND: [[number, number], [number, number]] = [toLngLat(700, 1000), toLngLat(12900, 12200)];

const LIGHT: Record<Exclude<WarFeedLight, 'off'>, { label: string; tone: string; hint: string }> = {
    game: { label: 'Live', tone: 'text-map-success', hint: 'The game server is reporting: every bridge tick lands here.' },
    stale: {
        label: 'No feed',
        tone: 'text-muted-foreground',
        hint: 'The game server has not reported for 30 s (offline, or the war mod is not running). Everyone is shown where they were last seen.',
    },
    linking: { label: 'Linking', tone: 'text-muted-foreground', hint: 'Connecting to the live feed.' },
    lost: { label: 'Link lost', tone: 'text-destructive', hint: 'The live feed dropped; reconnecting.' },
};

/**
 * Kolguyev on MapLibre: the game's own terrain tiles and topo vectors (roads, buildings),
 * then everything your side knows. Click a squad or soldier to pick it, open ground to mark.
 */
export function KolguyevMap({
    war,
    selectedSquadId,
    selectedSoldierId,
    marks,
    orderKind,
    markLabel = '',
    aim = NO_AIM,
    canPlace,
    onPlace,
    onClearPin,
    onSelectSquad,
    onSelectSoldier,
    onSelectVehicle,
    pickedVehicleId = null,
    focus = null,
    light = 'off',
    fire = null,
    children,
}: {
    war: WarCommand;
    selectedSquadId: number | null;
    selectedSoldierId: number | null;
    /** The order being drawn up: its mark, and a patrol's other end. */
    marks: { a: MapOrderTarget | null; b: MapOrderTarget | null; legs?: MapOrderTarget[] };
    orderKind: WarOrderKind;
    markLabel?: string;
    /** Place kinds to ring: what the operation being aimed can be aimed at. */
    aim?: readonly string[];
    canPlace: boolean;
    onPlace: (x: number, z: number) => void;
    onClearPin: () => void;
    onSelectSquad: (squadId: number) => void;
    onSelectSoldier: (squadId: number, soldierId: number) => void;
    /** One of your vehicles tapped, and where it is. */
    onSelectVehicle?: (vehicleId: number, x: number, z: number) => void;
    pickedVehicleId?: number | null;
    focus?: { x: number; z: number; key: number } | null;
    light?: WarFeedLight;
    /** The HQ fire mission being aimed: its danger ring follows the pointer. */
    fire?: string | null;
    /** The order console over the bottom of the map (phones). */
    children?: ReactNode;
}) {
    const container = useRef<HTMLDivElement>(null);
    const mapRef = useRef<MapLibreMap | null>(null);
    const gliders = useRef<{ squads: GlidingSource; soldiers: GlidingSource } | null>(null);
    const [ready, setReady] = useState(false);
    const [zoom, setZoom] = useState(0);
    const [cursor, setCursor] = useState<{ x: number; z: number } | null>(null);
    const [hidden, setHidden] = useState<Set<LayerGroup>>(new Set());
    const [ground, setGround] = useState<Ground>(() => {
        try {
            return window.localStorage.getItem(GROUND_KEY) === 'satellite' ? 'satellite' : 'map';
        } catch {
            return 'map';
        }
    });
    const handlers = useRef({
        canPlace,
        onPlace,
        onClearPin,
        onSelectSquad,
        onSelectSoldier,
        onSelectVehicle,
    });
    handlers.current = {
        canPlace,
        onPlace,
        onClearPin,
        onSelectSquad,
        onSelectSoldier,
        onSelectVehicle,
    };
    const selected = war.squads.find((s) => s.id === selectedSquadId) ?? null;
    // Only while HQ fire is being aimed does the pointer move its danger ring.
    const aimCursor = fire ? cursor : null;
    const overlay = useRef<HTMLDivElement>(null);
    const moved = useRef(false);

    /** The whole island, in the part of the map the order console does not cover. */
    function fitIsland(duration: number) {
        const map = mapRef.current;
        if (!map) {
            return;
        }
        // Never more than half the map: a short map still shows the island, under the console if need be.
        const covered = Math.min(overlay.current?.offsetHeight ?? 0, map.getContainer().clientHeight / 2);
        map.fitBounds(ISLAND, { padding: { top: 44, left: 12, right: 12, bottom: covered + 12 }, duration });
    }

    useEffect(() => {
        if (!container.current) {
            return;
        }
        // MapLibre 6 loads its worker from a URL; the built site must hand it the one Vite emitted.
        installMapLibreWorker();
        const map = new MapLibreMap({
            container: container.current,
            style: kolguyevStyle(),
            center: toLngLat(WORLD / 2, WORLD / 2),
            zoom: 0.5,
            // Below 0: Kolguyev fills the whole Mercator square (512 px at z0), so a phone needs
            // a negative zoom to see all of it.
            minZoom: -2,
            maxZoom: 8,
            // No maxBounds: the island fills the whole Mercator square anyway, and a bounds clamp
            // on a zero-size container (hidden tab, first layout) yields a singular matrix and throws.
            renderWorldCopies: false,
            // The default constraint keeps the world square filling the viewport's height, so a
            // tall, narrow phone map could never zoom out to the whole island. Keep only the
            // centre on the world.
            transformConstrain: (lngLat, zoom) => ({
                center: new LngLat(Math.max(-180, Math.min(180, lngLat.lng)), Math.max(-85.05, Math.min(85.05, lngLat.lat))),
                zoom,
            }),
            attributionControl: false,
            dragRotate: false,
            pitchWithRotate: false,
            touchPitch: false,
        });
        map.touchZoomRotate.disableRotation();
        map.keyboard.disableRotation();
        const unprovide = provideSymbols(map);
        mapRef.current = map;

        map.on('load', () => {
            map.addSource(KSRC.grid, { type: 'geojson', data: gridData() });
            map.addSource(KSRC.locations, { type: 'geojson', data: locationData() });
            for (const id of [
                KSRC.rings,
                KSRC.fog,
                KSRC.flashes,
                KSRC.trails,
                KSRC.fire,
                KSRC.zones,
                KSRC.places,
                KSRC.objectives,
                KSRC.tasks,
                KSRC.taskLines,
                KSRC.orders,
                KSRC.targets,
                KSRC.support,
                KSRC.contacts,
                KSRC.vehicles,
                KSRC.squads,
                KSRC.soldiers,
                KSRC.pin,
            ]) {
                map.addSource(id, { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            }
            for (const layer of warLayers()) {
                map.addLayer(layer);
            }
            gliders.current = { squads: new GlidingSource(map, KSRC.squads), soldiers: new GlidingSource(map, KSRC.soldiers) };
            fitIsland(0);
            setZoom(map.getZoom());
            setReady(true);
        });
        map.on('zoom', () => setZoom(map.getZoom()));
        // Until the commander moves the map, keep the whole island in view as the container
        // settles (first layout, phone rotation, the side column appearing, the console folding).
        const touched = (event: { originalEvent?: unknown }) => {
            if (event.originalEvent) {
                moved.current = true;
            }
        };
        map.on('dragstart', touched);
        map.on('zoomstart', touched);
        map.on('resize', () => {
            if (!moved.current) {
                fitIsland(0);
            }
        });
        const settle = new ResizeObserver(() => {
            if (!moved.current && map.loaded()) {
                fitIsland(0);
            }
        });
        if (overlay.current) {
            settle.observe(overlay.current);
        }

        // Nothing on the map sends an order: a click (a tap) marks or picks, a right click and a
        // long press do nothing at all; orders and fire go from the panel's buttons.
        map.on('contextmenu', (event) => event.preventDefault());
        let fingerAt = 0;
        map.on('touchstart', () => {
            fingerAt = Date.now();
        });

        // Nothing on the map moves: a mark moves by clicking elsewhere, and an order under way
        // changes only by sending a new one.
        const pick = (event: MapMouseEvent) => {
            const finger = Date.now() - fingerAt < 1000;
            const hits = map.queryRenderedFeatures(event.point, {
                layers: ['kol-squads', 'kol-soldiers', 'kol-vehicles'].filter((id) => map.getLayer(id)),
            });
            const hit = hits[0];
            if (hit?.layer.id === 'kol-vehicles' && handlers.current.onSelectVehicle) {
                const m = toMetres(event.lngLat.lng, event.lngLat.lat);
                handlers.current.onSelectVehicle(Number(hit.properties.id), clamp(m.x), clamp(m.z));

                return;
            }
            if (hit?.layer.id === 'kol-squads' && hit.properties.commandable) {
                handlers.current.onSelectSquad(Number(hit.properties.id));

                return;
            }
            // Allies' and HQ's men are shown, not commanded.
            // A finger picks the man's squad: his own card is a deliberate click (or the roster).
            if (hit?.layer.id === 'kol-soldiers' && hit.properties.commandable) {
                if (finger) {
                    handlers.current.onSelectSquad(Number(hit.properties.squad_id));
                } else {
                    handlers.current.onSelectSoldier(Number(hit.properties.squad_id), Number(hit.properties.id));
                }

                return;
            }
            if (handlers.current.canPlace) {
                const m = toMetres(event.lngLat.lng, event.lngLat.lat);
                handlers.current.onPlace(clamp(m.x), clamp(m.z));
            }
        };
        map.on('click', pick);
        map.on('mousemove', (event) => {
            const m = toMetres(event.lngLat.lng, event.lngLat.lat);
            setCursor({ x: clamp(m.x), z: clamp(m.z) });
            const over =
                map.queryRenderedFeatures(event.point, {
                    layers: ['kol-squads', 'kol-soldiers', 'kol-vehicles'].filter((id) => map.getLayer(id)),
                }).length > 0;
            map.getCanvas().style.cursor = over ? 'pointer' : handlers.current.canPlace ? 'crosshair' : '';
        });
        map.getCanvasContainer().addEventListener('mouseleave', () => setCursor(null));
        map.getContainer().addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                handlers.current.onClearPin();
            }
        });

        return () => {
            gliders.current?.squads.stop();
            gliders.current?.soldiers.stop();
            gliders.current = null;
            settle.disconnect();
            unprovide();
            map.remove();
            mapRef.current = null;
        };
    }, []);

    // War data → sources.
    useEffect(() => {
        const map = mapRef.current;
        if (!map || !ready) {
            return;
        }
        const set = (id: string, data: Parameters<GeoJSONSource['setData']>[0]) => (map.getSource(id) as GeoJSONSource | undefined)?.setData(data);
        const orders = orderData(war, selectedSquadId);
        set(KSRC.places, placeData(war, aim));
        set(KSRC.objectives, objectiveData(war));
        set(KSRC.orders, orders.lines);
        set(KSRC.targets, orders.targets);
        set(KSRC.support, supportData(war));
        set(KSRC.contacts, contactData(war));
        set(KSRC.trails, trailData(war));
        set(KSRC.fire, fireData(war));
        set(KSRC.vehicles, vehicleData(war, pickedVehicleId));
        const tasks = taskData(war);
        set(KSRC.tasks, tasks.points);
        set(KSRC.taskLines, tasks.lines);
        // People glide to where the game saw them last, rather than jump every tick.
        gliders.current?.squads.set(squadData(war, selectedSquadId));
        gliders.current?.soldiers.set(soldierData(war, selectedSquadId, selectedSoldierId));
    }, [ready, war, selectedSquadId, selectedSoldierId, aim, pickedVehicleId]);

    // HQ fire lands where it is aimed: no squad walks there.
    const from = selected && marks.a && !fire ? { x: selected.x, z: selected.z } : null;
    // What just happened, flashed where it happened: a diff of each new desk against the last.
    const previous = useRef<WarCommand | null>(null);
    const flashes = useRef<MapFlash[]>([]);
    useEffect(() => {
        const now = Date.now();
        flashes.current = [...liveFlashes(flashes.current, now), ...diffFlashes(previous.current, war, now)];
        previous.current = war;
    }, [war]);
    useEffect(() => {
        if (!ready) {
            return;
        }
        const tick = window.setInterval(() => {
            const now = Date.now();
            flashes.current = liveFlashes(flashes.current, now);
            (mapRef.current?.getSource(KSRC.flashes) as GeoJSONSource | undefined)?.setData(flashData(flashes.current, now));
        }, 1000);

        return () => window.clearInterval(tick);
    }, [ready]);

    // Fog of war, with what the page remembers seeing lately.
    const sightMemory = useRef(new Map<number, number>());
    useEffect(() => {
        const map = mapRef.current;
        if (!map || !ready) {
            return;
        }
        const now = Date.now();
        const seen = seenCells(war, sightMemory.current, now);
        (map.getSource(KSRC.fog) as GeoJSONSource | undefined)?.setData(fogData(seen, sightMemory.current, now));
    }, [ready, war]);

    // Reach and danger rings: the draft order's, and a fire mission's at the spot being looked at.
    useEffect(() => {
        const map = mapRef.current;
        if (!map || !ready) {
            return;
        }
        const spot = fire ? (marks.a ?? aimCursor) : null;
        (map.getSource(KSRC.rings) as GeoJSONSource | undefined)?.setData(
            ringData(
                war,
                { kind: orderKind, a: fire ? null : marks.a, squad: selected ? { x: selected.x, z: selected.z } : null },
                spot && fire ? { ...spot, id: fire } : null,
            ),
        );
    }, [ready, war, marks, orderKind, fire, aimCursor, selected]);
    useEffect(() => {
        const map = mapRef.current;
        if (map && ready) {
            (map.getSource(KSRC.zones) as GeoJSONSource | undefined)?.setData(zoneData(war));
        }
    }, [ready, war.places, war.capture_radius]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => {
        const source = mapRef.current?.getSource(KSRC.pin) as GeoJSONSource | undefined;
        if (ready) {
            source?.setData(
                pinData({
                    from,
                    a: marks.a,
                    b: marks.b,
                    legs: marks.legs,
                    kind: fire ? 'suppress' : orderKind,
                    label: markLabel,
                    driven: selected ? war.markers.some((m) => m.squad_id === selected.id && m.in_vehicle) : false,
                }),
            );
        }
    }, [ready, marks, orderKind, fire, markLabel, from?.x, from?.z]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        const map = mapRef.current;
        if (!map || !ready) {
            return;
        }
        for (const [group, layers] of Object.entries(LAYER_GROUPS) as Array<[LayerGroup, readonly string[]]>) {
            for (const id of layers) {
                if (map.getLayer(id)) {
                    map.setLayoutProperty(id, 'visibility', hidden.has(group) ? 'none' : 'visible');
                }
            }
        }
    }, [ready, hidden]);

    useEffect(() => {
        const map = mapRef.current;
        if (!map || !ready) {
            return;
        }
        for (const [kind, layers] of Object.entries(GROUND_LAYERS) as Array<[Ground, readonly string[]]>) {
            for (const id of layers) {
                if (map.getLayer(id)) {
                    map.setLayoutProperty(id, 'visibility', kind === ground ? 'visible' : 'none');
                }
            }
        }
        for (const { id, property, value } of groundPaint(ground)) {
            if (map.getLayer(id)) {
                map.setPaintProperty(id, property, value);
            }
        }
        try {
            window.localStorage.setItem(GROUND_KEY, ground);
        } catch {
            // Private mode: the choice lasts for this visit.
        }
    }, [ready, ground]);

    useEffect(() => {
        if (focus && mapRef.current) {
            mapRef.current.flyTo({ center: toLngLat(focus.x, focus.z), zoom: Math.max(mapRef.current.getZoom(), 3.5), duration: 600 });
        }
    }, [focus]);

    const scale = useMemo(() => scaleBar(zoom), [zoom]);
    const fromSquad =
        cursor && selected
            ? { m: distance(selected.x, selected.z, cursor.x, cursor.z), deg: bearing(selected.x, selected.z, cursor.x, cursor.z) }
            : null;

    return (
        <div className="flex h-full flex-col">
            <div
                ref={container}
                className="relative h-full min-h-96 w-full overflow-hidden bg-[#9fc3da]"
                tabIndex={0}
                aria-label="Kolguyev map, north up"
            >
                <div className="absolute top-2 left-2 z-10 flex gap-1">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button type="button" size="icon" variant="secondary" className="size-8 shadow" aria-label="Map layers">
                                <Layers />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuLabel>Ground</DropdownMenuLabel>
                            <DropdownMenuRadioGroup value={ground} onValueChange={(value) => setGround(value === 'satellite' ? 'satellite' : 'map')}>
                                <DropdownMenuRadioItem value="map" onSelect={(event) => event.preventDefault()}>
                                    Map
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="satellite" onSelect={(event) => event.preventDefault()}>
                                    From above
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel>Layers</DropdownMenuLabel>
                            {(Object.keys(LAYER_GROUPS) as LayerGroup[]).map((group) => (
                                <DropdownMenuCheckboxItem
                                    key={group}
                                    checked={!hidden.has(group)}
                                    onSelect={(event) => event.preventDefault()}
                                    onCheckedChange={(on) =>
                                        setHidden((current) => {
                                            const next = new Set(current);
                                            if (on) {
                                                next.delete(group);
                                            } else {
                                                next.add(group);
                                            }

                                            return next;
                                        })
                                    }
                                >
                                    {GROUP_LABEL[group]}
                                </DropdownMenuCheckboxItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Button
                        type="button"
                        size="icon"
                        variant="secondary"
                        className="size-8 shadow"
                        aria-label="Whole island"
                        onClick={() => fitIsland(600)}
                    >
                        <Maximize />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="secondary"
                        className="size-8 shadow"
                        aria-label="Centre on the squad"
                        disabled={!selected}
                        onClick={() =>
                            selected &&
                            mapRef.current?.flyTo({
                                center: toLngLat(selected.x, selected.z),
                                zoom: Math.max(mapRef.current.getZoom(), 4),
                                duration: 500,
                            })
                        }
                    >
                        <Crosshair />
                    </Button>
                </div>

                {/* Top right: the feed light, the scale, and where the pointer is. */}
                <div className="pointer-events-none absolute top-2 right-2 z-10 flex flex-col items-end gap-1">
                    {light !== 'off' ? (
                        <div
                            className={cn(
                                'flex items-center gap-1 rounded-sm bg-card/90 px-1.5 py-0.5 text-xs font-semibold tracking-wider uppercase shadow-sm',
                                LIGHT[light].tone,
                            )}
                            title={LIGHT[light].hint}
                        >
                            <Radio className={cn('size-3', light === 'game' && 'animate-pulse')} /> {LIGHT[light].label}
                        </div>
                    ) : null}
                    <div className="flex flex-col items-end rounded-sm bg-card/90 px-1.5 pt-0.5 text-xs shadow-sm">
                        <span>{formatDistance(scale.metres)}</span>
                        <span className="block h-1 border-x border-b border-foreground/70" style={{ width: `${scale.px}px` }} />
                    </div>
                    {cursor ? (
                        <div className="rounded-sm bg-card/90 px-1.5 py-0.5 font-mono text-xs shadow-sm max-sm:hidden">
                            {gridRef(cursor.x, cursor.z)}
                            {fromSquad ? (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {formatDistance(fromSquad.m)} · {fromSquad.deg}°
                                </span>
                            ) : null}
                        </div>
                    ) : null}
                </div>

                <div ref={overlay} className="pointer-events-none absolute inset-x-2 bottom-2 z-10 mx-auto max-w-2xl lg:max-w-4xl">
                    {children}
                </div>
            </div>
        </div>
    );
}

function clamp(value: number): number {
    return Math.min(WORLD, Math.max(0, Math.round(value)));
}

/** A round distance that fills about 90 px. Exact: the projection is linear in metres. */
function scaleBar(zoom: number): { metres: number; px: number } {
    const mpp = metresPerPixel(zoom);
    const steps = [10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 2500, 5000];
    const metres = steps.reduce((best, step) => (Math.abs(step / mpp - 90) < Math.abs(best / mpp - 90) ? step : best), steps[0]);

    return { metres, px: metres / mpp };
}
