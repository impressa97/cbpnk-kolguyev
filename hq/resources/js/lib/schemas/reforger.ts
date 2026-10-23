import { z } from 'zod';

export const WarSideSchema = z.enum(['us', 'ussr', 'fia']);

/** What a man does in a fight, for his symbol (WarEconomy::roleClass). */
export const WarRoleClassSchema = z.enum(['leader', 'mg', 'at', 'marksman', 'medic', 'sapper', 'radio', 'grenadier', 'scout', 'rifle']);

/** Mirrors App\Enums\WarOrderKind: the game's commanding-menu commands, plus rest and the operations. */
export const WarOrderKindSchema = z.enum([
    'move',
    'move_forced',
    'defend',
    'search',
    'suppress',
    'heal',
    'mount',
    'dismount',
    'halt',
    'patrol',
    'rest',
    'recon',
    'sabotage',
    'raid',
    'smoke',
    'watch',
    'follow',
    'attack',
]);

/** The vanilla combat-mode commands. */
export const WarCombatModeSchema = z.enum(['hold', 'return', 'free']);

/** SCR_EAIGroupFormation, plus auto (no setting of ours). */
export const WarFormationSchema = z.enum(['auto', 'wedge', 'line', 'column', 'staggered']);
export type WarFormation = z.infer<typeof WarFormationSchema>;
export type WarCombatMode = z.infer<typeof WarCombatModeSchema>;
export type WarOrderKind = z.infer<typeof WarOrderKindSchema>;

const WarSideOptionSchema = z
    .object({
        id: WarSideSchema,
        name: z.string(),
        detail: z.string(),
        squads: z.number(),
        commanders: z.number(),
        home: z.object({ x: z.number(), z: z.number() }).strict(),
    })
    .strict();

/** An enemy your side's squads report from the game (group perception). */
const WarContactSchema = z
    .object({
        id: z.number(),
        side: WarSideSchema,
        x: z.number(),
        z: z.number(),
        kind: z.enum(['inf', 'veh']),
        how: z.enum(['seen', 'heard']),
        /** Where he looks (a compass bearing), when we see him. */
        heading: z.number().nullable(),
        /** A man seen: what he does (his weapon gives it away). Null when only heard. */
        role: WarRoleClassSchema.nullable(),
        /** A vehicle seen: which of the enemy's kinds (config war.vehicles). Null when unknown. */
        vehicle: z.string().nullable(),
        seen_at: z.string(),
        age_s: z.number(),
        /** Where it was seen before, oldest first. */
        trail: z.array(z.object({ x: z.number(), z: z.number() }).strict()),
    })
    .strict();

const WarSquadMarkerSchema = z
    .object({
        id: z.number(),
        commander_id: z.number().nullable(),
        /** The commander's name (for allies' squads). */
        name: z.string(),
        /** The squad's own name, or its callsign; HQ detachments read "HQ ...". */
        label: z.string(),
        side: WarSideSchema,
        strength: z.number(),
        alive: z.number(),
        tier: z.number(),
        tier_name: z.string(),
        train_cost: z.number().nullable(),
        train_name: z.string(),
        x: z.number(),
        z: z.number(),
        mine: z.boolean(),
        managed: z.boolean(),
        task_id: z.number().nullable(),
        /** The combat mode the commander set, the one the AI is actually in, and what the game has the group doing. */
        combat: WarCombatModeSchema,
        combat_actual: WarCombatModeSchema.nullable(),
        /** Formation and pace for the whole squad: the game's group AI settings; auto leaves them to the AI. */
        formation: WarFormationSchema,
        /** Readiness for contact: the game's threat state the mod holds the men at. */
        alert: z.enum(['normal', 'alert', 'ready']),
        pace: z.enum(['auto', 'walk', 'run', 'sprint']),
        doing: z.union([WarOrderKindSchema, z.enum(['idle', 'own'])]).nullable(),
        condition: z
            .object({
                field: z.number(),
                reserve: z.number(),
                wounded: z.number(),
                low_ammo: z.number(),
                under_fire: z.number(),
                morale: z.number().nullable(),
                health: z.number().nullable(),
            })
            .strict(),
    })
    .strict();

const WarPlaceSchema = z
    .object({
        id: z.string(),
        name: z.string(),
        side: WarSideSchema.nullable(),
        kind: z.enum(['home', 'base', 'town', 'objective', 'relay', 'harbour']),
        x: z.number(),
        z: z.number(),
    })
    .strict();

