import type { WarCombatMode, WarFormation, WarOrderKind, WarSideId } from '@/lib/schemas/reforger';
import { router } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import { toast } from 'sonner';

/**
 * Every write the war page makes, one typed function per endpoint (routes/web.php,
 * reforger/war/*). All go the same way: an Inertia POST that keeps scroll and state and, after
 * the redirect back, reloads only the `war` prop. A refusal (the server's validation message)
 * is shown as a toast unless the caller shows it inline; `useWarSending()` says whether a write
 * is under way, for disabling buttons.
 */

type Point = { x: number; z: number };

/** One step of an order: what IssueWarMoveOrderAction takes (ReforgerWarController::order). */
export type WarOrderStep = {
    kind: WarOrderKind;
    x: number;
    z: number;
    x2?: number;
    z2?: number;
    vehicle_id?: number;
    follow_squad_id?: number;
    soldier_id?: number;
    route?: Point[];
};

export type WarOrderRequest = WarOrderStep & {
    squad_id: number;
    /** More orders to wait behind this one, in turn (board, drive, get out). */
    then?: WarOrderStep[];
    /** Wait behind the squad's open orders instead of replacing them. */
    queue?: boolean;
};

export type WarUpgradeRequest =
    | { kind: 'recruit' | 'train'; squad_id: number }
    | { kind: 'order'; squad_id: number; role: string; name?: string; note?: string }
    | { kind: 'tune'; squad_id: number; soldier_id: number; role: string }
    | { kind: 'lose' | 'dismiss'; squad_id: number; soldier_id: number }
    | { kind: 'remember'; squad_id: number; soldier_id: number; name: string; note?: string }
    | { kind: 'drill'; squad_id: number; soldier_id: number; stat: 'aim' | 'grit' | 'fieldcraft' | 'endurance' };

export type WarSupportRequest = { kind: string; x: number; z: number } | { kind: 'deploy'; squad_id: number };

export type WarRunRequest = { kind: 'supply' | 'ambulance'; squad_id: number } | { kind: 'transport'; squad_id: number; x: number; z: number };

export type WarTaskRequest = {
    kind: 'hold' | 'patrol';
    size: number;
    minutes?: number;
    label?: string;
    a_x: number;
    a_z: number;
    b_x?: number;
    b_z?: number;
};

export type WarAlert = 'normal' | 'alert' | 'ready';
export type WarPace = 'auto' | 'walk' | 'run' | 'sprint';
export type WarStance = 'auto' | 'stand' | 'crouch' | 'prone';

export type SendOptions = {
    /** After the server took it. */
    onSuccess?: () => void;
    /** The caller shows the server's refusal itself (from the page's `errors`): no toast. */
    inline?: boolean;
};

// Writes under way, across the page: every button that sends can grey out while one is.
let pending = 0;
const listeners = new Set<() => void>();
function setPending(delta: number) {
    pending = Math.max(0, pending + delta);
    listeners.forEach((listen) => listen());
}

export function useWarSending(): boolean {
    return useSyncExternalStore(
        (listen) => {
            listeners.add(listen);

            return () => listeners.delete(listen);
        },
        () => pending > 0,
        () => false,
    );
}

/** The first message of a refusal, whatever field the server put it under. */
export function firstError(errors: Record<string, string | string[] | undefined>): string | null {
    for (const value of Object.values(errors)) {
        const text = Array.isArray(value) ? value[0] : value;
        if (text) {
            return text;
        }
    }

    return null;
}

function post(url: string, data: object, options: SendOptions = {}): void {
    router.post(url, data as Record<string, never>, {
        preserveScroll: true,
        preserveState: true,
        // The redirect back renders the same page: only the war desk changed.
        only: ['war'],
        onStart: () => setPending(1),
        onFinish: () => setPending(-1),
        onSuccess: () => options.onSuccess?.(),
        onError: (errors) => {
            const text = firstError(errors);
            if (text && !options.inline) {
                toast.error(text, { id: `war-refused:${text}` });
            }
        },
    });
}

const base = '/reforger/war';

export const warApi = {
    enlist: (side: WarSideId, o?: SendOptions) => post(`${base}/enlist`, { side }, o),

    order: (body: WarOrderRequest, o?: SendOptions) => post(`${base}/orders`, body, o),
    cancelOrder: (orderId: number, o?: SendOptions) => post(`${base}/orders/${orderId}/cancel`, {}, o),

    support: (body: WarSupportRequest, o?: SendOptions) => post(`${base}/support`, body, o),
    cancelSupport: (supportId: number, o?: SendOptions) => post(`${base}/support/${supportId}/cancel`, {}, o),
    run: (body: WarRunRequest, o?: SendOptions) => post(`${base}/runs`, body, o),

    task: (body: WarTaskRequest, o?: SendOptions) => post(`${base}/tasks`, body, o),
    cancelTask: (taskId: number, o?: SendOptions) => post(`${base}/tasks/${taskId}/cancel`, {}, o),

    orderVehicle: (kind: string, o?: SendOptions) => post(`${base}/vehicles`, { kind }, o),
    scrapVehicle: (vehicleId: number, o?: SendOptions) => post(`${base}/vehicles/${vehicleId}/scrap`, {}, o),
    serviceVehicle: (vehicleId: number, o?: SendOptions) => post(`${base}/vehicles/${vehicleId}/service`, {}, o),

    upgrade: (body: WarUpgradeRequest, o?: SendOptions) => post(`${base}/upgrade`, body, o),

    squad: {
        combat: (squadId: number, mode: WarCombatMode, o?: SendOptions) => post(`${base}/squads/${squadId}/combat`, { mode }, o),
        alert: (squadId: number, level: WarAlert, o?: SendOptions) => post(`${base}/squads/${squadId}/alert`, { level }, o),
        form: (squadId: number, body: { formation?: WarFormation; pace?: WarPace }, o?: SendOptions) =>
            post(`${base}/squads/${squadId}/form`, body, o),
        rename: (squadId: number, name: string, o?: SendOptions) => post(`${base}/squads/${squadId}/name`, { name }, o),
        split: (squadId: number, body: { part: 'passengers' | 'crew'; unload?: boolean }, o?: SendOptions) =>
            post(`${base}/squads/${squadId}/split`, body, o),
        logistics: (squadId: number, action: 'rearm' | 'hospital', o?: SendOptions) => post(`${base}/squads/${squadId}/logistics`, { action }, o),
    },

    soldier: {
        posture: (soldierId: number, body: { stance?: WarStance; pace?: WarPace }, o?: SendOptions) =>
            post(`${base}/soldiers/${soldierId}/posture`, body, o),
        transfer: (soldierId: number, squadId: number, o?: SendOptions) => post(`${base}/soldiers/${soldierId}/transfer`, { squad_id: squadId }, o),
        detach: (soldierId: number, o?: SendOptions) => post(`${base}/soldiers/${soldierId}/detach`, {}, o),
    },
} as const;
