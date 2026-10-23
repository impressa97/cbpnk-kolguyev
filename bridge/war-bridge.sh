#!/usr/bin/env bash
# Pull Kolguyev war orders into the Reforger profile and push squad state back.
# The mod reads $profile:cbpnk-war/ and ignores the directory until `enabled` exists.
# Set REFORGER_WAR_ENABLE=1 once the packed addon is what the server is running.
set -euo pipefail

# The server runs with -profile /home/profile, and the game's $profile: is its profile/ subfolder
# (where it keeps .save); the mod reads $profile:cbpnk-war/.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PROFILE="${REFORGER_WAR_PROFILE:-$ROOT/server/profile/profile/cbpnk-war}"
ORDERS_URL="${REFORGER_WAR_ORDERS_URL:-http://127.0.0.1:8001/api/reforger/war/orders?format=tsv}"
STATE_URL="${REFORGER_WAR_STATE_URL:-http://127.0.0.1:8001/api/reforger/war/state}"
SECRET="${REFORGER_BRIDGE_SECRET:-}"
INTERVAL="${REFORGER_WAR_INTERVAL:-5}"

if [[ -z "$SECRET" ]]; then
  echo "REFORGER_BRIDGE_SECRET is empty" >&2
  exit 1
fi

mkdir -p "$PROFILE"

sync_once() {
  local tmp
  tmp="$(mktemp)"
  curl -fsS -H "X-Reforger-Bridge-Secret: ${SECRET}" "$ORDERS_URL" -o "$tmp"
  mv "$tmp" "$PROFILE/orders.tsv"

  if [[ "${REFORGER_WAR_ENABLE:-}" == "1" ]]; then
    : > "$PROFILE/enabled"
  fi

  # The mod rewrites state.tsv in place and ends it with END. Copy first, then post
  # only a complete file; a copy caught mid-write is tried again a moment later, so a
  # tick is not lost (each one is a live push to the commanders' pages).
  if [[ -s "$PROFILE/state.tsv" ]]; then
    local state try
    state="$(mktemp)"
    for try in 1 2 3 4 5; do
      cp "$PROFILE/state.tsv" "$state"
      [[ "$(tail -n 1 "$state" | tr -d '\r')" == "END" ]] && break
      sleep 0.3
    done
    if [[ "$(tail -n 1 "$state" | tr -d '\r')" == "END" ]]; then
      curl -fsS -X POST \
        -H "X-Reforger-Bridge-Secret: ${SECRET}" \
        -H "Content-Type: text/plain" \
        --data-binary @"$state" \
        "$STATE_URL" >/dev/null
    fi
    rm -f "$state"
  fi
}

if [[ "${1:-}" == "--loop" ]]; then
  while true; do
    sync_once || echo "war bridge sync failed" >&2
    sleep "$INTERVAL"
  done
fi

sync_once
