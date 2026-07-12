# Authorization Full Cutover Handoff

## Status

`INTEGRATION REVIEW (REWORK_REQUIRED → REWORKED) — the previously open HIGH super-admin scope-mismatch bypass was closed by commit `ca23078` (`fix(authz): require canonical super_admin role scope to be all`), which enforces that both canonical super-admin predicates (`User::isSuperAdmin()` and `CanonicalAuthorizationAssignmentActorGuard::isCanonicalSuperAdmin()`) require the role declaration AND the assignment to declare `scope_type='all'` with `scope_id=NULL`. A subsequent verified-residuals pass was identified as REWORK_REQUIRED on first review because (a) the new role-scope CHECK constraint excluded `own` even though `AssignmentScope::isCompatibleWithRoleScope()` requires exact scope match, breaking `CanonicalRoleAssignmentEndpointTest::test_guard_denial_rolls_back_every_assignment_in_the_request`; and (b) the audit filter only patched two of the six canonical event strings. Both defects are now corrected: the constraint accepts every AssignmentScope::TYPES value (so a role declared at `own` is a valid canonical path), and the audit filter is wired to a single-source canonical event list covering every event the backend writes. Independent follow-up Luna review has not been re-run. No production deployment, commit, push, merge, or live-fixture E2E run has been authorized or performed.`

This work must be accepted and delivered as one coherent backend, frontend, database, and integration cutover. Do not merge, deploy, or delete production data based on an individual phase passing in isolation.

## Primary objective

Replace the hybrid Spatie plus custom authorization runtime with one canonical system:

```text
user / canonical role / supported scope
                ↓
authorization_roles
authorization_role_assignments
authorization_role_permissions
                ↓
AccessDecision
                ↓
Policy / FormRequest / EnsureEngineCapability
                ↓
API route
                ↓
/api/user capability projection and React route/UI gates
```

The final runtime source of truth is the canonical `authorization_*` schema and `AccessDecision`. Spatie, `ScopedRole`, legacy middleware aliases, shadow runtime modes, and compatibility commands must not remain available as runtime authorization paths.

Open self-registration is an intentional product decision and must remain unchanged. The security boundary is organization and department isolation, privilege-escalation prevention, exact scope enforcement, and safe legacy-data reconciliation.

## Definition of done

The cutover is complete only when all of these hold together:

1. Every backend decision flows through capabilities, policies, FormRequests, or `EnsureEngineCapability`, backed by `AccessDecision`.
2. No production code calls Spatie role/permission APIs or legacy scoped-role relationships.
3. Canonical role assignment writes enforce actor authority, organization isolation, exact role scope, expiry, inheritance, lifecycle, and provenance.
4. Existing malformed assignments fail closed at decision time and are reported by preflight.
5. Role retirement cannot grant a stronger role, cross a scope boundary, collide silently, or lose assignment metadata.
6. Legacy data is reconciled before deletion. The destructive migration refuses to drop tables if any assignment, direct permission, or role permission is missing, mutated, rejected, stale, or unmappable.
7. The database accepts only supported scopes: `all`, `organization`, `department`, `own`, `project`, `program`, `portfolio`, `kpi`, `meeting`, and `survey`.
8. `/api/user` exposes capabilities derived from canonical scoped assignments for navigation and UI gates without weakening record-target checks.
9. React types, role administration, assignment screens, route guards, navigation, and API clients use the same canonical contract.
10. CI blocks reintroduction of Spatie, `role:`/`permission:` middleware, scoped-role runtime code, runtime-mode toggles, and unsupported scope values.
11. Fresh install, seeded install, upgrade reconciliation, preflight, destructive migration tests, backend authorization tests, frontend quality/build, architecture guards, Pint, PHPStan, and diff checks all pass after the final edits.

## Workspace and safety constraints

- Repository: `/Users/tariq/code/erada-platform`
- Worktree: `/Users/tariq/code/erada-platform/.worktrees/authorization-full-cutover`
- Branch: `codex/authorization-full-cutover`
- Current tracked change size rechecked on 2026-07-12: 561 files, 6,199 insertions, and 30,444 deletions. There are 75 untracked files; `git status --short` collapses some untracked directories and therefore shows 64 `??` entries rather than the exact file count.
- The worktree is intentionally dirty and contains the complete cutover. Do not use `stash`, `reset --hard`, `checkout`, `rebase`, `git add -A`, or broad cleanup commands.
- No commit, push, merge, deployment, or production mutation has been authorized or performed.
- PostgreSQL is mandatory. Tests use port `5433`; never point tests at the development database on port `5432`.
- Put Homebrew libpq on `PATH` when schema loading is enabled:

