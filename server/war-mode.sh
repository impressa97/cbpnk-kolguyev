#!/usr/bin/env bash
# Switch the Reforger server between Conflict PvE and the Kolguyev war mode. Run on the host.
#
#   war-mode.sh status
#   war-mode.sh on    # Game Master Kolguyev + the CBPNK War addon (packed, local), bridge on
#   war-mode.sh off   # back to the stock launcher with .env (Conflict PvE), bridge off
#
# The war addon is loaded from $SERVER_DIR/profile/localaddons/CBPNKWar_53D18422A46F789F, as
# Workbench packs it (scripts/pack-war-mod.ps1); copy it there before `on`. The two
# modes never run together: Conflict PvE's AI and the commanders' squads share the AI cap.
# Both restart reforger-server.
set -euo pipefail

SERVER_DIR="${SERVER_DIR:-/opt/reforger-server}"
REPO="${REPO:-$(cd "$(dirname "$0")/.." && pwd)}"
APP_ENV="${APP_ENV:-$REPO/hq/.env}"
ADDON="$SERVER_DIR/profile/localaddons/CBPNKWar_53D18422A46F789F"
BRIDGE_DIR="$SERVER_DIR/profile/profile/cbpnk-war"
MARK="$SERVER_DIR/.war-mode"

set_key() { # file key value
  if grep -qE "^$2=" "$1"; then
    sed -i "s|^$2=.*|$2=$3|" "$1"
  else
    printf '%s=%s\n' "$2" "$3" >> "$1"
  fi
}

status() {
  if [ -f "$MARK" ]; then echo "mode:     war (Game Master Kolguyev + CBPNK War)"; else echo "mode:     stock ($(grep -E '^GAME_SCENARIO_ID=' "$SERVER_DIR/.env" | cut -d= -f2-))"; fi
  echo "server:   $(docker ps -a --filter name=reforger-server --format '{{.Status}}')"
  echo "bridge:   $(systemctl is-active reforger-war-bridge 2>/dev/null || true)"
  [ -f "$ADDON/data.pak" ] && echo "addon:    $(stat -c '%s bytes, %y' "$ADDON/data.pak")"
  if [ -f "$BRIDGE_DIR/state.tsv" ]; then
    echo "state:    $(wc -l < "$BRIDGE_DIR/state.tsv") lines, $(( $(date +%s) - $(stat -c %Y "$BRIDGE_DIR/state.tsv") )) s old"
  fi
  return 0
}

compose() {
  install -m 644 "$REPO/server/docker-compose.yml" "$SERVER_DIR/docker-compose.yml"
  install -m 644 "$REPO/server/docker-compose.war.yml" "$SERVER_DIR/docker-compose.war.yml"
  (cd "$SERVER_DIR" && docker compose "$@")
}

case "${1:-status}" in
  status) status ;;
  on)
    [ -f "$ADDON/data.pak" ] || { echo "No packed addon at $ADDON" >&2; exit 1; }
    mkdir -p "$BRIDGE_DIR"
    compose -f docker-compose.yml -f docker-compose.war.yml up -d --force-recreate
    touch "$MARK"
    set_key "$APP_ENV" REFORGER_WAR_ENABLE 1
    systemctl enable --now reforger-war-bridge
    systemctl restart reforger-war-bridge
    status
    ;;
  off)
    systemctl disable --now reforger-war-bridge || true
    set_key "$APP_ENV" REFORGER_WAR_ENABLE 0
    rm -f "$BRIDGE_DIR/enabled" "$MARK"
    compose -f docker-compose.yml up -d --force-recreate
    status
    ;;
  *) echo "usage: $0 status|on|off" >&2; exit 2 ;;
esac
