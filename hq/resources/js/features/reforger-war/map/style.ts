import type { ExpressionSpecification, FilterSpecification, LayerSpecification, StyleSpecification } from 'maplibre-gl';
import { BUILDINGS_URL, LANDCOVER_URL, ROADS_URL, SATELLITE_MAXZOOM, SATELLITE_URL, TERRAIN_URL, TILE_MAXZOOM, TILE_URL } from './projection';

/** Source ids. Game data sources are static; war sources are refreshed from page props. */
export const KSRC = {
    base: 'kol-base',
    satellite: 'kol-satellite',
    roads: 'kol-roads',
    buildings: 'kol-buildings',
    terrain: 'kol-terrain',
    landcover: 'kol-landcover',
    grid: 'kol-grid',
    locations: 'kol-locations',
    places: 'kol-places',
    objectives: 'kol-objectives',
    orders: 'kol-orders',
    targets: 'kol-targets',
    contacts: 'kol-contacts',
    squads: 'kol-squads',
    soldiers: 'kol-soldiers',
    vehicles: 'kol-vehicles',
    support: 'kol-support',
    tasks: 'kol-tasks',
    taskLines: 'kol-task-lines',
    pin: 'kol-pin',
    rings: 'kol-rings',
    fog: 'kol-fog',
    flashes: 'kol-flashes',
    trails: 'kol-trails',
    fire: 'kol-fire',
    zones: 'kol-zones',
} as const;

/** Layer groups the layer chips switch on and off. */
export const LAYER_GROUPS = {
    fog: ['kol-fog'],
    grid: ['kol-grid-minor', 'kol-grid-major'],
    locations: ['kol-locations-0', 'kol-locations-1', 'kol-locations-2', 'kol-locations-3'],
    bases: ['kol-places'],
    territory: ['kol-territory', 'kol-zones'],
    objectives: ['kol-objectives'],
    orders: ['kol-orders', 'kol-orders-eta', 'kol-targets', 'kol-support'],
    tasks: ['kol-task-lines', 'kol-tasks'],
    contacts: ['kol-trails', 'kol-fire', 'kol-fire-end', 'kol-contact-look', 'kol-contacts', 'kol-contacts-age'],
    events: ['kol-flash-dot', 'kol-flash-text'],
    units: ['kol-soldier-look', 'kol-squads', 'kol-soldiers', 'kol-soldier-roles', 'kol-soldier-names', 'kol-soldier-names-all', 'kol-vehicles'],
} as const;
export type LayerGroup = keyof typeof LAYER_GROUPS;

const FONT = ['Noto Sans Regular'];
const FONT_BOLD = ['Noto Sans Bold'];

/** Width in metres to pixels at the current zoom: 12800 m span 512 * 2^zoom px. */
const metres = (minPx: number): ExpressionSpecification => [
    'interpolate',
    ['exponential', 2],
    ['zoom'],
    0,
    ['max', minPx, ['*', ['get', 'w'], 0.04]],
    9,
    ['max', minPx, ['*', ['get', 'w'], 20.48]],
];

/** Road classes as the game's topo data numbers them (see scripts/reforger/build-kolguyev-map.py). */
const ROAD_CLASSES: Array<{ c: number; colour: string; casing: string | null; minzoom: number; minPx: number; dash?: number[] }> = [
    { c: 5, colour: '#9c8466', casing: null, minzoom: 1.6, minPx: 0.7, dash: [2, 1.5] }, // dirt track
    { c: 3, colour: '#c8a372', casing: null, minzoom: 0.8, minPx: 0.8 }, // gravel road
    { c: 2, colour: '#f1c25a', casing: '#a07a2c', minzoom: 0, minPx: 1.2 }, // asphalt road
    { c: 1, colour: '#e48a34', casing: '#8a4a12', minzoom: 0, minPx: 1.6 }, // main road
    { c: 0, colour: '#a8a399', casing: '#6f6a60', minzoom: 0, minPx: 1.5 }, // runways, taxiways, aprons
];

const BUILDING_OPACITY: ExpressionSpecification = ['interpolate', ['linear'], ['zoom'], 2.6, 0.4, 4, 0.9];

