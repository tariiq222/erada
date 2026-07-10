#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════════════════
# Script: migration-safety-preflight.sh
# Purpose: Phase 5C — migration-safety preflight.
#
# Reads the Laravel `migrations` table and ABORTS the deploy when any of the
# known-destructive historical migrations are still pending. The preflight is
# strictly read-only:
#
#   - It NEVER calls `php artisan migrate` (no mutation).
#   - It NEVER inserts / updates / deletes a row in the `migrations` table.
#   - It NEVER touches production data of any other table.
#
# On failure it prints the exact blocked migration file names and exits 1. On
# success it prints the applied set and exits 0. CI / deploy hook can `set -e`
# this script before any further upgrade.
#
# Usage:
#   scripts/migration-safety-preflight.sh
#
# Override behavior:
#   MIGRATION_SAFETY_BYPASS=1   skip the check (logged). Use only as an
#                               emergency escape with a written notice in the
#                               incident timeline.
#
# The blocked list is the Phase 5C gate — each migration has a documented
# local remediation path under docs/migrations-remediation-playbook.md.
# ════════════════════════════════════════════════════════════════════════════════

set -euo pipefail

readonly RED=$'\033[0;31m'
readonly GREEN=$'\033[0;32m'
readonly YELLOW=$'\033[0;33m'
readonly NC=$'\033[0m'

if [[ "${MIGRATION_SAFETY_BYPASS:-0}" == "1" ]]; then
    printf '%s⚠%s MIGRATION_SAFETY_BYPASS=1 — preflight skipped.\n' "$YELLOW" "$NC" >&2
    printf '   This is an emergency override. Document the bypass in the incident timeline.\n' >&2
    exit 0
fi

# Known-destructive historical migrations. The preflight fails if any are
# still PENDING (not in the `migrations` table as `applied`).
#
# Adding a migration to this list is an explicit reviewer decision. Each entry
# must have a documented local remediation path in
# docs/migrations-remediation-playbook.md. Adding a migration by accident is a
# hardening regression — keep this list short and explicit.
readonly BLOCKED_MIGRATIONS=(
    # patient-file-number column widening — the OVR source-of-truth
    # column. The down() reverses the cast; the design brief flags
    # this as a destructive historical migration that requires a
    # separate local remediation path before it can run on a DB with
    # legacy data.
    "2026_06_27_121000_widen_patient_file_number_to_text"

    # legacy decisions table drop — irreversible by design (no data
    # backfill in down(); only a bare id + timestamps stub).
    "2026_07_06_300003_drop_decisions_table"

    # legacy KPI tables drop — three legacy dual-source KPI tables
    # (strategic_kpi_measurements, strategic_kpis, project_kpis).
    # down() is intentionally a no-op; the data has no surviving
    # reader path. Migration is permanent.
    "2026_06_19_000003_drop_legacy_kpi_tables"

    # legacy department_role tables drop — final form of the dept-role
    # unification. Permanent; down() is a no-op.
    "2026_06_30_000002_drop_legacy_department_role_tables"
)

# Locate the Laravel app and the artisan binary. If `artisan` is missing we
# fail loud — never auto-skip the check, since that masks a real drift.
ARTISAN=""
for candidate in \
    "$(pwd)/artisan" \
    "$(pwd)/../artisan" \
    "$(pwd)/../../artisan" \
    "$(pwd)/../../../artisan"
do
    if [[ -x "$candidate" ]]; then
        ARTISAN="$candidate"
        break
    fi
done

if [[ -z "$ARTISAN" ]]; then
    printf '%s✖%s artisan not found. Run this script from a Laravel project root.\n' "$RED" "$NC" >&2
    exit 1
fi