const WarSoldierMarkerSchema = z
    .object({
        id: z.number(),
        squad_id: z.number(),
        name: z.string(),
        role_name: z.string(),
        role: WarRoleClassSchema,
        side: WarSideSchema,
        x: z.number(),
        z: z.number(),
        mine: z.boolean(),
        /** One of HQ's men: shown, not commanded. */
        hq: z.boolean(),
        health: z.number(),
        bleeding: z.boolean(),
        down: z.boolean(),
        threat: z.enum(['safe', 'vigilant', 'alerted', 'threatened']),
        in_vehicle: z.boolean(),
        seat: z.enum(['driver', 'gunner', 'passenger']).nullable(),
        stance: z.enum(['stand', 'crouch', 'prone']).nullable(),
        /** The AI action he is on in the game (Attack, MoveInFormation, Heal, ...). */
        action: z.string().nullable(),
        /** Where he looks, a compass bearing from the game. */
        heading: z.number().nullable(),
        /** Where the enemy he is fighting stands (the game's current target), or null. */
        target: z.object({ x: z.number(), z: z.number() }).strict().nullable(),
        /** False until the game has reported his own position (he is drawn in the squad's formation). */
        reported: z.boolean(),
    })
    .strict();

const WarEventSchema = z
    .object({
        id: z.string(),
        at: z.string(),
        kind: z.enum(['join', 'loss', 'move', 'supply', 'kill', 'vehicle', 'fire', 'territory', 'objective', 'hq', 'threat']),
        text: z.string(),
    })
    .strict();

const WarOrderMarkerSchema = z
    .object({
        id: z.number(),
        squad_id: z.number(),
        /** The soldier the order is about (whom the medic treats); null otherwise. */
        soldier_id: z.number().nullable(),
        /** The vehicle a board order gets into. */
        vehicle_id: z.number().nullable(),
        /** The squad a follow order follows. */
        follow_squad_id: z.number().nullable(),
        /** A move's further points, in order. */
        route: z.array(z.object({ x: z.number(), z: z.number() }).strict()),
        kind: WarOrderKindSchema,
        status: z.enum(['queued', 'pending', 'acked', 'done', 'failed', 'superseded']),
        /** A patrol's other end. */
        x2: z.number().nullable(),
        z2: z.number().nullable(),
        x: z.number(),
        z: z.number(),
        target: z.string().nullable(),
        note: z.string(),
        effect_until: z.string().nullable(),
    })
    .strict();

const WarUpgradeSchema = z
    .object({
        cost: z.number().nullable(),
        available: z.boolean(),
        label: z.string(),
    })
    .strict();

const WarRoleSchema = z
    .object({
        id: z.string(),
        name: z.string(),
        task: z.string(),
        cost: z.number(),
        min_cost: z.number(),
        aim: z.number(),
        grit: z.number(),
        fieldcraft: z.number(),
        sides: z.array(WarSideSchema),
        weapons: z
            .object({
                us: z.string().nullable(),
                ussr: z.string().nullable(),
                fia: z.string().nullable(),
            })
            .strict(),
    })
    .strict();

const WarSoldierSchema = z
    .object({
        id: z.number(),
        squad_id: z.number(),
        name: z.string(),
        note: z.string(),
        role: z.string(),
        role_name: z.string(),
        aim: z.number(),
        grit: z.number(),
        fieldcraft: z.number(),
        endurance: z.number(),
        endurance_effective: z.number(),
        aim_effective: z.number(),
        /** What drilling each stat once more costs; null at the top. */
        drill: z
            .object({ aim: z.number().nullable(), grit: z.number().nullable(), fieldcraft: z.number().nullable(), endurance: z.number().nullable() })
            .strict(),
        grit_effective: z.number(),
        fieldcraft_effective: z.number(),
        service: z.number(),
        spent: z.number(),
        loss: z.string().nullable(),
        cost: z.number(),
        health: z.number(),
        stamina: z.number(),
        bleeding: z.boolean(),
        down: z.boolean(),
        threat: z.enum(['safe', 'vigilant', 'alerted', 'threatened']),
        mags: z.number().nullable(),
        in_vehicle: z.boolean(),
        seat: z.enum(['driver', 'gunner', 'passenger']).nullable(),
        stance: z.enum(['stand', 'crouch', 'prone']).nullable(),
        action: z.string().nullable(),
        /** His squad leader's standing orders: the game's per-soldier AI orders. */
        stance_order: z.enum(['auto', 'stand', 'crouch', 'prone']),
        pace_order: z.enum(['auto', 'walk', 'run', 'sprint']),
        morale: z.number(),
        mood: z.enum(['shaken', 'fair', 'steady']),
        battles: z.number(),
        /** Enemies he killed, as the game reported them. */
        kills: z.number(),
        /** The game's AI skill level he fights at (aiming), from his stats and squad training. */
        ai_skill: z.enum(['regular', 'veteran', 'expert']),
        flags: z.array(z.string()),
        location: z.enum(['field', 'reserve']),
        ready_at: z.string().nullable(),
        /** How he died, as the game reported it (the fallen only): who, with what, from how far. */
        death: z
            .object({
                cause: z.enum(['enemy', 'friendly', 'self', 'fire', 'vehicle', 'wounds', 'other']),
                by: z.string().nullable(),
                weapon: z.string().nullable(),
                metres: z.number().nullable(),
            })
            .strict()
            .nullable(),
    })
    .strict();

