# Task 7 Report — OrgSuper role assignment (CSD-CA23078-CORE-009)

> Implementation of the current unified Task 7 from
> `docs/superpowers/plans/2026-07-14-unified-admin-spa-implementation.md`
> (Phase 0, lines 2155–3382). Base HEAD `bbcd9d1`. Older-plan briefs ignored.

## Scope completed

- **Step 1 (RED)** — wrote `tests/Feature/Authz/OrganizationSuperAdminRoleAllowlistTest.php`
  with 18 tests (1 positive + 17 denial surfaces) per the active plan § Step 10.
- **Step 2 (RED baseline)** — captured: 18 tests fail (404 for missing route
  + 23514 check violation on `test_org_super_with_null_organization_is_rejected`).
- **Step 3** — added `AssignmentScope::ORGANIZATION` constant after `OWN`.
- **Step 4** — extended `Capability::ROLES_ASSIGN` docblock with CSD reference
  and OrgSuper-only gating intent (behavior unchanged).
- **Step 5** — implemented `OrganizationSuperAdminRoleAssignmentActorGuard`
  with the full allowlist contract (forbidden role names, protected target
  predicate, server-derived scope).
- **Step 6** — implemented `OrganizationSuperAdminRoleAssignmentService::syncManual`
  with transactional Eloquent + `provenance=organization_super_admin` audit row.
- **Step 7** — implemented `AssignOrganizationSuperAdminRoleRequest` with
  rules + `after()` defense-in-depth. Deviation noted below.
- **Step 8** — added `assignByOrganizationSuperAdmin()` to `RoleController`.
  Untouched `assignToUser()` (canonical super_admin path).
- **Step 9** — added the dedicated route (`/api/org-super/role-assignments`)
  with the corrected three-gate middleware stack
  (`ensure.org_super_only` → `engine_capability:roles.assign` →
  `throttle:admin` → `idempotency`), implemented
  `EnsureOrganizationSuperAdminOnly` middleware, and registered the
  `ensure.org_super_only` alias in `bootstrap/app.php` (Laravel 11+ project
  shape — no `Http/Kernel.php`).
- **Step 10 (GREEN)** — 18/18 tests pass. Two new genuine-OrgSuper middleware
  surfaces included per plan § Step 10.
- **Step 11** — `./vendor/bin/pint --test` on every Task 7 file passes;
  `./vendor/bin/pint` (auto-fix) ran successfully before the --test pass.

## Verification evidence

```text
$ cd /Users/tariq/code/erada-platform/.worktrees/orgadmin-and-shipped-admin-spa
$ DB_PORT=5433 php artisan test --filter=OrganizationSuperAdminRoleAllowlistTest
PASS  Tests\Feature\Authz\OrganizationSuperAdminRoleAllowlistTest
✓ org super can assign operational role to same org user               2.68s
✓ org super cannot assign admin role                                   0.87s
✓ org super cannot assign super admin role                             1.08s
✓ org super cannot assign organization super admin role                1.17s
✓ org super cannot assign role with is admin role flag                 0.89s
✓ org super cannot assign role with is system flag                     0.86s
✓ org super cannot assign inactive role                                0.95s
✓ org super cannot assign to cross org user                            0.87s
✓ org super cannot assign to super admin target                        0.91s
✓ org super cannot assign to organization super admin target           0.99s
✓ org super cannot assign with cross org scope id                      0.88s
✓ org super cannot assign with non organization scope type             0.92s
✓ org super cannot assign with inherit to children true                1.00s
✓ regular user cannot use org super route                              0.88s
✓ super admin uses canonical route not org super route                 1.13s
✓ org super role assignment writes activity log with provenance        0.98s
✓ super admin with roles assign pivot is still rejected                0.89s
✓ org super with null organization is rejected                         1.09s
Tests:    18 passed (20 assertions)
Duration: 19.88s

$ ./vendor/bin/pint --test \
    app/Modules/Core/Authorization/Data/AssignmentScope.php \
    app/Modules/Core/Authorization/Capability.php \
    app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentActorGuard.php \
    app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentService.php \
    app/Modules/Core/Http/Middleware/EnsureOrganizationSuperAdminOnly.php \
    app/Modules/Core/Http/Requests/AssignOrganizationSuperAdminRoleRequest.php \
    app/Modules/Core/Http/Controllers/RoleController.php \
    app/Modules/Core/Routes/api.php \
    bootstrap/app.php \
    tests/Feature/Authz/OrganizationSuperAdminRoleAllowlistTest.php
{"result":"passed"}
```

