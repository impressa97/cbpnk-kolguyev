/**
 * Tactical symbols for the Kolguyev map, drawn on a canvas when MapLibre first asks for them
 * (styleimagemissing), so no image files are fetched. Shapes follow APP-6 as the in-game map
 * does: friendly units are rectangles, enemy contacts diamonds; infantry carries a cross,
 * wheeled vehicles wheels under the frame, armour an oval. A squad shows one echelon dot.
 *
 * Image ids encode everything: unit:us:mine:mot:sel, veh:ussr:apc, contact:fia:inf:seen:mg,
 * contact:ussr:veh:seen:apc, role:medic:ally, home:fia, star, mark:move, target:defend,
 * place:base:us, place:relay:none:claim-ussr.
 *
 * A man's role (what he does in a fight) is a small glyph: leader a star, machine gun a bar,
 * anti-tank an inverted V, marksman a crosshair, medic a cross, sapper a comb (the engineer's
 * bridge), radio a zigzag, grenadier a grenade, scout a slash. Riflemen carry none.
 */

import type { Map as MapLibreMap } from 'maplibre-gl';

/** Faction colours as the Reforger map uses them: BLUFOR blue, OPFOR red, INDFOR green. */
export const SIDE_HEX = {
    us: { stroke: '#1f63b8', fill: '#a9cdf2' },
    ussr: { stroke: '#b8332a', fill: '#f1b0aa' },
    fia: { stroke: '#2f7d3a', fill: '#b5dcb4' },
    none: { stroke: '#6b6559', fill: '#e2ddd0' },
} as const;

/** HQ's detachments: green on every side, so a commander tells them from the squads he leads. */
export const HQ_HEX = { stroke: '#2e8540', fill: '#c6ecc4' } as const;

type SideKey = keyof typeof SIDE_HEX;

const RATIO = 2;
const INK = '#1c1a17';

function side(key: string | undefined): SideKey {
    return key === 'us' || key === 'ussr' || key === 'fia' ? key : 'none';
}

function canvas(w: number, h: number) {
    const el = document.createElement('canvas');
    el.width = w * RATIO;
    el.height = h * RATIO;
    const ctx = el.getContext('2d')!;
    ctx.scale(RATIO, RATIO);
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';

    return { el, ctx };
}

function friendlyFrame(
    ctx: CanvasRenderingContext2D,
    x: number,
    y: number,
    w: number,
    h: number,
    colours: { stroke: string; fill: string },
    width: number,
    dashed = false,
) {
    ctx.fillStyle = colours.fill;
    ctx.strokeStyle = colours.stroke;
    ctx.lineWidth = width;
    ctx.setLineDash(dashed ? [3, 2] : []);
    ctx.fillRect(x, y, w, h);
    ctx.strokeRect(x, y, w, h);
    ctx.setLineDash([]);
}

function infantryCross(ctx: CanvasRenderingContext2D, x: number, y: number, w: number, h: number, colour: string) {
    ctx.strokeStyle = colour;
    ctx.lineWidth = 1.6;
    ctx.beginPath();
    ctx.moveTo(x, y);
    ctx.lineTo(x + w, y + h);
    ctx.moveTo(x + w, y);
    ctx.lineTo(x, y + h);
    ctx.stroke();
}

function echelonDot(ctx: CanvasRenderingContext2D, cx: number, y: number) {
    ctx.fillStyle = INK;
    ctx.beginPath();
    ctx.arc(cx, y, 1.8, 0, Math.PI * 2);
    ctx.fill();
}

/**
 * A role's glyph, centred on cx, cy, about `s` across, in `colour`. Riflemen have none.
 */