/** Island time and weather from the server (TimeAndWeatherManagerEntity). */
const WarWorldSchema = z
    .object({
        hour: z.number(),
        weather: z.string(),
        rain: z.number(),
        fog: z.number(),
        wind: z.number(),
        wind_dir: z.number(),
        sunrise: z.number(),
        sunset: z.number(),
        moon: z.number(),
        at: z.string(),
    })
    .strict();

/** One of the commander's vehicles: his, any of his squads boards it (none is a squad's own). */
const WarVehicleSchema = z
    .object({
        id: z.number(),
        kind: z.string(),
        name: z.string(),
        model: z.string(),
        status: z.enum(['ordered', 'fielded', 'destroyed', 'scrapped']),
        x: z.number().nullable(),
        z: z.number().nullable(),
        health: z.number(),
        fuel: z.number(),
        seats: z.number().nullable(),
        crew: z.number(),
        /** Lent by HQ for a run: not one of yours. */
        hq: z.boolean(),
    })
    .strict();

const WarMotorPoolEntrySchema = z
    .object({ id: z.string(), name: z.string(), role: z.string(), cost: z.number(), model: z.string(), prefab: z.string() })
    .strict();

const WarSupportRequestSchema = z
    .object({
        id: z.number(),
        kind: z.string(),
        name: z.string(),
        status: z.enum(['requested', 'waiting', 'done', 'aborted', 'failed', 'cancelled']),
        squad_id: z.number().nullable(),
        vehicle_id: z.number().nullable(),
        x: z.number().nullable(),
        z: z.number().nullable(),
        cost: z.number(),
        ready_at: z.string(),
        note: z.string(),
    })
    .strict();

const WarSupportOfferSchema = z
    .object({
        id: z.string(),
        name: z.string(),
        detail: z.string(),
        cost: z.number(),
        delay: z.number(),
        cooldown: z.number(),
        danger_close: z.number(),
        magazines: z.number(),
        ready_at: z.string().nullable(),
    })
    .strict();

const WarPointSchema = z.object({ x: z.number(), z: z.number() }).strict();

const WarTaskSchema = z
    .object({
        id: z.number(),
        kind: z.enum(['hold', 'patrol']),
        size: z.number(),
        label: z.string(),
        a: WarPointSchema,
        b: WarPointSchema.nullable(),
        squad_id: z.number().nullable(),
        note: z.string(),
        /** HQ works on request and for a while: when it calls the squad in. */
        ends_at: z.string().nullable(),
        /** What the detachment is up to: its men on the island, what the game has it doing, where. */
        men: z.number(),
        doing: z.union([WarOrderKindSchema, z.enum(['idle', 'own'])]).nullable(),
        at: WarPointSchema.nullable(),
    })
    .strict();

const WarObjectiveSchema = z
    .object({
        id: z.number(),
        kind: z.enum(['capture', 'supply_run', 'interdict', 'sabotage', 'raid']),
        title: z.string(),
        target: z.string(),
        x: z.number(),
        z: z.number(),
        reward: z.number(),
        status: z.enum(['open', 'done', 'expired']),
        progress_at: z.string().nullable(),
        progress_squad_id: z.number().nullable(),
        stage: z.string().nullable(),
        expires_at: z.string(),
        mine: z.boolean(),
    })
    .strict();

const WarControlSchema = z
    .object({
        place: z.string(),
        income: z.number(),
        side: WarSideSchema.nullable(),
        claim_side: WarSideSchema.nullable(),
        claim_since: z.string().nullable(),
        interdicted_until: z.string().nullable(),
    })
    .strict();