function roadLayers(): LayerSpecification[] {
    const layers: LayerSpecification[] = [];
    for (const road of ROAD_CLASSES) {
        if (road.casing) {
            layers.push({
                id: `kol-road-${road.c}-casing`,
                type: 'line',
                source: KSRC.roads,
                ...(road.minzoom > 0 ? { minzoom: road.minzoom } : {}),
                filter: ['==', ['get', 'c'], road.c],
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: { 'line-color': road.casing, 'line-width': metres(road.minPx + 1), 'line-gap-width': 0 },
            });
        }
        layers.push({
            id: `kol-road-${road.c}`,
            type: 'line',
            source: KSRC.roads,
            minzoom: road.minzoom,
            filter: ['==', ['get', 'c'], road.c],
            layout: { 'line-cap': road.dash ? 'butt' : 'round', 'line-join': 'round' },
            paint: { 'line-color': road.colour, 'line-width': metres(road.minPx), ...(road.dash ? { 'line-dasharray': road.dash } : {}) },
        });
    }

    return layers;
}

/** The ground under everything: the schematic map, or the island from above. */
export type Ground = 'map' | 'satellite';

/** Schematic fills and lines the picture from above already shows; they go when it is on. */
export const GROUND_LAYERS: Record<Ground, readonly string[]> = {
    map: ['kol-base', 'kol-forest', 'kol-forest-edge', 'kol-water', 'kol-stream', 'kol-contour', 'kol-contour-major'],
    satellite: ['kol-satellite'],
};

/**
 * Roads and roofs are in the picture from above: over it their lines go faint (they only mark
 * where they are), on the map they are drawn in full.
 */
export function groundPaint(
    ground: Ground,
): Array<{ id: string; property: 'line-opacity' | 'fill-opacity'; value: number | ExpressionSpecification }> {
    const above = ground === 'satellite';
    // Runways and aprons are plain to see from above; a road keeps a faint line, no casing.
    const roads = ROAD_CLASSES.flatMap((road) => [
        { id: `kol-road-${road.c}`, faint: road.c === 0 ? 0 : 0.3 },
        ...(road.casing ? [{ id: `kol-road-${road.c}-casing`, faint: 0 }] : []),
    ]);

    return [
        ...roads.map(({ id, faint }) => ({ id, property: 'line-opacity' as const, value: above ? faint : 1 })),
        { id: 'kol-buildings', property: 'fill-opacity', value: above ? 0.12 : BUILDING_OPACITY },
    ];
}

const LOCATION_SIZE: ExpressionSpecification = ['match', ['get', 'kind'], 'city', 15, 'town', 13, 'village', 12, 'settlement', 11, 10];
/** Label rank by kind; data.ts writes it onto each location feature. */
export const LOCATION_RANK: Record<string, number> = { city: 0, town: 1, village: 2, ridge: 3, hill: 3, settlement: 4, island: 4 };

