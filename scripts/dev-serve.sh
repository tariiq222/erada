#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════
# Script: dev-serve.sh
# Purpose: Guarded wrapper around `php artisan serve`.
#
# Why this exists:
#   The Docker `app` service (docker compose --profile full) and the
#   host-based `composer dev` both try to bind port 8000. When both run
#   at once, macOS routes 127.0.0.1:8000 to whichever bound it first,
#   producing a confusing "second, broken server" and intermittent 500s
#   on login. This guard refuses to start a host server when port 8000
#   is already taken (i.e. Docker is already serving the app), instead of
#   silently creating the conflicting server.
# ═══════════════════════════════════════════════════════════════

set -euo pipefail

HOST="${APP_SERVE_HOST:-127.0.0.1}"
PORT="${APP_SERVE_PORT:-8000}"

RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
NC='\033[0m'

port_in_use() {
  if command -v lsof >/dev/null 2>&1; then
    lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1
  elif command -v nc >/dev/null 2>&1; then
    nc -z "$HOST" "$PORT" >/dev/null 2>&1
  else
    # No probe tool available; assume free and let artisan serve report.
    return 1
  fi
}

if port_in_use; then
  printf "${RED}✖ Port %s is already in use.${NC}\n" "$PORT" >&2
  printf "${YELLOW}A server is already listening on %s — most likely the Docker 'app' container\n" "$PORT" >&2
  printf "(docker compose --profile full up). Running 'php artisan serve' now would create a\n" >&2
  printf "second, conflicting server and break login on 127.0.0.1:%s.${NC}\n\n" "$PORT" >&2
  printf "Choose ONE dev mode:\n" >&2
  printf "  • Docker (full):  docker compose --profile full up    -> app on http://localhost:%s\n" "$PORT" >&2
  printf "  • Host (composer dev): first run  docker compose stop app vite  then  composer dev\n\n" >&2
  printf "${YELLOW}Skipping host 'php artisan serve' (Docker already serves port %s).${NC}\n" "$PORT" >&2
  # Exit non-zero so concurrently --kill-others stops the duplicate stack.
  exit 1
fi

printf "${GREEN}▶ Starting host server on http://%s:%s${NC}\n" "$HOST" "$PORT"
exec php artisan serve --host="$HOST" --port="$PORT"
