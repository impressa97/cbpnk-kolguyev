# Server and mod

How to run the Kolguyev war: headquarters, packed addon, dedicated server, and the bridge between them. The short version is the Run section of the [README](../README.md).

Playing needs Steam Arma Reforger (app **1874880**). The server image is the official dedicated server (app **1874900**) from `ghcr.io/acemod/arma-reforger`.

| Piece | Path |
|-------|------|
| Headquarters | `./scripts/hq.sh` → http://127.0.0.1:8001 |
| Addon source | `mod/` |
| Pack | `scripts/pack-war-mod.ps1` |
| Packed addon | `server/profile/localaddons/CBPNKWar_53D18422A46F789F` |
| Compose | `server/docker-compose.yml` and `server/docker-compose.war.yml` |
| Environment | `server/.env` from `server/.env.example` |
| Bridge | `bridge/war-bridge.sh` |
| Profile and saves | `server/profile` (not in git) |

## 1. Headquarters

```bash
./scripts/hq.sh
```

The desk is one local commander. Set `REFORGER_BRIDGE_SECRET` in `hq/.env` before starting the bridge. Enlist a side: a closed week exports `WEEK 0` and the mod stands the island down.

## 2. Pack the mod

Arma Reforger Tools on Windows. The script mirrors `mod/` to a Windows path, packs it, and copies `data.pak` into the server profile:

```powershell
pwsh scripts/pack-war-mod.ps1
```

Pass `-LocalServerAddon` if the profile is not `server/profile/localaddons/CBPNKWar_53D18422A46F789F`. Restart the server after a new pack so it loads the new `data.pak`.

## 3. Start the server

```bash
cp server/.env.example server/.env
# Set SERVER_PUBLIC_ADDRESS, GAME_PASSWORD_ADMIN, and RCON_PASSWORD.

docker compose -f server/docker-compose.yml -f server/docker-compose.war.yml up -d
```

The war compose replaces the image launcher. It runs:

```text
./ArmaReforgerServer -server {F45C6C15D31252E6}Missions/27_GM_Cain.conf \
  -addonsDir /home/profile/localaddons -addons 53D18422A46F789F \
  -profile /home/profile -loadSessionSave false
```

`GAME_SCENARIO_ID` and `GAME_NAME` in `server/.env` are not read by that command. Join by address and port. Each start is clean: a loaded session would bring back soldiers the mod no longer tracks.

The first start downloads about 10 GB into `server/install`. A recreate keeps that download. Open UDP `2001` (game) and `17777` (server browser). RCON stays on `127.0.0.1:19999`.

Logs:

```bash
docker logs -f reforger-server
```

The world is up when the log says `Frame start` and lists addon `53D18422A46F789F`.

## 4. Bridge

```bash
REFORGER_BRIDGE_SECRET=change-me REFORGER_WAR_ENABLE=1 ./bridge/war-bridge.sh --loop
```

The secret is the same value as `REFORGER_BRIDGE_SECRET` in `hq/.env`. The script reads orders from `http://127.0.0.1:8001/api/reforger/war/orders?format=tsv` and posts state to `http://127.0.0.1:8001/api/reforger/war/state`. It writes `server/profile/profile/cbpnk-war/`, which the game sees as `$profile:cbpnk-war/`. `REFORGER_WAR_ENABLE=1` creates the `enabled` file. Without it the mod ignores the directory.

`bridge/reforger-war-bridge.service` is the same loop for systemd. Replace the `/path/to/cbpnk-kolguyev` lines with this checkout before enabling it.

## Join

Arma Reforger → Multiplayer → Direct Connect: `127.0.0.1`, port `2001`. No join password unless `GAME_PASSWORD` is set. Deploy at the enlisted side's home.

Do not commit `server/.env`, `server/profile`, or `server/install`.
