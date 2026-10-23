# Kolguyev

Kolguyev war: an Arma Reforger mod, a dedicated server, and a browser headquarters. Playing needs Steam Arma Reforger. The headquarters is a local page, with no account to register.

```
browser  →  hq (Laravel)
hq       →  orders.tsv
orders   →  war mod  →  Reforger dedicated server
mod      →  state.tsv
state    →  war-bridge  →  hq
```

The mod talks in squad and soldier ids.

## Run

Four steps, in this order. Details for the server are in [docs/REFORGER.md](docs/REFORGER.md). How the war plays is in [docs/KOLGUYEV_WAR.md](docs/KOLGUYEV_WAR.md).

### 1. Headquarters

Needs PHP 8.3 or newer, Composer, and Node.

```bash
./scripts/hq.sh
```

Open [http://127.0.0.1:8001](http://127.0.0.1:8001). Put a shared secret in `hq/.env` (any long random string) and leave the host on this machine:

```
REFORGER_BRIDGE_SECRET=change-me
REFORGER_HOST=127.0.0.1
REFORGER_PORT=2001
```

Enlist a side on the desk. Until a week is open, the island stays stood down.

### 2. Mod

On Windows, with Arma Reforger Tools installed, pack the addon into the server profile:

```powershell
pwsh scripts/pack-war-mod.ps1
```

That writes `data.pak` to `server/profile/localaddons/CBPNKWar_53D18422A46F789F`. The server loads that directory. It is not a Workshop mod, so the game client does not download it.

### 3. Server

```bash
cp server/.env.example server/.env
```

Set `SERVER_PUBLIC_ADDRESS` to the address players connect to (`127.0.0.1` on this machine), and set `GAME_PASSWORD_ADMIN` and `RCON_PASSWORD`.

```bash
docker compose -f server/docker-compose.yml -f server/docker-compose.war.yml up -d
```

The first start downloads the dedicated server, about 10 GB, into `server/install`. The server runs the vanilla Game Master scenario `{F45C6C15D31252E6}Missions/27_GM_Cain.conf` and the packed addon. UDP `2001` is the game, UDP `17777` is the server browser. RCON stays on `127.0.0.1:19999`.

### 4. Bridge

The bridge copies orders into the profile and posts the island's state back. Use the same secret as `hq/.env`.

```bash
REFORGER_BRIDGE_SECRET=change-me REFORGER_WAR_ENABLE=1 ./bridge/war-bridge.sh --loop
```

`REFORGER_WAR_ENABLE=1` is what writes `$profile:cbpnk-war/enabled`. Without that file the mod stays idle. The default profile is `server/profile/profile/cbpnk-war`, which is where the container reads `$profile:`.

### Join

In Arma Reforger: Multiplayer → Direct Connect, address `127.0.0.1`, port `2001`. There is no join password unless `GAME_PASSWORD` is set. Deploy at the home of the side enlisted on the desk.

Do not commit `hq/.env`, `server/.env`, `server/profile`, or `server/install`.
