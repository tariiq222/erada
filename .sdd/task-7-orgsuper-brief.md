# Task 7 Brief — OrgSuper role assignment

> Metadata-only extraction from the current active unified plan
> `docs/superpowers/plans/2026-07-14-unified-admin-spa-implementation.md`
> (Phase 0 — Backend Contracts, single SPA cutover). No tracked code touched.

## Evidence

- **Source plan**: `.worktrees/orgadmin-and-shipped-admin-spa/docs/superpowers/plans/2026-07-14-unified-admin-spa-implementation.md`
- **Section heading (exact, line 2155)**:
  `### Task 7: OrgSuper-specific role-assignment actor path (dedicated route, narrow actor guard, auditable FormRequest)`
- **Line range**: `2155` — `3382` (Task 8 starts at line `3384`; the `---` separator sits at line `3383`).
- **Phase**: `## Phase 0 — Backend Contracts` (heading line `140`; Task 7 is the last task of Phase 0 before Phase 1 Unified UI at line `3501`).
- **CSD ref**: `CSD-CA23078-CORE-009` (OrgSuper rewrite), recorded in the preflight contradiction block (line `2157`) and embedded in docblocks throughout Steps 5–9.
- **Preflight contradiction resolution** (lines `2157–2163`): the previous Task 7 attempted to admit OrgSuper through the canonical `POST /api/roles/assign` route via `AssignCanonicalRolesRequest::after()` allowlist logic. Preflight proved it unbuildable:
  1. `EnsureEngineCapability` middleware on `/api/roles/assign` (`app/Modules/Core/Routes/api.php:154-155`) rejects any actor without `Capability::CORE_ASSIGN_ROLES`, which T3's curated OrgSuper pivot set deliberately excludes.
  2. `CanonicalAuthorizationAssignmentActorGuard::allows()` re-checks `AccessDecision::canonicalTrace($actor, Capability::CORE_ASSIGN_ROLES, $target)['granted']` at `app/Modules/Core/Authorization/Services/CanonicalAuthorizationAssignmentActorGuard.php:32` — OrgSuper fails regardless of FormRequest allowlist.
  3. Therefore the OrgSuper path cannot widen `core.assign_roles`. This task rebuilds Task 7 around a **dedicated OrgSuper-only actor path** with a narrow route, narrow actor guard, and auditable FormRequest.

## Owned files (from Task 7 "Files:" block, lines `2165–2173`)

### Create

| Path | Purpose |
|---|---|
| `app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentActorGuard.php` | Narrow `AuthorizationAssignmentActorGuard` impl: same-org, operational-only, no protected targets, server-derived scope. Forces `isOrganizationSuperAdmin() && !isSuperAdmin()`. |
| `app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentService.php` | Composes underlying assignment service with the OrgSuper guard. Server-derives scope from `actor->organization_id` regardless of client payload. Transactional + audit row tagged `provenance=organization_super_admin`. |
| `app/Modules/Core/Http/Requests/AssignOrganizationSuperAdminRoleRequest.php` | Auditable seam. `authorize()` returns true (route middleware is the public gate). `rules()` reject non-`organization` scope, require `inherit_to_children=false`, prohibit `expires_at`. `after()` does defensive double-checks on role name, `is_admin_role`, `is_system`, `is_active`, cross-org subject, protected target. |
| `app/Modules/Core/Http/Middleware/EnsureOrganizationSuperAdminOnly.php` | Genuine-OrgSuper-only middleware. Rejects `super_admin` even if they hold `roles.assign`; rejects OrgSuper with null `organization_id`. Runs BEFORE `engine_capability:roles.assign`, BEFORE the actor guard, BEFORE the service. Aliased as `ensure.org_super_only`. |
| `tests/Feature/Authz/OrganizationSuperAdminRoleAllowlistTest.php` | 18 tests: 1 positive + 17 denial surfaces. `seedOrgSuper()` runs `RolesAndPermissionsSeeder` so positive test can resolve `roles.assign`. (Plan count went from 16 → 18 after Step 10 added the two genuine-OrgSuper middleware denial surfaces.) |

### Modify

| Path | Change |
|---|---|
| `app/Modules/Core/Http/Controllers/RoleController.php` | Add `assignByOrganizationSuperAdmin()` method using new request + service + actor guard. Existing canonical `assignToUser()` UNTOUCHED. |
| `app/Modules/Core/Routes/api.php` | Append `Route::post('/org-super/role-assignments', [RoleController::class, 'assignByOrganizationSuperAdmin'])` immediately after the existing `/roles/assign` route (line `155`). Middleware stack: `ensure.org_super_only`, `engine_capability:roles.assign`, `throttle:admin`, `idempotency`. Canonical `/api/roles/assign` route UNTOUCHED. |
| `app/Modules/Core/Authorization/Data/AssignmentScope.php` | Add `ORGANIZATION = 'organization'` constant immediately after existing `OWN` constant (line `11`). String is already in `AssignmentScope::TYPES` at line `15`; the named constant lets the new FormRequest and actor guard avoid magic strings. |
| `app/Modules/Core/Authorization/Capability.php` | Extend `ROLES_ASSIGN` (line `376`) docblock to record OrgSuper-only gating. Behavior unchanged (documentation-only). |
| `app/Http/Kernel.php` (or `bootstrap/app.php` for Laravel 11+) | Register middleware alias: `'ensure.org_super_only' => \App\Modules\Core\Http\Middleware\EnsureOrganizationSuperAdminOnly::class`. |

