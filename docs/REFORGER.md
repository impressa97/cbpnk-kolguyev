# Arma Reforger — shared dedicated server

Crew Conflict server next to the city game. Auth players use [`/reforger`](https://cbpnk.com/reforger) for join info and the Kolguyev war HQ ([KOLGUYEV_WAR.md](KOLGUYEV_WAR.md)). The command mod is not loaded yet; this page's scenario is still Conflict PvE.

**Conflict PvE Remixed on Kolguyev.** Players deploy as the US. USSR AI holds the towns and counterattacks, with vehicle patrols and artillery. No city-skill bridge.

| Surface | Path |
|---------|------|
| Join page | `/reforger` — Inertia `resources/js/pages/reforger.tsx` |
| Config | `config/reforger.php` + env `REFORGER_*` |
| Docker | `/opt/reforger-server` (`reforger-server` container) |
| Compose | `deploy/reforger/docker-compose.yml` |
| Join alerts | Host log watcher → `POST /api/reforger/join` |
| Canonical ops | this doc |

---

## Live stack

| Piece | Role |
|-------|------|
| **Address** | `REFORGER_HOST:REFORGER_PORT` (prod `157.180.74.182:2001`) |
| **Game** | `2001` UDP |
| **A2S** | `17777` UDP — server browser queries and occupancy |
| **RCON** | `127.0.0.1:19999` UDP only |
| **Client** | Arma Reforger on Steam (stable, app **1874880**) |
| **Server** | Official DS via `ghcr.io/acemod/arma-reforger` (Steam app **1874900**) |
| **Scenario** | `{3BE30F418DCEE458}Missions/ConflictPVERemixedVanilla2_Cain.conf` — Conflict PvE Remixed, Kolguyev |
| **Mods** | `61B514B96692C049` Conflict PvE Remixed |
| **AI** | On, `OPERATING_AI_LIMIT=192`. Enemy holds bases and counterattacks |
| **Players** | 8, PC only |

Do not put the live `.env`, profile, or saves in git.

---

## Host ops

```bash
# First time
sudo mkdir -p /opt/reforger-server/{config,profile,workshop}
sudo cp deploy/reforger/docker-compose.yml /opt/reforger-server/
sudo cp deploy/reforger/.env.example /opt/reforger-server/.env
# Set GAME_PASSWORD, GAME_PASSWORD_ADMIN, RCON_PASSWORD (no spaces)

cd /opt/reforger-server && docker compose up -d
docker logs -f reforger-server
```

The image runs as root and rewrites `config/docker_generated.json` from the environment on every start, after SteamCMD. The dedicated server files live in `./install` (`/reforger`), so a recreate resumes the download instead of starting over.

UFW: `2001/udp`, `17777/udp`. Do not publish RCON.

```bash
cd /opt/reforger-server && docker compose up -d
docker logs -f reforger-server

# Stop (flush the session save)
cd /opt/reforger-server && docker compose stop
```

Laravel `.env` on the app host (also `docker-compose.prod.yml`):

```
REFORGER_HOST=157.180.74.182
REFORGER_PORT=2001
REFORGER_QUERY_PORT=17777
REFORGER_SERVER_NAME=qpstall
REFORGER_PASSWORD=
REFORGER_SCENARIO=Conflict PvE · Kolguyev
REFORGER_MAX_PLAYERS=8
REFORGER_AI_LIMIT=192
REFORGER_VERSION=stable
```

`REFORGER_PASSWORD` must match `GAME_PASSWORD` in `/opt/reforger-server/.env`. The join page shows it to signed-in players.

RAM cap is 10G and 3.5 CPUs, sim pinned at 60 FPS. Server view distance is 2500 m. The browser name is `qpstall` and there is no join password. Minecraft stays down; NeoForge stays. Autosave is every 10 minutes. Kolguyev is the full scenario (counterattacks, vehicle patrols, artillery). Everon 4x is not used. Linear Conflict PvE is not loaded, so more than one town stays in play. Escapists, RHS, and Realism Overhaul are not in the mod list. A captured war reloads after a restart.

---

## Occupancy

A2S on UDP `17777`. Prometheus: `cbpnk_game_*{service="reforger"}` from game-exporter.

## Join alerts

`deploy/reforger/reforger-join-watch.sh` follows `docker logs` of `reforger-server` and POSTs through `deploy/crew/post-join.sh` when the console prints `Player #N Name (address:port) connected`. Telegram `/alerts` → Arma Reforger.

Secret is env-only (`REFORGER_BRIDGE_SECRET` in the Laravel `.env`). App deploy installs the unit and `crew-post-join.sh`, then restarts `reforger-join-watch` only. It does not restart `reforger-server`.