export function kolguyevStyle(): StyleSpecification {
    return {
        version: 8,
        glyphs: '/fonts/{fontstack}/{range}.pbf',
        sources: {
            [KSRC.base]: {
                type: 'raster',
                tiles: [TILE_URL],
                tileSize: 256,
                minzoom: 0,
                maxzoom: TILE_MAXZOOM,
                bounds: [-180, -85.0511, 180, 85.0511],
            },
            [KSRC.satellite]: {
                type: 'raster',
                tiles: [SATELLITE_URL],
                tileSize: 256,
                minzoom: 0,
                maxzoom: SATELLITE_MAXZOOM,
                bounds: [-180, -85.0511, 180, 85.0511],
            },
            [KSRC.roads]: { type: 'geojson', data: ROADS_URL, tolerance: 0.2 },
            [KSRC.buildings]: { type: 'geojson', data: BUILDINGS_URL, tolerance: 0.2 },
            [KSRC.terrain]: { type: 'geojson', data: TERRAIN_URL, tolerance: 0.3 },
            [KSRC.landcover]: { type: 'geojson', data: LANDCOVER_URL, tolerance: 0.3 },
        },
        // The raster is only the soft ground (land, sea depth, hillshade); every line is a vector,
        // so contours, coast, woods, water and roads stay sharp however far you zoom in.
        layers: [
            { id: 'kol-paper', type: 'background', paint: { 'background-color': '#c4dae4' } },
            { id: 'kol-base', type: 'raster', source: KSRC.base, paint: { 'raster-fade-duration': 0, 'raster-resampling': 'linear' } },
            {
                id: 'kol-satellite',
                type: 'raster',
                source: KSRC.satellite,
                layout: { visibility: 'none' },
                paint: { 'raster-fade-duration': 0, 'raster-resampling': 'linear' },
            },
            {
                id: 'kol-forest',
                type: 'fill',
                source: KSRC.landcover,
                filter: ['==', ['get', 'k'], 'forest'],
                paint: { 'fill-color': '#b9d3a0', 'fill-opacity': ['interpolate', ['linear'], ['zoom'], 0, 0.35, 3, 0.75] },
            },
            {
                id: 'kol-forest-edge',
                type: 'line',
                source: KSRC.landcover,
                minzoom: 3.5,
                filter: ['==', ['get', 'k'], 'forest'],
                paint: { 'line-color': '#8fb27a', 'line-width': 0.8 },
            },
            { id: 'kol-water', type: 'fill', source: KSRC.landcover, filter: ['==', ['get', 'k'], 'water'], paint: { 'fill-color': '#8dbbdc' } },
            {
                id: 'kol-stream',
                type: 'line',
                source: KSRC.landcover,
                filter: ['==', ['get', 'k'], 'stream'],
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: { 'line-color': '#78acd4', 'line-width': ['interpolate', ['exponential', 2], ['zoom'], 1, 0.8, 8, 6] },
            },
            {
                id: 'kol-contour',
                type: 'line',
                source: KSRC.terrain,
                minzoom: 2,
                filter: ['all', ['==', ['get', 'k'], 'contour'], ['!', ['get', 'major']]],
                paint: { 'line-color': '#b39b72', 'line-opacity': ['interpolate', ['linear'], ['zoom'], 2, 0.2, 4, 0.5], 'line-width': 0.6 },
            },
            {
                id: 'kol-contour-major',
                type: 'line',
                source: KSRC.terrain,
                filter: ['all', ['==', ['get', 'k'], 'contour'], ['get', 'major']],
                paint: {
                    'line-color': '#9a7f52',
                    'line-opacity': ['interpolate', ['linear'], ['zoom'], 0, 0.35, 4, 0.7],
                    'line-width': ['interpolate', ['linear'], ['zoom'], 0, 0.6, 6, 1.2],
                },
            },
            {
                id: 'kol-coast',
                type: 'line',
                source: KSRC.terrain,
                filter: ['==', ['get', 'k'], 'coast'],
                paint: { 'line-color': '#5f8ba6', 'line-width': ['interpolate', ['linear'], ['zoom'], 0, 0.7, 6, 1.6] },
            },
            ...roadLayers(),
            {
                id: 'kol-buildings',
                type: 'fill',
                source: KSRC.buildings,
                minzoom: 2.6,
                paint: {
                    'fill-color': '#5c5852',
                    'fill-outline-color': '#2f2c28',
                    'fill-opacity': BUILDING_OPACITY,
                },
            },
            {
                id: 'kol-contour-label',
                type: 'symbol',
                source: KSRC.terrain,
                minzoom: 4,
                filter: ['all', ['==', ['get', 'k'], 'contour'], ['get', 'major']],
                layout: {
                    'symbol-placement': 'line',
                    'symbol-spacing': 360,
                    'text-field': ['to-string', ['get', 'e']],
                    'text-font': FONT,
                    'text-size': 10,
                },
                paint: { 'text-color': '#8a6f42', 'text-halo-color': 'rgba(238,235,224,0.9)', 'text-halo-width': 1.2 },
            },
        ],
    };
}