function roleGlyph(ctx: CanvasRenderingContext2D, role: string, cx: number, cy: number, s: number, colour: string) {
    const h = s / 2;
    ctx.save();
    ctx.strokeStyle = colour;
    ctx.fillStyle = colour;
    ctx.lineWidth = Math.max(1.4, s / 7);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.beginPath();
    switch (role) {
        case 'leader':
            for (let i = 0; i < 10; i++) {
                const r = i % 2 === 0 ? h : h * 0.42;
                const a = (Math.PI / 5) * i - Math.PI / 2;
                ctx.lineTo(cx + r * Math.cos(a), cy + r * Math.sin(a));
            }
            ctx.closePath();
            ctx.fill();
            break;
        case 'mg':
            ctx.lineWidth = Math.max(2.2, s / 4);
            ctx.moveTo(cx - h, cy);
            ctx.lineTo(cx + h, cy);
            ctx.stroke();
            break;
        case 'at':
            ctx.moveTo(cx - h, cy + h * 0.7);
            ctx.lineTo(cx, cy - h * 0.7);
            ctx.lineTo(cx + h, cy + h * 0.7);
            ctx.stroke();
            break;
        case 'marksman':
            ctx.arc(cx, cy, h * 0.7, 0, Math.PI * 2);
            ctx.moveTo(cx, cy - h);
            ctx.lineTo(cx, cy + h);
            ctx.moveTo(cx - h, cy);
            ctx.lineTo(cx + h, cy);
            ctx.stroke();
            break;
        case 'medic':
            ctx.fillStyle = '#c62828';
            ctx.fillRect(cx - h * 0.28, cy - h, h * 0.56, s);
            ctx.fillRect(cx - h, cy - h * 0.28, s, h * 0.56);
            break;
        case 'sapper':
            ctx.moveTo(cx - h, cy + h * 0.5);
            ctx.lineTo(cx - h, cy - h * 0.5);
            ctx.lineTo(cx + h, cy - h * 0.5);
            ctx.lineTo(cx + h, cy + h * 0.5);
            ctx.moveTo(cx, cy - h * 0.5);
            ctx.lineTo(cx, cy + h * 0.5);
            ctx.stroke();
            break;
        case 'radio':
            ctx.moveTo(cx - h, cy - h * 0.2);
            ctx.lineTo(cx - h * 0.3, cy - h * 0.6);
            ctx.lineTo(cx + h * 0.3, cy + h * 0.6);
            ctx.lineTo(cx + h, cy + h * 0.2);
            ctx.stroke();
            break;
        case 'grenadier':
            ctx.arc(cx, cy + h * 0.15, h * 0.6, 0, Math.PI * 2);
            ctx.fill();
            ctx.beginPath();
            ctx.moveTo(cx, cy - h * 0.45);
            ctx.lineTo(cx + h * 0.5, cy - h);
            ctx.stroke();
            break;
        case 'scout':
            ctx.moveTo(cx - h, cy + h);
            ctx.lineTo(cx + h, cy - h);
            ctx.stroke();
            break;
        default:
            break;
    }
    ctx.restore();
}

/** role:<class>:<mine|ally|hq> — a man's glyph over his dot, on a pale disc so it reads on any ground. */
function role(parts: string[]) {
    const { el, ctx } = canvas(16, 16);
    const colour = parts[2] === 'hq' ? HQ_HEX.stroke : parts[2] === 'ally' ? '#4a4538' : INK;
    ctx.fillStyle = 'rgba(255,255,255,0.85)';
    ctx.beginPath();
    ctx.arc(8, 8, 7.5, 0, Math.PI * 2);
    ctx.fill();
    roleGlyph(ctx, parts[1] ?? '', 8, 8, 9, colour);

    return el;
}

/**
 * unit:<side>:<mine|ally|hq>[:mot][:sel] — an infantry squad; mot: most of it rides in a
 * vehicle (motorised infantry: wheels under the frame).
 */
