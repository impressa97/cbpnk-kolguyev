# Kolguyev war

Continuous three-side war on the Arma Reforger dedicated server. A war lasts seven real days. The headquarters is one local commander. They lead AI squads of named soldiers from the desk, and may also deploy in the client on that same side. Commanders run the fighting and the operations themselves. HQ is the backbone: support on call, squads of its own to secure ground, objectives with bounties, and the motor pool. When the week ends, the roster stays as a record and the island stands down. The next week starts when someone enlists again.

Launch the headquarters, the packed addon, and the dedicated server together: [README](../README.md), with server detail in [REFORGER.md](REFORGER.md). The server runs vanilla Game Master Kolguyev plus this addon. Do not add another scenario's AI on the same server: those soldiers and the commanders' squads share the AI budget.

| Piece | Path |
|-------|------|
| Page | `/` command panel (`hq/resources/js/pages/reforger.tsx`) |
| Enlist / order / roster | `POST /reforger/war/enlist`, `/reforger/war/orders`, `/reforger/war/upgrade` |
| HQ | `POST /reforger/war/support` (+ `/{id}/cancel`), `/reforger/war/tasks` (+ `/{id}/cancel`), `/reforger/war/vehicles` (+ `/{id}/scrap`) |
| Mod bridge | `GET /api/reforger/war/orders`, `POST /api/reforger/war/state` |
| Secret | `REFORGER_BRIDGE_SECRET`, header `X-Reforger-Bridge-Secret` |
| Host sync | `bridge/war-bridge.sh` |
| Mod source | `mod/` (`addon.gproj`, `Scripts/Game/War/*.c`) |
| Tests | `cd hq && php artisan test --filter=ReforgerWar` |

## Status

The addon packs with `scripts/pack-war-mod.ps1` and the server loads it from `server/profile/localaddons`. Launch steps are in the [README](../README.md).

Still to confirm in a client: spawn points in the deploy menu, boarding and driving, fire missions, `FullHeal` at home and beside an ambulance, the `WORLD` line, and locking the client slot to the enlisted side. The headquarters does not lock the slot yet.

```bash
cd hq
php artisan test --filter=ReforgerWar
vendor/bin/pint --dirty
```

War rows belong to `users.id` (`commander_id`).

### Code map

| Concern | Path |
|---------|------|
| All numbers | `hq/config/reforger.php` → `war` (`places`, `condition`, `vehicles`, `support`, `reserve`, `operations`, `territory`, `objectives`, `hq`, `economy`) |
| Prices, catalogs | `hq/app/Support/WarEconomy.php` |
| Morale and flags | `hq/app/Support/WarCondition.php` |
| Control points and income | `hq/app/Support/WarTerritory.php`, `ControlWarTerritoryAction` |
| Mod tick → site | `IngestWarStateAction` → `ResolveWarSupportAction`, `ResolveWarOperationsAction`, `RunWarHqAction`, `ControlWarTerritoryAction`, `RunWarObjectivesAction` |
| Site → mod | `ExportWarOrdersAction`, `hq/app/Support/WarBridgeCodec.php` |
| Orders and operations | `IssueWarMoveOrderAction`, `ResolveWarOperationsAction` |
| HQ desk | `RequestWarSupportAction`, `ManageWarTaskAction`, `OrderWarVehicleAction`, `ReforgerWarHqController` |
| Page data (fog of war) | `BuildWarCommandDataAction` |
| Page | `hq/resources/js/features/reforger-war/` (`war-command`, `hq-header`, `hq-desk`, `orders-panel`, `army-panel`, `kolguyev-map`, `map/*`, `lib/use-war-live`) |
| Desk refresh | The page polls `GET /desk`. `BuildWarCommandDataAction` builds that payload. |
| Contract | `hq/resources/js/lib/schemas/reforger.ts` |
| Map labels from the game | `hq/resources/js/lib/generated/kolguyev-locations.ts` ← `scripts/extract-kolguyev-locations.py "<Reforger install>"` |
| Mod | `CBPNK_WarBridge.c` (core), `CBPNK_WarCondition.c`, `CBPNK_WarMotorPool.c`, `CBPNK_WarSupport.c`, `CBPNK_WarWorld.c` |