/** Map labels from the game, by rank: towns first, landmarks only close in. */
function locationLayers(): LayerSpecification[] {
    const bands: Array<[number, number, number]> = [
        [0, 1, 0],
        [2, 3, 0.9],
        [4, 4, 1.6],
        [5, 9, 2.4],
    ];

    return bands.map(
        ([from, to, minzoom], index) =>
            ({
                id: `kol-locations-${index}`,
                type: 'symbol',
                source: KSRC.locations,
                minzoom,
                filter: ['all', ['>=', ['get', 'rank'], from], ['<=', ['get', 'rank'], to]],
                layout: {
                    'text-field': ['get', 'name'],
                    'text-font': from <= 2 ? FONT_BOLD : FONT,
                    'text-size': LOCATION_SIZE,
                    'text-transform': from <= 1 ? 'uppercase' : 'none',
                    'text-letter-spacing': from <= 1 ? 0.08 : 0.02,
                    'text-allow-overlap': false,
                    // Step off a base or home marker standing on the same spot instead of hiding under it.
                    'text-variable-anchor': ['center', 'top', 'bottom', 'left', 'right'],
                    'text-radial-offset': 1.1,
                },
                paint: {
                    'text-color': ['match', ['get', 'kind'], ['lake', 'bay'], '#2d6a9a', ['hill', 'ridge'], '#6b5a3c', '#26231d'],
                    'text-halo-color': 'rgba(246,243,233,0.9)',
                    'text-halo-width': 1.4,
                },
            }) satisfies LayerSpecification,
    );
}

function soldierLabels(id: string, minzoom: number, filter: FilterSpecification): LayerSpecification {
    return {
        id,
        type: 'symbol',
        source: KSRC.soldiers,
        minzoom,
        filter,
        layout: {
            'text-field': [
                'format',
                ['get', 'name'],
                { 'font-scale': 1 },
                '\n',
                {},
                ['get', 'state'],
                { 'font-scale': 0.85, 'text-color': ['get', 'tone'] },
            ],
            'text-font': FONT,
            'text-size': 10,
            'text-anchor': 'left',
            'text-justify': 'left',
            'text-offset': [0.9, 0],
            'symbol-sort-key': ['case', ['get', 'active'], 0, 1],
        },
        paint: { 'text-color': '#1c1a17', 'text-halo-color': 'rgba(246,243,233,0.95)', 'text-halo-width': 1.3 },
    };
}