function unit(parts: string[]) {
    const colours = parts[2] === 'hq' ? HQ_HEX : SIDE_HEX[side(parts[1])];
    const selected = parts.includes('sel');
    const motorised = parts.includes('mot');
    const { el, ctx } = canvas(36, 34);
    // Frame centred on the canvas, so the frame's centre is the squad's position.
    if (selected) {
        ctx.fillStyle = 'rgba(255,255,255,0.85)';
        ctx.fillRect(1, 3, 34, 24);
    }
    friendlyFrame(ctx, 4, 6, 28, 18, colours, selected ? 2.6 : parts[2] === 'ally' ? 1.4 : 2, parts[2] === 'hq');
    infantryCross(ctx, 4, 6, 28, 18, colours.stroke);
    echelonDot(ctx, 18, 2.5);
    if (motorised) {
        ctx.fillStyle = colours.stroke;
        for (const wx of [12, 24]) {
            ctx.beginPath();
            ctx.arc(wx, 29, 2.6, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    return el;
}

/** veh:<side>:<kind> — wheeled vehicles get wheels, armour gets an oval, the ambulance a cross. */
function vehicle(parts: string[]) {
    const colours = SIDE_HEX[side(parts[1])];
    const kind = parts[2] ?? 'jeep';
    const { el, ctx } = canvas(36, 32);
    friendlyFrame(ctx, 4, 4, 28, 18, colours, 2);
    ctx.strokeStyle = colours.stroke;
    ctx.lineWidth = 1.6;
    if (kind === 'apc' || kind === 'armed_car') {
        ctx.beginPath();
        ctx.ellipse(18, 13, 9, 5, 0, 0, Math.PI * 2);
        ctx.stroke();
    }
    if (kind === 'ambulance') {
        ctx.fillStyle = colours.stroke;
        ctx.fillRect(16.5, 7, 3, 12);
        ctx.fillRect(12, 11.5, 12, 3);
    }
    if (kind === 'jeep_mg' || kind === 'armed_car') {
        ctx.beginPath();
        ctx.moveTo(10, 13);
        ctx.lineTo(26, 13);
        ctx.stroke();
    }
    if (kind === 'truck' || kind === 'utility') {
        ctx.beginPath();
        ctx.moveTo(18, 7);
        ctx.lineTo(18, 19);
        ctx.stroke();
    }
    // Wheels: the APP-6 wheeled mobility modifier sits under the frame.
    ctx.fillStyle = colours.stroke;
    for (const wx of kind === 'truck' || kind === 'apc' ? [10, 18, 26] : [12, 24]) {
        ctx.beginPath();
        ctx.arc(wx, 27, 2.6, 0, Math.PI * 2);
        ctx.fill();
    }

    return el;
}

/**
 * contact:<side>:<inf|veh>:<seen|heard>[:<class>] — hostile diamond, dashed when only heard.
 * Inside: a man's role when he was seen (a plain cross for a rifleman or one only heard), a
 * vehicle's kind when it was recognised (armour an oval, a gun a bar, an ambulance a cross).
 */
function contact(parts: string[]) {
    const known = parts[4] ?? '';
    const colours = SIDE_HEX[side(parts[1])];
    const heard = parts[3] === 'heard';
    const { el, ctx } = canvas(30, 30);
    ctx.beginPath();
    ctx.moveTo(15, 2);
    ctx.lineTo(28, 15);
    ctx.lineTo(15, 28);
    ctx.lineTo(2, 15);
    ctx.closePath();
    ctx.fillStyle = heard ? 'rgba(255,255,255,0.6)' : colours.fill;
    ctx.strokeStyle = colours.stroke;
    ctx.lineWidth = 2;
    ctx.setLineDash(heard ? [3, 2] : []);
    ctx.fill();
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.strokeStyle = colours.stroke;
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    if (parts[2] === 'veh') {
        if (known === 'apc' || known === 'armed_car' || known === '') {
            ctx.ellipse(15, 15, 6, 3.5, 0, 0, Math.PI * 2);
        }
        if (known === 'jeep_mg' || known === 'armed_car') {
            ctx.moveTo(9, 15);
            ctx.lineTo(21, 15);
        }
        if (known === 'truck' || known === 'utility' || known === 'jeep') {
            ctx.rect(10, 12, 10, 6);
        }
        ctx.stroke();
        if (known === 'ambulance') {
            roleGlyph(ctx, 'medic', 15, 15, 9, colours.stroke);
        }
    } else if (known !== '' && known !== 'rifle') {
        roleGlyph(ctx, known, 15, 15, 10, colours.stroke);
    } else {
        ctx.moveTo(10, 10);
        ctx.lineTo(20, 20);
        ctx.moveTo(20, 10);
        ctx.lineTo(10, 20);
        ctx.stroke();
    }

    return el;
}

/** home:<side> — respawn and muster. */
function home(parts: string[]) {
    const colours = SIDE_HEX[side(parts[1])];
    const { el, ctx } = canvas(24, 24);
    ctx.beginPath();
    ctx.moveTo(12, 2);
    ctx.lineTo(22, 10);
    ctx.lineTo(22, 22);
    ctx.lineTo(2, 22);
    ctx.lineTo(2, 10);
    ctx.closePath();
    ctx.fillStyle = colours.fill;
    ctx.strokeStyle = colours.stroke;
    ctx.lineWidth = 2;
    ctx.fill();
    ctx.stroke();
    ctx.fillStyle = colours.stroke;
    ctx.fillRect(9.5, 14, 5, 8);

    return el;
}

function star() {
    const { el, ctx } = canvas(22, 22);
    ctx.beginPath();
    for (let i = 0; i < 10; i++) {
        const r = i % 2 === 0 ? 10 : 4.5;
        const a = (Math.PI / 5) * i - Math.PI / 2;
        ctx.lineTo(11 + r * Math.cos(a), 11 + r * Math.sin(a));
    }
    ctx.closePath();
    ctx.fillStyle = '#e8b13a';
    ctx.strokeStyle = '#6b4a0c';
    ctx.lineWidth = 1.4;
    ctx.fill();
    ctx.stroke();

    return el;
}

/** mark:<kind> — the commander's new mark; target:<kind> — an order under way. */
function mark(parts: string[], fresh: boolean) {
    const { el, ctx } = canvas(26, 26);
    ctx.strokeStyle = INK;
    ctx.lineWidth = 2;
    ctx.setLineDash(fresh ? [3, 2] : []);
    if (parts[1] === 'defend') {
        ctx.beginPath();
        ctx.moveTo(13, 3);
        ctx.lineTo(22, 7);
        ctx.lineTo(22, 13);
        ctx.quadraticCurveTo(22, 21, 13, 24);
        ctx.quadraticCurveTo(4, 21, 4, 13);
        ctx.lineTo(4, 7);
        ctx.closePath();
        ctx.fillStyle = 'rgba(255,255,255,0.7)';
        ctx.fill();
        ctx.stroke();
    } else {
        ctx.beginPath();
        ctx.arc(13, 13, 8, 0, Math.PI * 2);
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.beginPath();
        ctx.moveTo(13, 1);
        ctx.lineTo(13, 7);
        ctx.moveTo(13, 19);
        ctx.lineTo(13, 25);
        ctx.moveTo(1, 13);
        ctx.lineTo(7, 13);
        ctx.moveTo(19, 13);
        ctx.lineTo(25, 13);
        ctx.stroke();
    }

    return el;
}

/**
 * place:<kind>:<holder|none>[:claim-<side>] — Conflict bases, objectives, relays, harbours,
 * centred on the point. Fill and edge show the holder; while another side is taking it, the
 * edge turns dashed in the taker's colour.
 */
function place(parts: string[]) {
    const colours = SIDE_HEX[side(parts[2])];
    const claim = parts[3]?.startsWith('claim-') ? SIDE_HEX[side(parts[3].slice(6))] : null;
    const { el, ctx } = canvas(20, 20);
    ctx.fillStyle = colours.fill;
    ctx.strokeStyle = claim ? claim.stroke : colours.stroke;
    ctx.lineWidth = 2;
    ctx.setLineDash(claim ? [3, 2] : []);
    ctx.beginPath();
    if (parts[1] === 'relay') {
        ctx.moveTo(10, 2.5);
        ctx.lineTo(17.5, 17);
        ctx.lineTo(2.5, 17);
        ctx.closePath();
    } else if (parts[1] === 'harbour') {
        ctx.arc(10, 10, 7, 0, Math.PI * 2);
    } else if (parts[1] === 'objective') {
        ctx.moveTo(10, 2);
        ctx.lineTo(18, 10);
        ctx.lineTo(10, 18);
        ctx.lineTo(2, 10);
        ctx.closePath();
    } else {
        ctx.rect(3, 3, 14, 14);
    }
    ctx.fill();
    ctx.stroke();
    ctx.setLineDash([]);
    // The exact spot.
    ctx.fillStyle = INK;
    ctx.beginPath();
    ctx.arc(10, 10, 1.4, 0, Math.PI * 2);
    ctx.fill();

    return el;
}

/**
 * Where someone looks: a soft wedge from the point it is drawn on, opening up to the north
 * (the layer rotates it to his bearing). look:own (blue) for your men, look:enemy (red).
 */
function look(parts: string[]): HTMLCanvasElement {
    const size = 64;
    const el = document.createElement('canvas');
    el.width = size * RATIO;
    el.height = size * RATIO;
    const ctx = el.getContext('2d')!;
    ctx.scale(RATIO, RATIO);
    const colour = parts[1] === 'enemy' ? '184,51,42' : '31,99,184';
    const cx = size / 2;
    const cy = size / 2;
    const gradient = ctx.createRadialGradient(cx, cy, 2, cx, cy, size / 2);
    gradient.addColorStop(0, `rgba(${colour},0.55)`);
    gradient.addColorStop(1, `rgba(${colour},0)`);
    ctx.fillStyle = gradient;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.arc(cx, cy, size / 2, -Math.PI / 2 - 0.42, -Math.PI / 2 + 0.42);
    ctx.closePath();
    ctx.fill();

    return el;
}

function draw(id: string): HTMLCanvasElement | null {
    const parts = id.split(':');
    switch (parts[0]) {
        case 'unit':
            return unit(parts);
        case 'veh':
            return vehicle(parts);
        case 'contact':
            return contact(parts);
        case 'role':
            return role(parts);
        case 'home':
            return home(parts);
        case 'star':
            return star();
        case 'mark':
            return mark(parts, true);
        case 'target':
            return mark(parts, false);
        case 'place':
            return place(parts);
        case 'look':
            return look(parts);
        default:
            return null;
    }
}

/**
 * Draw any symbol the style asks for the first time it is needed. MapLibre 6 asks the resolver
 * before it gives up on an image (the old `styleimagemissing` event only fires after that).
 */
export function provideSymbols(map: MapLibreMap): () => void {
    map.setMissingStyleImageResolver((id) => {
        if (map.hasImage(id)) {
            return;
        }
        const el = draw(id);
        if (!el) {
            return;
        }
        const ctx = el.getContext('2d')!;
        map.addImage(id, ctx.getImageData(0, 0, el.width, el.height), { pixelRatio: RATIO });
    });

    return () => {
        map.setMissingStyleImageResolver(null);
    };
}
