#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════
# Script: deploy.sh
# Purpose: Production deployment tasks for the Erada PMO Laravel app.
#          Runs inside the Docker image at /deploy.sh (see Dockerfile:104).
#          Invoked by /start.sh after .env is hydrated from container env.
#
# Failure recovery contract:
#   - All cache rebuilds are wrapped in artisan maintenance mode.
#   - A secret bypass token lets operators (or the rollout probe) bypass
#     the 503 page even during deploys.
#   - The EXIT trap brings the app back up on every exit path — success,
#     failure, and signal — so the 503 page never lingers if a step
#     explodes.
#   - Readiness is gated by the Dockerfile HEALTHCHECK and Dokploy AFTER
#     services start — NOT inside this script. nginx/php-fpm come up via
#     supervisord (/start.sh) only after deploy.sh exits, so an in-script
#     HTTP probe here can never pass and must not block the boot.
#
# Dokploy rolling strategy (configured on the orchestrator, NOT here):
#   - max_unavailable = 0  (drain old container before starting new)
#   - healthcheck_path   = /api/health
#   - healthcheck_delay  = 5s
#   - rollback_on_failure: image_sha revert + DB schema guard
#     (see deploy.yml jobs.rollback)
#
# .github/workflows/deploy.yml jobs.migrate (lines 105-148) runs
# migrations against production DB BEFORE the deploy job triggers Dokploy,
# which is why this script intentionally does NOT call migrate.
#
# Order matters:
#   1. artisan down FIRST (before any cache rebuild) — otherwise a stale
#      cached config survives the restart and we deadlock against it.
#      (Same convention as composer.json scripts.test, line 56.)
#   2. config:clear, then config:cache, route:cache, view:cache, event:cache.
#   3. storage:link is idempotent — safe on every boot.
#   4. trap fires on EXIT and runs artisan up.
# ═══════════════════════════════════════════════════════════════

set -euo pipefail

cd /var/www

# Random bypass token so operators + the rollout probe can stay open
# during a deploy (--secret emits a Set-Cookie that's checked by the
# default exception handler's maintenance bypass middleware).
TOKEN=$(openssl rand -hex 16 2>/dev/null || head -c 16 /dev/urandom | xxd -p)

# Bring the app back up on every exit path (success, failure, signal).
# `set -e` ensures any failed step exits non-zero, triggering this trap.
bring_up() {
    local exit_code=$?
    echo "▶ EXIT (${exit_code}): bringing application up..."
    php artisan up --no-interaction 2>&1 || true
    exit "${exit_code}"
}
trap bring_up EXIT
trap 'bring_up' INT TERM

# M-18: block the deploy if production configuration is unsafe (HTTPS/proxy/
# secrets/debug). Runs with the real in-container prod env; `set -e` aborts the
# boot on a non-zero exit, and the EXIT trap brings the app back up.
echo "▶ Checking production readiness..."
php artisan production:check-readiness --no-interaction

echo "▶ Entering maintenance mode (bypass token: ${TOKEN})..."
php artisan down \
    --secret="${TOKEN}" \
    --render="errors::503" \
    --no-interaction

echo "▶ Clearing stale config cache..."
php artisan config:clear

echo "▶ Caching config..."
php artisan config:cache

echo "▶ Caching routes..."
php artisan route:cache

echo "▶ Caching views..."
php artisan view:cache

echo "▶ Caching events..."
php artisan event:cache

echo "▶ Linking storage..."
php artisan storage:link

# ─────────────────────────────────────────────────────────────────────
# Migrations: defend-in-depth. .github/workflows/deploy.yml jobs.migrate
# already runs migrations ahead of deploy.sh, but if a deploy is triggered
# outside that pipeline (manual rollback, emergency hotfix, CI bypass) we
# still need the schema to match the new code BEFORE traffic switches.
# `migrate --force` is idempotent against already-migrated schemas.
# Audit 2026-06-29 finding #5: a fresh deploy with a pending migration
# would 500 every request until ops ran it manually.
# ─────────────────────────────────────────────────────────────────────
echo "▶ Migrations (defense-in-depth, idempotent)..."
php artisan migrate --force --no-interaction

# ─────────────────────────────────────────────────────────────────────
# Readiness is gated AFTER this script returns — NOT here. nginx/php-fpm
# are started by supervisord in /start.sh only after deploy.sh exits, so
# nothing is listening on the web port while this runs; an in-script HTTP
# probe can never pass. The Dockerfile HEALTHCHECK (curl localhost/api/
# health, 120s start-period) plus Dokploy's own probe are the real gates.
# deploy.sh's contract is: do prep work, then exit 0 so /start.sh proceeds
# to start the services.
# ─────────────────────────────────────────────────────────────────────
echo "✅ Deploy tasks complete."