## Game

### Army

- Sides: US (BLUFOR), USSR (OPFOR), FIA (INDFOR). A side sticks for the week. The week starts when the first player enlists and ends at `ends_at`, seven real days later. `WEEK 0` stands the island down.
- One commander starts with one free squad of 4 named riflemen and may raise three more at 160 each. Each musters 200 m further from home toward the island centre. A squad stops at 12.
- Each soldier is ordered by name, with a private note and a role from that side's vanilla infantry catalog, at that role's price. Hours on the island raise every stat by 1, up to 24. Training into another role costs that role's price and keeps the hours. Squad training: Green → Regular (80) → Veteran (200).
- Supplies start at 80 and earn 40 an hour plus territory income, collected on the next visit; passive income stops at 400, bounties do not. They are the war treasury.
- New men, replacements, and returns from the reserve appear at the side's spawn on free ground, as a player respawns at a base, and walk to the group; nobody materialises beside a squad in the field.
- The server AI cap (`REFORGER_AI_LIMIT`, 192) counts soldiers in the field. The reserve at base is off the island and off the cap.

### How soldiers hold up

- From the game, every tick: health, bleeding, unconscious, stamina, the AI threat state (calm, watchful, alerted, under fire), spare magazines for the weapon in hand, aboard a vehicle or not.
- Kept on the site: **morale** and **fights survived**. Morale settles at 60 + 10 per tier; it climbs fast resting calm at home, drops under fire and with wounds, and every squadmate killed costs everyone left 12. Morale is a site rating only: it never changes how the game plays the soldier (his AI skill is what he was trained to). Under 30 he is **shaken**, from 80 **steady**; HQ pulls a worn-out task squad back.
- A calm minute at home, or beside the squad's own ambulance, and the mod treats every wounded body (`FullHeal`).
- A loss refunds nothing. `killed` comes only from the mod; a commander's dismissal is `discharged`. The memorial row stays.

### Economy

The numbers live in `war.economy`, `war.support`, `war.vehicles`, `war.territory` and `war.objectives`. They are set against one yardstick, the base income of 60 supplies an hour:

| | Supplies | Hours of base income |
|---|---|---|
| Rifleman | 30 | 0.5 |
| Specialist (medic 45, sapper 55, MG 65, AT 70) | 40–70 | 0.7–1.2 |
| Special forces | 75–110 | 1.3–1.8 |
| A full squad of 12 riflemen (8 more than the free 4) | 240 | 4 |
| Training a squad (Regular / Veteran) | 60 / 150 | 1 / 2.5 |
| Rearm at a truck (free at home) | 10 | 0.2 |
| Mortar / heavy barrage | 45 / 80 | 0.75 / 1.3 |
| Truck / armoured car / APC | 80 / 130 / 220 | 1.3 / 2.2 / 3.7 |

- **Start:** 250 supplies plus four free riflemen. On the first visit that buys a machine gunner, a medic and two riflemen (65 + 45 + 60 = 170), leaving a reserve for a vehicle or a barrage.
- **Income:** 60 an hour, plus held ground (base 10, objective 12, town 8, relay 5, harbour 10 an hour for every commander of the side). A side holding five points earns about 100 an hour each.
- **Cap:** 1500, a day's income. A commander who looks in once a day loses nothing; one away for longer stops earning until he spends.
- **Losses:** a man killed in action returns a quarter of what he cost; a man sent home returns half. The four free riflemen return nothing. HQ's men are HQ's.
- **Bounties** are 1–2 hours of income (capture 90, supply run 90 and 30 to every other commander, scouting 75, sabotage 120, raid 60), so chasing HQ's objectives pays about as well as holding ground. A raid takes 90 for you and 15 from each enemy commander.
- **Over a week:** 168 h × 60 = about 10,000 supplies from income alone. That is far more men than the server's AI cap (192 for everyone) allows on the island at once, so the real limit is the cap and how fast men die, not money.

### Squads