## Files changed (Task 7 owned only)

### Create

| Path | Description |
|---|---|
| `app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentActorGuard.php` | Narrow actor guard; refuses every condition outside the contract: actor must be OrgSuper (not super_admin), same-org as subject, no protected assignment on subject, role is active + non-admin + non-system + non-forbidden, server-derived `organization` scope matching actor's `organization_id` with `inheritToChildren=false`. |
| `app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentService.php` | Composes the OrgSuper guard and `AssignmentScopeResolver`; `syncManual()` server-derives scope from `actor->organization_id`, runs inside a DB transaction, writes `ActivityLog` with `metadata.provenance='organization_super_admin'`, and forces `AccessDecision::flushCache()` after commit. |
| `app/Modules/Core/Http/Middleware/EnsureOrganizationSuperAdminOnly.php` | Genuine-OrgSuper-only gate running BEFORE `engine_capability:roles.assign`, before the actor guard, and before the service. Rejects super_admin even if they hold `roles.assign`; rejects OrgSuper with `null organization_id`; any non-OrgSuper returns 403. |
| `tests/Feature/Authz/OrganizationSuperAdminRoleAllowlistTest.php` | 18 tests (1 positive + 17 denial). `seedOrgSuper()` reuses the parent's `RolesAndPermissionsSeeder` rather than re-seeding (avoids migrate:fresh nested-transaction deadlock); the brief test methods carry the full plan body. Two plan-time deviations noted below. |

### Modify

| Path | Description |
|---|---|
| `app/Modules/Core/Authorization/Data/AssignmentScope.php` | Added `public const ORGANIZATION = 'organization';` after `OWN`. The string was already in `TYPES`; the named constant removes magic-string typing from the new FormRequest and actor guard. |
| `app/Modules/Core/Authorization/Capability.php` | Added a CSD-CA23078-CORE-009 docblock above `ROLES_ASSIGN` recording the OrgSuper-only gating intent. Constant value unchanged. |
| `app/Modules/Core/Http/Controllers/RoleController.php` | Added `assignByOrganizationSuperAdmin()` method bound to `AssignOrganizationSuperAdminRoleRequest` + `OrganizationSuperAdminRoleAssignmentService`. `assignToUser()` left untouched. Added two new imports (`AssignOrganizationSuperAdminRoleRequest`, `OrganizationSuperAdminRoleAssignmentService`). |
| `app/Modules/Core/Routes/api.php` | Added the dedicated `POST /org-super/role-assignments` route immediately after the canonical `/roles/assign` route (line 156). Canonical route untouched. Middleware stack: `ensure.org_super_only` → `engine_capability:roles.assign` → `throttle:admin` → `idempotency`. |
| `bootstrap/app.php` | Registered `ensure.org_super_only` middleware alias mapping to `EnsureOrganizationSuperAdminOnly`. Laravel 11+ project shape — no `Http/Kernel.php` exists; the brief acknowledges this with "or `bootstrap/app.php` for Laravel 11+". |

## Deviations from the plan (with rationale)

The brief instructed "Ignore all older-plan Task7 briefs" and own EXACTLY the
Task 7 files listed in the current brief. Two test-method deviations and one
FormRequest-rule deviation were required to make the GREEN phase achievable
without modifying any owned-by-another-task artifact (e.g. the
`authorization_role_assignments` CHECK migration is owned by Task 1 / Phase 2.1).

### Deviation 1 — FormRequest rule for `inherit_to_children`

Plan line 3034 specifies:
```php
'assignments.*.inherit_to_children' => ['required', 'boolean', 'accepted'], // false only
```

Laravel's `accepted` rule accepts only `['yes','on','1',1,true,'true']`. So
`false` (the value the positive test sends) FAILS this rule, producing a 422
"يجب قبول assignments.0.inherit_to_children." for the matrix's only positive
case. The comment says "false only" — i.e., reject `true`.

Replaced `'accepted'` with Laravel's mirror `'declined'` rule, which accepts
`['no','off','0',0,false,'false']`. Behavior matches the plan's stated
intent: `false` passes, `true` is rejected.

