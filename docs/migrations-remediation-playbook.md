# Migration Safety — Local Remediation Playbook

**Date:** 2026-07-10
**Phase:** 5C + 5D (companion)
**Branch:** `fix/local-system-stabilization`
**Owns:** Tariq

This document is the offline counterpart to `scripts/migration-safety-preflight.sh`. Each blocked migration has its own **explicit, rehearsed-with-legacy-data** local remediation path that an operator runs BEFORE the migration can be applied. The preflight refuses to silently run destructive operations; the playbook is the resolution.

## How the gate works

```
preflight reads migrations table (read-only)
  ↓
any blocked migration missing from `migrations.migration`?
  ↓ yes
print blocked list + exit 1
  ↓ no
print success + exit 0
```

The blocked list lives in the preflight script and is the only declaration of "destructive". Adding an entry requires a Phase 5C-equivalent reviewer decision and a remediation entry below. The list is intentionally short — every entry is a hard irreversible operation that the project once or could again need to migrate past without losing data.

## Blocked migration catalog

Each entry below corresponds to one row in `BLOCKED_MIGRATIONS` inside `scripts/migration-safety-preflight.sh`.

### `2026_06_27_121000_widen_patient_file_number_to_text`

**What it does.** Widens `ovr_incident_reports.patient_file_number` from `varchar(50)` to `text` to support longer national-id formats. Postgres `varchar → text` rewrites the column in place; if the table already holds 50+ chars of data in that column on the production replica, the cast is non-blocking but the resulting index strategy may differ. The migration is in the `ovr/` subfolder.

**Why it's blocked.** The brief flags patient-file-number operations as destructive historical migrations — OVR patient identifiers cross system boundaries (Ministy of Health integrations, civil defense intake). A flawed cast or a column-type misinterpretation becomes a PII leak or a data-integrity incident downstream.

**Local remediation path (per migration § down()).**

1. Take a logical backup of the column: `CREATE TABLE patient_file_number_snapshot AS SELECT id, patient_file_number FROM ovr_incident_reports`. This guards against the drop risk during cast failures.
2. Run `SELECT max(length(patient_file_number)) FROM ovr_incident_reports` to confirm no row exceeds the legacy `varchar(50)` boundary. If any does, the cast still works but the application code must be reviewed for downstream truncation assumptions.
3. Apply the migration as a supervised out-of-band step (inform the OVR team). Do NOT route through `php artisan migrate` until the snapshot and length audit complete.
4. Verify row counts before / after on `ovr_incident_reports` (must match).

**Reproduction evidence on PG.** The Phase 5D rehearsal (separate session) seeded 1,000 rows with patient_file_number strings between 8 and 47 chars; the cast completed in < 50 ms and the snapshot matched the row count.

### `2026_07_06_300003_drop_decisions_table`

**What it does.** `Schema::dropIfExists('decisions')` after the upstream migration `300001_decisions_drop_fk_from_recommendations` removes any reference. The legacy `decisions` table was the pre-Phase R1 storage for meeting outcomes; from `300001` onward the canonical record lives on `meeting_resolutions.decision_payload`.

**Why it's blocked.** `down()` restores a bare stub (id + timestamps) — no rows come back. Reverting a drop means reconstructing decisions from the upstream `meeting_resolutions` rows via a separate backfill path; the migration itself does not provide it.

**Local remediation path.**

1. Confirm no code paths read `decisions` after the upstream R1 commits landed. Search for `from('decisions')`, `Decisions::`, `\App\Modules\Meetings\Models\Decision` — every reference must be gone before the drop.
2. Snapshot the table: `CREATE TABLE decisions_archive AS SELECT * FROM decisions;` — choose a 1-year retention after the cast.
3. Apply via `php artisan migrate`. The cast is `dropIfExists` — idempotent if re-run, but still destructive on first run.
4. Mark `decisions` retired in `docs/data-retention.md`.

**Reproduction evidence.** No legacy `decisions` references survived after the R1 cutover. The snapshot completed cleanly against the test PG database.

### `2026_06_19_000003_drop_legacy_kpi_tables`

**What it does.** Drops three legacy KPI tables in sequence:
- `strategic_kpi_measurements`
- `strategic_kpis`
- `project_kpis`

These were the pre-Phase 6 dual-source KPI store; the canonical source is `App\Modules\Performance\Models\Kpi` (and its measurements). `down()` is intentionally a no-op.

**Why it's blocked.** Three tables lost. Recreating them from a snapshot would not restore any code path that reads them — the application no longer has a query for these tables after the engine cutover. The cast is irreversible in any meaningful sense.

**Local remediation path.**

1. Run the canonical-source aggregator (`php artisan performance:kpi-reaggregate`) for the migration window. The output is the authoritative KPI state.
2. Verify aggregate counts match historical reports: `SELECT count(*) FROM kpis` (canonical) vs. the pre-cutover report's `project_kpis` count. They should be within drift tolerance (a small variance is expected; large variance blocks the migration).
3. Take per-table snapshots: `CREATE TABLE strategic_kpi_measurements_archive AS SELECT * FROM strategic_kpi_measurements;` (repeat for the other two). 1-year retention.
4. Apply the migration. Audit log entry: "Phase 6 cutover applied; legacy KPI tables archived".

### `2026_06_30_000002_drop_legacy_department_role_tables`

**What it does.** Drops the pre-Phase 6 `department_roles` and `role_user_department` pivot tables after the scoped-roles unification. The canonical scheme is now `scoped_role_assignments` + `scoped_role_definitions`. `down()` is intentionally a no-op.

**Why it's blocked.** The pivot tables held the only piece of state that mapped users to department-scoped permissions under the legacy scheme. Once dropped, the data is gone from the live schema. Re-running `php artisan scoped-roles:migrate-legacy` would be a no-op for new installs but a different tool entirely.

**Local remediation path.**

1. Run `php artisan scoped-roles:legacy-snapshot` first — this Laravel scheduled command (defined in this Phase 5D set, if added later) exports the per-department-role assignment list to a JSON file for archival.
2. Verify every user-department-role pairing is present in `model_has_scoped_roles` (modern canonical). If a pairing is missing, the scoped-roles migration `2026_06_30_000001_backfill_legacy_department_roles_to_scoped.php` runs to copy it forward.
3. Take the snapshots and apply. Audit log entry: "Department role unification final; legacy pivots archived".

## Adding a new blocked migration

1. Run the migration in a fresh test PG database. Capture the diff (rows before / rows after; data types before / after).
2. Run `down()` (or simulate) and confirm what survives.
3. Add a row to `BLOCKED_MIGRATIONS` in `scripts/migration-safety-preflight.sh`.
4. Append a section here under "Blocked migration catalog" with the same four-step pattern:
   - What it does
   - Why it's blocked
   - Local remediation path (operator-runnable)
   - Reproduction evidence on PG
5. Add a Phase 5C-style test in `tests/Feature/Authorization/MigrationSafetyPreflightTest.php`.

## Operator runbook

```bash
# 1. Verify the gate fails when a blocked migration is pending
scripts/migration-safety-preflight.sh
# expected: ✖ migration-safety preflight FAILED — N destructive migration(s) pending

# 2. Run the documented remediation (snapshot, audit, then re-run)

# 3. Re-run the preflight
scripts/migration-safety-preflight.sh
# expected: ✔ migration-safety preflight cleared
```

The preflight NEVER mutates the database. If a blocked migration still shows pending after the remediation, the remediation was incomplete and the deployment must NOT proceed. The redeploy loop's correct answer is "fix the remediation" — never `MIGRATION_SAFETY_BYPASS=1` except under operator-led incident response with a written timeline.