Every commander has ten squads (`max_squads`) from the day he enlists: the first with four riflemen, the other nine empty. Squads cost nothing; men do (recruit or order a soldier into any squad). Each squad has a callsign (Alpha … Juliet) until the commander names it (`POST /reforger/war/squads/{id}/name`, empty restores the callsign). A man moves between your squads from his menu in the roster or his card in the order console (`transfer`); an empty squad takes him too. Only squads with someone on the island go to the game: an empty one is a name on the page. New men appear at your spawn and make their way to their squad.

HQ detachments are not yours to command. You ask HQ for one (secure a point, patrol a stretch of your rear) for a while (`hq.durations`: 30, 60 or 120 minutes); HQ raises it from its own budget, runs it on its own (treats, rotates, refills) and calls it in when the time is up. You see where it is, how many men are out, what it is doing and how long it has left, not its men.

### Orders (your squads only)

The game is the authority: the site gives orders in the game's own terms and shows what the game reports back. Orders are the vanilla commanding menu (`Configs/Commanding/Commands.conf`), issued by the mod with the same prefabs, completion radii, and forcing, to the **whole squad** (in vanilla the squad leader commands his AI as one group):

| Order | In game | Done when |
|-------|---------|-----------|
| move | `AIWaypoint_Move`, 5 m (driven once the squad is aboard a vehicle) | the game completes the waypoint |
| rush (`move_forced`) | the same, player priority: does not stop to fight | ditto |
| defend | `AIWaypoint_Defend`, 15 m, forced | never: when the game lets the waypoint go, the site gives it again |
| search | `AIWaypoint_SearchAndDestroy`, 100 m, forced | the game completes it |
| suppress | `AIWaypoint_Suppress_Commanding`, guns free | ditto |
| treat (`heal`) | `AIWaypoint_Heal` on one soldier: the squad's medic goes to him (needs a medic on his feet) | ditto |
| board / dismount | `AIWaypoint_GetIn` on one of the commander's vehicles, named in the order (the game's "get in" on that vehicle) / `AIWaypoint_GetOut`, forced, and the game's dismount message to every man of the squad still aboard (so part of a squad in a vehicle gets out too) | everyone in / out |
| halt | the group's waypoints cleared | at once |
| home (`rest`) | a move home | arrival |
| scout (`recon`) | `AIWaypoint_Scout`: go there holding fire and watch the area for `hold` s | the game completes it |
| sabotage | a forced move to the relay, then the sapper plants a real demolition charge (see operations) | the charge goes off (or is disarmed: failed) |
| raid | a move under hold fire | see operations |
| patrol | two `AIWaypoint_Patrol` in an `AIWaypoint_Cycle` between two marks | never |
| move / rush along a route | one `AIWaypoint_Move` per point, up to 7, queued in order | the last waypoint completes |
| follow | `AIWaypoint_Follow` on another of your squads' leader (`SCR_FollowGroupCommand`) | never; replaced by the next order |
| attack | `AIWaypoint_Attack` on the enemy the group perceives nearest a known contact; a group that does not see him searches and destroys there | the game completes it |
| smoke | `SCR_DeploySmokeCoverWaypoint` put first: men with smoke throw it between the squad and the enemies it perceives (at most half throw); the order under way goes on | at once |

A new order replaces the squad's open one and its chain, as a vanilla command overrides the previous ones. Completion is the game's own: a finished waypoint leaves the group's list (`AIGroup.CompleteWaypoint`).

**Chains.** An order sent with `queue` waits behind the squad's open ones (status `queued`, not exported) and goes to the game once the game completes the one before it (`AdvanceWarOrdersAction`, after every bridge tick). A failed order stops the rest of its chain; nothing can wait behind a defend, a patrol or a follow (they never end), and at most 6 wait. A whole chain can go in one request (`then`: the orders after the first). A waiting order can be taken out (`POST /reforger/war/orders/{id}/cancel`). The console uses this for **riding**: a move, a rush or the way home "by car" is board that vehicle → drive → get out. The game calls a board done when the leader is in (and a dismount when he is out), so the order after one waits until every man of the squad is aboard (or out), at most 150 s (`AdvanceWarOrdersAction::SETTLE_SECONDS`). The console warns when the vehicle has fewer free seats than the squad has men: the rest would stay on foot.