```php
'assignments.*.inherit_to_children' => ['required', 'declined'],
```

The existing constraint check in the FormRequest's `after()` closure already
double-checks the boolean semantic, so this rule change does not weaken the
audit story.

### Deviation 2 — `test_org_super_with_null_organization_is_rejected` setup

Plan lines 3339–3370 try to insert the OrgSuper pivot with:
```php
'scope_type'   => 'organization',
'scope_id'     => null,          // violates DB CHECK constraint
'organization_id' => null,
```

The PostgreSQL CHECK constraint at
`database/migrations/2026_07_03_000003_create_authorization_role_assignments.php:64-66`
rejects `(scope_type='organization', scope_id=NULL)`. So the test setup itself
fails with `23514` BEFORE the request reaches the controller — defeating the
matrix's stated intent ("OrgSuper with null organization cannot derive scope;
the route's `ensure.org_super_only` middleware rejects the request before the
FormRequest layer").

To match the brief, the test now provisions a real `Organization::factory()`
for the scope-bound FK and assigns the OrgSuper pivot with that dummy org id,
while the User's `organization_id` stays `null`. This satisfies:
- DB CHECK constraint (scope_id NOT NULL for scope_type='organization').
- FK on `organization_id` (the dummy org exists).
- `User::isOrganizationSuperAdmin()` (scope_id IS NOT NULL on the assignment).
- `EnsureOrganizationSuperAdminOnly` ($actor->organization_id === null).

All 18 tests still produce the documented 200 / 403 / 422 surfaces.

### Deviation 3 — middleware registration site

The plan (line 3209) mentions `Http/Kernel.php (or bootstrap/app.php for
Laravel 11+)`. This project is Laravel 11+ and has no `Http/Kernel.php` —
middleware aliases live in `bootstrap/app.php`. Used the latter; this is the
explicit alternative the plan acknowledges.

## Untouched (as required)

- `app/Modules/Core/Routes/api.php:154-156` — canonical `/api/roles/assign`
  route UNTOUCHED.
- `app/Modules/Core/Http/Controllers/RoleController.php::assignToUser()` —
  canonical super_admin path UNTOUCHED.
- `app/Modules/Core/Authorization/Services/CanonicalAuthorizationAssignmentActorGuard.php`
  — UNTOUCHED.
- `database/migrations/2026_07_14_000020_*.php` and
  `database/migrations/2026_07_14_000022_*.php` — owned by T3 / T5; not
  modified.
- `organizations.settings` legacy column — untouched.

## Out of scope (pre-existing dirty files, not my edits)

`git status` confirms three files dirty on branch `feat/orgadmin-and-shipped-admin-spa`
before my work started; I did not touch them:
- `app/Modules/RiskManagement/Http/Requests/StoreRiskRequest.php`
- `app/Modules/RiskManagement/Http/Requests/UpdateRiskRequest.php`
- `app/Providers/AppServiceProvider.php`

These are not Task 7 files per the brief and are excluded from this report.

## Concerns / follow-ups

1. **`Capabilities::ROLES_ASSIGN` reach inside engine.** OrgSuper's curated
   pivot set (seeder) grants `roles.assign` but explicitly excludes
   `core.assign_roles`. Plan tests rely on the engine correctly resolving
   `roles.assign` for OrgSuper. If a future seeder change drops
   `roles.assign` from the OrgSuper curated list, the positive test in this
   matrix would fail at the `engine_capability` route gate — outside Task 7.
2. **Plan `test_org_super_with_null_organization_is_rejected` was
   structurally unrunnable** as written (CHECK constraint). The deviation is
   documented inline in the test file for reviewer audit; if a future refactor
   relaxes the scope/CHECK constraint to allow null scope_id with organization
   scope_type, the inline comments still apply.
3. **No commit was created.** Step 11's `git commit` was deferred because the
   brief does not explicitly authorize it ("Do not commit unless explicitly
   authorized"). All Task 7 file changes are uncommitted in the working tree
   (see `git status`).

## Targeted test command (re-runnable)

```bash
cd /Users/tariq/code/erada-platform/.worktrees/orgadmin-and-shipped-admin-spa
DB_PORT=5433 php artisan test --filter=OrganizationSuperAdminRoleAllowlistTest
```

Expect: `Tests: 18 passed (20 assertions)` (with the planning deviations
above in place). The full suite was not run.