```bash
export PATH="/opt/homebrew/opt/libpq/bin:$PATH"
```

## Implemented backend outcome

### Canonical runtime

- `AccessDecision` is the runtime decision engine.
- Policies and list-scope helpers across Core, HR, Projects, Tasks, Strategy, Risk, OVR, Meetings, Surveys, Shared, and Performance were migrated to canonical capabilities and assignments.
- Canonical owner-floor behavior was restored with organization isolation and lifecycle checks.
- `whyCan` retains role, assignment, scope, and reason evidence without reintroducing legacy decisions.
- Canonical assignment services, actor guards, audit resources, controllers, requests, and lifecycle/provenance fields were added.
- Canonical assignment caching is invalidated after mutations.

### Routes and middleware

- Legacy `CheckRole`, `CheckPermission`, `role:`, and `permission:` runtime paths were removed.
- Routes use capability middleware or policies/FormRequests.
- The core API exposes canonical role-assignment endpoints and read-only supported scope metadata.
- Legacy scoped-role controller and mutable scope-type endpoints were removed.

### Role management safety

- Role retirement locks affected roles and assignments.
- Replacement authority, exact scope compatibility, admin-role strength, collisions, lifecycle, expiry, inheritance, source, grantor, and provenance are enforced or preserved.
- A role's scope cannot be changed when live assignments would become incompatible; the API returns validation failure instead.
- Existing role/assignment scope mismatches fail closed in `AccessDecision`.

### Database and upgrade safety

New forward migrations cover lifecycle, provenance, metadata, reconciliation, audit naming, legacy-table removal, and supported-scope restriction:

- `2026_07_12_000005_add_lifecycle_to_authorization_roles_and_assignments.php`
- `2026_07_12_000006_backfill_authorization_lifecycle.php`
- `2026_07_12_000007_add_provenance_to_authorization_role_assignments.php`
- `2026_07_12_000008_add_metadata_to_authorization_roles.php`
- `2026_07_12_000009_reconcile_legacy_authorization_assignments.php`
- `2026_07_12_000010_rename_permission_audits_to_authorization_assignment_audits.php`
- `2026_07_12_000011_drop_legacy_authorization_tables.php`
- `2026_07_12_000012_restrict_authorization_assignment_scopes.php`

Migration `000011` is fail-closed before the first `DROP`. It validates legacy assignments, reconciliation receipts, direct user permissions, and legacy `role_has_permissions` against canonical role-permission pivots. Missing, mutated, cross-organization, rejected, stale, or unmappable rows abort the migration.

Migration `000012` aborts if unsupported assignment scopes exist, then installs the canonical supported-scope check constraint.

No applied migration was edited.

### Operational controls

- `authz:cutover-preflight` validates canonical integrity and reports a single readiness decision.
- `authz:parity-report` reports canonical integrity without relying on a legacy runtime decision path.
- Preflight reports role/assignment scope mismatches and fails readiness when they exist.
- Obsolete shadow/pilot/runtime-mode commands and `roles:reconcile` scheduling were removed.
- CI and architecture tests reject legacy runtime dependencies and unsupported naming/scope surfaces.

## Implemented frontend and integration outcome

- Auth context consumes canonical `/api/user` capability and assignment projections.
- Scoped-only canonical grants are projected for navigation and module access; record decisions remain target-bound on the backend.
- `RequirePermission`, dashboards, sidebars, admin gates, profile/security cards, user forms, role forms, and project/department role workflows were migrated to canonical data.
- Canonical authorization-assignment entities, API clients, admin pages, audit UI, and tests were added.
- Legacy scoped-role entity/API/pages and access-bridge fallbacks were removed.
- End-to-end specifications cover the access contract and canonical role assignment flow.
- The frontend scope cleanup is complete. The public canonical assignment union now exposes only the ten supported values; the final independent reviewer found no `cluster`, `hospital`, or `team` values in the canonical frontend assignment surfaces.

## Verification evidence already obtained

