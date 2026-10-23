import type { WarCommand, WarSoldierRow } from '@/lib/schemas/reforger';
import type { Marks, OrderOptions, SquadOrderKind } from '../lib/orders';

export type Squad = WarCommand['squads'][number];
export type Point = { x: number; z: number };

/** What is being drawn up: an order for the picked squad, or HQ fire, and how it goes. */
export type Draft = OrderOptions & {
    kind: SquadOrderKind;
    /** The HQ fire desk is open instead of the squads' orders. */
    hq: boolean;
    /** HQ fire mission being aimed (a support id). */
    fire: string | null;
    marks: Marks;
};

export type CommandProps = {
    war: WarCommand;
    squads: Squad[];
    selected: Squad | null;
    soldier: WarSoldierRow | null;
    draft: Draft;
    onKind: (kind: SquadOrderKind) => void;
    /** Open the HQ fire desk (true) or the squads' orders (false). */
    onHq: (on: boolean) => void;
    onFire: (id: string | null) => void;
    onOptions: (patch: Partial<OrderOptions>) => void;
    onClearMarks: () => void;
    /** Pick a squad and fly the map to it. */
    onSelectSquad: (id: number) => void;
    onSelectSoldier: (squadId: number, soldierId: number) => void;
    onLeaveSoldier: () => void;
    onFocus: (point: Point) => void;
    /** Send the drawn-up order; append: behind what the squad is doing. */
    onSend: (append: boolean) => void;
    /** Call the HQ fire being aimed on the mark. */
    onCallFire: () => void;
};