/** A special operation as config war.operations has it (App\Support\WarOperations::catalog). */
const WarOperationSchema = z
    .object({
        id: z.enum(['recon', 'sabotage', 'raid']),
        label: z.string(),
        /** Metres from the target the squad must stay within, and seconds it must stay unseen. */
        radius: z.number(),
        hold: z.number(),
        /** Place kinds it is aimed at; a mark within `snap` m of one snaps to it. */
        targets: z.array(WarPlaceSchema.shape.kind),
        snap: z.number(),
        /** Refuses a mark with no target in reach. */
        required: z.boolean(),
        /** A role the squad needs in the field. */
        needs: z.string().nullable(),
        help: z.string(),
    })
    .strict();
export type WarOperation = z.infer<typeof WarOperationSchema>;

const WarCatalogSchema = z
    .object({
        stat_min: z.number(),
        stat_max: z.number(),
        point_cost: z.number(),
        stats: z.array(z.object({ id: z.string(), name: z.string() }).strict()),
        roles: z.array(WarRoleSchema),
    })
    .strict();

export const WarCommandSchema = z
    .object({
        world_size: z.number(),
        squad_strength: z.number(),
        capture_radius: z.number(),
        max_squads: z.number(),
        ai_limit: z.number(),
        enlisted_side: WarSideSchema.nullable(),
        session: z
            .object({
                open: z.boolean(),
                starts_at: z.string().nullable(),
                ends_at: z.string().nullable(),
                seconds_left: z.number(),
            })
            .strict(),
        sides: z.array(WarSideOptionSchema),
        squads: z.array(WarSquadMarkerSchema),
        orders: z.array(WarOrderMarkerSchema),
        places: z.array(WarPlaceSchema),
        markers: z.array(WarSoldierMarkerSchema),
        contacts: z.array(WarContactSchema),
        intel: z
            .object({
                blind_until: z.string().nullable(),
                operations: z.array(z.object({ id: z.number(), kind: z.string(), until: z.string().nullable(), note: z.string() }).strict()),
            })
            .strict(),
        world: WarWorldSchema.nullable(),
        /** Last bridge tick from the mod on the game server. */
        feed: z
            .object({ source: z.literal('game'), at: z.string() })
            .strict()
            .nullable(),
        vehicles: z.array(WarVehicleSchema),
        motor_pool: z.array(WarMotorPoolEntrySchema),
        /** Vehicles a commander may run at once. */
        vehicle_limit: z.number(),
        /** HQ runs for your squads: an ammunition truck, an ambulance, a lift. */
        runs: z.array(
            z
                .object({
                    id: z.number(),
                    kind: z.enum(['supply', 'ambulance', 'transport']),
                    squad_id: z.number(),
                    vehicle_id: z.number().nullable(),
                    stage: z.string(),
                    status: z.enum(['active', 'done', 'aborted']),
                    note: z.string(),
                })
                .strict(),
        ),
        run_costs: z.record(z.string(), z.number()),
        /** Where a squad gets its wounded seen to and its magazines: home, or beside a truck. */
        logistics: z
            .object({
                home_radius: z.number(),
                truck_radius: z.number(),
                truck_cost: z.number(),
                service_cost: z.number(),
                magazines: z.number(),
                ammo_vehicles: z.array(z.string()),
            })
            .strict(),
        support: z.array(WarSupportRequestSchema),
        support_catalog: z.array(WarSupportOfferSchema),
        tasks: z.array(WarTaskSchema),
        hq: z.object({ budget: z.number(), in_use: z.number(), sizes: z.array(z.number()), durations: z.array(z.number()) }).strict(),
        operations: z.array(WarOperationSchema),
        objectives: z.array(WarObjectiveSchema),
        territory: z.array(WarControlSchema),
        income: z.object({ base: z.number(), territory: z.number() }).strict(),
        events: z.array(WarEventSchema),
        soldiers: z.array(WarSoldierSchema),
        fallen: z.array(WarSoldierSchema),
        catalog: WarCatalogSchema,
        economy: z
            .object({
                supplies: z.number().nullable(),
                income_per_hour: z.number(),
                supply_cap: z.number(),
                service_cap: z.number(),
                missing: z.number(),
                tier: z.number().nullable(),
                tier_name: z.string().nullable(),
                recruit: WarUpgradeSchema,
                train: WarUpgradeSchema,
            })
            .strict(),
    })
    .strict();

export type WarCommand = z.infer<typeof WarCommandSchema>;
export type WarRole = z.infer<typeof WarRoleSchema>;
export type WarSoldierRow = z.infer<typeof WarSoldierSchema>;
export type WarEvent = z.infer<typeof WarEventSchema>;
export type WarSideId = z.infer<typeof WarSideSchema>;