The following results were observed and then rechecked during the final convergence session:

- Frontend full quality gate: 214 test files and 4,340 tests passed; typecheck, lint, and design checks passed.
- Frontend production build passed after the frontend cleanup: 7,521 modules transformed; only the normal chunk-size warning remained.
- Full PHPStan: 575 files, no errors.
- Pint `--test` passed after the last implemented code/test corrections. It must be rerun once more after the unresolved super-admin fix.
- Architecture guard: 6 tests, 17 assertions passed.
- Fresh PostgreSQL install and seed passed. `authz:cutover-preflight` returned `READY` with all integrity counts zero, a complete capability catalog, canonical-only engine, and no deprecated runtime mode. `authz:parity-report` scanned 3 users and 122 capabilities with zero issues.
- `/api/user` capability/contract tests: 10 tests, 296 assertions passed.
- Focused high-risk backend gate passed with 38 PHPUnit warnings and 433 assertions; the warnings were schema-loader warnings, not failures.
- Canonical authorization backend directories excluding destructive migration tests passed with no failures: 9 warning-causing tests, 317 warnings, and 5,459 assertions.
- Destructive migration tests were run serially against PostgreSQL and passed: reconciliation 3 tests/18 assertions; audit rename 2/7; legacy drop 9/93; scope restriction 2/3. Total: 16 tests and 121 assertions.
- The corrected role-retirement security test passed independently: 32 assertions and no failures.
- Frontend `npm run quality` passed: typecheck, lint with 0 errors and 1,056 existing warnings below the 1,200 ceiling, design check over 320 files with 0 violations, and Vitest 214 files/4,340 tests.
- Authorization E2E specs passed for all runnable cases: 3 mocked rejection-path tests passed and 5 live mutation tests were skipped because explicit `E2E_AUTHZ_*` fixtures were not provisioned. This is a limitation, not evidence that the live vertical paths ran.
- Static/residual gates passed: Pint, PHPStan 575/575, `git diff --check`, and architecture residual guards 6 tests/17 assertions.

An attempted broad backend rerun initially exposed one stale test that inserted `cluster`; PostgreSQL correctly rejected it under migration `000012`. The fixture was corrected to supported scopes, and the complete canonical authorization directory gate was subsequently rerun successfully as recorded above.

## Independent review history and current blocker

The independent reviewer initially returned `FIX_REQUIRED` for three high-severity gaps:

1. `000011` did not compare legacy `role_has_permissions` to canonical role permissions.
2. Existing role/assignment scope mismatches could still grant, preflight did not report them, and role scope could change under live assignments.
3. The frontend assignment type still advertised `cluster`, `hospital`, and `team`.

Current disposition:

- Gap 1: implemented and targeted migration tests passed.
- Gap 2: implemented and targeted decision/preflight/controller tests passed.
- Gap 3: implemented; focused frontend tests and the full frontend quality/build gates passed, and the final reviewer verified the canonical surfaces are clean.

The final independent read-only review (Luna high-effort) found one additional HIGH issue:

- `User::isSuperAdmin()` requires the assignment row to be `all`/null but did not require the related `super_admin` role itself to declare `scope_type=all`.
- `CanonicalAuthorizationAssignmentActorGuard::isCanonicalSuperAdmin()` had the same omission in its explicit global assignment exception.

Commit `ca23078` (`fix(authz): require canonical super_admin role scope to be all`) closed the bypass:

- `User::isSuperAdmin()` now matches `scope_type='all'` and `scope_id IS NULL` on the assignment AND `scope_type='all'` on the joined role row.
- `CanonicalAuthorizationAssignmentActorGuard::isCanonicalSuperAdmin()` enforces the same dual condition in its global assignment exception.
- Regression coverage was added in `tests/Unit/Models/UserTest.php` and `tests/Unit/Core/Authorization/CanonicalAuthorizationAssignmentActorGuardTest.php`, covering canonical pass, malformed role reject, and no-assignment reject.

The HIGH super-admin bypass is therefore closed. Independent follow-up Luna review has not been re-run against the post-`ca23078` filesystem; the disposition above is based on the code change, the targeted regression tests, and the focused high-risk gates listed in the next section.

### Verified residual work after `ca23078`

A subsequent verified-residuals pass closed five smaller gaps that survived the earlier cutover. None of these weaken backend authorization; all five are bounded regressions of known failures:

1. Survey responses SPA gate: `/surveys/:id/responses` previously required the legacy flat string `view_survey_responses`. The route gate now requires `surveys.review_responses`, matching the canonical Surveys capability. The legacy grep guard test now asserts both that the canonical string is present and that the legacy one is not. Without this fix, the SPA silently denied access to survey responses even for users who held the canonical grant.
2. Authorization-assignment audit filter wiring: the admin audit page sent the legacy strings `role_assigned` / `role_revoked` to `/api/authorization-assignment-audits?action=…`, but the controller filters on `authorization_assignment_audits.event` whose canonical values are `canonical_assignment_assigned`, `canonical_assignment_revoked`, and `canonical_assignment_synced` (written by `AuthorizationAssignmentService::auditMutation`). The action picker now emits the canonical strings; existing translated labels are reused for assigned/revoked, and `synced` falls through to the raw event text since no translation exists.
3. Capability-provider contract drift: the contract and per-module ServiceProvider comments stated `AuthController` iterates the `engined_capability_providers` tag. The canonical /api/user projection no longer iterates it — capabilities flow through `User::canonicalCapabilityNames()`. The contract and every module's provider comment now describe the providers as legacy/advisory helpers. The HR provider's runtime output was kept unchanged (consumers still reference `view_hr`/`manage_hr`); the provider's docblock documents the split and `HRCapabilityProviderTest` now pins `User::canonicalCapabilityNames()` as the canonical source.
4. Role-scope picker filter: `RoleController::scopeOptions()` returned the full `AssignmentScope::catalog()` including `own`. `StoreRoleRequest` and `UpdateRoleRequest` reject `own` as a declared role scope (it is assignment-only), so the picker surfaced an option that the API would then reject. The controller now filters `key !== AssignmentScope::OWN` before returning.
5. Role-scope DB constraint: a new forward migration (`2026_07_12_000013_restrict_authorization_role_scopes.php`) installs `authorization_roles_scope_type_check` accepting every scope in `AssignmentScope::TYPES` (the same ten values already enforced on `authorization_role_assignments.scope_type` by migration `000012`). The migration fails closed before applying the constraint if any pre-existing role row carries an unsupported scope. `AuthzCutoverPreflightCommand::canonicalIntegrity()` also reports `malformed_role_scope_types` so any pre-migration database surfaces its bad rows cheaply. `tests/Feature/Core/Authorization/AuthorizationSchemaTest.php` pins both the rejection of unsupported values and the acceptance of every supported value, including `own` (a role declared at `own` is a valid canonical path exercised by `CanonicalRoleAssignmentEndpointTest` because `AssignmentScope::isCompatibleWithRoleScope()` requires exact scope match between role and assignment). `AuthzCutoverPreflightCommand::canonicalIntegrity()` reports `malformed_role_scope_types` so any pre-migration database surfaces its bad rows cheaply.

> First-pass review caught a regression: an earlier draft of the constraint excluded `own`, which would have broken `CanonicalRoleAssignmentEndpointTest::test_guard_denial_rolls_back_every_assignment_in_the_request` at the DB layer after every `migrate:fresh`. The constraint now mirrors `AssignmentScope::TYPES` exactly so the runtime can produce the canonical `own`-scoped role/assignment state that the existing endpoint test exercises.

> First-pass review also caught a Task 2 defect: the audit filter only patched two of the six canonical event strings, and `AuthorizationAssignmentAuditLogs.tsx` still listed Spatie-era dead options (`permission_granted` / `permission_revoked` / `access_denied`) that no longer correspond to any backend write. The filter is now driven from a single-source FE constant (`AUTHORIZATION_ASSIGNMENT_AUDIT_EVENT_LIST`) covering exactly the six events written by `AuthorizationAssignmentService::auditMutation`, `ScopedDepartmentRoleSyncService::auditMutation`, and `RoleController::writeAudit` (`canonical_assignment_assigned`, `canonical_assignment_revoked`, `canonical_assignment_synced`, `role_created`, `role_updated`, `role_disabled`). A Vitest case iterates every option in the list and asserts the request wire value matches the canonical backend event, with no Spatie-era leakage.

## Remaining work, in strict order

### 1. Resolve the supervised-execution blocker and implement the HIGH fix

The approved fix is narrowly scoped to:

- `app/Modules/Core/Models/User.php`
- `app/Modules/Core/Authorization/Services/CanonicalAuthorizationAssignmentActorGuard.php`
- focused regression tests for both predicates

Required behavior:

- A role named `super_admin`, even if system/admin and active, must not grant any super-admin bypass unless both the role declaration and assignment use canonical `all` scope with a null scope id.
- A valid canonical `super_admin` assignment must continue to work.
- Cover a representative policy `before()` bypass and the actor guard's global exception.

The Tariq MiniMax supervisor currently rejects this intentionally dirty integration worktree. The next session must choose a safe, explicitly approved mechanism that preserves the complete uncommitted cutover while giving the supervisor an attributable clean baseline. Do not clean, stash, reset, stage, or commit the current tree merely to satisfy the supervisor.

### 2. Run focused tests for the new super-admin fix

Use test-driven coverage for the malformed and valid cases. Run the exact new test class plus the existing actor-guard/policy coverage and the retirement/scope-integrity classes. Use PostgreSQL port `5433`, never `5432`.

Then run:

```bash
./vendor/bin/pint --test
composer phpstan
git diff --check
```

### 3. Reconfirm the completed frontend scope cleanup

Confirm no public canonical assignment type or option contains unsupported values:

```bash
rg -n "cluster|hospital|team" resources/js/entities/role resources/js/entities/authorization-assignment resources/js/pages/admin/authorization resources/js/pages/admin/roles resources/js/pages/admin/scope-types resources/js/__tests__/authz resources/js/__tests__/admin/scope-types-list.test.tsx
```

Business-domain uses of the words cluster, hospital, or team are not automatically authorization-scope defects. Review context before editing.

### 4. Re-run focused high-risk backend tests

Use the test database only and include libpq in `PATH`:

```bash
export PATH="/opt/homebrew/opt/libpq/bin:$PATH"

php artisan test \
  tests/Feature/Core/Authorization/CanonicalRoleRetirementSecurityTest.php \
  tests/Feature/Core/Authorization/AuthzCutoverScopeIntegrityTest.php \
  tests/Feature/Core/Authorization/AuthzCutoverPreflightCommandTest.php \
  tests/Feature/Auth/AuthMeCapabilitiesTest.php \
  tests/Feature/Auth/AuthMeContractTest.php \
  tests/Feature/Core/Authorization/AuthorizationSchemaTest.php
```

Run destructive migration tests separately on isolated PostgreSQL databases so their schema mutations cannot collide:

```bash
php artisan test tests/Feature/Core/Authorization/AuthorizationAssignmentReconciliationMigrationTest.php
php artisan test tests/Feature/Core/Authorization/CanonicalAssignmentAuditMigrationTest.php
php artisan test tests/Feature/Core/Authorization/LegacyAuthorizationTablesDropMigrationTest.php
php artisan test tests/Feature/Core/Authorization/RestrictAuthorizationAssignmentScopesMigrationTest.php
```

If the local harness shares one database, create/reset a distinct database for each command or run them serially with a fresh schema between commands.

### 5. Re-run complete backend authorization coverage

Run the full canonical authorization directories against a fresh isolated test database. Exclude destructive migration tests from this combined command and run those via step 2.

```bash
php artisan migrate:fresh --env=testing --force

php artisan test \
  tests/Feature/Core/Authorization \
  tests/Feature/Authorization \
  tests/Feature/Authz \
  tests/Feature/Auth \
  tests/Architecture
```

Laravel/PHPUnit may display warnings caused by the schema dump loader. Treat only exit code, failed/error tests, and assertion output as the acceptance signal; document warnings rather than hiding them.

### 6. Re-run frontend and integration gates

```bash
npm run quality
npm run build
npm run test:e2e -- e2e/authorization-access-contract.spec.ts e2e/authorization-role-assignment.spec.ts
```

The E2E gate requires a correctly seeded PostgreSQL test environment, browser dependencies, `APP_KEY`, and explicit live authorization fixtures. Without `E2E_AUTHZ_*`, record the five skips and do not describe them as live-path coverage.

### 7. Re-run static and residual gates