### Untouched (explicit non-targets)

- `app/Modules/Core/Routes/api.php` — canonical `/api/roles/assign` route (lines `154–155`).
- `app/Modules/Core/Http/Controllers/RoleController.php` — `assignToUser()` (canonical super_admin path).
- `app/Modules/Core/Authorization/Services/CanonicalAuthorizationAssignmentActorGuard.php` — remains the canonical guard.
- `database/migrations/2026_07_14_000020_seed_organization_super_admin_role.php` and the targeted sweep `2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots.php` — owned by Task 3 / Task 5; consumed here.

## Focused test command

From Step 2 baseline (line `2728`) and Step 10 final (line `3275`):

```bash
php artisan test --filter=OrganizationSuperAdminRoleAllowlistTest
```

**Expected baseline (Step 2, pre-implementation)**: ALL tests fail. The route 404s (no `/api/org-super/role-assignments` route yet); even if the route existed, the canonical actor guard rejects OrgSuper at `CanonicalAuthorizationAssignmentActorGuard.php:32` because OrgSuper does not hold `core.assign_roles`.

**Expected final (Step 10, post-implementation)**: 18 tests pass (1 positive + 17 denial surfaces). The denial surfaces include:
- `test_super_admin_uses_canonical_route_not_org_super_route` — rejected by `ensure.org_super_only` BEFORE actor guard.
- `test_super_admin_with_roles_assign_pivot_is_still_rejected` — edge case: super_admin with an incidental OrgSuper pivot. Rejected by `ensure.org_super_only` (the "genuine OrgSuper" requirement).
- `test_org_super_with_null_organization_is_rejected` — edge case: OrgSuper with null `organization_id` cannot derive scope. Rejected by `ensure.org_super_only`.

All three of the above assert 403; the new `ensure.org_super_only` middleware is the rejection point — it runs before the FormRequest layer, before the actor guard, and before the service.

## Interfaces (from Task 7 "Interfaces:" block, lines `2175–2187`)

**Consumes** (from earlier tasks in the plan):
- `User::isOrganizationSuperAdmin()` — Task 2 (`### Task 2` at line `230`).
- `AuthorizationRole`, `AuthorizationRoleAssignment`, `User` models.
- `Capability::ROLES_ASSIGN` — granted to OrgSuper via T3's curated set; explicitly NOT granted to `admin`, `super_admin`, or any other role.
- `AssignmentScopeResolver`.

**Produces**:
- `POST /api/org-super/role-assignments` route — gated by `ensure.org_super_only + engine_capability:roles.assign + throttle:admin + idempotency`. Reachable ONLY by OrgSuper.
- `OrganizationSuperAdminRoleAssignmentActorGuard::allows()` returns true ONLY IF all of: actor is OrgSuper and not super_admin; `subject.organization_id === actor.organization_id`; subject has no active `super_admin`/`organization_super_admin` assignment; `role.name` NOT IN `['super_admin', 'organization_super_admin', 'admin']`; `role.is_admin_role === false`; `role.is_system === false`; `role.is_active === true`; `scope.type === 'organization'`; `scope.id === actor.organization_id`; `scope.inheritToChildren === false`.
- `OrganizationSuperAdminRoleAssignmentService::syncManual()` — server-derives scope from `actor.organization_id`, uses the OrgSuper guard, writes through `AuthorizationRoleAssignment::updateOrCreate()` inside a DB transaction, audit row tagged `provenance=organization_super_admin`.
- 422 envelope (FormRequest validation) or 403 envelope (actor guard or `ensure.org_super_only` middleware denial) with Arabic `message` and field-level errors.

## Step map

| Step | Range | Action |
|---|---|---|
| 1 | 2189–2724 | Write failing tests (1 positive + 15 denial surfaces initially). |
| 2 | 2726–2729 | Run failing tests; capture baseline (`php artisan test --filter=OrganizationSuperAdminRoleAllowlistTest`). |
| 3 | 2731–2739 | Add `AssignmentScope::ORGANIZATION` constant. |
| 4 | 2741–2758 | Extend `Capability::ROLES_ASSIGN` docblock. |
| 5 | 2760–2858 | Implement `OrganizationSuperAdminRoleAssignmentActorGuard`. |
| 6 | 2860–2992 | Implement `OrganizationSuperAdminRoleAssignmentService`. |
| 7 | 2994–3120 | Implement `AssignOrganizationSuperAdminRoleRequest`. |
| 8 | 3122–3177 | Add `assignByOrganizationSuperAdmin()` to `RoleController`. |
| 9 | 3179–3271 | Add dedicated route + `EnsureOrganizationSuperAdminOnly` middleware + alias registration. Includes preflight correction: previous Step 9 only used `engine_capability:roles.assign`, insufficient because super_admin with an incidental `roles.assign` pivot would slip through. Corrected Step 9 adds `ensure.org_super_only` running BEFORE actor guard and service. |
| 10 | 3273–3372 | Re-run tests; add 2 new denial surfaces (`test_super_admin_with_roles_assign_pivot_is_still_rejected`, `test_org_super_with_null_organization_is_rejected`). |
| 11 | 3374–3380 | Lint (`./vendor/bin/pint --test <files>`) and commit (`feat(roles): dedicated OrgSuper role-assignment actor path with genuine-OrgSuper middleware`). |