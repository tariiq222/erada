# Codex Security Diff Scan Completion — Plan

## Slice 1 — scout_remaining_rows [DONE in scout]

Enumerate remaining worklist rows; classify by area; verify path existence.

Result: 111 rows remaining. 47 paths are absent in HEAD (mostly `resources/admin/*`, `e2e/admin/*`, `scripts/check-admin-boundaries.mjs`, `tsconfig.admin.json`, `tsconfig.admin.test.json`, `vite.admin.config.ts`, `vitest.admin.config.ts`, `playwright.admin.config.ts`, and 2 deleted seeders). 64 paths exist and need real `full_file_read` receipts.

## Slice 2 — receipt_pass_not_applicable [BLOCKING FIRST]

Append `not_applicable` receipts for the 47 deleted-path rows using direct `git diff main..HEAD` evidence.

## Slice 3 — receipt_pass_backend

Process remaining existing backend paths:
- `app/Modules/OVR/Http/Requests/UpdateIncidentTypeRequest.php`
- `app/Modules/OVR/Http/Resources/IncidentReportResource.php`
- `app/Modules/OVR/Routes/api.php`
- `app/Modules/RiskManagement/Http/Resources/RiskResource.php`
- `app/Modules/Shared/Formatters/ActivityLogFormatter.php`
- `app/Modules/Shared/Providers/SharedServiceProvider.php`
- `app/Modules/Surveys/Providers/SurveysServiceProvider.php`
- `app/Modules/Surveys/Routes/api.php`

For each: full read → receipt with `full_file_read: true` → if any candidate surfaces, append to `raw_candidates.jsonl` and the matching candidate ledger.

## Slice 4 — receipt_pass_seeders_and_db

Process remaining database paths (9 seeders/migrations). Most should be `not_applicable` (deleted) or trivially safe. Read each, append receipts.

## Slice 5 — receipt_pass_resources_js

Process remaining frontend resources/js paths (46 files). Most are admin SPA files in `resources/js/pages/admin/*`, `resources/js/admin/*`. Read each fully; receipts should reference the corresponding backend controllers.

## Slice 6 — receipt_pass_configs_tooling_i18n

Process remaining config + scripts + i18n + tests paths. Most should be `not_applicable` because the file is unchanged (no diff) or out of security scope. Read fully; mark `no_candidate` with evidence.

## Slice 7 — reconcile_orphan_candidate

Open `artifacts/05_findings/authorization-role-edit-non-superadmin-escalation/candidate_ledger.jsonl` (3 phases present) and decide:

- Compare coverage against `CSD-CA23078-HR-002` (department capacity role escalation) and `CSD-CA23078-PROJECTS-002` (project update self-grant).
- The orphan covers `RoleController::syncCapabilities` reached via `PUT /roles/{roleDefinition}` with `roles.edit`. Neither HR-002 nor PROJECTS-002 cover this path.
- Decision: promote to a new canonical CSD id `CSD-CA23078-CORE-006` and add a discovery/validation/attack-path entry to `raw_candidates.jsonl`. Mark the orphan directory as a supporting evidence attachment, not a separate finding. Record reconciliation in `artifacts/04_reconciliation/candidate_dedup.json`.

## Slice 8 — verify_candidate_ledgers

For each candidate in `raw_candidates.jsonl`:
- Confirm three-phase row presence in `<id>/candidate_ledger.jsonl`.
- Confirm `validation_report.md` exists.
- Identify any candidate with a missing phase; backfill.

## Slice 9 — generate_canonical_artifacts

Write:
- `artifacts/03_coverage/coverage.json` — counts: total/receipted/spurious, by disposition, by area.
- `artifacts/00_manifest/scan-manifest.json` — base/head/commit/started_at/finished_at/diff stats.
- `artifacts/05_findings/findings.json` — array of reportable findings with severity/title/summary/cwe/evidence pointers.

Validate every JSON with `jq -e .`.

## Slice 10 — write_final_report

Append `## Final Scan Report` section to `docs/runbooks/authorization-full-cutover-security-review-handoff.md`. Include:

- Closure counts (309/309, candidate ledger completeness).
- Reconciliation record pointer.
- Final list of reportable findings with severity.
- Explicit stop condition: merge blocked until findings remediated.

## Slice 11 — close

Update goal.yaml with all slice statuses = completed and evidence pointers. Update Goal Plugin via `update_goal status:complete` with evidence summary.

## Sequencing constraints

- Slices 2 and 3 must finish before raw candidate additions.
- Slice 7 must finish before `findings.json` generation.
- Slice 8 must finish before `findings.json` generation.
- Slice 10 must finish before slice 11.