**Crew and dismounts.** The game reports each man's seat (driver, gunner, passenger). A squad in a vehicle shows its crew and riders; **Unload as a new squad** (`POST /reforger/war/squads/{id}/split`, `part: passengers`, `unload`) moves the riders into the commander's first empty squad, where they are, and tells that squad to get out; the crew keeps the vehicle and its gun.

**On the page** the desk runs like a real-time strategy game (`war-command.tsx`, `command-panel.tsx`). The map points, the command panel at the top of the right column decides. It has two modes, **Squad orders** and **HQ fire**:

- **Squad orders.** Pick a squad: a row in the panel (it flies the map there; the chevron unfolds its men, who can be dragged onto another squad's row), keys 1–0, or the squad on the map. Pick an order in the palette (greyed with the reason when it cannot be given, the same checks as the server: `lib/orders.ts`). Click the map to mark; send from the panel, or **Then** to chain it. A move, rush or Home can go **by car** (board, drive, get out). The squad's chain (a waiting order has an ✕), logistics (home or a truck in reach: hospital and rearm; otherwise how far home is), **Men** (recruit by role in one tap), combat mode and formation sit with it.
- **HQ fire**: no squad needed. Pick a mission (smoke, illumination, mortar, heavy barrage), mark where it lands, press **Call fire**. The danger-close ring follows the pointer and HQ's refusals (reloading, money, your own men within reach) show before the call. Missions on their way are listed with a cancel.
- **Nothing on the map sends anything.** A click marks (it does not change the picked order; your vehicle is the one to board only with Board picked); a right click and a long press do nothing. Orders and fire go only from the panel's buttons, and a man is bought in two steps (pick the role, then send him). A drawn-up order is amber on the map and labelled *not sent* (fire: *not called*), with **Not sent** by the send button; a sent one takes the side's colour and says *sent*, then *under way*. With a finger, marks are not dragged (a second tap moves one) so the map always pans, and a tap on a man picks his squad.
- On a phone the panel sits over the map's bottom edge (the map fills the screen), the squad's settings fold behind **Squad**, and the squad list is a tab. An HQ objective's **Go** sets up the order that completes it (capture: defend the point; interdiction: recon; sabotage; raid; supply run: move, then home once loaded).

- **Formation and pace** are squad settings too: the game's group AI settings of origin `COMMANDING` (`SCR_AIGroupFormationSetting`: wedge, line, column, staggered column; `SCR_AIGroupCharactersMovementSpeedSetting`: walk, run, sprint). Auto removes ours and leaves them to the AI.
- **Combat mode** is a squad setting, not an order: hold fire, return fire, fire at will (`SCR_AIGroupUtilityComponent.SetCombatMode`). The game reports the mode the AI is **actually** in, which it evaluates itself (return fire reads as hold fire until they are shot at).
- **One soldier**: his stance (auto, stand, crouch, prone) and pace (auto, walk, run, sprint) are the game's AI character settings (`SCR_AICharacterStanceSetting`, `SCR_AICharacterMovementSpeedSetting`, origin `COMMANDING`); a stance holds up to combat, a pace outside it. Treatment by the squad's medic. A transfer to another of your squads moves him between groups like the game's recruit/remove commands.
- **What the game reports**: each squad's current order (`doing`: the order whose waypoint the group is on, `idle`, or `own` when the game gave it one) and actual combat mode; each soldier's position, seat (driver, gunner, passenger), stance, and the AI action he is on (`AIBaseUtilityComponent.GetCurrentAction`: fighting, in formation, boarding, treating, ...).
- Wounds are the game's: medics and bandages. Nothing on the island heals anyone by itself, and nobody is flown out.

### Logistics

Nothing is flown in or out; a squad gets what it needs where it is (`SupplyWarSquadAction`, `war.logistics`):

- **Home is the field hospital and the arsenal.** A squad within 200 m of its side's home hands its wounded (down, bleeding, or under 60 health) to the hospital: they leave the island for the reserve and recover there (unconscious 120 min, critical 90, wounded 45). It rearms for free: every man to six spare magazines (the mod's `SUPPLY` line). A **Home** order does both when the game says they are there; the console has the buttons for a squad that got home some other way.
- **A troop truck or an armoured carrier is a moving arsenal.** A squad within 40 m of one of yours rearms for 10 supplies. A squad rearms at most once every 2 minutes.
- Getting the wounded home is a drive: board a vehicle (the ambulance has room for them), Home by car. The squad's medic treats men in the field (the game's heal command).

