# Local System Stabilization Design

**Date:** 2026-07-10
**Status:** Approved for planning
**Branch:** `fix/local-system-stabilization`
**Base:** `origin/main` at `7ee8641c6e7d473183921ae117b17f934b175580`

## Purpose

Stabilize Erada locally before any further publication or deployment. The work closes the confirmed deployment, authorization, tenant-isolation, data-integrity, API/UI-contract, and test-reliability findings from the 2026-07-10 review. Production access, GitHub Actions recovery, pushing, pull requests, and deployment remain deferred until the local definition of done is satisfied and the user explicitly authorizes the release phase.

## Delivery Model

Work stays on one branch. Read-only MiniMax-M3 scouts may investigate independent domains in parallel. Exactly one MiniMax-M3 worker edits the shared working tree at a time. The primary agent reviews every task for security, architecture, and cross-task consistency before a scoped commit is accepted.

The local Tariq role wiring is a prerequisite. `tariq-worker` and `tariq-scout` must route through the registered `minimax` provider, obtain authentication only from `MINIMAX_API_KEY` or macOS Keychain, and pass both the existing wiring verifier and a live non-mutating MiniMax request. No secret may be written to Codex config or the repository.

## Global Constraints

- Start from `origin/main`; do not build this stabilization series on either deploy-repair draft branch.
- Use one normal branch and the shared checkout. Do not create a worktree, stash, reset, or use `git add -A`.
- Use test-driven development for every behavior change: reproduce the defect, observe the expected failing test, implement the minimum fix, then run the focused and regression gates.
- Never edit an applied migration. Schema or data changes require new additive migrations or explicit remediation commands.
- Preserve PostgreSQL-only behavior. Tests use the dedicated PostgreSQL test service on port `5433`, never the development database on `5432`.
- Treat tenant isolation and authorization as backend-enforced. Frontend guards are usability controls, not security boundaries.
- Do not push, open a pull request, modify GitHub billing, access production, or deploy during this project.
- Fix warnings in touched files and the confirmed circular-chunk defect. Do not mix the unrelated historical 1,060-warning lint backlog into the stabilization diff.
- Stage and commit only task-owned files. Each commit must be independently reviewable and reversible.

## Stabilization Phases

### 0. Restore MiniMax Worker Routing

The worker and scout profiles currently set `model = "minimax/MiniMax-M3"` but inherit the OpenAI provider. Add the recognized provider selection to both profiles, extend `verify-tariq-wiring.py` to assert the provider field, run strict configuration validation, and prove the route with a live scout response that reports `provider: minimax`. The top-level Codex model remains unchanged.

### 1. Activity-Log Authorization and Disclosure Boundary

`GET /api/activity-logs/{id}` must require `AUDIT_VIEW` for same-organization users as well as cluster users. Out-of-scope rows continue to return 404 before authorization to avoid existence disclosure.

Audit serialization has two explicit shapes:

- Same-organization authorized auditors receive the existing audit detail after universal secret, credential, network, and direct-PII redaction.
- Cross-organization cluster auditors receive a minimal audit envelope: identifiers, action, model label/type, scope, coarse actor identity, and timestamps. Free-form `description`, `reason`, `old_values`, `new_values`, and `metadata` are omitted or null. This prevents audit access from bypassing Projects, Tasks, HR, Surveys, or OVR capabilities.

CSV and JSON export must use the same serializer and scope rules as interactive reads. Tests cover sequential-ID enumeration without `AUDIT_VIEW`, user phone/name/job-title changes, task descriptions, project business fields, and export payloads.

### 2. Task Cluster Isolation and Sanitization

Task organization resolution must use the direct `tasks.organization_id` when neither project nor department supplies the scope. This closes the source-only task path admitted by the cluster query but missed by `scopeOrganizationId()`.

Cross-organization task responses use an explicit safe shape. Narrative fields, people, subtasks, counts, project/department/milestone names, and parent titles are withheld. Foreign-key identifiers needed for stable routing may remain. `is_private = true` is a real privacy floor: private non-personal tasks do not widen through cluster access unless an existing explicit need-to-know rule grants access.

HTTP tests exercise list and show responses for source-only Recommendation, MeetingResolution, Risk, KPI, Milestone, and OVR-derived tasks. They verify both row membership and every sensitive serialized field rather than testing policies in isolation.

### 3. Survey Historical Attribution and Export Lifecycle

Add a new respondent-organization snapshot to survey responses. New responses capture the respondent organization at submission time. A forward-only backfill attributes existing identified responses to the user's current organization and anonymous/deleted responses to the survey organization, recording this legacy fallback in tests and documentation. Cluster aggregates group by the snapshot rather than the mutable `users.organization_id` relation.

Cluster export becomes a direct authorized download response for JSON and CSV. It must not write persistent artifacts to `storage/app/private/exports`, must return a non-success response on serialization/stream failure, and must expose matching typed frontend API methods. Tests assert no filesystem residue.

### 4. Backend and Frontend Contract Alignment

