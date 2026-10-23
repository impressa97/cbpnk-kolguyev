import type { WarCombatMode, WarCommand } from '@/lib/schemas/reforger';

type Squad = WarCommand['squads'][number];
type Order = WarCommand['orders'][number];

/**
 * The squad's latest order. Every order goes to the whole group, as in the game. Smoke is
 * thrown on top of an order and leaves it under way, so it is not the squad's order.
 */
export function latestOrder(orders: Order[], squadId: number): Order | null {
    return (
        orders
            .filter((order) => order.squad_id === squadId && order.kind !== 'smoke' && order.kind !== 'watch' && order.status !== 'queued')
            .sort((a, b) => b.id - a.id)[0] ?? null
    );
}

/** An open order to treat this soldier: the squad's medic is on his way. */
export function treatmentFor(orders: Order[], soldierId: number): Order | null {
    return (
        orders.find((order) => order.kind === 'heal' && order.soldier_id === soldierId && (order.status === 'pending' || order.status === 'acked')) ??
        null
    );
}

/** What the game says the group is doing, in words. */
export const DOING: Record<NonNullable<Squad['doing']>, string> = {
    idle: 'Standing by',
    own: 'On its own initiative',
    move: 'Moving',
    move_forced: 'Rushing',
    defend: 'Defending',
    search: 'Searching',
    suppress: 'Suppressing',
    heal: 'Treating a wounded man',
    mount: 'Boarding',
    dismount: 'Dismounting',
    halt: 'Halted',
    patrol: 'Patrolling',
    rest: 'Going home',
    recon: 'Scouting',
    sabotage: 'On sabotage',
    raid: 'Raiding',
    smoke: 'Throwing smoke',
    watch: 'Watching',
    follow: 'Following',
    attack: 'Attacking',
};

export const COMBAT: Record<WarCombatMode, string> = { hold: 'Hold fire', return: 'Return fire', free: 'Fire at will' };

const ACTION_WORD: Record<string, string> = {
    Idle: 'idle',
    Attack: 'fighting',
    MoveInFormation: 'in formation',
    MoveIndividually: 'moving',
    Move: 'moving',
    Heal: 'treating',
    HealWait: 'waiting for the medic',
    GetIn: 'boarding',
    GetInVehicle: 'boarding',
    GetOut: 'getting out',
    MoveFromDanger: 'taking cover',
    Retreat: 'falling back',
    Suppress: 'suppressing',
    ThrowGrenadeTo: 'throwing a grenade',
    Observe: 'watching',
    LookAt: 'watching',
    Investigate: 'investigating',
    Defend: 'defending',
    Reload: 'reloading',
    Vehicle: 'in the vehicle',
};

/** What a man's AI is doing in the game, in a word or two. Unknown actions are split apart. */
function actionWord(action: string | null | undefined): string | null {
    if (!action) {
        return null;
    }

    // Variants the game appends after an underscore (Idle_Driver) read as their base action.
    const base = action.split('_')[0];

    return ACTION_WORD[action] ?? ACTION_WORD[base] ?? base.replace(/([a-z])([A-Z])/g, '$1 $2').toLowerCase();
}

/** A man's state in the game in one line: what his AI is doing, his stance, his seat. */
export function soldierDoing(s: { action?: string | null; stance?: string | null; seat?: string | null }): string {
    // Aboard, his seat is what counts; the stance he keeps for when he gets out is noise.
    return [actionWord(s.action), !s.seat && s.stance && s.stance !== 'stand' ? s.stance : null, s.seat].filter(Boolean).join(' · ');
}

export type SquadState = { label: string; tone: 'idle' | 'busy' | 'good' | 'bad' | 'hold' };

/**
 * The squad's state as the game reports it (doing), falling back to the order's own status
 * only while the order has not reached the game yet.
 */
export function squadState(squad: Squad, order: Order | null): SquadState {
    if (squad.strength === 0) {
        return { label: 'Empty', tone: 'idle' };
    }
    if (squad.condition.field === 0) {
        return { label: 'At base', tone: 'idle' };
    }
    if (order?.status === 'pending') {
        return { label: 'Order sent', tone: 'busy' };
    }
    if (order?.status === 'failed') {
        return { label: 'Order failed', tone: 'bad' };
    }
    if (squad.doing) {
        const tone: SquadState['tone'] =
            squad.doing === 'idle'
                ? order?.status === 'done'
                    ? 'good'
                    : 'idle'
                : squad.doing === 'defend' || squad.doing === 'patrol'
                  ? 'hold'
                  : 'busy';

        return { label: squad.doing === 'idle' && order?.status === 'done' ? 'On position' : DOING[squad.doing], tone };
    }
    if (order === null || order.status === 'superseded') {
        return { label: 'Standing by', tone: 'idle' };
    }

    return order.status === 'done' ? { label: 'On position', tone: 'good' } : { label: DOING[order.kind] ?? 'Moving', tone: 'busy' };
}

export const STATE_TONE: Record<SquadState['tone'], string> = {
    idle: 'bg-muted text-muted-foreground',
    busy: 'bg-map-warning/10 text-map-warning',
    good: 'bg-map-success/10 text-map-success',
    bad: 'bg-destructive/10 text-destructive',
    hold: 'bg-map-accent/10 text-map-accent',
};

export function countdown(seconds: number): string {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    return days > 0 ? `${days}d ${hours}h` : `${hours}h ${String(minutes).padStart(2, '0')}m`;
}

type Death = NonNullable<WarCommand['fallen'][number]['death']>;

/** A weapon's prefab name as a soldier would say it: Rifle_AK74_GP25 → AK74 GP25. */
export function weaponName(prefab: string): string {
    return prefab.replace(/^(Rifle|MG|LMG|Pistol|Launcher|Grenade|Handgun|Weapon)_/i, '').replace(/_/g, ' ');
}

/** How a man died, as the game reported it: "by Pvt Ivanov · AK74 · 140 m"; null when it did not say. */
export function howHeDied(death: Death | null): string | null {
    if (!death) {
        return null;
    }
    const how = {
        enemy: death.by ? `by ${death.by}` : 'by the enemy',
        friendly: death.by ? `friendly fire from ${death.by}` : 'friendly fire',
        fire: 'under artillery',
        vehicle: 'by a vehicle',
        self: 'by his own hand',
        wounds: 'of his wounds',
        other: null,
    }[death.cause];
    const parts = [how, death.weapon && death.cause !== 'fire' ? weaponName(death.weapon) : null, death.metres !== null ? `${death.metres} m` : null];
    const said = parts.filter((part): part is string => part !== null);

    return said.length > 0 ? said.join(' · ') : null;
}