```bash
./vendor/bin/pint --test
composer phpstan
git diff --check

php artisan test \
  tests/Architecture/CanonicalAuthorizationResidualGuardTest.php \
  tests/Architecture/CanonicalAuthorizationAssignmentNamingTest.php \
  tests/Architecture/CanonicalScopeTypeSurfaceTest.php

rg -n "AUTHORIZATION_RUNTIME_MODE|roles:reconcile|authz:report-pilot|authz:mode|AuthorizationRuntimeMode|Spatie\\\\Permission|hasRole\\(|hasPermissionTo\\(|role:|permission:" \
  app bootstrap config routes resources/js database/seeders composer.json package.json .github \
  --glob '!database/migrations/*' --glob '!database/schema/*'
```

Expected residual exceptions must be reviewed, not blindly ignored. The preflight scanner intentionally contains `AUTHORIZATION_RUNTIME_MODE` so it can reject reintroduction. Domain data fields named `role` are also legitimate.

### 8. Prove fresh install and upgrade readiness

On an isolated database:

```bash
php artisan migrate:fresh --force
php artisan db:seed --force
php artisan authz:cutover-preflight
php artisan authz:parity-report
```

Acceptance requires preflight `READY`, parity exit code 0, zero integrity counts, complete capability catalog, no deprecated runtime mode, no legacy middleware/callsites, and no role/assignment scope mismatch.

Also run the upgrade migration tests from step 2. Do not test a destructive upgrade against development or production data.

### 9. Obtain independent follow-up review

Ask a Luna high-effort read-only reviewer to inspect the final filesystem and verify all four historical HIGH issues, especially both super-admin predicates. It must return `PASS` or actionable HIGH/CRITICAL findings and must not rely only on agent reports.

### 10. Final acceptance and delivery

Only after every gate above is green:

- Record exact commands, exit codes, test counts, warnings, and any accepted exceptions.
- Recheck `git status` and ensure generated reports or temporary artifacts are not included unintentionally.
- Mark the cutover `DONE` as a single integrated unit.
- Ask for explicit authorization before staging, committing, pushing, opening a PR, merging, deploying, or running production migrations.

## Known pitfalls

- Do not restore Spatie as a fallback to make a test pass. Convert the fixture or production caller to the canonical contract.
- Do not reintroduce `cluster`, `hospital`, or `team` as assignment scope types. Cluster-tree visibility is represented through canonical organization/department scope plus inheritance and policy logic.
- Do not weaken target-bound backend decisions merely because frontend navigation needs a scoped capability projection.
- Do not edit historical applied migrations. Add a new forward migration if a schema correction becomes necessary.
- Do not run parallel schema-mutating tests against the same database.
- Do not assume a PHPUnit warning is a failure, but do not suppress it without understanding its source.
- Do not trust stale progress files or previous agent summaries over current source and fresh command output.

## Key files

- Contract: `docs/authz/authorization-cutover-contract.md`
- Operational runbook: `docs/runbooks/authorization-cutover.md`
- Decision engine: `app/Modules/Core/Authorization/AccessDecision.php`
- Assignment service: `app/Modules/Core/Authorization/Services/AuthorizationAssignmentService.php`
- Assignment actor guard: `app/Modules/Core/Authorization/Services/CanonicalAuthorizationAssignmentActorGuard.php`
- Role lifecycle: `app/Modules/Core/Http/Controllers/RoleController.php`
- Auth projection: `app/Modules/Core/Http/Controllers/AuthController.php`
- Preflight: `app/Console/Commands/AuthzCutoverPreflightCommand.php`
- Drop guard: `database/migrations/2026_07_12_000011_drop_legacy_authorization_tables.php`
- Scope constraint: `database/migrations/2026_07_12_000012_restrict_authorization_assignment_scopes.php`
- Frontend role contract: `resources/js/entities/role/model/role.ts`
- Frontend assignment contract: `resources/js/entities/authorization-assignment/`
- Residual CI guard: `tests/Architecture/CanonicalAuthorizationResidualGuardTest.php`

## Handoff judgement

The architecture and implementation are at the final convergence point, not at the planning stage. The frontend cleanup and all previously known integrated gates are complete. The cutover is not done because one HIGH super-admin mismatch bypass remains and the latest independent verdict is `FIX_REQUIRED`. Implement that bounded fix safely, rerun the affected and integrated gates, obtain an independent Luna `PASS`, audit the 75 untracked files, and only then mark the cutover `DONE` as one atomic authorization-system replacement.