- The user detail page supports the restricted cross-organization directory shape without casting it to a complete same-organization user or dereferencing absent roles and relations.
- Activity-log navigation and routes use the audit capability contract rather than `RequireAdmin`, allowing the non-admin cluster-auditor role to reach its intended page.
- OVR cluster statistics/export frontend methods call the cluster endpoints and use the backend-supported capability pair.
- Survey cluster statistics/export methods consume the aggregate-only contract and initiate the direct download response.
- Activity-log frontend types match the backend actor shape and do not expect an email that is intentionally absent.
- Imports that currently create circular DataTable chunks are changed to stable direct module imports or a chunk-safe shared boundary.

Frontend tests cover restricted user rendering, capability guards, cluster endpoint selection, aggregate-only rendering, download behavior, and absence of raw sensitive fields.

### 5. Role Provisioning and Migration Safety

Provision `cluster_auditor` for existing databases through a new idempotent additive data migration using exact role and capability keys. Fresh-install seeders retain the same definition. Re-running provisioning must not duplicate rows or broaden the role. Rollback must not remove capabilities or roles that predated the migration; a safe no-op down is preferable to destructive revocation.

Add a migration-safety preflight that reads the Laravel migrations table and blocks when known destructive historical migrations are pending, including patient-file-number recreation and legacy decisions/KPI table drops. The preflight prints the exact blocked migration names and exits non-zero. It never marks migrations as applied and never changes production data.

Create a separate, explicit local remediation path for each blocked historical migration. Rehearse it against a PostgreSQL database containing representative legacy data and prove preservation before the later release plan can authorize it.

### 6. Test Isolation and Quality Gates

Reproduce the combined CFA test failure in which later OVR classes see missing tables. Trace which test lifecycle drops or partially rolls back the schema, then fix the responsible test setup rather than accepting grouped retries as proof. CI retry logic must execute failed classes in true isolation; it must not rerun a group of failing classes against one contaminated schema.

Every phase runs focused tests before commit. At integration checkpoints run:

- `./vendor/bin/pint --test`
- `composer phpstan`
- `npm run typecheck`
- lint for the full frontend, with zero new warnings in touched files
- `npm test`
- `npm run build`
- the complete PHPUnit suite against port `5433`

Any full-suite failure is investigated and either fixed or reproduced as an independently documented environmental failure. It is not converted into a success merely because a later grouped retry passes.

### 7. Clean-Checkout Runtime Verification

Create a clean archive of the branch so ignored local runtime directories cannot mask defects. From that archive:

- run the dependency/bootstrap sequence used by CI and deployment;
- build the production Docker image without suppressing Composer or Artisan failures;
- start the container with production-like non-secret test configuration;
- verify readiness, `/api/health`, worker and scheduler startup, and expected failure behavior;
- verify that a failed health probe produces a failing gate.

These checks are local only. They do not call Dokploy or production services.

## Deferred Release Phase

Deployment workflow repair is designed only after local stabilization is green. The later release design must cover runtime directories in every fresh runner and Docker stage, correct `skip_migrations` graph semantics, concurrency control, environment protection, production database TLS propagation, SHA-aware readiness, failure propagation, and an explicit rollback strategy. GitHub Actions billing must be healthy before any check result is treated as code evidence.

No release action is part of the current implementation authorization.

## Error-Handling Principles

- Authorization failures use 403 when the row is in scope but the action is denied; cross-tenant existence checks remain 404.
- Export creation or streaming failures return a non-success response and never claim a filename was created.
- Provisioning and remediation commands are idempotent and fail closed on ambiguous state.
- Migration-safety checks name the unsafe condition and stop before any schema or data mutation.
- Health, bootstrap, Composer, migration, and container-start failures remain non-zero; no `|| true` or final `exit 0` may mask them.

## Definition of Done

Local stabilization is complete only when:

1. Every confirmed P0-P2 review finding in this specification has a regression test and an implemented fix.
2. MiniMax worker/scout routing passes static and live verification without storing a secret.
3. Authorization and tenant-isolation tests cover HTTP entry points and serialized payloads.
4. Historical survey attribution remains stable after a user moves organizations or is deleted.
5. Cluster export leaves no persistent local file and is usable through the frontend.
6. Existing databases can receive the cluster-auditor role idempotently.
7. Unsafe pending legacy migrations are blocked and their remediation paths preserve representative data locally.
8. The combined CFA tests and the full PHPUnit suite finish without schema-contamination failures.
9. PHP and frontend quality gates pass, with no new warnings in touched files and no circular DataTable chunk warnings.
10. A clean production Docker image boots locally and fails loudly when readiness fails.
11. Git status is clean and all commits are scoped, reviewed, and reversible.
12. No push, pull request, production access, or deployment has occurred.

## Exclusions

- The unrelated historical frontend lint backlog outside touched files.
- Low-confidence product-policy questions such as small-cohort suppression thresholds, unless a regression test proves an existing stated policy is violated.
- GitHub billing repair, production migration execution, production secrets, Dokploy changes, and deployment.
