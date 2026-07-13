# Canonical authorization operations

`AccessDecision` evaluates only `authorization_*` data. There is no runtime
legacy, shadow, or fallback decision path.

## Pre-deploy gates

1. Run `php artisan authz:cutover-preflight`; the final line must be `READY`.
2. Run `php artisan authz:parity-report --json=storage/app/authz-integrity.json`.
   The report is now a canonical integrity report: orphan, unknown, duplicate,
   and cross-organization counts must all be zero.
3. Run the backend, frontend, and integration authorization suites in CI.
4. Confirm `/api/user` exposes only canonical capabilities, access, and role
   assignments.

There is no runtime-mode configuration or environment switch. The running
release always evaluates the canonical authorization graph.

## Rollback

Application rollback means deploying the previous release and restoring its
matching database snapshot. Do not attempt to switch the running release to a
legacy mode: none exists. Preserve authorization audit rows and the canonical
integrity artifact for incident review.

The legacy authorization tables have been removed by the forward-only cleanup
migration. Restore a release and database snapshot as one matched unit; never
recreate only the old tables inside a canonical release.
