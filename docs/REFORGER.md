# Arma Reforger server

Dedicated server for the Kolguyev war. The headquarters is the Laravel app in `hq/`. The addon source is `mod/`. Playing needs Steam Arma Reforger (app **1874880**). The server image is the official dedicated server (app **1874900**) from `ghcr.io/acemod/arma-reforger`.

War mode is the normal launch. It runs the vanilla Game Master scenario `{F45C6C15D31252E6}Missions/27_GM_Cain.conf` and loads the packed CBPNK Kolguyev War addon from `profile/localaddons`. Conflict PvE is not part of this launch: its AI and the commanders' squads share the same AI cap.

| Piece | Path |
|-------|------|
| Compose | `server/docker-compose.yml` and `server/docker-compose.war.yml` |
| Environment | `server/.env` from `server/.env.example` |
| Switch | `server/war-mode.sh` |
| Bridge | `bridge/war-bridge.sh` |
| Profile and saves | `server/profile` (not in git) |

## Launch

```bash
cp server/.env.example server/.env
# Set SERVER_PUBLIC_ADDRESS, GAME_PASSWORD_ADMIN, and RCON_PASSWORD.

docker compose -f server/docker-compose.yml -f server/docker-compose.war.yml up -d
```

The first start downloads the dedicated server, about 10 GB, into `server/install`. A recreate keeps that download. Open UDP `2001` (game) and `17777` (server browser). RCON stays on `127.0.0.1:19999`.

Pack the addon on Windows, with Arma Reforger Tools, before the server can load it:

```bash
pwsh scripts/pack-war-mod.ps1
```

The bridge copies `orders.tsv` into the profile and posts `state.tsv` back. Point `bridge/war-bridge.sh` at this headquarters with `REFORGER_WAR_ORDERS_URL`, `REFORGER_WAR_STATE_URL`, and `REFORGER_BRIDGE_SECRET`. It writes `$profile:cbpnk-war/enabled` only when `REFORGER_WAR_ENABLE=1`. Leave that off until the packed addon is what the server is running.

Do not commit `server/.env`, `server/profile`, or `server/install`.
