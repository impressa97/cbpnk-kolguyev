# CBPNK Kolguyev

Kolguyev war as its own game: an Arma Reforger mod, a dedicated server, and a browser headquarters. Playing on the island needs Steam Arma Reforger. The headquarters opens in a browser on its own.

```
browser  →  hq (Laravel)
hq       →  orders.tsv
orders   →  war mod  →  Reforger dedicated server
mod      →  state.tsv
state    →  war-bridge  →  hq
```

The mod talks in squad and soldier ids. It does not know about city players.

## Headquarters

Headquarters needs PHP 8.3 or newer, Composer, and Node.

```bash
cd hq
composer install
npm install
npm run build
php artisan migrate
php artisan serve
```

Register the first account. That commander leads their side of the week. The desk is at `/` and polls `GET /desk` every couple of seconds. Set `REFORGER_HOST`, `REFORGER_PORT`, and `REFORGER_BRIDGE_SECRET` in `hq/.env` (see `hq/.env.example`).

## Mod

Pack the addon into the local dedicated server's `localaddons` from Windows, with Arma Reforger Tools installed:

```bash
pwsh ../scripts/pack-war-mod.ps1
```

Run that from `hq/`, or call `scripts/pack-war-mod.ps1` from the repo root. The script's default source is this repo's `mod/`.

## Server

Copy `server/.env.example` to `server/.env` and set `SERVER_PUBLIC_ADDRESS` to the address players will connect to. War mode is the normal launch:

```bash
docker compose -f server/docker-compose.yml -f server/docker-compose.war.yml up -d
```

The first start downloads the official dedicated server. Profile and saves stay in `server/profile`.

## Bridge

`bridge/war-bridge.sh` copies `orders.tsv` into the server profile and posts `state.tsv` back. Point it at this headquarters with `REFORGER_WAR_ORDERS_URL`, `REFORGER_WAR_STATE_URL`, and `REFORGER_BRIDGE_SECRET`. The profile directory the mod reads stays `$profile:cbpnk-war/`.

The bridge writes `enabled` only when `REFORGER_WAR_ENABLE=1`. Leave that off until the packed war mod is the scenario the server is running.