# Read the applied migrations set via `migrate:status` — a deterministic,
# read-only command (`migrate:status` does NOT run any migration). The
# output format is:
#
#   Y_m_d_HXXXXX_migration_name ............... [N] Ran
#   Y_m_d_HXXXXX_other_migration_name ......................... Pending
#
# When MIGRATION_SAFETY_APP_ENV=testing is set, we ALSO pin the DB
# connection to the testing DB. Laravel's phpunit.xml overrides set
# the test DB to 127.0.0.1:5433 / iradah_pmo_test; the artisan CLI on
# its own reads .env (which points at the dev DB), so we override
# the relevant DB_* env vars here. The script never reads or writes
# secret material; it only configures which connection artisan uses
# for the read-only `migrate:status` call.
APP_ARGS=()
if [[ -n "${MIGRATION_SAFETY_APP_ENV:-}" ]]; then
    APP_ARGS+=(--env="$MIGRATION_SAFETY_APP_ENV")
    DB_ARGS=(
        "DB_CONNECTION=pgsql"
        "DB_HOST=127.0.0.1"
        "DB_PORT=5433"
        "DB_DATABASE=iradah_pmo_test"
        "DB_USERNAME=iradah"
        "DB_PASSWORD=secret"
    )
else
    DB_ARGS=()
fi

# Capture via a temp file. $(...) inside `set -e` can drop stdout
# under pipefail when the artisan process is interactive; a temp
# file keeps the contract deterministic.
STATUS_TMP="$(mktemp -t preflight-status.XXXXXX)"
trap 'rm -f "$STATUS_TMP"' EXIT
env "${DB_ARGS[@]}" php "$ARTISAN" migrate:status "${APP_ARGS[@]}" >"$STATUS_TMP" 2>&1 || STATUS_EXIT=$?
STATUS_EXIT="${STATUS_EXIT:-0}"
STATUS_RAW="$(cat "$STATUS_TMP")"

if [[ "$STATUS_EXIT" -ne 0 && -z "$STATUS_RAW" ]]; then
    printf '%s✖%s migrate:status failed (exit %s) — unable to read migrations table.\n' "$RED" "$NC" "$STATUS_EXIT" >&2
    printf '   Re-run with MIGRATION_SAFETY_BYPASS=1 only as an emergency override.\n' >&2
    exit 1
fi

if [[ -z "$STATUS_RAW" ]]; then
    printf '%s✖%s migrate:status returned no output. Is the DB reachable?\n' "$RED" "$NC" >&2
    exit 1
fi

# Each blocked migration has to appear in the migrate:status output AND
# the line must end with `Ran` (Laravel's marker for an applied entry).
pending=()
for blocked in "${BLOCKED_MIGRATIONS[@]}"; do
    # Use printf + grep -F for fast substring match, then a regex
    # check for `Ran` near end of line. Some shells mishandle `<<<`
    # under `set -euo pipefail` (the right-hand side exits non-zero on
    # grep-miss, even with `|| true` as a fallback); `grep -F` returns
    # an explicit exit code we route through `|| true` below.
    line="$(printf '%s\n' "$STATUS_RAW" | grep -E "^[[:space:]]*${blocked}[[:space:]]" || true)"
    if [[ -z "$line" ]]; then
        pending+=("$blocked")
        continue
    fi
    if [[ ! "$line" =~ Ran[[:space:]]*$ ]]; then
        pending+=("$blocked")
    fi
done

if [[ "${#pending[@]}" -gt 0 ]]; then
    printf '%s✖%s migration-safety preflight FAILED — %s destructive migration(s) pending:\n' \
        "$RED" "$NC" "${#pending[@]}" >&2
    for entry in "${pending[@]}"; do
        printf '   - %s\n' "$entry" >&2
    done
    printf '\nDocumentation: docs/migrations-remediation-playbook.md\n' >&2
    printf 'Each pending migration above requires its documented local remediation path\n' >&2
    printf 'to run BEFORE the migration can be applied. The preflight refuses to\n' >&2
    printf 'silently run a destructive op on a database with representative legacy\n' >&2
    printf 'data — that is the entire point of the gate.\n' >&2
    exit 1
fi

printf '%s✔%s migration-safety preflight cleared — %s known-destructive migration(s) verified.\n' \
    "$GREEN" "$NC" "${#BLOCKED_MIGRATIONS[@]}"
exit 0