/** War layers, added once the style has loaded. Order is bottom to top. */
export function warLayers(): LayerSpecification[] {
    return [
        // Fog of war: ground nobody of your side sees now (darker if not seen lately either).
        {
            id: 'kol-fog',
            type: 'fill',
            source: KSRC.fog,
            paint: { 'fill-color': '#2b2822', 'fill-opacity': ['match', ['get', 'level'], 1, 0.12, 0.24], 'fill-antialias': false },
        },
        {
            id: 'kol-grid-minor',
            type: 'line',
            source: KSRC.grid,
            minzoom: 4.2,
            filter: ['==', ['get', 'major'], false],
            paint: { 'line-color': '#3a362e', 'line-opacity': 0.12, 'line-width': 0.6 },
        },
        {
            id: 'kol-grid-major',
            type: 'line',
            source: KSRC.grid,
            filter: ['==', ['get', 'major'], true],
            paint: { 'line-color': '#3a362e', 'line-opacity': 0.28, 'line-width': 0.8 },
        },
        ...locationLayers(),
        // Every control point's capture zone, faint, closer in.
        {
            id: 'kol-zones',
            type: 'line',
            source: KSRC.zones,
            minzoom: 3.2,
            paint: { 'line-color': '#4a4436', 'line-opacity': 0.35, 'line-width': 1, 'line-dasharray': [2, 3] },
        },
        // While an operation is being aimed: a ring on every place it can be aimed at.
        {
            id: 'kol-aim',
            type: 'circle',
            source: KSRC.places,
            filter: ['==', ['get', 'aim'], true],
            paint: { 'circle-radius': 15, 'circle-color': 'rgba(196,138,18,0.14)', 'circle-stroke-color': '#c48a12', 'circle-stroke-width': 1.6 },
        },
        {
            id: 'kol-places',
            type: 'symbol',
            source: KSRC.places,
            // Centred on the point: the symbol's middle is the place's game coordinates.
            layout: {
                'icon-image': ['get', 'icon'],
                'icon-anchor': 'center',
                'icon-allow-overlap': true,
                'text-field': ['step', ['zoom'], '', 1.4, ['get', 'name']],
                'text-font': FONT,
                'text-size': 10,
                'text-offset': [0, 1.1],
                'text-anchor': 'top',
                'text-optional': true,
            },
            paint: {
                'text-color': '#3b372f',
                'text-halo-color': 'rgba(246,243,233,0.9)',
                'text-halo-width': 1.2,
                'icon-opacity': ['case', ['get', 'cut'], 0.45, 1],
            },
        },
        {
            id: 'kol-territory',
            type: 'symbol',
            source: KSRC.places,
            filter: ['!=', ['get', 'income'], null],
            minzoom: 1.2,
            layout: {
                'text-field': ['concat', '+', ['to-string', ['get', 'income']], '/h'],
                'text-font': FONT_BOLD,
                'text-size': 9,
                'text-anchor': 'left',
                'text-offset': [0.9, 0],
                'text-allow-overlap': true,
            },
            paint: {
                'text-color': '#4a4436',
                'text-halo-color': 'rgba(246,243,233,0.9)',
                'text-halo-width': 1,
                'text-opacity': ['case', ['get', 'cut'], 0.5, 1],
            },
        },
        {
            id: 'kol-task-lines',
            type: 'line',
            source: KSRC.taskLines,
            layout: { 'line-cap': 'round' },
            paint: { 'line-color': ['get', 'colour'], 'line-width': 2, 'line-opacity': 0.5, 'line-dasharray': [0.5, 2] },
        },
        {
            id: 'kol-tasks',
            type: 'symbol',
            source: KSRC.tasks,
            layout: {
                'icon-image': ['match', ['get', 'kind'], 'hold', 'target:defend', 'mark:move'],
                'icon-size': 0.8,
                'icon-allow-overlap': true,
                'text-field': ['get', 'label'],
                'text-font': FONT_BOLD,
                'text-size': 9,
                'text-anchor': 'top',
                'text-offset': [0, 1.2],
                'text-optional': true,
            },
            paint: { 'icon-opacity': 0.7, 'text-color': ['get', 'colour'], 'text-halo-color': 'rgba(246,243,233,0.9)', 'text-halo-width': 1.2 },
        },
        {
            id: 'kol-orders',
            type: 'line',
            source: KSRC.orders,
            layout: { 'line-cap': 'round' },
            // Sent (the game has not picked it up yet): thin and pale; under way: the squad's colour.
            paint: {
                'line-color': ['match', ['get', 'status'], 'failed', '#b8332a', ['get', 'colour']],
                'line-width': ['case', ['get', 'soldier'], 1.2, ['==', ['get', 'status'], 'pending'], 1.2, ['get', 'selected'], 2.4, 1.6],
                'line-opacity': ['match', ['get', 'status'], 'pending', 0.45, 'queued', 0.4, 'done', 0.35, ['case', ['get', 'selected'], 0.95, 0.65]],
                'line-dasharray': [
                    'case',
                    ['get', 'soldier'],
                    ['literal', [0.5, 1.8]],
                    ['==', ['get', 'status'], 'pending'],
                    ['literal', [0.5, 1.5]],
                    // Waiting in the chain: what comes after the order under way.
                    ['==', ['get', 'status'], 'queued'],
                    ['literal', [1, 2.5]],
                    ['literal', [2, 1.5]],
                ],
            },
        },
        {
            id: 'kol-targets',
            type: 'symbol',
            source: KSRC.targets,
            layout: {
                'icon-image': ['get', 'icon'],
                'icon-size': ['case', ['get', 'soldier'], 0.75, 1],
                'icon-allow-overlap': true,
                // Who goes there and why, under the mark.
                'text-field': ['get', 'label'],
                'text-font': FONT_BOLD,
                'text-size': ['case', ['get', 'soldier'], 9, 10],
                'text-anchor': 'top',
                'text-offset': [0, 1.1],
                'text-optional': true,
            },
            paint: {
                'text-color': [
                    'match',
                    ['get', 'status'],
                    'failed',
                    '#b8332a',
                    'done',
                    '#2f6b33',
                    'pending',
                    '#6b6559',
                    'queued',
                    '#6b6559',
                    '#1c1a17',
                ],
                'text-halo-color': 'rgba(246,243,233,0.95)',
                'text-halo-width': 1.3,
                'text-opacity': ['case', ['get', 'selected'], 1, 0.8],
                'icon-opacity': ['match', ['get', 'status'], 'done', 0.45, 'pending', 0.6, 'queued', 0.45, 1],
            },
        },
        {
            id: 'kol-support',
            type: 'circle',
            source: KSRC.support,
            paint: { 'circle-radius': 14, 'circle-color': 'rgba(232,177,58,0.12)', 'circle-stroke-color': '#c48a12', 'circle-stroke-width': 1.6 },
        },
        {
            id: 'kol-objectives',
            type: 'symbol',
            source: KSRC.objectives,
            // A badge on the marker's top-right corner: the point itself stays the marker's centre.
            layout: {
                'icon-image': 'star',
                'icon-anchor': 'bottom-left',
                'icon-offset': [3, -3],
                'icon-allow-overlap': true,
                'text-field': ['concat', '+', ['to-string', ['get', 'reward']]],
                'text-font': FONT_BOLD,
                'text-size': 10,
                'text-anchor': 'bottom-left',
                'text-offset': [1.9, -0.7],
                'text-allow-overlap': true,
            },
            paint: { 'text-color': '#6b4a0c', 'text-halo-color': 'rgba(246,243,233,0.95)', 'text-halo-width': 1.2 },
        },
        // Where each enemy went: a fading line through the spots it was seen at.
        // Who is fighting whom: our man to the enemy he is shooting at.
        {
            id: 'kol-fire',
            type: 'line',
            source: KSRC.fire,
            filter: ['==', ['geometry-type'], 'LineString'],
            layout: { 'line-cap': 'round' },
            paint: {
                'line-color': '#d03a2b',
                'line-width': ['interpolate', ['linear'], ['zoom'], 2, 1, 6, 2],
                'line-opacity': 0.85,
                'line-dasharray': [3, 2],
            },
        },
        {
            id: 'kol-fire-end',
            type: 'circle',
            source: KSRC.fire,
            filter: ['==', ['geometry-type'], 'Point'],
            paint: {
                'circle-radius': ['interpolate', ['linear'], ['zoom'], 2, 4, 6, 9],
                'circle-color': 'rgba(208,58,43,0.12)',
                'circle-stroke-color': '#d03a2b',
                'circle-stroke-width': 1.5,
            },
        },
        {
            id: 'kol-trails',
            type: 'line',
            source: KSRC.trails,
            layout: { 'line-cap': 'round' },
            paint: { 'line-color': ['get', 'colour'], 'line-width': 1.6, 'line-opacity': ['get', 'fade'], 'line-dasharray': [1, 2] },
        },
        {
            id: 'kol-contacts-age',
            type: 'symbol',
            source: KSRC.contacts,
            minzoom: 3,
            layout: {
                'text-field': ['get', 'age'],
                'text-font': FONT,
                'text-size': 9,
                'text-anchor': 'top',
                'text-offset': [0, 1],
                'text-optional': true,
            },
            paint: { 'text-color': '#7a2a24', 'text-opacity': ['get', 'fade'], 'text-halo-color': 'rgba(246,243,233,0.9)', 'text-halo-width': 1 },
        },
        // Where an enemy we see is looking.
        {
            id: 'kol-contact-look',
            type: 'symbol',
            source: KSRC.contacts,
            filter: ['>=', ['get', 'heading'], 0],
            layout: {
                'icon-image': 'look:enemy',
                'icon-rotate': ['get', 'heading'],
                'icon-rotation-alignment': 'map',
                'icon-allow-overlap': true,
                'icon-ignore-placement': true,
                'icon-size': ['interpolate', ['linear'], ['zoom'], 3, 0.6, 6, 1.4],
            },
            paint: { 'icon-opacity': ['get', 'fade'] },
        },
        {
            id: 'kol-contacts',
            type: 'symbol',
            source: KSRC.contacts,
            layout: { 'icon-image': ['get', 'icon'], 'icon-allow-overlap': true },
            paint: { 'icon-opacity': ['get', 'fade'] },
        },
        {
            id: 'kol-vehicles',
            type: 'symbol',
            source: KSRC.vehicles,
            layout: {
                'icon-image': ['get', 'icon'],
                // Frame centre on the point; the wheels hang below it.
                'icon-offset': [0, 3],
                'icon-allow-overlap': true,
                'text-field': ['step', ['zoom'], '', 1.8, ['get', 'model']],
                'text-font': FONT,
                'text-size': 9,
                'text-offset': [0, 1.7],
                'text-anchor': 'top',
            },
            paint: { 'text-color': ['get', 'tone'], 'text-halo-color': 'rgba(246,243,233,0.9)', 'text-halo-width': 1.2 },
        },
        {
            id: 'kol-squads',
            type: 'symbol',
            source: KSRC.squads,
            maxzoom: 4.2,
            layout: {
                'icon-image': ['get', 'icon'],
                'icon-allow-overlap': true,
                'symbol-sort-key': ['case', ['get', 'selected'], 1, 0],
                'text-field': ['get', 'label'],
                'text-font': FONT_BOLD,
                'text-size': 10,
                'text-offset': [0, 1.6],
                'text-anchor': 'top',
                'text-allow-overlap': true,
            },
            paint: {
                'text-color': '#1c1a17',
                'text-halo-color': 'rgba(246,243,233,0.95)',
                'text-halo-width': 1.3,
                'icon-opacity': ['case', ['get', 'empty'], 0.4, 1],
                'text-opacity': ['case', ['get', 'empty'], 0.6, 1],
            },
        },
        // Where each of your men is looking.
        {
            id: 'kol-soldier-look',
            type: 'symbol',
            source: KSRC.soldiers,
            minzoom: 4.2,
            filter: ['>=', ['get', 'heading'], 0],
            layout: {
                'icon-image': ['get', 'look'],
                'icon-rotate': ['get', 'heading'],
                'icon-rotation-alignment': 'map',
                'icon-allow-overlap': true,
                'icon-ignore-placement': true,
                'icon-size': ['interpolate', ['linear'], ['zoom'], 4.2, 0.6, 7, 1.5],
            },
            paint: { 'icon-opacity': ['case', ['get', 'selected'], 0.95, 0.6] },
        },
        {
            id: 'kol-soldiers',
            type: 'circle',
            source: KSRC.soldiers,
            minzoom: 4.2,
            paint: {
                'circle-radius': ['case', ['get', 'active'], 7, ['get', 'selected'], 5.5, 4.5],
                'circle-color': ['get', 'fill'],
                'circle-stroke-color': ['get', 'stroke'],
                'circle-stroke-width': ['case', ['get', 'active'], 3, ['get', 'selected'], 2.2, 1.6],
            },
        },
        // What each man does in a fight, over his dot (riflemen carry none).
        {
            id: 'kol-soldier-roles',
            type: 'symbol',
            source: KSRC.soldiers,
            minzoom: 4.8,
            filter: ['!=', ['get', 'role_icon'], ''],
            layout: {
                'icon-image': ['get', 'role_icon'],
                'icon-allow-overlap': true,
                'icon-ignore-placement': true,
                'icon-offset': [7, -7],
                'icon-size': ['interpolate', ['linear'], ['zoom'], 4.8, 0.75, 7, 1.1],
            },
        },
        // Each man's name and how he is, beside him: early for the squad you picked, closer in for
        // everyone else (filters cannot read zoom, hence two layers).
        soldierLabels('kol-soldier-names', 4.2, ['get', 'selected']),
        soldierLabels('kol-soldier-names-all', 5.2, ['!', ['get', 'selected']]),
        // Distance and time along each order under way, and along the one being drawn up.
        {
            id: 'kol-orders-eta',
            type: 'symbol',
            source: KSRC.orders,
            filter: ['!=', ['get', 'eta'], ''],
            layout: {
                'symbol-placement': 'line-center',
                'text-field': ['get', 'eta'],
                'text-font': FONT_BOLD,
                'text-size': 10,
                'text-offset': [0, -0.8],
            },
            paint: { 'text-color': ['get', 'colour'], 'text-halo-color': 'rgba(246,243,233,0.95)', 'text-halo-width': 1.4 },
        },
        {
            id: 'kol-pin-eta',
            type: 'symbol',
            source: KSRC.pin,
            filter: ['==', ['geometry-type'], 'LineString'],
            layout: {
                'symbol-placement': 'line-center',
                'text-field': ['get', 'eta'],
                'text-font': FONT_BOLD,
                'text-size': 10,
                'text-offset': [0, -0.8],
                'text-allow-overlap': true,
            },
            paint: { 'text-color': '#a2530b', 'text-halo-color': 'rgba(246,243,233,0.95)', 'text-halo-width': 1.4 },
        },
        // Reach and danger: what the order or the fire mission being aimed covers.
        {
            id: 'kol-rings-fill',
            type: 'fill',
            source: KSRC.rings,
            paint: { 'fill-color': ['get', 'colour'], 'fill-opacity': ['case', ['get', 'danger'], 0.12, 0.07] },
        },
        {
            id: 'kol-rings',
            type: 'line',
            source: KSRC.rings,
            paint: { 'line-color': ['get', 'colour'], 'line-width': 1.4, 'line-opacity': 0.85, 'line-dasharray': [3, 2] },
        },
        {
            id: 'kol-rings-label',
            type: 'symbol',
            source: KSRC.rings,
            layout: {
                'symbol-placement': 'line',
                'text-field': ['get', 'label'],
                'text-font': FONT_BOLD,
                'text-size': 10,
                'text-allow-overlap': true,
            },
            paint: { 'text-color': ['get', 'colour'], 'text-halo-color': 'rgba(246,243,233,0.95)', 'text-halo-width': 1.3 },
        },
        // What just happened, where it happened, fading out.
        {
            id: 'kol-flash-dot',
            type: 'circle',
            source: KSRC.flashes,
            paint: {
                'circle-radius': 9,
                'circle-color': ['get', 'colour'],
                'circle-opacity': ['*', 0.25, ['get', 'fade']],
                'circle-stroke-color': ['get', 'colour'],
                'circle-stroke-width': 2,
                'circle-stroke-opacity': ['get', 'fade'],
            },
        },
        {
            id: 'kol-flash-text',
            type: 'symbol',
            source: KSRC.flashes,
            layout: {
                'text-field': ['get', 'text'],
                'text-font': FONT_BOLD,
                'text-size': 11,
                'text-anchor': 'bottom',
                'text-offset': [0, -1.1],
                'text-allow-overlap': true,
            },
            paint: {
                'text-color': ['get', 'colour'],
                'text-opacity': ['get', 'fade'],
                'text-halo-color': 'rgba(246,243,233,0.95)',
                'text-halo-width': 1.5,
            },
        },
        // The order being drawn up: the way from the squad to the mark (a patrol: its beat), and the marks.
        {
            id: 'kol-pin-line',
            type: 'line',
            source: KSRC.pin,
            filter: ['==', ['geometry-type'], 'LineString'],
            layout: { 'line-cap': 'round' },
            // Not sent yet: amber, so it never reads as an order the squad has.
            paint: { 'line-color': '#c0640f', 'line-width': 2, 'line-opacity': 0.9, 'line-dasharray': [1, 1.4] },
        },
        {
            id: 'kol-pin-halo',
            type: 'circle',
            source: KSRC.pin,
            filter: ['==', ['geometry-type'], 'Point'],
            paint: {
                'circle-radius': 15,
                'circle-color': 'rgba(232,140,40,0.18)',
                'circle-stroke-color': '#c0640f',
                'circle-stroke-width': 1.5,
            },
        },
        {
            id: 'kol-pin',
            type: 'symbol',
            source: KSRC.pin,
            filter: ['==', ['geometry-type'], 'Point'],
            layout: {
                'icon-image': ['get', 'icon'],
                'icon-allow-overlap': true,
                'text-field': ['get', 'label'],
                'text-font': FONT_BOLD,
                'text-size': 10,
                'text-anchor': 'top',
                'text-offset': [0, 1.1],
                'text-optional': true,
            },
            paint: { 'text-color': '#a2530b', 'text-halo-color': 'rgba(246,243,233,0.95)', 'text-halo-width': 1.3 },
        },
    ];
}