### Operations

The squad walks in holding fire. Within the radius and with nobody alerted or under fire, the clock starts; being spotted resets it. Each operation's rules live in `war.operations` (`App\Support\WarOperations`): the place kinds it is aimed at (`targets`), how far a mark snaps onto one (`snap`), whether it needs one (`required`), a role it needs (`needs`), and its numbers. The page's help is built from the same config, and while one is being aimed the map rings every place it can hit. Defaults:

- **Scout** (the game's `AIWaypoint_Scout`, 10 min): the squad goes there holding fire and watches the area; the game says when it is done. What it sees is on your map anyway (group perception); afterwards your side keeps that squad's contacts for 30 minutes instead of `contact_minutes` (15). A mark within 150 m of a control point snaps onto it; if that point is enemy-held, scouting it **interdicts** it: it pays its holder nothing for 2 hours.
- **Sabotage** a relay tower (needs a sapper): the squad goes to the tower; once the sapper has been within 15 m of it for `plant` s (20), the mod puts a real demolition charge in the game at his feet (M112 for the US, TSh-400g for the USSR and FIA), armed with a `fuze` s (45) timed fuze the way the Game Master arms one, and pulls the squad back 120 m. Anyone on the server can disarm it in time (the game's own disarm action): then the sabotage fails. If it goes off, every other side's contact picture goes dark for 60 minutes and the relay's holder loses its income for 2 hours. The mod reports `SABOTAGE <order> approach|planting|armed|detonated|disarmed <s>`.
- **Raid** a harbour (75 m, 2 min): +60 supplies for you, every enemy commander loses 20. Once per harbour per side every 6 hours.

### Territory

Every place except a side's home is a control point. A side takes one by keeping a squad within 150 m for 5 minutes while no other side has a squad there and its squads see no enemy within 300 m. The holder keeps it until someone else does the same. Each held point pays every commander of its side per hour: base 8, objective 10, town 6, relay 4, harbour 8. Flags are public.

### HQ

HQ does supporting work only. It never attacks and never runs operations.

- **Objectives** (3 open per side, 6 hours each), bounties paid to the commander whose squad completes them:
  - *Take* a point your side does not hold — bounty 60, then its hourly income.
  - *Supply run* — a crewed vehicle loads at a harbour (your side's, or any if you hold none) and brings it home. 60 to the driver's commander, 20 to every other commander of the side.
  - *Interdict* an enemy-held point with a recon — 50, and the enemy loses that income for 2 hours.
  - *Sabotage* a relay — 80.
  - *Raid* a harbour — 40 on top of the plunder.
- **Fire on call** (supplies, delay, battery cooldown): smoke 15 (1 min), illumination 10, mortar barrage 45 (30 min cooldown), heavy barrage 80 (60 min). HQ will not fire within 150/250 m of your side's soldiers. A mission that has not landed yet can be cancelled for a full refund. HQ flies nobody out and drops nothing (see Logistics).
- **Reserve**: men in the hospital recover before they can go back, morale climbing 15 an hour. Sent back (the roster's **Send back**), they appear at the side's spawn, like every new man, and make their own way to the squad.
- **Standing tasks**: secure a point or patrol a route of your own rear with a squad of 4, 6, or 8 from HQ's budget of 8 soldiers, for 30, 60 or 120 minutes. HQ raises it at home with a leader, a gunner, and a medic and sends it; its medic treats the wounded; when it is worn (morale under 35 or fewer than half left) it walks home, where the whole squad rests in the reserve; HQ refills losses at a rifleman's price each and sends it back. Standing a task down brings the squad home.
- **Motor pool**: as at a Conflict vehicle depot, a vehicle is delivered at the side's spawn on free ground (`SCR_WorldTools.FindEmptyTerrainPosition`) and belongs to the commander, not to a squad; any of his squads boards it by name (tap it on the map). Up to `vehicle_limit` (4) at once, from the side's vanilla catalog: light utility 60, utility car 80, gun jeep 110, troop truck 120, ambulance 100, armoured car 180, armoured carrier 300. Seats and crew are read in game. A wreck is a loss; sending a vehicle back refunds nothing. Helicopters are left out: AI pilots are not reliable in the vanilla behaviour trees.

### HQ runs

HQ lends a driver and a vehicle for one of your squads (`RunWarRunsAction`, `war.runs`); every step is the game's own (get in, drive, get out), and the vehicle is HQ's: it does not count toward your limit and you cannot board it yourself. At most 2 runs at once; a run is over after 30 minutes.

- **Ammunition truck** (20): drives to the squad; the squad rearms once it is within 60 m of the truck, then the truck drives home.
- **Ambulance** (25): drives to the squad; its wounded within reach join the driver's group and board, are driven home, and the hospital takes them (back on their own squad's roll, recovering).
- **Lift** (15, "By HQ truck" in the console's ride select for a move): the truck comes to the squad, the squad boards, is driven to the mark, gets out (told again if someone stays in), and the truck drives home. Cheaper than a vehicle of your own.

A truck within 120 m of where it is driving to counts as there (it cannot always reach a spot in a wood or a yard): the driver halts. A squad that does not come to the vehicle within 5 minutes is left; a run whose vehicle burns or whose driver falls is aborted. The console shows the run under way with the squad.

### Living island (AI command)

The sides no commander plays this week are run by an AI command (`RunWarAiAction`, `war.pve`), as long as at least one commander is in the war. Its squads, men and orders are ordinary war rows with no commander (`ai`, `commander_id` null), so the mod fields them like any other and kills, losses and territory work the same. Every bridge tick it:

- **Garrisons** up to 3 of its own places (bases, its towns, points it holds), nearest its home first, with 4 men on a defend.
- **Patrols** between two of its places with 4 men.
- **Counterattacks** (every 30 minutes at most) the commanders' point nearest its ground within 2.5 km: 6 men rush to a line 150 m short, then search and destroy there, and hold the point if they take it. The commanders of the side that holds it hear it on the radio log and get a warning toast.
- Replaces a wiped-out squad only after 15 minutes, keeps to 24 men, and always leaves 64 of the server's AI cap to commanders.

A side a commander enlists on gets no new AI squads; the ones out there carry on until they fall. Off with `REFORGER_WAR_PVE=false` (the tests run with it off).

### Fog of war and the map

- A commander sees their side's squads, soldiers, and vehicles. Enemies show only as contacts their side's squads report (seen or heard in the last 15 minutes, merged within 60 m). Another side is a squad count and a "put a squad on the island" log line. Someone not enlisted sees no squads.
- The header shows island time, day or night, weather, rain, fog, wind, sunrise and sunset from the server's `TimeAndWeatherManagerEntity`.
- Map is Kolguyev, 12.8 km × 12.8 km, metres from the south-west corner, X east, Z north, on MapLibre. Everything is built from the game files by `scripts/build-kolguyev-map.py "<Reforger install>"` into `hq/public/geodata/kolguyev/` (no third-party assets): a soft raster (land, sea depth, hillshade, forest tint; z0–5 on the Web Mercator grid, so metres per pixel are exact) and vectors that stay sharp at any zoom — `roads.geojson` (class, true width, junction links), `buildings.geojson`, `terrain.geojson` (10 m contours, 50 m major, coastline), `landcover.geojson` (woods traced from the 478k single trees in the topo's AREA pool; ponds). `Cain.topo` stores Z growing southward; the builder flips it (checked: every road on dry ground, every named place at its game elevation). Grid references are the in-game three digits east, three north. Labels: the 71 named locations from `Cain.ent`.
- Each soldier is drawn where the mod last saw him (`x z` on his `SOLDIER ok` line), with his name and state beside him (health, bleeding, unconscious, alert or under fire, aboard); until the mod reports him he stands at his side's spawn, where the game puts every new man and every return from the reserve. Waypoints of every order under way are lines from whoever carries it out (squad or one man) to a labelled mark; HQ's held points and patrol beats are shown too.
- Homes from the Conflict layer: US **Villa** `1951, 7401`, FIA **Ugoldar** `6642, 3907`, USSR **Kamensk** `11867, 10680`, all on land. Conflict picks HQs at random from HQ_W, HQ_SW, HQ_NE, HQ_EastCoast, and Base_MilitaryHQ, so there is no fixed vanilla home to copy. Each side's vanilla spawn point is placed at its home.
- The picker is that side's vanilla infantry. The mod matches the lowercased prefab filename (`/character_us_sniper.et`), so a clothing variant or a KLMK file does not win.

## Live desk

After every bridge tick the headquarters advances the week. The page polls `GET /desk` every couple of seconds, swaps that JSON in place, and markers glide to their new ground. The light on the map says what is behind it: **Live** (the mod reported in the last 30 s), **No feed** (the server is offline or the war mod is not running), **Link lost** (the poll failed).

## Bridge files

`bridge/war-bridge.sh` writes `server/profile/profile/cbpnk-war/orders.tsv` unless `REFORGER_WAR_PROFILE` says otherwise. The server runs with `-profile /home/profile`, and the game's `$profile:` is the `profile/` folder inside it (next to its `.save`), so the mod reads `$profile:cbpnk-war/` = `/home/profile/profile/cbpnk-war`. The mod stays idle until `enabled` exists there.

```bash
bridge/war-bridge.sh                            # one sync
REFORGER_BRIDGE_SECRET=change-me REFORGER_WAR_ENABLE=1 bridge/war-bridge.sh --loop
```

`bridge/reforger-war-bridge.service` is the same loop as a systemd unit. It needs `REFORGER_WAR_ENABLE=1` and `REFORGER_BRIDGE_SECRET` from `hq/.env`. Turning it on: pack the mod, start the war compose, set the secret, then enable the unit. The full order is in the [README](../README.md).

`orders.tsv`, in this order (vehicles before orders, so a boarding order finds its vehicle):

```
WEEK <id>
HOME <us|ussr|fia> <x> <z>
SQUAD <id> <side> <x> <z> <strength> <tier>
VEHICLE <id> <side> <kind> <prefab>      # delivered at that side's spawn, nobody's squad's
COMBAT <squad_id> <hold|return|free>
FORM <squad_id> <auto|wedge|line|column|staggered> <auto|walk|run|sprint>
ORDER <id> <squad_id> <side> <x> <z> <kind> <target|0> [<x2> <z2>]   # kinds as above; target: soldier to treat, vehicle to board, squad to follow
LEG <order_id> <x> <z>      # a move's further points, in order
SOLDIER <id> <squad_id> <role> <aim> <grit> <fieldcraft> <service> <stance> <pace>   # field only
FIRE <id> <he_small|he_medium|smoke|illum> <x> <z>
SUPPLY <id> <squad_id> <magazines>
```

`state.tsv`, rewritten every tick:

```
SQUAD <id> <x> <z> <alive> <doing> <hold|return|free>
SOLDIER <id> dead
SOLDIER <id> ok <health> <bleeding> <down> <stamina> <safe|vigilant|alerted|threatened> <mags|-1> <in_vehicle> <x> <z> <driver|gunner|passenger|-> <stand|crouch|prone> <action>
ORDER <id> acked|done|failed
CONTACT <squad_id> <us|ussr|fia> <x> <z> <inf|veh> <seen|heard> <age_s>
VEHICLE <id> <x> <z> <health> <fuel> <seats> <crew> <alive>
FIRE <id> done|failed
SUPPLY <id> done|failed
WORLD <hour> <weather> <rain> <fog> <wind m/s> <wind deg> <sunrise> <sunset> <moon>
END
```

`war-bridge.sh` copies the file and posts it only when the last line is `END`. A state file replaces every contact of the squads it lists. The mod keeps one body per field `SOLDIER` id; a soldier who leaves the export (evacuated, discharged) is taken off the island, a dead one is left to the garbage system. A closed week exports `WEEK 0` and no squads. JSON on the same routes is for debugging; `?format=tsv` is what the script saves.
