<?php

/**
 * Kolguyev war. The dedicated server is server/ (see docs/REFORGER.md).
 * Metres: south-west origin, X east, Z north. Terrain is 12.8 km × 12.8 km.
 */

return [

    'bridge_secret' => env('REFORGER_BRIDGE_SECRET', ''),

    'host' => env('REFORGER_HOST', '127.0.0.1'),
    'port' => (int) env('REFORGER_PORT', 2001),
    'query_port' => (int) env('REFORGER_QUERY_PORT', 17777),
    'password' => env('REFORGER_PASSWORD', ''),
    'ai_limit' => (int) env('REFORGER_AI_LIMIT', 192),

    /**
     * Continuous three-side war on Kolguyev.
     */
    'war' => [
        'world_size' => 12800,
        'squad_strength' => 4,
        // Every commander has this many squads from the start; men in them are what costs.
        'max_squads' => 10,
        'session_days' => 7,
        /*
         * Named ground from the Kolguyev Conflict layer worlds/MP/CTI_Campaign_Cain.ent
         * (world metres, X east, Z north, read from the game data, rounded to 1 m).
         * Homes are where a side musters. Bases and objectives are Conflict bases;
         * relay/harbour rows are radio relays and landing points. Hamertin and Michurino
         * are town centres, not Conflict bases.
         */
        'places' => [
            ['id' => 'villa', 'name' => 'Villa', 'side' => 'us', 'kind' => 'home', 'x' => 1951, 'z' => 7401],
            ['id' => 'airport', 'name' => 'Airport', 'side' => 'us', 'kind' => 'base', 'x' => 2646, 'z' => 5367],
            ['id' => 'west-hq', 'name' => 'West HQ', 'side' => 'us', 'kind' => 'base', 'x' => 3662, 'z' => 7931],
            ['id' => 'kamensk', 'name' => 'Kamensk', 'side' => 'ussr', 'kind' => 'home', 'x' => 11867, 'z' => 10680],
            ['id' => 'military-hq', 'name' => 'Military HQ', 'side' => 'ussr', 'kind' => 'base', 'x' => 7618, 'z' => 4917],
            ['id' => 'east-coast', 'name' => 'East Coast HQ', 'side' => 'ussr', 'kind' => 'base', 'x' => 10542, 'z' => 9708],
            ['id' => 'ugoldar', 'name' => 'Ugoldar', 'side' => 'fia', 'kind' => 'home', 'x' => 6642, 'z' => 3907],
            ['id' => 'hamertin', 'name' => 'Hamertin', 'side' => 'fia', 'kind' => 'town', 'x' => 3318, 'z' => 6842],
            ['id' => 'michurino', 'name' => 'Michurino', 'side' => 'fia', 'kind' => 'town', 'x' => 3800, 'z' => 3719],
            ['id' => 'mineraly', 'name' => 'Mineraly', 'side' => null, 'kind' => 'objective', 'x' => 6322, 'z' => 8012],
            ['id' => 'gorshkovo', 'name' => 'Gorshkovo', 'side' => null, 'kind' => 'objective', 'x' => 8422, 'z' => 6592],
            ['id' => 'krasnyy-oktyabr', 'name' => 'Krasnyy Oktyabr', 'side' => null, 'kind' => 'objective', 'x' => 3674, 'z' => 5223],
            ['id' => 'northern-crossroad', 'name' => 'Northern Crossroad', 'side' => null, 'kind' => 'objective', 'x' => 8559, 'z' => 9376],
            ['id' => 'hq-ne', 'name' => 'North-east HQ', 'side' => null, 'kind' => 'base', 'x' => 7925, 'z' => 9595],
            ['id' => 'hq-sw', 'name' => 'South-west HQ', 'side' => null, 'kind' => 'base', 'x' => 4365, 'z' => 3220],
            ['id' => 'bars-relay', 'name' => 'BARS relay tower', 'side' => null, 'kind' => 'relay', 'x' => 6860, 'z' => 6781],
            ['id' => 'peak-relay', 'name' => 'Peak relay tower', 'side' => null, 'kind' => 'relay', 'x' => 5056, 'z' => 6328],
            ['id' => 'sosnovy-mys', 'name' => 'Sosnovy Mys', 'side' => null, 'kind' => 'harbour', 'x' => 4356, 'z' => 8144],
            ['id' => 'mussel-cove', 'name' => 'Mussel Cove', 'side' => null, 'kind' => 'harbour', 'x' => 6046, 'z' => 8444],
            ['id' => 'west-cape-dock', 'name' => 'West Cape dock', 'side' => null, 'kind' => 'harbour', 'x' => 1642, 'z' => 7386],
            ['id' => 'michurino-pier', 'name' => 'Michurino pier', 'side' => null, 'kind' => 'harbour', 'x' => 3743, 'z' => 3726],
            ['id' => 'ugoldar-harbour', 'name' => 'Ugoldar harbour', 'side' => null, 'kind' => 'harbour', 'x' => 6817, 'z' => 3492],
            ['id' => 'coal-harbour', 'name' => 'Coal harbour', 'side' => null, 'kind' => 'harbour', 'x' => 7554, 'z' => 4186],
            ['id' => 'military-harbour', 'name' => 'Military harbour', 'side' => null, 'kind' => 'harbour', 'x' => 8802, 'z' => 7395],
            ['id' => 'komsomolsk-pier', 'name' => 'Komsomolsk pier', 'side' => null, 'kind' => 'harbour', 'x' => 8583, 'z' => 10186],
            ['id' => 'twin-pier', 'name' => 'Twin pier', 'side' => null, 'kind' => 'harbour', 'x' => 10054, 'z' => 10860],
            ['id' => 'kamensk-harbour', 'name' => 'Kamensk harbour', 'side' => null, 'kind' => 'harbour', 'x' => 11838, 'z' => 10975],
            ['id' => 'rocky-bay', 'name' => 'Rocky Bay harbour', 'side' => null, 'kind' => 'harbour', 'x' => 12122, 'z' => 10520],
        ],
        'sides' => [
            'us' => [
                'name' => 'US',
                'detail' => 'BLUFOR. Home is Villa, on the west coast. The airfield is south of it.',
            ],
            'ussr' => [
                'name' => 'USSR',
                'detail' => 'OPFOR. Home is Kamensk, in the northeast. Military HQ sits inland.',
            ],
            'fia' => [
                'name' => 'FIA',
                'detail' => 'INDFOR. Home is Ugoldar, the southern works. Hamertin and Michurino are inland.',
            ],
        ],
        /*
         * How soldiers hold up. Health, bleeding, stamina, threat, and magazines come from the
         * game each tick; morale and battles are kept here. Per-minute rates.
         */
        'condition' => [
            'morale_base' => 60,          // plus 10 per training tier
            'morale_rest_per_min' => 3,   // safe within rest_radius of home
            'morale_safe_per_min' => 0.5, // safe elsewhere, up to the soldier's base
            'morale_fire_per_min' => -1,  // alerted or threatened
            'morale_wound_per_min' => -0.5, // health under 60
            'morale_loss' => 12,          // each squadmate killed
            'shaken_below' => 30,         // fights a skill level worse
            'steady_from' => 80,          // fights a little better
            'rest_radius' => 150,
            'low_mags' => 1,
        ],

        /*
         * HQ support. delay: seconds from the request until it happens. cooldown: seconds before
         * the commander may call that kind again (the battery reloads). danger_close: HQ will not
         * fire within that many metres of your side's soldiers.
         */
        'support' => [
            'smoke' => ['name' => 'Smoke screen', 'detail' => 'Mortar smoke on the mark. Covers a withdrawal or a pickup', 'cost' => 15, 'delay' => 60, 'cooldown' => 600],
            'illum' => ['name' => 'Illumination', 'detail' => 'A flare over the mark', 'cost' => 10, 'delay' => 45, 'cooldown' => 300],
            'fire_he_small' => ['name' => 'Mortar barrage', 'detail' => 'A short HE barrage on the mark', 'cost' => 45, 'delay' => 90, 'cooldown' => 1800, 'danger_close' => 150],
            'fire_he_medium' => ['name' => 'Heavy barrage', 'detail' => 'A long HE barrage over a wider area', 'cost' => 80, 'delay' => 120, 'cooldown' => 3600, 'danger_close' => 250],
        ],

        /*
         * HQ runs (RunWarRunsAction): an HQ driver and vehicle for one of your squads. supply: an
         * ammunition truck the squad rearms beside; ambulance: the wounded are driven to the
         * hospital; transport: a lift to a point. The squad must be within `reach` m of the
         * vehicle; it waits `wait` s for them. A run ends after `minutes`; a commander has at most
         * `per_commander` at once, `seats` men to a lift.
         */
        'runs' => [
            // How far walking wounded go to the ambulance.
            'walk' => 300,
            'cost' => ['supply' => 20, 'ambulance' => 25, 'transport' => 15],
            'reach' => 60,
            // A vehicle this close to where it is driving to is there (it cannot always reach the spot).
            'close' => 120,
            'wait' => 300,
            'minutes' => 30,
            'per_commander' => 4,
            'seats' => 12,
        ],

        /*
         * A living island: the sides no commander plays this week are run by an AI command
         * (RunWarAiAction), while at least one commander is in the war. It garrisons up to
         * `garrisons` of its bases and held points with `garrison_size` men, walks a patrol of
         * `patrol_size`, and every `counter_cooldown` s sends `counter_size` men to retake a
         * commander's point within `counter_radius` m of its ground. A wiped-out squad is not
         * replaced for `raise_cooldown` s. It keeps to `budget` men and leaves `headroom` of the
         * server's AI cap to commanders.
         */
        // A squad on normal readiness goes on alert when its side saw the enemy within this many
        // metres of it in the last alert_seen seconds.
        'alert_radius' => 500,
        'alert_seen' => 120,

        'pve' => [
            'enabled' => (bool) env('REFORGER_WAR_PVE', true),
            'budget' => 32,
            'headroom' => 64,
            'garrisons' => 3,
            'garrison_size' => 4,
            'patrol_size' => 4,
            'counter_size' => 6,
            'counter_radius' => 2500,
            'counter_cooldown' => 1800,
            'raise_cooldown' => 900,
            // The patrol hunts a sighting of the enemy its side made in the last sighting_age s
            // within hunt_radius of it, once every hunt_cooldown s.
            'sighting_age' => 120,
            'hunt_radius' => 1500,
            'hunt_cooldown' => 300,
            // A post under fire: the patrol runs over if it is within hunt_radius, else a squad
            // of qrf_size comes out from home; it holds the post qrf_hold s, then goes home.
            'qrf_size' => 4,
            'qrf_cooldown' => 600,
            'qrf_hold' => 900,
            // An attack of its own on the nearest point it does not hold, within raid_radius of
            // its ground, once every raid_cooldown s (a counterattack goes first).
            'raid_radius' => 3000,
            'raid_cooldown' => 1500,
            // A squad down to this share of the men it was raised with falls back home.
            'retreat_share' => 0.34,
            // A man stuck on the way home with no enemy squad this close is taken off the island.
            'clear_radius' => 800,
            // Attacks and reliefs farther than drive_from from home go out by truck and get out
            // drop_short short of the point.
            'drive_from' => 1500,
            'drop_short' => 500,
        ],

        /*
         * Logistics on the ground. Home is the field hospital and the arsenal: a squad within
         * `home_radius` hands its wounded over (they recover in the reserve) and rearms to
         * `magazines` spare magazines for free; a Go home order does both on arrival. A troop
         * truck or a carrier (`ammo_vehicles`) carries ammunition: a squad within `truck_radius`
         * of one rearms there for `truck_cost`. A squad rearms once every `rearm_cooldown` s.
         * A vehicle is refuelled and repaired the same way: at home, or beside another truck or
         * carrier of yours for `service_cost`.
         */
        'logistics' => [
            'home_radius' => 200,
            'magazines' => 6,
            'ammo_vehicles' => ['truck', 'apc'],
            'truck_radius' => 40,
            'truck_cost' => 10,
            'rearm_cooldown' => 120,
            // A vehicle is refuelled and repaired at home for free, beside a truck for this.
            'service_cost' => 15,
        ],

        /*
         * Special operations. `targets` are the place kinds one is aimed at: a mark within `snap` m
         * of one snaps to it. `required` refuses a mark with none in reach; `needs` is a role the
         * squad must have in the field. The page builds its help from these numbers
         * (App\Support\WarOperations).
         *   recon    the game's Scout (AIWaypoint_Scout): the squad goes there and watches the area
         *            for `hold` seconds; the game says when it is done. Then your side keeps that
         *            squad's contacts for `intel_minutes` instead of `contact_minutes`, and a scouted
         *            enemy-held point is interdicted.
         *   sabotage a relay tower: the squad's sapper, within 15 m of it for `plant` seconds, puts a
         *            real demolition charge in the game (M112 / TSh-400g) on a `fuze` s timer, and
         *            the squad falls back. Anyone may disarm it in time. If it goes off, every other
         *            side's contact picture goes dark for `blackout_minutes`, and the relay's holder
         *            loses its income.
         *   raid     walk in holding fire, stay within `radius` of a harbour unseen for `hold` s:
         *            your side takes `plunder` supplies, each enemy commander loses `enemy_loss`. A
         *            harbour can be raided once per side every `cooldown_hours`.
         */
        'operations' => [
            'recon' => ['label' => 'Scout', 'radius' => 20, 'hold' => 600, 'intel_minutes' => 30, 'targets' => ['base', 'objective', 'town', 'relay', 'harbour'], 'snap' => 150, 'required' => false],
            'sabotage' => ['label' => 'Sabotage', 'radius' => 15, 'hold' => 0, 'plant' => 20, 'fuze' => 45, 'blackout_minutes' => 60, 'targets' => ['relay'], 'snap' => 400, 'required' => true, 'needs' => 'sapper'],
            'raid' => ['label' => 'Raid', 'radius' => 75, 'hold' => 120, 'plunder' => 90, 'enemy_loss' => 15, 'cooldown_hours' => 6, 'targets' => ['harbour'], 'snap' => 400, 'required' => true],
        ],

        // How long an enemy contact a squad reported stays on its side's map.
        'contact_minutes' => 15,

        /*
         * Territory. Every place except a side's home is a control point. A side takes one by
         * keeping a squad within `radius` for `capture` seconds while nobody else is there and its
         * squads see no enemy within `clear` m. A held point pays every commander of that side
         * `income[kind]` supplies an hour on top of the base income, unless it is interdicted.
         */
        'territory' => [
            'radius' => 150,
            'clear' => 300,
            'capture' => 300,
            'income' => ['base' => 10, 'objective' => 12, 'town' => 8, 'relay' => 5, 'harbour' => 10],
            'interdict_hours' => 2,
        ],

        /*
         * HQ objectives: bounties that point commanders at the money. Each side always has `open`
         * of them; one not done in `expire_hours` lapses and HQ issues another.
         *   capture     take a control point your side does not hold (bounty, then its income)
         *   supply_run  a crewed vehicle loads cargo at a harbour and brings it home
         *               (bounty to the driver's commander, `share` to every commander of the side)
         *   interdict   a recon operation on an enemy-held point: it pays nobody for interdict_hours
         *   sabotage    a sabotage operation on a relay: the other sides go blind, its holder loses its income
         *   raid        a raid on a harbour: plunder for you, losses for every enemy commander
         */
        'objectives' => [
            'open' => 3,
            'expire_hours' => 6,
            'kinds' => [
                'capture' => ['reward' => 90, 'places' => ['base', 'objective', 'town', 'relay', 'harbour']],
                'supply_run' => ['reward' => 90, 'share' => 30, 'radius' => 60, 'places' => ['harbour']],
                'interdict' => ['reward' => 75, 'places' => ['base', 'objective', 'town', 'harbour', 'relay']],
                'sabotage' => ['reward' => 120, 'places' => ['relay']],
                'raid' => ['reward' => 60, 'places' => ['harbour']],
            ],
        ],

        /*
         * HQ detachments. Each commander has `budget` HQ soldiers on top of their own squads.
         * A task (hold a point, patrol between two) gets a squad HQ raises from that budget and
         * runs on its own: casualty evacuation when someone is hurt, rotation when the squad is
         * worn (morale under `rotate_morale` or fewer than half left), and replacements at home.
         * HQ evacuations for its own squads cost nothing; replacements cost a rifleman each.
         */
        'hq' => [
            'budget' => 8,
            'sizes' => [4, 6, 8],
            // HQ works for you on request and for a while: a task stands down after this many minutes.
            'durations' => [30, 60, 120],
            'rotate_morale' => 35,
        ],

        /*
         * Reserve at base. Evacuated soldiers recover before they can go back: minutes by how
         * badly they were hurt when they left. Morale climbs while they rest.
         */
        'reserve' => [
            'recover_minutes' => ['down' => 120, 'critical' => 90, 'wounded' => 45, 'fit' => 10],
            'morale_per_hour' => 15,
        ],

        /*
         * Motor pool, from each side's vanilla vehicle catalog
         * (Configs/EntityCatalog/{US,USSR,FIA}/Vehicles_EntityCatalog_*.conf). As in Conflict's
         * vehicle depot, a vehicle is drawn at the side's spawn, on free ground, and is nobody's
         * squad's: it is the commander's, and any of his squads boards it with the game's "get in"
         * command on that vehicle (AIWaypoint_GetIn), then drives on later moves. A commander
         * keeps at most `vehicle_limit` at once. Seats are read in game. Helicopters are left
         * out: AI pilots are not reliable in the vanilla behaviour trees.
         */
        'vehicle_limit' => 4,
        'vehicles' => [
            'jeep' => ['name' => 'Light utility', 'role' => 'Fast, two seats, no armour', 'cost' => 40, 'sides' => [
                'us' => ['model' => 'M151A2', 'prefab' => '{F649585ABB3706C4}Prefabs/Vehicles/Wheeled/M151A2/M151A2.et'],
                'ussr' => ['model' => 'UAZ-469', 'prefab' => '{259EE7B78C51B624}Prefabs/Vehicles/Wheeled/UAZ469/UAZ469.et'],
                'fia' => ['model' => 'UAZ-469', 'prefab' => '{F7E9AA0C813EABDA}Prefabs/Vehicles/Wheeled/UAZ469/UAZ469_FIA.et'],
            ]],
            'utility' => ['name' => 'Utility car', 'role' => 'Carries a fire team', 'cost' => 55, 'sides' => [
                'us' => ['model' => 'M998 HMMWV', 'prefab' => '{B55C6990A6A9411B}Prefabs/Vehicles/Wheeled/M998/M998_covered.et'],
                'ussr' => ['model' => 'UAZ-452', 'prefab' => '{1FBB492E86002BF5}Prefabs/Vehicles/Wheeled/UAZ452/UAZ452_transport.et'],
                'fia' => ['model' => 'S1203 van', 'prefab' => '{D6C8D37EA81A3985}Prefabs/Vehicles/Wheeled/S1203/S1203_transport_FIA.et'],
            ]],
            'jeep_mg' => ['name' => 'Gun jeep', 'role' => 'Mounted machine gun', 'cost' => 80, 'sides' => [
                'us' => ['model' => 'M151A2 M2HB', 'prefab' => '{F6B23D17D5067C11}Prefabs/Vehicles/Wheeled/M151A2/M151A2_M2HB.et'],
                'ussr' => ['model' => 'UAZ-469 PKM', 'prefab' => '{0B4DEA8078B78A9B}Prefabs/Vehicles/Wheeled/UAZ469/UAZ469_PKM.et'],
                'fia' => ['model' => 'UAZ-469 UK-59', 'prefab' => '{E72D78E7F45532EC}Prefabs/Vehicles/Wheeled/UAZ469/UAZ469_UK59_FIA.et'],
            ]],
            'truck' => ['name' => 'Troop truck', 'role' => 'Carries a full squad and its ammunition', 'cost' => 80, 'sides' => [
                'us' => ['model' => 'M923A1', 'prefab' => '{81FDAD5EB644CC3D}Prefabs/Vehicles/Wheeled/M923A1/M923A1_transport_covered.et'],
                'ussr' => ['model' => 'Ural-4320', 'prefab' => '{D9B91FAB817A6033}Prefabs/Vehicles/Wheeled/Ural4320/Ural4320_transport_covered.et'],
                'fia' => ['model' => 'Ural-4320', 'prefab' => '{B70E6D12A8EC2410}Prefabs/Vehicles/Wheeled/Ural4320/Ural4320_FIA_transport_covered.et'],
            ]],
            'ambulance' => ['name' => 'Ambulance', 'role' => 'Room for the wounded on the drive home', 'cost' => 70, 'sides' => [
                'us' => ['model' => 'M997', 'prefab' => '{00C9BBE426F7D459}Prefabs/Vehicles/Wheeled/M998/M997_maxi_ambulance.et'],
                'ussr' => ['model' => 'UAZ-452 ambulance', 'prefab' => '{43C4AF1EEBD001CE}Prefabs/Vehicles/Wheeled/UAZ452/UAZ452_ambulance.et'],
                'fia' => ['model' => 'UAZ-452 ambulance', 'prefab' => '{F0EC3EFF70B174B0}Prefabs/Vehicles/Wheeled/UAZ452/UAZ452_ambulance_FIA.et'],
            ]],
            'armed_car' => ['name' => 'Armoured car', 'role' => 'Armour and a heavy gun', 'cost' => 130, 'sides' => [
                'us' => ['model' => 'M1025 M2HB', 'prefab' => '{3EA6F47D95867114}Prefabs/Vehicles/Wheeled/M998/M1025_armed_M2HB.et'],
                'ussr' => ['model' => 'BRDM-2', 'prefab' => '{254289B9C09904AB}Prefabs/Vehicles/Wheeled/BRDM2/BRDM2.et'],
                'fia' => ['model' => 'BRDM-2', 'prefab' => '{442939C9617DF228}Prefabs/Vehicles/Wheeled/BRDM2/BRDM2_FIA.et'],
            ]],
            'apc' => ['name' => 'Armoured carrier', 'role' => 'Carries a squad and its ammunition under armour', 'cost' => 220, 'sides' => [
                'us' => ['model' => 'LAV-25', 'prefab' => '{0FBF8F010F81A4E5}Prefabs/Vehicles/Wheeled/LAV25/LAV25.et'],
                'ussr' => ['model' => 'BTR-70', 'prefab' => '{C012BB3488BEA0C2}Prefabs/Vehicles/Wheeled/BTR70/BTR70.et'],
                'fia' => ['model' => 'BTR-70', 'prefab' => '{B47110AA1A806556}Prefabs/Vehicles/Wheeled/BTR70/BTR70_FIA.et'],
            ]],
        ],

        'economy' => [
            // Balance (see docs/KOLGUYEV_WAR.md, Economy): a rifleman is half an hour of income, a
            // specialist about an hour; the cap holds a day's income, so a commander who looks in
            // once a day loses nothing; the start fields a proper first squad on the first visit.
            'starting_supplies' => 250,
            'income_per_hour' => 60,
            'supply_cap' => 1500,
            // Part of what a lost man cost comes back: killed in action, or sent home.
            'refund_killed' => 0.25,
            'refund_discharged' => 0.5,
            'max_strength' => 12,
            'max_tier' => 2,
            'stat_min' => 10,
            'stat_max' => 100,
            'point_cost' => 1,
            'service_cap' => 24,
            'names' => [
                'us' => ['Cole', 'Briggs', 'Hale', 'Dunn', 'Keller', 'Shaw', 'Brooks', 'Adler'],
                'ussr' => ['Orlov', 'Petrov', 'Sokolov', 'Morozov', 'Volkov', 'Lebedev', 'Kuznetsov', 'Smirnov'],
                'fia' => ['Ilic', 'Novak', 'Marku', 'Deda', 'Horvat', 'Kovac', 'Petrescu', 'Nagy'],
            ],
            // Drill: one man's aim, grit or fieldcraft, drill_step points a time, for drill_cost plus
            // drill_rank for every step he already had over his role's own.
            'drill_step' => 10,
            'drill_cost' => 20,
            'drill_rank' => 10,
            'train_costs' => [
                1 => 60,
                2 => 150,
            ],
            'tiers' => [
                0 => ['name' => 'Green'],
                1 => ['name' => 'Regular'],
                2 => ['name' => 'Veteran'],
            ],
            // Vanilla character catalog (US.conf / USSR.conf / FIA.conf). The value is the
            // weapon shown on the order. Clothing variants, vehicle crews, and unarmed bodies are left out.
            'roles' => [
                'rifleman' => ['name' => 'Rifleman', 'task' => 'Line', 'cost' => 30, 'min_cost' => 15, 'aim' => 40, 'grit' => 40, 'fieldcraft' => 40, 'sides' => ['us' => 'M16A2', 'ussr' => 'AK-74', 'fia' => 'Sa-58P']],
                'team_leader' => ['name' => 'Team leader', 'task' => 'Command', 'cost' => 40, 'min_cost' => 20, 'aim' => 50, 'grit' => 45, 'fieldcraft' => 45, 'sides' => ['us' => 'M16A2, 4x scope']],
                'squad_leader' => ['name' => 'Squad leader', 'task' => 'Command', 'cost' => 50, 'min_cost' => 25, 'aim' => 45, 'grit' => 55, 'fieldcraft' => 50, 'sides' => ['us' => 'M16A2, tracers', 'ussr' => 'AK-74, GP-25', 'fia' => 'Sa-58V']],
                'senior' => ['name' => 'Senior rifleman', 'task' => 'Line', 'cost' => 40, 'min_cost' => 20, 'aim' => 55, 'grit' => 40, 'fieldcraft' => 45, 'sides' => ['ussr' => 'AK-74N, 1P29']],
                'sergeant' => ['name' => 'Platoon sergeant', 'task' => 'Command', 'cost' => 50, 'min_cost' => 20, 'aim' => 45, 'grit' => 55, 'fieldcraft' => 40, 'sides' => ['us' => 'M16A2', 'ussr' => 'AK-74']],
                'platoon' => ['name' => 'Platoon leader', 'task' => 'Command', 'cost' => 60, 'min_cost' => 30, 'aim' => 40, 'grit' => 60, 'fieldcraft' => 50, 'sides' => ['us' => 'M16A2', 'ussr' => 'AK-74', 'fia' => 'Sa-58V']],
                'officer' => ['name' => 'Officer', 'task' => 'Command', 'cost' => 45, 'min_cost' => 20, 'aim' => 35, 'grit' => 50, 'fieldcraft' => 40, 'sides' => ['us' => 'M9', 'ussr' => 'PM']],
                'autorifle' => ['name' => 'Automatic rifleman', 'task' => 'Fire support', 'cost' => 50, 'min_cost' => 25, 'aim' => 35, 'grit' => 55, 'fieldcraft' => 30, 'sides' => ['us' => 'M249', 'ussr' => 'RPK-74']],
                'machinegun' => ['name' => 'Machine gunner', 'task' => 'Fire support', 'cost' => 65, 'min_cost' => 30, 'aim' => 40, 'grit' => 60, 'fieldcraft' => 25, 'sides' => ['us' => 'M60', 'ussr' => 'PKM', 'fia' => 'UK-59L']],
                'assistant_mg' => ['name' => 'Machine gunner assistant', 'task' => 'Fire support', 'cost' => 40, 'min_cost' => 20, 'aim' => 35, 'grit' => 45, 'fieldcraft' => 35, 'sides' => ['us' => 'M16A2, M60 belts', 'ussr' => 'AK-74, PK belts', 'fia' => 'Sa-58P, UK-59 belts']],
                'grenadier' => ['name' => 'Grenadier', 'task' => 'Fire support', 'cost' => 50, 'min_cost' => 20, 'aim' => 40, 'grit' => 45, 'fieldcraft' => 35, 'sides' => ['us' => 'M16A2, M203', 'ussr' => 'AK-74, GP-25']],
                'ammo' => ['name' => 'Ammunition bearer', 'task' => 'Fire support', 'cost' => 35, 'min_cost' => 15, 'aim' => 30, 'grit' => 40, 'fieldcraft' => 35, 'sides' => ['us' => 'M16A2, extra belts', 'ussr' => 'AK-74, extra belts', 'fia' => 'Sa-58P, extra belts']],
                'antitank' => ['name' => 'Anti-tank', 'task' => 'Anti-armor', 'cost' => 70, 'min_cost' => 35, 'aim' => 45, 'grit' => 50, 'fieldcraft' => 30, 'sides' => ['us' => 'M72A3 LAW', 'ussr' => 'RPG-7V1', 'fia' => 'RPG-7V1']],
                'assistant_at' => ['name' => 'Anti-tank assistant', 'task' => 'Anti-armor', 'cost' => 40, 'min_cost' => 20, 'aim' => 40, 'grit' => 45, 'fieldcraft' => 35, 'sides' => ['ussr' => 'AK-74, RPG rockets', 'fia' => 'Sa-58P, RPG rockets']],
                'marksman' => ['name' => 'Marksman', 'task' => 'Recon', 'cost' => 55, 'min_cost' => 30, 'aim' => 70, 'grit' => 35, 'fieldcraft' => 50, 'sides' => ['us' => 'M21 SWS', 'ussr' => 'SVD', 'fia' => 'SVD']],
                'spotter' => ['name' => 'Spotter', 'task' => 'Recon', 'cost' => 50, 'min_cost' => 25, 'aim' => 55, 'grit' => 40, 'fieldcraft' => 55, 'sides' => ['us' => 'M16 carbine, M203']],
                'scout' => ['name' => 'Scout', 'task' => 'Recon', 'cost' => 40, 'min_cost' => 20, 'aim' => 45, 'grit' => 35, 'fieldcraft' => 60, 'sides' => ['us' => 'M16A2', 'ussr' => 'AK-74', 'fia' => 'Sa-58V']],
                'scout_radio' => ['name' => 'Scout radio operator', 'task' => 'Recon', 'cost' => 50, 'min_cost' => 20, 'aim' => 40, 'grit' => 40, 'fieldcraft' => 55, 'sides' => ['us' => 'M16A2, AN/PRC-77', 'ussr' => 'AK-74, R-148']],
                'medic' => ['name' => 'Medic', 'task' => 'Support', 'cost' => 45, 'min_cost' => 20, 'aim' => 30, 'grit' => 50, 'fieldcraft' => 45, 'sides' => ['us' => 'M16A2', 'ussr' => 'AK-74', 'fia' => 'Sa-58V']],
                'radioman' => ['name' => 'Combat signaler', 'task' => 'Support', 'cost' => 40, 'min_cost' => 20, 'aim' => 35, 'grit' => 40, 'fieldcraft' => 45, 'sides' => ['us' => 'M16A2, AN/PRC-77', 'ussr' => 'AK-74, R-148', 'fia' => 'Sa-58P']],
                'sapper' => ['name' => 'Sapper', 'task' => 'Support', 'cost' => 50, 'min_cost' => 25, 'aim' => 35, 'grit' => 45, 'fieldcraft' => 50, 'sides' => ['us' => 'M16A2, M112', 'ussr' => 'AK-74, demo', 'fia' => 'Sa-58P, demo']],
                'sf' => ['name' => 'Special forces', 'task' => 'Special forces', 'cost' => 80, 'min_cost' => 40, 'aim' => 55, 'grit' => 55, 'fieldcraft' => 55, 'sides' => ['us' => 'M16 carbine, red dot', 'ussr' => 'AK-74N']],
                'sf_officer' => ['name' => 'Special forces officer', 'task' => 'Special forces', 'cost' => 75, 'min_cost' => 40, 'aim' => 45, 'grit' => 60, 'fieldcraft' => 50, 'sides' => ['us' => 'M16 carbine, M9', 'ussr' => 'AKS-74UN']],
                'sf_leader' => ['name' => 'Special forces squad leader', 'task' => 'Special forces', 'cost' => 90, 'min_cost' => 45, 'aim' => 50, 'grit' => 65, 'fieldcraft' => 55, 'sides' => ['us' => 'M16 carbine, M203', 'ussr' => 'AK-74N, GP-25']],
                'sf_grenadier' => ['name' => 'Special forces grenadier', 'task' => 'Special forces', 'cost' => 85, 'min_cost' => 40, 'aim' => 50, 'grit' => 55, 'fieldcraft' => 45, 'sides' => ['us' => 'M16 carbine, M203', 'ussr' => 'AK-74N, GP-25']],
                'sf_machinegun' => ['name' => 'Special forces machine gunner', 'task' => 'Special forces', 'cost' => 95, 'min_cost' => 45, 'aim' => 45, 'grit' => 70, 'fieldcraft' => 40, 'sides' => ['us' => 'M249', 'ussr' => 'RPK-74N']],
                'sf_medic' => ['name' => 'Special forces medic', 'task' => 'Special forces', 'cost' => 80, 'min_cost' => 40, 'aim' => 40, 'grit' => 60, 'fieldcraft' => 55, 'sides' => ['us' => 'M16 carbine', 'ussr' => 'AKS-74UN']],
                'sf_radio' => ['name' => 'Special forces radio operator', 'task' => 'Special forces', 'cost' => 80, 'min_cost' => 40, 'aim' => 45, 'grit' => 50, 'fieldcraft' => 55, 'sides' => ['us' => 'M16 carbine, AN/PRC-77', 'ussr' => 'AKS-74UN']],
                'sf_marksman' => ['name' => 'Special forces sharpshooter', 'task' => 'Special forces', 'cost' => 100, 'min_cost' => 50, 'aim' => 80, 'grit' => 45, 'fieldcraft' => 60, 'sides' => ['us' => 'M16A2, 4x scope', 'ussr' => 'SVD']],
                'sf_sapper' => ['name' => 'Special forces sapper', 'task' => 'Special forces', 'cost' => 90, 'min_cost' => 45, 'aim' => 45, 'grit' => 55, 'fieldcraft' => 60, 'sides' => ['us' => 'M16 carbine, LAW', 'ussr' => 'AKS-74UN']],
                'sf_recon' => ['name' => 'Special forces recon', 'task' => 'Suppressed', 'cost' => 105, 'min_cost' => 50, 'aim' => 60, 'grit' => 55, 'fieldcraft' => 70, 'sides' => ['us' => 'M16 carbine, suppressor', 'ussr' => 'AK-74N, PBS-4']],
                'sf_recon_leader' => ['name' => 'Special forces recon leader', 'task' => 'Suppressed', 'cost' => 110, 'min_cost' => 50, 'aim' => 55, 'grit' => 65, 'fieldcraft' => 70, 'sides' => ['us' => 'M16 carbine, suppressor', 'ussr' => 'AK-74N, PBS-4']],
                'sf_recon_grenadier' => ['name' => 'Special forces recon grenadier', 'task' => 'Suppressed', 'cost' => 105, 'min_cost' => 50, 'aim' => 55, 'grit' => 55, 'fieldcraft' => 65, 'sides' => ['us' => 'M16A2, M203, suppressor', 'ussr' => 'AK-74N, GP-25, PBS-4']],
                'sf_recon_medic' => ['name' => 'Special forces recon medic', 'task' => 'Suppressed', 'cost' => 100, 'min_cost' => 50, 'aim' => 45, 'grit' => 60, 'fieldcraft' => 70, 'sides' => ['us' => 'M16 carbine, suppressor', 'ussr' => 'AKS-74UN, PBS-4']],
                'sf_recon_radio' => ['name' => 'Special forces recon radio operator', 'task' => 'Suppressed', 'cost' => 100, 'min_cost' => 50, 'aim' => 50, 'grit' => 50, 'fieldcraft' => 70, 'sides' => ['us' => 'M16 carbine, suppressor', 'ussr' => 'AKS-74UN, PBS-4']],
                'sf_recon_marksman' => ['name' => 'Special forces recon sharpshooter', 'task' => 'Suppressed', 'cost' => 110, 'min_cost' => 55, 'aim' => 85, 'grit' => 45, 'fieldcraft' => 75, 'sides' => ['us' => 'M16A2, scope, suppressor', 'ussr' => 'AK-74N, 1P29, PBS-4']],
                'sf_recon_sapper' => ['name' => 'Special forces recon sapper', 'task' => 'Suppressed', 'cost' => 110, 'min_cost' => 50, 'aim' => 50, 'grit' => 55, 'fieldcraft' => 70, 'sides' => ['us' => 'M16 carbine, LAW, suppressor', 'ussr' => 'AKS-74UN, PBS-4']],
                'plain' => ['name' => 'Guerrilla', 'task' => 'Plain clothes', 'cost' => 25, 'min_cost' => 10, 'aim' => 25, 'grit' => 35, 'fieldcraft' => 55, 'sides' => ['fia' => 'PM']],
                'plain_grenadier' => ['name' => 'Guerrilla grenadier', 'task' => 'Plain clothes', 'cost' => 30, 'min_cost' => 15, 'aim' => 30, 'grit' => 35, 'fieldcraft' => 50, 'sides' => ['fia' => 'PM']],
                'plain_medic' => ['name' => 'Guerrilla medic', 'task' => 'Plain clothes', 'cost' => 35, 'min_cost' => 15, 'aim' => 25, 'grit' => 50, 'fieldcraft' => 55, 'sides' => ['fia' => 'PM']],
                'plain_spotter' => ['name' => 'Guerrilla spotter', 'task' => 'Plain clothes', 'cost' => 30, 'min_cost' => 15, 'aim' => 35, 'grit' => 30, 'fieldcraft' => 60, 'sides' => ['fia' => 'PM']],
            ],
        ],
    ],

    'notes' => [
        'Client is the current Arma Reforger Steam build (stable, not Experimental).',
        'Find the server in Multiplayer, or Direct Connect to the IP and UDP port above.',
        'Deploy as the US. The USSR side is AI.',
        'The war is shared. Captured bases stay captured when you come back.',
        'BattlEye is on. A blocked BattlEye client cannot join.',
    ],

];
