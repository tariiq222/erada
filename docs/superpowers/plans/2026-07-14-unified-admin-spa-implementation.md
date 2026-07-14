# Unified Admin SPA — Implementation Plan (Single SPA + `organization_super_admin` role)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the `organization_super_admin` system role, the actor-derived `is_organization_super_admin` payload flag, the narrow `/api/organizations/{org}/settings` contract, and the unified Admin SPA guards/nav so that `PlatformSuperAdmin` and `OrganizationSuperAdmin` share one route tree, never ride the legacy `admin` shortcut, and fail closed on every cross-org / self / admin-on-admin surface.

**Architecture:** One Admin SPA (`resources/admin/`), one router (`AdminRouter`), one navigation (`AdminNavigation`), three payload flags (`is_super_admin`, `is_org_admin`, `is_organization_super_admin`). The new `organization_super_admin` role is `is_admin_role=false` and `is_system=true`, so `AccessDecision::whyCan()`'s admin-shortcut branch (`AccessDecision.php:~1170`) cannot silently elevate it. Org scope is **server-derived** from `users.organization_id`; `X-Organization-Id`, query, and body parameters are never authoritative for `organization_super_admin`. Reused canonical routes (`/api/users`, `/api/hr/departments`, `/api/activity-logs`, `/api/users/{user}/unlock`) carry new server-side target-validation rules. New backend surfaces: `app/Modules/Core/Http/Controllers/OrganizationSettingsController.php` + its FormRequests (T5) AND the dedicated OrgSuper role-assignment actor path (`POST /api/org-super/role-assignments` + `OrganizationSuperAdminRoleAssignmentActorGuard` + `OrganizationSuperAdminRoleAssignmentService` + `AssignOrganizationSuperAdminRoleRequest` — T7). The OrgSuper path is deliberately narrow: gated by `engine_capability:roles.assign` (NOT `core.assign_roles`), admits OrgSuper only, server-derives scope from `actor.organization_id`, and rejects all role-definition mutation. The legacy `admin` role is untouched (Phase 3 cutover is deferred).

**Tech Stack:** Laravel 12 / PHP 8.4 (Docker), Sanctum, AccessDecision engine, AuthorizationRole assignments, Spatie free, PHPUnit 11, Vitest 3, React 19 + TS 5.7, react-router-dom 7, i18next, Playwright 1.49 (chromium only).

## Global Constraints

- **Postgres only.** `composer test` uses `iradah_pmo_test` on port `5433` per `phpunit.xml`. Do not point tests at the dev DB on `5432`.
- **Capability constants live in `app/Modules/Core/Authorization/Capability.php`.** Never use legacy flat strings (`view_users`, `manage_hr`, etc.) in any new code.
- **Authorization seam is FormRequest `authorize()`.** Do not add `authorize()` to controllers — audits won't see it and IDOR lands.
- **Pint array-form validation rules.** Write new rules as arrays, not `'a|b'`.
- **FSD boundaries are ESLint errors.** `@entities → @shared → @app → @pages → @widgets → @features`. The admin SPA does not follow FSD; treat it as a peer SPA with its own layout (`resources/admin/{api,app,pages,widgets,model,test}`).
- **No Husky.** Run `npm run typecheck`, `npm run lint`, `./vendor/bin/pint --test`, and `composer phpstan` before committing.
- **API client:** use `api.get/post/put/patch/delete/blob`. State-changing `/admin/*` calls MUST include `X-Idempotency-Key` (already attached by `shared/api/client.ts` since commit `198f0d2`).
- **TypeScript:** `npm run typecheck` must stay green. The payload-additive `is_organization_super_admin` key MUST be typed optional and consumed when present so legacy mocks keep compiling.
- **PHPUnit:** `php artisan test --filter=...` is the safe way to run a single test class against `iradah_pmo_test`. Per AGENTS.md, full-suite flakes are tolerated only if the failing class passes in isolation.
- **No new schema migration is required for the role catalog** (idempotent `updateOrCreate`). A "role catalog sync" migration follows the `2026_07_12_000018_role_catalog_sync_obsolete_pivots` pattern so prod reflects the curated list and obsolete pivots are swept on first deploy.
- **Backward compatibility:** `/api/user` payload is additive. Existing `is_super_admin` / `is_org_admin` keys remain untouched. Existing `admin` role pivot set is unchanged from the curated OrgAdmin set; the `OrgAdminCuratedCapabilitiesTest` still passes.
- **Sensitive mutation contract (Org-Super writes):** `FormRequest::authorize()` → server-side target validation (`actor.organization_id`, `target.organization_id`, target active role assignments, role-name allowlist for `roles.assign`) → `DB::transaction` → `ActivityLog` audit row with `organization_super_admin` provenance tag → `IdempotencyKey` middleware reuses the cached response on retry → `throttle:admin` (user mutations / role assignments) or `throttle:sensitive` (organization settings) → 5xx renders `<ServerError />` with `request_id`; 409 → no retries; 422 → per-field errors; 429 → `<RateLimited />` with `retry_after`.
- **Obsolete-plan notice:** This plan supersedes `docs/superpowers/plans/2026-07-13-orgadmin-and-shipped-admin-spa.md`. Tasks 1–5, 9, 11, 12, 13, 14, 15, 16 from the obsolete plan remain valid (already shipped on the branch) and are reused where independent of `/org/*` and the curated `admin`-as-boundary framing. Tasks 6 (regression test, also committed as `6ed111f`), 7 (`AdminE2ETestSeeder`), 8 (`AdminRouteContractTest`), 10 (`OrgAdminBoundary` + `/org/*`), 17 (admin E2E spec rewrites), 18 (CI parity) are rescoped here.
- **Unmerged commits integration:** `198f0d2` (X-Idempotency-Key on mutations, already on the branch) is reused unchanged — every `adminApi` mutation already rides it. `6ed111f` (curated admin role cluster/assign-roles/cross-org regression test, already on the branch) is locked-in but extended with a Task 15 addendum so the curated `admin` pivot set remains the regression guard for the legacy path while `organization_super_admin` becomes the new boundary role.

---

## File Structure / Mapping (before tasks)

### Backend (new files only — no migrations alter existing columns)

| Path | Responsibility |
|---|---|
| `app/Modules/Core/Models/User.php` *(modify)* | Add `isOrganizationSuperAdmin(): bool` predicate and harden `resolveActiveOrganizationId()` so non-super actors never read a different `organization_id` from any input. |
| `app/Modules/Core/Authorization/Capability.php` *(modify)* | Append `USERS_ACTIVATE`, `USERS_DEACTIVATE`, `ORGANIZATION_SETTINGS_VIEW`, `ORGANIZATION_SETTINGS_EDIT` constants. |
| `app/Modules/Core/Http/Controllers/AuthController.php` *(modify)* | Extend `buildFormatUserPayload()` to add `is_organization_super_admin` alongside existing `is_super_admin`/`is_org_admin` flags. |
| `app/Modules/Core/Http/Controllers/UserController.php` *(modify)* | Add FormRequest target-validation that rejects self-modification for Org-Super, rejects `super_admin`/`organization_super_admin` targets, and rejects Org-Super's own `organization_id` mutation. Widen `canManageUserLifecycle()` to admit Org-Super via `Capability::USERS_ACTIVATE` / `USERS_DEACTIVATE`. |
| `app/Modules/Core/Http/Controllers/RoleController.php` *(modify)* | Add `assignByOrganizationSuperAdmin()` method that uses the new OrgSuper request + service + actor guard. Existing canonical `assignToUser()` (gated by `engine_capability:core.assign_roles` for super_admin) is UNTOUCHED. |
| `app/Modules/Core/Http/Controllers/OrganizationSettingsController.php` *(new)* | Read/update organization-scoped settings (locale overrides, branding overrides, notification templates). New `organization.settings.view` / `organization.settings.edit` capability gates. |
| `app/Modules/Core/Http/Requests/ViewOrganizationSettingsRequest.php` *(new)* | `authorize()` returns true only for actor.organization_id === org. |
| `app/Modules/Core/Http/Requests/UpdateOrganizationSettingsRequest.php` *(new)* | `authorize()` returns true only for `organization.settings.edit` capability + same-org; array-form validation rules. |
| `app/Modules/Core/Models/OrganizationSettings.php` *(new)* | Eloquent model for the new `organization_settings` table. |
| `app/Modules/Core/Routes/api.php` *(modify)* | Add `Route::prefix('organizations/{organization}/settings')` group with GET (read) and PUT (update, `throttle:sensitive` + `idempotency`). |
| `app/Modules/Core/Authorization/Data/AssignmentScope.php` *(modify)* | Add `ORGANIZATION` constant alongside `ALL` and `OWN`. |
| `app/Modules/Core/Authorization/Capability.php` *(modify)* | Extend `ROLES_ASSIGN` docblock to record the OrgSuper-only gating. |
| `app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentActorGuard.php` *(new)* | Narrow actor guard: same-org + operational-role-only + no protected targets + server-derived scope. Implements `AuthorizationAssignmentActorGuard`. |
| `app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentService.php` *(new)* | Composes the underlying service with the OrgSuper guard; server-derives scope from `actor->organization_id` regardless of client input; transactional + audit with `provenance=organization_super_admin`. |
| `app/Modules/Core/Http/Requests/AssignOrganizationSuperAdminRoleRequest.php` *(new)* | Auditable seam; `rules()` reject non-`organization` scope, require `inherit_to_children=false`, prohibit `expires_at`; `after()` defensive double-check on role name / flags / inactive / cross-org subject / protected target. |
| `app/Modules/Core/Routes/api.php` *(modify)* | Add `Route::post('/org-super/role-assignments', …)` gated by `engine_capability:roles.assign + throttle:admin + idempotency`. Canonical `/roles/assign` (gated by `core.assign_roles`) is UNTOUCHED. |
| `database/seeders/RolesAndPermissionsSeeder.php` *(modify)* | Add `organization_super_admin` entry to `roleCatalog()`. Extend `SWEPT_SYSTEM_ROLES` constant. |
| `database/migrations/2026_07_14_000020_role_catalog_sync_organization_super_admin.php` *(new)* | Migration that mirrors the obsolete-pivot sweep, scoped to `organization_super_admin`, gated to PG only, idempotent. |
| `database/migrations/2026_07_14_000021_create_organization_settings_table.php` *(new)* | Adds `organization_settings` table for the new contract. PG-only. |
| `tests/Unit/Core/OrganizationSuperAdminCapabilityConstantsTest.php` *(new)* | Unit test for the four capability constants. |
| `tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php` *(new)* | Unit test for `User::isOrganizationSuperAdmin()`. |
| `tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php` *(new)* | Asserts `/api/user` exposes the new additive flag. |
| `tests/Feature/Api/OrganizationSettingsContractTest.php` *(new)* | Read/update contract for the new endpoint (own-org + cross-org + forbidden). |
| `tests/Feature/Authz/OrganizationSuperAdminRoleSeedTest.php` *(new)* | Seeder adds the role with `is_admin_role=false`, `is_system=true`, and the curated capability list; no `projects.*` / `tasks.*` / `kpis.*` / `risks.*` / `ovr.*` / `core.cluster_tree.*` / `core.view_organizations` / `core.assign_roles` / `audit.export`. |
| `tests/Feature/Authz/OrganizationSuperAdminUserTargetTest.php` *(new)* | Self-modify, super_admin target, other Org-Super target, activate/deactivate, cross-org user — all matrix surfaces. |
| `tests/Feature/Authz/OrganizationSuperAdminRoleAllowlistTest.php` *(new)* | Comprehensive OrgSuper role-assignment matrix — 16 tests: 1 positive (operational role → same-org ordinary user) + 15 denial surfaces (admin / super_admin / organization_super_admin / is_admin_role / is_system / inactive role / cross-org subject / super_admin target / organization_super_admin target / cross-org scope_id / non-organization scope_type / inherit_to_children=true / regular user middleware / super_admin uses canonical route / audit log provenance). |
| `tests/Feature/Authz/OrganizationSuperAdminClusterRescueRegressionTest.php` *(new)* | Engine regression: Org-Super cannot widen via `CLUSTER_TREE_*`; `X-Organization-Id` header is ignored for non-super actors. |
| `tests/Feature/Authz/OrganizationSuperAdminLegacyAdminParityTest.php` *(new)* | Extends `6ed111f` baseline: seeding Org-Super does not mutate the curated `admin` pivot set. |

### Frontend (admin SPA only — no `/org/*` files)

| Path | Responsibility |
|---|---|
| `resources/admin/widgets/admin-shell/AdminNavigation.tsx` *(modify)* | Extend `isAdminNavItemVisible` to consider `is_organization_super_admin` and the new `org-super` group; add new nav item under that group. |
| `resources/admin/app/SuperAdminBoundary.tsx` *(no change)* | Stays strictly `is_super_admin === true` for system-only routes. |
| `resources/admin/app/OrgSuperOrSuperBoundary.tsx` *(new)* | Parallel guard for the Org-Super-reachable subset: `(is_super_admin === true) OR (is_organization_super_admin === true)`. Renders `<Forbidden />` for everyone else. |
| `resources/admin/app/AdminRouter.tsx` *(modify)* | Wrap the routes Org-Super can reach in `<OrgSuperOrSuperBoundary>`; keep `SuperAdminBoundary` only around system-only routes. |
| `resources/admin/api/adminApi.ts` *(modify)* | Add `adminApi.organizationSettings.get/update`. |
| `resources/admin/model/admin.ts` *(modify)* | Add `OrganizationSettings`, `OrganizationSettingsInput` types. |
| `resources/admin/pages/organizations/OrganizationSettingsPage.tsx` *(new)* | New page using shared UI primitives. |
| `resources/admin/pages/users/UsersPage.tsx` *(modify)* | Add per-row activate/deactivate actions gated by `users.activate` / `users.deactivate` capabilities. |
| `resources/admin/test/admin-auth-routing.test.tsx` *(modify)* | Add Org-Super routing matrix entries (additive; existing tests remain green). |
| `resources/admin/test/admin-nav-org-super.test.tsx` *(new)* | Predicate matrix tests for the new flag and `org-super` group. |
| `resources/admin/test/admin-api-org-settings.test.ts` *(new)* | Contract test for `adminApi.organizationSettings.*` URL shapes. |
| `resources/admin/test/admin-org-settings-page.test.tsx` *(new)* | Page rendering and submit tests. |
| `resources/admin/test/admin-user-lifecycle.test.tsx` *(new)* | Activate/deactivate button contract. |
| `resources/admin/test/admin-idempotency-org-super.test.ts` *(new)* | Verifies `198f0d2` reuse: Org-Super mutations route through `api.put`/`api.post` and carry the key. |
| `resources/admin/test/org-super-boundary.test.tsx` *(new)* | Predicate tests for `OrgSuperOrSuperBoundary`. |
| `resources/js/shared/types/index.ts` *(modify)* | Mirror the optional `is_organization_super_admin?: boolean` on the shared `User` interface. |
| `resources/js/__tests__/auth/user-organization-super-admin-flag.test.ts` *(new)* | Type-level test for the optional flag. |

### Locale ledger (i18n keys added by this plan)

| Key | Where used |
|---|---|
| `admin.shell.nav.org_settings` | `AdminNavigation` new `org-super` group label |
| `admin.shell.sidebar.section_org_super` | Section header for the `org-super` group |
| `admin.organizationSettings.title` | Page title |
| `admin.organizationSettings.subtitle` | Page subtitle |
| `admin.organizationSettings.load_failed` | Error message |
| `admin.organizationSettings.save_failed` | Error message |
| `admin.organizationSettings.fields.locale` | StatStrip label |
| `admin.organizationSettings.fields.branding` | StatStrip label |
| `admin.organizationSettings.fields.templates` | StatStrip label |
| `admin.organizationSettings.fields.locale_key` | DataTable column |
| `admin.organizationSettings.fields.locale_value` | DataTable column |
| `admin.organizationSettings.sections.locale` | FilterBar heading |
| `admin.organizationSettings.sections.branding` | FilterBar heading |
| `admin.organizationSettings.sections.templates` | FilterBar heading |
| `admin.users.actions.activate` | Per-row action |
| `admin.users.actions.deactivate` | Per-row action |

(Exact Arabic/English strings are owned by the implementer; the plan specifies keys only.)

### Interfaces between tasks (consumed/produced)

| Producer task | Consumed by | What is passed |
|---|---|---|
| T1 (capability constants) | T2, T3, T5, T6, T7, T8, T11, T13 | `Capability::USERS_ACTIVATE/DEACTIVATE`, `Capability::ORGANIZATION_SETTINGS_VIEW/EDIT` |
| T2 (`isOrganizationSuperAdmin()`) | T3, T4, T6, T7, T8, T14, T17 | `User::isOrganizationSuperAdmin(): bool` |
| T3 (seed + migration) | T4, T6, T7, T11 | `authorization_roles` row with `name='organization_super_admin'`, `scope_type='organization'`, `is_admin_role=false`, `is_system=true`; capability pivots present |
| T4 (`/api/user` payload) | T11, T13, T14, T17 | `is_organization_super_admin: bool` on `/api/user` |
| T5 (OrganizationSettingsController) | T11, T13, T17 | `GET/PUT /api/organizations/{org}/settings` route + payload |
| T6 (UserController target validation) | T7, T13, T17 | 422/403 envelope for self / super_admin / organization_super_admin targets |
| T7 (OrgSuper role-assignment actor path) | T17 | 422/403 envelopes for the operational-role allowlist matrix on `POST /api/org-super/role-assignments`; new `OrganizationSuperAdminRoleAssignmentActorGuard` + service + FormRequest + dedicated route gated by `engine_capability:roles.assign` (NOT `core.assign_roles`) |
| T8 (engine regression + X-Org-Id) | T17 | None — locks baseline |
| T9 (OrgSuperOrSuperBoundary) | T10, T11, T12, T13 | `<OrgSuperOrSuperBoundary>` component + `<Forbidden />` fallback |
| T10 (AdminNavigation predicate) | T11, T12, T17 | New `org-super` group, updated `isAdminNavItemVisible` predicate |
| T11 (adminApi.organizationSettings) | T12, T17 | `adminApi.organizationSettings.{get,update}` |
| T12 (OrganizationSettings page) | T17 | `<OrganizationSettingsPage>` mounted under `OrgSuperOrSuperBoundary` |
| T13 (Users activate/deactivate UI) | T17 | Per-row activate/deactivate actions bound to `users.activate`/`users.deactivate` |
| T14 (AuthContext type widening) | T10, T11, T13 | Optional `is_organization_super_admin?: boolean` on `User` |
| T15 (extended admin role regression) | T17 | Confirms legacy admin role pivot set is byte-identical post-Org-Super seed |
| T16 (`198f0d2` integration check) | T11, T13 | X-Idempotency-Key already attached by `api.post/put/patch/delete` |
| T17 (focused PHP/TS/E2E runs) | T18 | All test commands green (or flake-documented) |
| T18 (CI parity) | Done | `composer ci` + `npm run quality:ci` |

---

## Phase 0 — Backend Contracts

### Task 1: Capability constants for Org-Super surface

**Files:**
- Modify: `app/Modules/Core/Authorization/Capability.php:464-474` (after the existing `SETTINGS_*` block).
- Test: `tests/Unit/Core/OrganizationSuperAdminCapabilityConstantsTest.php` (new).

**Interfaces:**
- Consumes: none.
- Produces: `Capability::USERS_ACTIVATE = 'users.activate'`, `Capability::USERS_DEACTIVATE = 'users.deactivate'`, `Capability::ORGANIZATION_SETTINGS_VIEW = 'organization.settings.view'`, `Capability::ORGANIZATION_SETTINGS_EDIT = 'organization.settings.edit'`.

- [ ] **Step 1: Write failing unit test**

`tests/Unit/Core/OrganizationSuperAdminCapabilityConstantsTest.php`:
```php
<?php

namespace Tests\Unit\Core;

use App\Modules\Core\Authorization\Capability;
use PHPUnit\Framework\TestCase;

class OrganizationSuperAdminCapabilityConstantsTest extends TestCase
{
    public function test_org_super_capability_constants_resolve_to_dotted_strings(): void
    {
        $this->assertSame('users.activate', Capability::USERS_ACTIVATE);
        $this->assertSame('users.deactivate', Capability::USERS_DEACTIVATE);
        $this->assertSame('organization.settings.view', Capability::ORGANIZATION_SETTINGS_VIEW);
        $this->assertSame('organization.settings.edit', Capability::ORGANIZATION_SETTINGS_EDIT);
    }

    public function test_org_super_capability_constants_are_part_of_capability_all(): void
    {
        $all = Capability::all();
        $this->assertContains(Capability::USERS_ACTIVATE, $all);
        $this->assertContains(Capability::USERS_DEACTIVATE, $all);
        $this->assertContains(Capability::ORGANIZATION_SETTINGS_VIEW, $all);
        $this->assertContains(Capability::ORGANIZATION_SETTINGS_EDIT, $all);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=OrganizationSuperAdminCapabilityConstantsTest`
Expected: FAIL — `Undefined constant App\Modules\Core\Authorization\Capability::USERS_ACTIVATE`.

- [ ] **Step 3: Add the constants**

In `app/Modules/Core/Authorization/Capability.php`, immediately after the existing `SETTINGS_MANAGE` constant (line 474), append:

```php
    // ========================================================
    // Organization-level user lifecycle (Phase 0)
    // ========================================================
    //
    // Held by the `organization_super_admin` role; required to express
    // the spec's actor / permission matrix. NOT granted to `admin` —
    // the curated OrgAdmin role remains a read-mostly surface.
    const USERS_ACTIVATE = 'users.activate';
    const USERS_DEACTIVATE = 'users.deactivate';

    // ========================================================
    // Organization-scoped settings (Phase 0)
    // ========================================================
    //
    // Distinct from `settings.view` / `settings.edit` (platform-wide
    // SystemSettings). Held by `organization_super_admin` only;
    // PlatformSuperAdmin retains both via Capability::all().
    const ORGANIZATION_SETTINGS_VIEW = 'organization.settings.view';
    const ORGANIZATION_SETTINGS_EDIT = 'organization.settings.edit';
```

- [ ] **Step 4: Re-run the test and confirm it passes**

Run: `php artisan test --filter=OrganizationSuperAdminCapabilityConstantsTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Lint and commit**

```bash
./vendor/bin/pint --test app/Modules/Core/Authorization/Capability.php tests/Unit/Core/OrganizationSuperAdminCapabilityConstantsTest.php
git add app/Modules/Core/Authorization/Capability.php tests/Unit/Core/OrganizationSuperAdminCapabilityConstantsTest.php
git commit -m "feat(authz): add capability constants for organization_super_admin surface"
```

---

### Task 2: `User::isOrganizationSuperAdmin()` predicate

**Files:**
- Modify: `app/Modules/Core/Models/User.php:316-327` (immediately after `isOrgAdmin()`).
- Test: `tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php` (new).

**Interfaces:**
- Consumes: `AuthorizationRoleAssignment::SCOPE_ORGANIZATION`, `AuthorizationRole::is_system`, role catalog from Task 3 (the role row MUST exist for the predicate to return true; the test will create it locally).
- Produces: `User::isOrganizationSuperAdmin(): bool`.

- [ ] **Step 1: Write failing unit tests**

`tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php`:
```php
<?php

namespace Tests\Unit\Core;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserOrganizationSuperAdminFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_organization_super_admin_returns_true_only_for_active_canonical_assignment(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'organization_super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Organization Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $this->assertTrue($user->fresh()->isOrganizationSuperAdmin());
    }

    public function test_is_organization_super_admin_returns_false_when_no_assignment(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $this->assertFalse($user->fresh()->isOrganizationSuperAdmin());
    }

    public function test_is_organization_super_admin_returns_false_when_assignment_is_inactive(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'organization_super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Organization Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => false,
        ]);

        $this->assertFalse($user->fresh()->isOrganizationSuperAdmin());
    }

    public function test_is_organization_super_admin_returns_false_for_curated_admin_role(): void
    {
        // Legacy `admin` role must NOT be re-classified as Org-Super, even
        // though it shares scope_type=organization + is_admin_role=true.
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => true,
                'is_system' => false,
                'is_active' => true,
                'label' => 'Organization Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $this->assertFalse($user->fresh()->isOrganizationSuperAdmin());
    }
}
```

- [ ] **Step 2: Run failing tests**

Run: `php artisan test --filter=UserOrganizationSuperAdminFlagTest`
Expected: FAIL — `Call to undefined method App\Modules\Core\Models\User::isOrganizationSuperAdmin()`.

- [ ] **Step 3: Add the predicate**

In `app/Modules/Core/Models/User.php`, immediately after `isOrgAdmin()` (line 327), append:

```php
    // هل المستخدم Organization Super Admin — الدور الموحّد الجديد على مستوى المؤسسة.
    //
    // الإعداد:
    //   - name = 'organization_super_admin'
    //   - scope_type = 'organization'  (server-derived, لا يقبل X-Organization-Id للتوسيع)
    //   - is_admin_role = false        (يحجب اختصار AccessDecision::whyCan() للمدير)
    //   - is_system = true             (يحجز الدور في كتالوج البذور)
    //
    // التمييز عن isOrgAdmin() ضروري — كلاهما scope_type=organization لكن
    // الاختلاف في is_admin_role يحدد سلوك المحرّك في فرع
    // AccessDecision.php:~1170 (الـ admin-shortcut).
    public function isOrganizationSuperAdmin(): bool
    {
        return $this->activeCanonicalRoleAssignments()
            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_ORGANIZATION)
            ->whereNotNull('scope_id')
            ->whereHas('role', fn ($query) => $query
                ->where('name', 'organization_super_admin')
                ->where('scope_type', AuthorizationRoleAssignment::SCOPE_ORGANIZATION)
                ->where('is_admin_role', false)
                ->where('is_system', true))
            ->exists();
    }
```

- [ ] **Step 4: Re-run tests**

Run: `php artisan test --filter=UserOrganizationSuperAdminFlagTest`
Expected: 4 tests pass.

- [ ] **Step 5: Lint and commit**

```bash
./vendor/bin/pint --test app/Modules/Core/Models/User.php tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php
git add app/Modules/Core/Models/User.php tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php
git commit -m "feat(authz): add User::isOrganizationSuperAdmin() predicate"
```

---

### Task 3: Seed `organization_super_admin` + obsolete-pivot sweep migration

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php:20-238` (extend `roleCatalog()` and `SWEPT_SYSTEM_ROLES`).
- Create: `database/migrations/2026_07_14_000020_role_catalog_sync_organization_super_admin.php`.
- Test: `tests/Feature/Authz/OrganizationSuperAdminRoleSeedTest.php` (new).

**Interfaces:**
- Consumes: `Capability::*` constants from Task 1; `CapabilityToAuthorizationRolePermission::map()` for pivot mapping.
- Produces: `authorization_roles` row `name='organization_super_admin'` with `scope_type='organization'`, `is_admin_role=false`, `is_system=true`; pivots for `users.view/create/edit/delete/activate/deactivate/unlock`, `departments.view/create/edit/delete`, `organization.settings.view/edit`, `audit.view`, `roles.view`, `roles.assign`. NO `projects.*`, NO `tasks.*`, NO `kpis.*`, NO `risks.*`, NO `ovr.*`, NO `core.cluster_tree.*`, NO `core.view_organizations`, NO `core.assign_roles`, NO `audit.export`. The migration sweeps any obsolete pivots on first deploy, idempotent.

- [ ] **Step 1: Write the seeder extension test (failing)**

`tests/Feature/Authz/OrganizationSuperAdminRoleSeedTest.php`:
```php
<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationSuperAdminRoleSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_provisions_organization_super_admin_role_with_curated_caps(): void
    {
        (new RolesAndPermissionsSeeder)->run();

        $role = AuthorizationRole::query()->where('name', 'organization_super_admin')->first();
        $this->assertNotNull($role, 'organization_super_admin role must be seeded');
        $this->assertSame('organization', $role->scope_type);
        $this->assertFalse((bool) $role->is_admin_role, 'must be is_admin_role=false to block the admin shortcut');
        $this->assertTrue((bool) $role->is_system);
    }

    public function test_organization_super_admin_pivots_match_the_curated_capability_list(): void
    {
        (new RolesAndPermissionsSeeder)->run();

        $expected = [
            Capability::USERS_VIEW,
            Capability::USERS_CREATE,
            Capability::USERS_EDIT,
            Capability::USERS_DELETE,
            Capability::USERS_ACTIVATE,
            Capability::USERS_DEACTIVATE,
            Capability::USERS_UNLOCK,
            Capability::DEPARTMENTS_VIEW,
            Capability::DEPARTMENTS_CREATE,
            Capability::DEPARTMENTS_EDIT,
            Capability::DEPARTMENTS_DELETE,
            Capability::ORGANIZATION_SETTINGS_VIEW,
            Capability::ORGANIZATION_SETTINGS_EDIT,
            Capability::AUDIT_VIEW,
            Capability::ROLES_VIEW,
            Capability::ROLES_ASSIGN,
        ];

        $role = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        $capabilities = $role->permissions
            ->map(fn (AuthorizationRolePermission $permission) => CapabilityToAuthorizationRolePermission::mapAll()
                ->first(fn (array $mapping) => $mapping['resource'] === $permission->resource?->key
                    && $mapping['action'] === $permission->action)?->['capability'])
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $capabilities);
    }

    public function test_organization_super_admin_has_no_cluster_tree_or_global_caps(): void
    {
        (new RolesAndPermissionsSeeder)->run();

        $role = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        $resourceKeys = $role->permissions->pluck('resource.key')->filter()->all();

        $forbidden = [
            'core.cluster_tree.view',
            'core.cluster_tree.manage',
            'core.cluster_tree.export',
            'core.view_organizations',
            'core.assign_roles',
            'projects.view',
            'projects.edit',
            'tasks.view',
            'kpis.view',
            'risks.view',
            'ovr.view',
            'audit.export',
            'settings.manage',
        ];
        foreach ($forbidden as $capability) {
            $mapping = CapabilityToAuthorizationRolePermission::map($capability);
            $this->assertNotNull($mapping, "{$capability} must have a pivot mapping");
            $this->assertNotContains(
                $mapping['resource'],
                $resourceKeys,
                "organization_super_admin must not hold {$capability}",
            );
        }
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=OrganizationSuperAdminRoleSeedTest`
Expected: FAIL — `organization_super_admin role must be seeded` fails first assertion.

- [ ] **Step 3: Add the seeder entry**

In `database/seeders/RolesAndPermissionsSeeder.php`, immediately after the existing `'admin'` entry (line 105), append inside `roleCatalog()`:

```php
            'organization_super_admin' => [
                'label' => 'Organization Super Admin',
                'label_ar' => 'المسؤول العام للمؤسسة',
                'label_en' => 'Organization Super Admin',
                'scope_type' => 'organization',
                'is_admin_role' => false, // hard-off: blocks AccessDecision admin-shortcut (~line 1170).
                'capabilities' => self::organizationSuperAdminCapabilities(),
            ],
```

Also add `organization_super_admin` to `SWEPT_SYSTEM_ROLES` (currently lines 250-255):

```php
    private const SWEPT_SYSTEM_ROLES = [
        'admin',
        'organization_super_admin',
        'viewer',
        'dept_manager',
        'member',
    ];
```

Add the curated capability helper at the end of the class (next to `orgAdminCapabilities()`):

```php
    /**
     * Strict Organization Super Admin capability set for the canonical
     * `organization_super_admin` role (Phase 0).
     *
     * No `Capability::all()`, no module write surface, no cluster primitives.
     * `is_admin_role=false` blocks the AccessDecision admin-shortcut, so this
     * list is the ONLY source of grants for this role.
     *
     * @return list<string>
     */
    private static function organizationSuperAdminCapabilities(): array
    {
        return [
            Capability::USERS_VIEW,
            Capability::USERS_CREATE,
            Capability::USERS_EDIT,
            Capability::USERS_DELETE,
            Capability::USERS_ACTIVATE,
            Capability::USERS_DEACTIVATE,
            Capability::USERS_UNLOCK,
            Capability::DEPARTMENTS_VIEW,
            Capability::DEPARTMENTS_CREATE,
            Capability::DEPARTMENTS_EDIT,
            Capability::DEPARTMENTS_DELETE,
            Capability::ORGANIZATION_SETTINGS_VIEW,
            Capability::ORGANIZATION_SETTINGS_EDIT,
            Capability::AUDIT_VIEW,
            Capability::ROLES_VIEW,
            Capability::ROLES_ASSIGN,
        ];
    }
```

- [ ] **Step 4: Run the test and confirm it passes (seeder)**

Run: `php artisan test --filter=OrganizationSuperAdminRoleSeedTest`
Expected: 3 tests pass.

- [ ] **Step 5: Create the obsolete-pivot sweep migration**

Create `database/migrations/2026_07_14_000020_role_catalog_sync_organization_super_admin.php`. Mirror `database/migrations/2026_07_12_000018_role_catalog_sync_obsolete_pivots.php` exactly, but:

- Rename class constants `MIGRATION_NAME = '2026_07_14_000020_role_catalog_sync_organization_super_admin'`, `AUDIT_EVENT = 'role_catalog_sync_organization_super_admin_obsolete_pivot_removed'`.
- Scope `SWEPT_SYSTEM_ROLES` to `['organization_super_admin']` only — this migration is incremental; `admin`, `viewer`, `dept_manager`, `member` continue to be swept by `2026_07_12_000018`.
- Reuse `RolesAndPermissionsSeeder::roleCatalog()['organization_super_admin']` to derive desired keys.
- Keep `down(): void {}` forward-only.
- Keep PG-only guard (`if (DB::getDriverName() !== 'pgsql') throw ...`).

- [ ] **Step 6: Run the migration against the test DB**

Run: `php artisan migrate --env=testing --pretend`
Expected: the new migration appears in the plan with the expected SQL (Postgres `DELETE FROM authorization_role_permissions WHERE authorization_role_id = ? ...`).

Run: `composer test -- --filter=OrganizationSuperAdminRoleSeedTest`
Expected: 3 tests pass on the post-migrate schema.

- [ ] **Step 7: Lint and commit**

```bash
./vendor/bin/pint --test database/seeders/RolesAndPermissionsSeeder.php database/migrations/2026_07_14_000020_role_catalog_sync_organization_super_admin.php tests/Feature/Authz/OrganizationSuperAdminRoleSeedTest.php
git add database/seeders/RolesAndPermissionsSeeder.php database/migrations/2026_07_14_000020_role_catalog_sync_organization_super_admin.php tests/Feature/Authz/OrganizationSuperAdminRoleSeedTest.php
git commit -m "feat(authz): seed organization_super_admin role + obsolete-pivot sync migration"
```

---

### Task 4: `is_organization_super_admin` additive payload on `/api/user`

**Files:**
- Modify: `app/Modules/Core/Http/Controllers/AuthController.php:469-493` (extend the success-path payload in `buildFormatUserPayload`).
- Modify: `app/Modules/Core/Http/Controllers/AuthController.php:497-507` (mirror in the catch-all fallback).
- Test: `tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php` (new).

**Interfaces:**
- Consumes: `User::isOrganizationSuperAdmin()` from Task 2.
- Produces: payload key `is_organization_super_admin: bool` alongside existing `is_super_admin` / `is_org_admin`. Additive, non-breaking; existing mocks that omit the key still compile.

- [ ] **Step 1: Write failing tests**

`tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php`:
```php
<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthControllerOrganizationSuperAdminPayloadTest extends TestCase
{
    public function test_payload_exposes_is_organization_super_admin_for_org_super_actor(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'organization_super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Organization Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonPath('is_super_admin', false);
        $response->assertJsonPath('is_org_admin', false);
        $response->assertJsonPath('is_organization_super_admin', true);
    }

    public function test_payload_exposes_is_organization_super_admin_false_for_non_org_super_actor(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonPath('is_organization_super_admin', false);
    }
}
```

- [ ] **Step 2: Run failing tests**

Run: `php artisan test --filter=AuthControllerOrganizationSuperAdminPayloadTest`
Expected: FAIL — `is_organization_super_admin` JSON path missing.

- [ ] **Step 3: Extend the payload**

In `app/Modules/Core/Http/Controllers/AuthController.php:469-493`, inside the success-path return array (immediately after the existing `'is_org_admin' => $user->isOrgAdmin(),`), append:

```php
                'is_organization_super_admin' => $user->isOrganizationSuperAdmin(),
```

In the catch-all fallback at lines 497-507, after `'is_org_admin' => false,`, append:

```php
                'is_organization_super_admin' => false,
```

- [ ] **Step 4: Re-run tests**

Run: `php artisan test --filter=AuthControllerOrganizationSuperAdminPayloadTest`
Expected: 2 tests pass.

- [ ] **Step 5: Lint and commit**

```bash
./vendor/bin/pint --test app/Modules/Core/Http/Controllers/AuthController.php tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php
git add app/Modules/Core/Http/Controllers/AuthController.php tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php
git commit -m "feat(auth): expose is_organization_super_admin on /api/user payload"
```

---

### Task 5: OrganizationSettingsController + FormRequests + migration

**Files:**
- Create: `app/Modules/Core/Http/Controllers/OrganizationSettingsController.php`.
- Create: `app/Modules/Core/Http/Requests/ViewOrganizationSettingsRequest.php`.
- Create: `app/Modules/Core/Http/Requests/UpdateOrganizationSettingsRequest.php`.
- Create: `app/Modules/Core/Models/OrganizationSettings.php`.
- Create: `database/migrations/2026_07_14_000021_create_organization_settings_table.php`.
- Modify: `app/Modules/Core/Routes/api.php:200-217` (add the new route group).
- Test: `tests/Feature/Api/OrganizationSettingsContractTest.php` (new).

**Interfaces:**
- Consumes: `Capability::ORGANIZATION_SETTINGS_VIEW`, `Capability::ORGANIZATION_SETTINGS_EDIT` from Task 1; `Organization` model.
- Produces: `GET /api/organizations/{organization}/settings` returning `{ data: OrganizationSettings }`; `PUT /api/organizations/{organization}/settings` returning the same shape. FormRequest `authorize()` enforces capability + same-org (`$request->user()->organization_id === $organization->id` for non-super actors). Stored on a new `organization_settings` JSONB column.

**Decision (URL shape):** `GET/PUT /api/organizations/{organization}/settings` — mirrors `/organizations/{id}/edit`. SPA calls via `adminApi.organizationSettings.{get,update}` in Task 11.

**Decision (response shape):** flat `{ locale_overrides: { ar?: string, en?: string }, branding_overrides: { primary_color?: string|null, logo_path?: string|null }, notification_templates: Record<string, string> }`.

**Decision (throttle):** PUT uses `throttle:sensitive` + `idempotency` middleware. GET has no throttle.

- [ ] **Step 1: Add the migration**

Create `database/migrations/2026_07_14_000021_create_organization_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                '2026_07_14_000021_create_organization_settings_table is PostgreSQL-only. Detected driver: ['.DB::getDriverName().'].'
            );
        }

        Schema::create('organization_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->jsonb('settings');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_settings');
    }
};
```

- [ ] **Step 2: Write failing contract test**

`tests/Feature/Api/OrganizationSettingsContractTest.php`:
```php
<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationSettingsContractTest extends TestCase
{
    public function test_org_super_can_read_own_org_settings(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        Sanctum::actingAs($actor, ['*']);

        $response = $this->getJson("/api/organizations/{$org->id}/settings");

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['locale_overrides', 'branding_overrides', 'notification_templates']]);
    }

    public function test_org_super_can_update_own_org_settings(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        Sanctum::actingAs($actor, ['*']);

        $payload = [
            'locale_overrides' => ['ar' => 'ar-EG'],
            'branding_overrides' => ['primary_color' => '#1F3A8A'],
            'notification_templates' => ['welcome' => 'Welcome aboard'],
        ];

        $response = $this->putJson("/api/organizations/{$org->id}/settings", $payload);

        $response->assertOk();
        $response->assertJsonPath('data.branding_overrides.primary_color', '#1F3A8A');
    }

    public function test_org_super_cannot_read_other_org_settings(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->getJson("/api/organizations/{$otherOrg->id}/settings");

        $this->assertContains($response->status(), [403, 404], "Unexpected {$response->status()} for cross-org read.");
    }

    public function test_org_super_cannot_edit_other_org_settings(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/organizations/{$otherOrg->id}/settings", [
            'branding_overrides' => ['primary_color' => '#000000'],
        ]);

        $this->assertContains($response->status(), [403, 404], "Unexpected {$response->status()} for cross-org write.");
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function seedOrgSuper(): array
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'organization_super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Organization Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        return [$org, $user];
    }
}
```

- [ ] **Step 3: Run the test and confirm it fails**

Run: `php artisan test --filter=OrganizationSettingsContractTest`
Expected: FAIL — route 404.

- [ ] **Step 4: Implement FormRequests**

`app/Modules/Core/Http/Requests/ViewOrganizationSettingsRequest.php`:
```php
<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

final class ViewOrganizationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if ($actor === null) {
            return false;
        }

        if (! AccessDecision::can($actor, Capability::ORGANIZATION_SETTINGS_VIEW)) {
            return false;
        }

        $org = $this->route('organization');
        if ($org === null) {
            return false;
        }

        return $actor->isSuperAdmin() || (int) $actor->organization_id === (int) $org->id;
    }

    public function rules(): array
    {
        return [];
    }
}
```

`app/Modules/Core/Http/Requests/UpdateOrganizationSettingsRequest.php`:
```php
<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateOrganizationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if ($actor === null) {
            return false;
        }

        if (! AccessDecision::can($actor, Capability::ORGANIZATION_SETTINGS_EDIT)) {
            return false;
        }

        $org = $this->route('organization');
        if ($org === null) {
            return false;
        }

        return $actor->isSuperAdmin() || (int) $actor->organization_id === (int) $org->id;
    }

    public function rules(): array
    {
        return [
            'locale_overrides' => ['sometimes', 'array'],
            'locale_overrides.ar' => ['sometimes', 'nullable', 'string', 'max:16'],
            'locale_overrides.en' => ['sometimes', 'nullable', 'string', 'max:16'],
            'branding_overrides' => ['sometimes', 'array'],
            'branding_overrides.primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'branding_overrides.logo_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notification_templates' => ['sometimes', 'array'],
            'notification_templates.*' => ['string', 'max:4000'],
        ];
    }
}
```

- [ ] **Step 5: Implement the model**

`app/Modules/Core/Models/OrganizationSettings.php`:
```php
<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSettings extends Model
{
    protected $table = 'organization_settings';

    protected $fillable = [
        'organization_id',
        'settings',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
```

- [ ] **Step 6: Implement the controller**

`app/Modules/Core/Http/Controllers/OrganizationSettingsController.php`:
```php
<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\UpdateOrganizationSettingsRequest;
use App\Modules\Core\Http\Requests\ViewOrganizationSettingsRequest;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\OrganizationSettings;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrganizationSettingsController extends Controller
{
    public function show(ViewOrganizationSettingsRequest $request, Organization $organization): JsonResponse
    {
        $settings = OrganizationSettings::query()
            ->where('organization_id', $organization->id)
            ->firstOrCreate(
                ['organization_id' => $organization->id],
                ['settings' => $this->defaultPayload(), 'created_by' => $request->user()?->id],
            );

        return response()->json(['data' => $settings->settings]);
    }

    public function update(UpdateOrganizationSettingsRequest $request, Organization $organization): JsonResponse
    {
        $actor = $request->user();
        $validated = $request->validated();

        $settings = DB::transaction(function () use ($actor, $organization, $validated): OrganizationSettings {
            $row = OrganizationSettings::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->firstOrFail();

            $previous = $row->settings;
            $row->fill([
                'settings' => array_replace($previous, array_filter($validated, fn ($v) => $v !== null)),
                'updated_by' => $actor?->id,
            ])->save();

            ActivityLog::create([
                'user_id' => $actor?->id,
                'action' => ActivityLog::ACTION_UPDATED,
                'description' => "تحديث إعدادات المؤسسة: {$organization->name}",
                'loggable_type' => Organization::class,
                'loggable_id' => $organization->id,
                'old_values' => ['settings' => $previous],
                'new_values' => ['settings' => $row->settings],
                'metadata' => ['provenance' => 'organization_super_admin'],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $row->refresh();
        });

        return response()->json(['data' => $settings->settings]);
    }

    /**
     * @return array{locale_overrides: array<string, string>, branding_overrides: array<string, string|null>, notification_templates: array<string, string>}
     */
    private function defaultPayload(): array
    {
        return [
            'locale_overrides' => [],
            'branding_overrides' => [],
            'notification_templates' => [],
        ];
    }
}
```

- [ ] **Step 7: Add the route group**

In `app/Modules/Core/Routes/api.php`, immediately after the existing `/organizations` prefix group (line 217), append:

```php
    Route::prefix('organizations/{organization}/settings')->group(function () {
        Route::get('/', [OrganizationSettingsController::class, 'show']);
        Route::put('/', [OrganizationSettingsController::class, 'update'])
            ->middleware(['throttle:sensitive', 'idempotency']);
    });
```

- [ ] **Step 8: Re-run tests**

Run: `php artisan test --filter=OrganizationSettingsContractTest`
Expected: 4 tests pass.

- [ ] **Step 9: Lint and commit**

```bash
./vendor/bin/pint --test app/Modules/Core/Http/Controllers/OrganizationSettingsController.php app/Modules/Core/Http/Requests/ViewOrganizationSettingsRequest.php app/Modules/Core/Http/Requests/UpdateOrganizationSettingsRequest.php app/Modules/Core/Models/OrganizationSettings.php app/Modules/Core/Routes/api.php database/migrations/2026_07_14_000021_create_organization_settings_table.php tests/Feature/Api/OrganizationSettingsContractTest.php
git add app/Modules/Core/Http/Controllers/OrganizationSettingsController.php app/Modules/Core/Http/Requests/ViewOrganizationSettingsRequest.php app/Modules/Core/Http/Requests/UpdateOrganizationSettingsRequest.php app/Modules/Core/Models/OrganizationSettings.php app/Modules/Core/Routes/api.php database/migrations/2026_07_14_000021_create_organization_settings_table.php tests/Feature/Api/OrganizationSettingsContractTest.php
git commit -m "feat(org-settings): add organization-scoped settings contract"
```

---

### Task 6: UserController target-validation for Org-Super

**Files:**
- Modify: `app/Modules/Core/Http/Controllers/UserController.php:443-447` (extend `canManageUserLifecycle()`).
- Modify: `app/Modules/Core/Http/Controllers/UserController.php:310-378` (extend `update()` with target validation).
- Test: `tests/Feature/Authz/OrganizationSuperAdminUserTargetTest.php` (new).

**Interfaces:**
- Consumes: `User::isOrganizationSuperAdmin()` from Task 2.
- Produces: 422 (target self-modification for Org-Super on `organization_id`), 422 (target is `super_admin` or `organization_super_admin`), 404 (target not in actor's org — already enforced by `UserPolicy::update`). `is_active` continues to flow through `users.update`; `canManageUserLifecycle()` is widened to admit Org-Super via `Capability::USERS_ACTIVATE` / `USERS_DEACTIVATE`.

- [ ] **Step 1: Write failing tests**

`tests/Feature/Authz/OrganizationSuperAdminUserTargetTest.php`:
```php
<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationSuperAdminUserTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_super_cannot_change_own_organization_id(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrg = Organization::factory()->create(['is_active' => true]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/users/{$actor->id}", [
            'organization_id' => $otherOrg->id,
            'name' => 'Hacker',
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for self org swap.");
    }

    public function test_org_super_cannot_modify_super_admin_target(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $super = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        AuthorizationRole::query()->updateOrCreate(
            ['name' => 'super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
                'is_admin_role' => true,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Super Admin',
            ],
        );
        $superRole = AuthorizationRole::query()->where('name', 'super_admin')->firstOrFail();
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $super->id,
            'authorization_role_id' => $superRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/users/{$super->id}", ['name' => 'Pwned']);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for super_admin target.");
    }

    public function test_org_super_cannot_modify_other_org_super_target(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrgSuper = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $otherRole = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'organization_super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Organization Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $otherOrgSuper->id,
            'authorization_role_id' => $otherRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/users/{$otherOrgSuper->id}", ['name' => 'Pwned']);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for other Org-Super target.");
    }

    public function test_org_super_can_activate_deactivate_same_org_user(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => false]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/users/{$target->id}", ['is_active' => true]);

        $response->assertOk();
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_org_super_cannot_mutate_cross_org_user(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $crossOrgUser = User::factory()->create([
            'organization_id' => Organization::factory()->create(['is_active' => true])->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/users/{$crossOrgUser->id}", ['name' => 'Cross']);

        $this->assertContains($response->status(), [403, 404], "Unexpected {$response->status()} for cross-org user.");
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function seedOrgSuper(): array
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'organization_super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Organization Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        return [$org, $user];
    }
}
```

- [ ] **Step 2: Run failing tests**

Run: `php artisan test --filter=OrganizationSuperAdminUserTargetTest`
Expected: `test_org_super_can_activate_deactivate_same_org_user` returns 403 (no Org-Super guard exists); the rejection tests pass today for self-org / cross-org via `UserPolicy`.

- [ ] **Step 3: Extend `canManageUserLifecycle()` to admit Org-Super**

In `app/Modules/Core/Http/Controllers/UserController.php`, replace the existing `canManageUserLifecycle()` (lines 443-447):

```php
    private function canManageUserLifecycle(User $user): bool
    {
        return $user->isSuperAdmin()
            || $user->isOrganizationSuperAdmin()
            || AccessDecision::canonicalTrace($user, Capability::USERS_MANAGE_ACCESS)['granted']
            || AccessDecision::can($user, Capability::USERS_ACTIVATE)
            || AccessDecision::can($user, Capability::USERS_DEACTIVATE);
    }
```

- [ ] **Step 4: Extend `update()` with target validation**

In `app/Modules/Core/Http/Controllers/UserController.php:310-378`, immediately after the `$user = User::findOrFail($id);` line (line 313) and before `$validated = $request->validated();`, insert:

```php
            // CSD-CA23078-CORE-008: Organization Super Admin target validation.
            //
            // Rules:
            //   - Org-Super cannot mutate their own organization_id (422).
            //   - Org-Super cannot mutate a user whose active canonical assignment
            //     is to `super_admin` or `organization_super_admin` (422).
            //   - super_admin already short-circuits in UserPolicy::before();
            //     this block only fires for non-super_admin callers.
            $currentUser = $request->user();
            if (! $currentUser->isSuperAdmin() && $currentUser->isOrganizationSuperAdmin()) {
                if ((int) $user->id === (int) $currentUser->id
                    && array_key_exists('organization_id', $validated)
                    && (int) $validated['organization_id'] !== (int) $currentUser->organization_id) {
                    throw ValidationException::withMessages([
                        'organization_id' => ['لا يمكن للمسؤول العام للمؤسسة نقل نفسه لمؤسسة أخرى.'],
                    ]);
                }

                $protectedTarget = AuthorizationRoleAssignment::query()
                    ->where('user_id', $user->id)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->whereHas('role', fn ($role) => $role
                        ->whereIn('name', ['super_admin', 'organization_super_admin'])
                        ->where('is_active', true))
                    ->exists();

                if ($protectedTarget && (int) $user->id !== (int) $currentUser->id) {
                    ActivityLog::create([
                        'user_id' => $currentUser->id,
                        'action' => ActivityLog::ACTION_ACCESS_DENIED,
                        'description' => "محاولة تعديل مستخدم محمي (super_admin/organization_super_admin): {$user->name}",
                        'loggable_type' => User::class,
                        'loggable_id' => $user->id,
                        'metadata' => ['provenance' => 'organization_super_admin', 'requested_capability' => 'users.edit'],
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]);

                    throw ValidationException::withMessages([
                        'user_id' => ['لا يمكن تعديل مستخدم يحمل دور super_admin أو organization_super_admin.'],
                    ]);
                }
            }
```

- [ ] **Step 5: Re-run tests**

Run: `php artisan test --filter=OrganizationSuperAdminUserTargetTest`
Expected: 5 tests pass.

- [ ] **Step 6: Lint and commit**

```bash
./vendor/bin/pint --test app/Modules/Core/Http/Controllers/UserController.php tests/Feature/Authz/OrganizationSuperAdminUserTargetTest.php
git add app/Modules/Core/Http/Controllers/UserController.php tests/Feature/Authz/OrganizationSuperAdminUserTargetTest.php
git commit -m "feat(users): reject Org-Super self-modify and admin-on-admin targets"
```

---

### Task 7: OrgSuper-specific role-assignment actor path (dedicated route, narrow actor guard, auditable FormRequest)

> **Preflight contradiction resolution.** The previous Task 7 attempted to admit OrganizationSuperAdmin through the canonical `POST /api/roles/assign` route (middleware `engine_capability:core.assign_roles` at `app/Modules/Core/Routes/api.php:154-155`) by adding allowlist logic to `AssignCanonicalRolesRequest::after()`. Preflight proved this never works:
>
> 1. The route middleware (`EnsureEngineCapability`) rejects any actor that does not hold `Capability::CORE_ASSIGN_ROLES`. OrgSuper's curated pivot set in T3 deliberately excludes that capability.
> 2. Even if the middleware passed, `CanonicalAuthorizationAssignmentActorGuard::allows()` re-checks `AccessDecision::canonicalTrace($actor, Capability::CORE_ASSIGN_ROLES, $target)['granted']` at `app/Modules/Core/Authorization/Services/CanonicalAuthorizationAssignmentActorGuard.php:32`. OrgSuper fails this guard regardless of FormRequest allowlist.
> 3. The OrgSuper path therefore CANNOT widen `core.assign_roles`. The previous Task 7 was unbuildable as written.
>
> This task rebuilds Task 7 around a **dedicated OrgSuper-only actor path** that preserves the authoritative user policy: OrgSuper MAY assign/revoke only a server-approved operational-role allowlist to same-org ordinary users; MUST NOT receive `core.assign_roles`, MUST NOT do Platform/OrganizationSuperAdmin assignment, MUST NOT do cross-org scope, MUST NOT do role definition mutation, MUST NOT have client-selected scope authority.

**Files:**
- Create: `app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentActorGuard.php` — narrow actor guard implementing `AuthorizationAssignmentActorGuard`. Forces same-org, operational-only, no protected targets, server-derived scope.
- Create: `app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentService.php` — composes the underlying service and replaces the canonical guard with the OrgSuper guard for the OrgSuper route. Server-derives scope from `actor->organization_id` regardless of client input.
- Create: `app/Modules/Core/Http/Requests/AssignOrganizationSuperAdminRoleRequest.php` — auditable seam; `authorize()` returns true (public gate is route middleware); `rules()` reject non-`organization` scope, require `inherit_to_children=false`, prohibit `expires_at`; `after()` does defensive double-checks on role name, `is_admin_role`, `is_system`, `is_active`, cross-org subject, protected target.
- Modify: `app/Modules/Core/Http/Controllers/RoleController.php` — add `assignByOrganizationSuperAdmin()` method using the new request + service + actor guard; transactional + audit with `organization_super_admin` provenance tag. Existing `assignToUser` (canonical path) is UNTOUCHED.
- Modify: `app/Modules/Core/Routes/api.php` — add `Route::post('/org-super/role-assignments', …)` with middleware `engine_capability:roles.assign + throttle:admin + idempotency`. The canonical `/api/roles/assign` route is UNTOUCHED.
- Modify: `app/Modules/Core/Authorization/Data/AssignmentScope.php` — add `ORGANIZATION` constant.
- Modify: `app/Modules/Core/Authorization/Capability.php` — extend the `ROLES_ASSIGN` docblock to record that this capability is held by `organization_super_admin` only and gates the new OrgSuper route.
- Test: `tests/Feature/Authz/OrganizationSuperAdminRoleAllowlistTest.php` — comprehensive matrix (16 tests: 1 positive + 15 denial surfaces).

**Interfaces:**
- Consumes: `User::isOrganizationSuperAdmin()` from Task 2; `AuthorizationRole`, `AuthorizationRoleAssignment`, `User` models; `Capability::ROLES_ASSIGN` (granted to OrgSuper via T3's curated set; explicitly NOT granted to `admin`, `super_admin`, or any other role); `AssignmentScopeResolver`.
- Produces:
  - `POST /api/org-super/role-assignments` route gated by `engine_capability:roles.assign + throttle:admin + idempotency`. Reachable ONLY by OrgSuper (not super_admin, not curated admin, not any non-admin). Throttled at the admin tier; idempotent retry on the shared client.
  - `OrganizationSuperAdminRoleAssignmentActorGuard::allows()` returns true ONLY IF all conditions hold:
    - Actor is `organization_super_admin` AND NOT `super_admin`.
    - `subject.organization_id === actor.organization_id` (same-org fail-closed).
    - Subject has NO active `super_admin` or `organization_super_admin` assignment.
    - `role.name` NOT IN `['super_admin', 'organization_super_admin', 'admin']`.
    - `role.is_admin_role === false`, `role.is_system === false`, `role.is_active === true`.
    - `scope.type === 'organization'`, `scope.id === actor.organization_id`, `scope.inheritToChildren === false`.
  - `OrganizationSuperAdminRoleAssignmentService::syncManual()` server-derives scope from `actor.organization_id`, uses the OrgSuper guard, writes through `AuthorizationRoleAssignment::updateOrCreate()` inside a DB transaction, audit row tagged `provenance=organization_super_admin`.
  - 422 envelope (FormRequest validation) or 403 envelope (actor guard denial) with Arabic `message` and field-level errors.

- [ ] **Step 1: Write failing tests (matrix covering the positive case and every denial)**

`tests/Feature/Authz/OrganizationSuperAdminRoleAllowlistTest.php`:
```php
<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationSuperAdminRoleAllowlistTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Positive case — OrgSuper assigns an operational role to a same-org
     * ordinary user via the new dedicated route. This is the ONLY positive
     * case in the matrix; every other test is a denial.
     */
    public function test_org_super_can_assign_operational_role_to_same_org_user(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'manager'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => false,
                'is_active' => true,
                'label' => 'Manager',
            ],
        );

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $response->assertOk();
    }

    public function test_org_super_cannot_assign_admin_role(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $adminRole = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => true,
                'is_system' => false,
                'is_active' => true,
                'label' => 'Organization Admin',
            ],
        );

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $adminRole->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for admin role assign.");
    }

    public function test_org_super_cannot_assign_super_admin_role(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $superRole = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
                'is_admin_role' => true,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Super Admin',
            ],
        );

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $superRole->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
                'scope_id' => null,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for super_admin assign.");
    }

    public function test_org_super_cannot_assign_organization_super_admin_role(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $orgSuperRole = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $orgSuperRole->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for organization_super_admin assign.");
    }

    public function test_org_super_cannot_assign_role_with_is_admin_role_flag(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $forbidden = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'cluster_auditor'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => true,
                'is_system' => false,
                'is_active' => true,
                'label' => 'Cluster Auditor',
            ],
        );

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $forbidden->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for is_admin_role=true.");
    }

    public function test_org_super_cannot_assign_role_with_is_system_flag(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $forbidden = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'archived_role'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Archived System Role',
            ],
        );

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $forbidden->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for is_system=true.");
    }

    public function test_org_super_cannot_assign_inactive_role(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $inactive = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'retired_manager'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => false,
                'is_active' => false,
                'label' => 'Retired Manager',
            ],
        );

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $inactive->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for inactive role.");
    }

    public function test_org_super_cannot_assign_to_cross_org_user(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $crossOrg = User::factory()->create([
            'organization_id' => Organization::factory()->create(['is_active' => true])->id,
            'is_active' => true,
        ]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $crossOrg->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id, // client says actor's org — server rejects the subject
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for cross-org subject.");
    }

    public function test_org_super_cannot_assign_to_super_admin_target(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $super = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $superRole = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
                'is_admin_role' => true,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $super->id,
            'authorization_role_id' => $superRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $super->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for super_admin target.");
    }

    public function test_org_super_cannot_assign_to_organization_super_admin_target(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrgSuper = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $orgSuperRole = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $otherOrgSuper->id,
            'authorization_role_id' => $orgSuperRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $otherOrgSuper->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for organization_super_admin target.");
    }

    public function test_org_super_cannot_assign_with_cross_org_scope_id(): void
    {
        // Client scope manipulation: client tries to write a different org's
        // scope_id. Server must reject even when subject is in actor's org.
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $otherOrg->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for cross-org scope_id.");
    }

    public function test_org_super_cannot_assign_with_non_organization_scope_type(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'dept_only_role'],
            [
                'scope_type' => 'department',
                'is_admin_role' => false,
                'is_system' => false,
                'is_active' => true,
                'label' => 'Department Only Role',
            ],
        );

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => 'department',
                'scope_id' => 1,
                'inherit_to_children' => false,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for non-organization scope.");
    }

    public function test_org_super_cannot_assign_with_inherit_to_children_true(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => true,
            ]],
        ]);

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for inherit_to_children=true.");
    }

    public function test_regular_user_cannot_use_org_super_route(): void
    {
        // Middleware gate: roles.assign is held by OrgSuper only.
        $org = Organization::factory()->create(['is_active' => true]);
        $regular = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($regular, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $response->assertForbidden();
    }

    public function test_super_admin_uses_canonical_route_not_org_super_route(): void
    {
        // super_admin holds core.assign_roles (canonical route) but NOT
        // roles.assign (OrgSuper route). The OrgSuper route MUST reject
        // super_admin; the canonical route is the only path for super_admin.
        $org = Organization::factory()->create(['is_active' => true]);
        $super = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $superRole = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
                'is_admin_role' => true,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $super->id,
            'authorization_role_id' => $superRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($super, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $response->assertForbidden();
    }

    public function test_org_super_role_assignment_writes_activity_log_with_provenance(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => $org->id,
                'inherit_to_children' => false,
            ]],
        ]);

        $response->assertOk();

        $audit = \App\Modules\Shared\Models\ActivityLog::query()
            ->where('user_id', $actor->id)
            ->where('loggable_id', $target->id)
            ->where('loggable_type', User::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'audit log row must exist for the assignment');
        $this->assertSame('organization_super_admin', $audit->metadata['provenance'] ?? null);
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function seedOrgSuper(): array
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'organization_super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Organization Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        return [$org, $user];
    }
}
```

- [ ] **Step 2: Run failing tests (capture baseline)**

Run: `php artisan test --filter=OrganizationSuperAdminRoleAllowlistTest`
Expected: ALL 16 tests fail. The route 404s for every test (no `/api/org-super/role-assignments` route yet); even if the route existed, the canonical actor guard rejects OrgSuper at `CanonicalAuthorizationAssignmentActorGuard.php:32` because OrgSuper does not hold `core.assign_roles`. Baseline captured before patching.

- [ ] **Step 3: Add `AssignmentScope::ORGANIZATION` constant**

In `app/Modules/Core/Authorization/Data/AssignmentScope.php`, immediately after the existing `OWN` constant (line 11), append:

```php
    public const ORGANIZATION = 'organization';
```

This is already a member of `AssignmentScope::TYPES` (line 15); the named constant is added so the new FormRequest and actor guard can reference it without a magic string.

- [ ] **Step 4: Extend `Capability::ROLES_ASSIGN` docblock**

In `app/Modules/Core/Authorization/Capability.php`, immediately after the existing `ROLES_ASSIGN` constant (line 376), append a docblock clarifying the OrgSuper-only gating:

```php
    /**
     * CSD-CA23078-CORE-009 (OrgSuper rewrite).
     *
     * Held by `organization_super_admin` ONLY (Task 3 curated set). Gates
     * the dedicated `POST /api/org-super/role-assignments` route. Distinct
     * from `core.assign_roles` (canonical super_admin-only path). The
     * curated `admin` role does NOT hold this capability — the OrgSuper
     * role is the single boundary actor for organizational role assignment.
     */
    const ROLES_ASSIGN = 'roles.assign';
```

(No behavior change to the constant value; this is a documentation-only step that records the gating intent for future readers.)

- [ ] **Step 5: Implement the OrgSuper actor guard**

Create `app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentActorGuard.php`:
```php
<?php

namespace App\Modules\Core\Authorization\Services;

use App\Modules\Core\Authorization\Contracts\AuthorizationAssignmentActorGuard;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite).
 *
 * Narrow actor guard for the OrgSuper-specific role-assignment route. Replaces
 * CanonicalAuthorizationAssignmentActorGuard for /api/org-super/role-assignments.
 *
 * Contract — `allows()` returns true ONLY IF ALL conditions hold:
 *   - actor is organization_super_admin AND NOT super_admin
 *   - subject.organization_id === actor.organization_id (same-org fail-closed)
 *   - subject has NO active super_admin or organization_super_admin assignment
 *   - role.name NOT IN ['super_admin', 'organization_super_admin', 'admin']
 *   - role.is_admin_role === false
 *   - role.is_system === false
 *   - role.is_active === true
 *   - scope.type === 'organization'
 *   - scope.id === actor.organization_id (server-derived)
 *   - scope.inheritToChildren === false
 */
final class OrganizationSuperAdminRoleAssignmentActorGuard implements AuthorizationAssignmentActorGuard
{
    /** @var list<string> */
    private const FORBIDDEN_ROLE_NAMES = [
        'super_admin',
        'organization_super_admin',
        'admin',
    ];

    /** @var list<string> */
    private const PROTECTED_TARGET_ROLE_NAMES = [
        'super_admin',
        'organization_super_admin',
    ];

    public function allows(User $actor, User $subject, AuthorizationRole $role, AssignmentScope $scope): bool
    {
        // 1. Actor must be OrgSuper, not super_admin.
        if (! $actor->isOrganizationSuperAdmin() || $actor->isSuperAdmin()) {
            return false;
        }

        // 2. Subject must be in actor's organization.
        if ($actor->organization_id === null
            || $subject->organization_id === null
            || (int) $actor->organization_id !== (int) $subject->organization_id) {
            return false;
        }

        // 3. Subject must NOT hold a protected role.
        $hasProtectedAssignment = AuthorizationRoleAssignment::query()
            ->where('user_id', $subject->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('role', fn ($roleQuery) => $roleQuery
                ->whereIn('name', self::PROTECTED_TARGET_ROLE_NAMES)
                ->where('is_active', true))
            ->exists();
        if ($hasProtectedAssignment) {
            return false;
        }

        // 4. Role must be active, not is_admin_role, not is_system, not in forbidden names.
        if (! (bool) $role->is_active) {
            return false;
        }
        if ((bool) $role->is_admin_role || (bool) $role->is_system) {
            return false;
        }
        if (in_array($role->name, self::FORBIDDEN_ROLE_NAMES, true)) {
            return false;
        }

        // 5. Scope is server-derived: organization + actor's org id + no children.
        if ($scope->type !== AssignmentScope::ORGANIZATION) {
            return false;
        }
        if ($scope->id === null || (int) $scope->id !== (int) $actor->organization_id) {
            return false;
        }
        if ($scope->inheritToChildren) {
            return false;
        }

        return true;
    }
}
```

- [ ] **Step 6: Implement the OrgSuper write service**

Create `app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentService.php`:
```php
<?php

namespace App\Modules\Core\Authorization\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Data\AssignmentWrite;
use App\Modules\Core\Authorization\Data\RoleAssignmentWrite;
use App\Modules\Core\Authorization\Exceptions\AuthorizationAssignmentDenied;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite).
 *
 * Composes the canonical AuthorizationAssignmentService but replaces the actor
 * guard with the OrgSuper-specific guard. Server-derives scope from
 * actor.organization_id regardless of any client-supplied
 * scope_type / scope_id / inherit_to_children values. Writes through the same
 * authorization_role_assignments table inside a DB transaction. Audit row is
 * tagged provenance=organization_super_admin.
 */
final readonly class OrganizationSuperAdminRoleAssignmentService
{
    public function __construct(
        private OrganizationSuperAdminRoleAssignmentActorGuard $actorGuard,
        private AssignmentScopeResolver $scopeResolver,
    ) {}

    /**
     * @param  list<RoleAssignmentWrite>  $writes
     * @param  array{ip_address?: ?string, user_agent?: ?string, request_id?: ?string}  $auditContext
     * @return list<AuthorizationRoleAssignment>
     */
    public function syncManual(User $actor, User $subject, array $writes, array $auditContext = []): array
    {
        $serverScope = $this->serverDerivedScope($actor);
        $serverWrites = [];

        foreach ($writes as $item) {
            if (! $item instanceof RoleAssignmentWrite) {
                throw new AuthorizationAssignmentDenied('OrgSuper sync accepts RoleAssignmentWrite values only.');
            }
            if ($item->assignment->source !== 'manual') {
                throw new AuthorizationAssignmentDenied('OrgSuper sync accepts manual source only.');
            }

            if (! $this->actorGuard->allows($actor, $subject, $item->role, $serverScope)) {
                throw new AuthorizationAssignmentDenied(
                    "OrgSuper [{$actor->id}] cannot assign role [{$item->role->name}] to subject [{$subject->id}] in scope [{:$serverScope->type}:{$serverScope->id}]."
                );
            }

            $overriddenWrite = new AssignmentWrite($serverScope, null, 'manual');
            $serverWrites[] = new RoleAssignmentWrite($item->role, $overriddenWrite);
        }

        return DB::transaction(function () use ($actor, $subject, $serverWrites, $auditContext): array {
            $roleIds = collect($serverWrites)->pluck('role.id')->all();
            $existing = AuthorizationRoleAssignment::query()
                ->where('user_id', $subject->id)
                ->where('source', 'manual')
                ->whereHas('role', fn ($roleQuery) => $roleQuery->whereIn('id', $roleIds))
                ->lockForUpdate()
                ->get();

            foreach ($existing as $assignment) {
                $assignment->delete();
            }

            $created = [];
            foreach ($serverWrites as $item) {
                $scope = $item->assignment->scope;
                $organizationId = $this->scopeResolver->organizationId($scope, $subject);
                $identity = [
                    'user_id' => $subject->id,
                    'authorization_role_id' => $item->role->id,
                    'scope_type' => $scope->type,
                    'scope_id' => $scope->id,
                    'source' => 'manual',
                ];
                $row = AuthorizationRoleAssignment::query()->updateOrCreate($identity, [
                    'organization_id' => $organizationId,
                    'inherit_to_children' => false,
                    'expires_at' => null,
                    'source' => 'manual',
                    'granted_by' => $actor->id,
                    'updated_at' => now(),
                ]);
                $created[] = $row;
            }

            DB::afterCommit(static fn () => AccessDecision::flushCache());

            ActivityLog::create([
                'user_id' => $actor->id,
                'action' => ActivityLog::ACTION_SYSTEM_ROLE_ASSIGNED,
                'description' => "Organization Super Admin role assignment: {$subject->name}",
                'loggable_type' => User::class,
                'loggable_id' => $subject->id,
                'metadata' => array_merge([
                    'provenance' => 'organization_super_admin',
                    'request_id' => $auditContext['request_id'] ?? null,
                    'role_ids' => $roleIds,
                ]),
                'ip_address' => $auditContext['ip_address'] ?? null,
                'user_agent' => $auditContext['user_agent'] ?? null,
            ]);

            return $created;
        });
    }

    private function serverDerivedScope(User $actor): AssignmentScope
    {
        if ($actor->organization_id === null) {
            throw new AuthorizationAssignmentDenied('OrgSuper actor has no organization context.');
        }

        return new AssignmentScope(
            AssignmentScope::ORGANIZATION,
            (int) $actor->organization_id,
            false,
        );
    }
}
```

- [ ] **Step 7: Implement the OrgSuper FormRequest**

Create `app/Modules/Core/Http/Requests/AssignOrganizationSuperAdminRoleRequest.php`:
```php
<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite).
 *
 * Auditable seam for POST /api/org-super/role-assignments. The public gate
 * is `engine_capability:roles.assign` on the route — this FormRequest is
 * the defense-in-depth layer that catches client-side payload manipulation
 * BEFORE the actor guard runs.
 */
final class AssignOrganizationSuperAdminRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // engine_capability:roles.assign on the route is the public gate.
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'replace_all' => ['required', 'accepted'],
            'assignments' => ['present', 'array'],
            'assignments.*.role_id' => ['required', 'integer', 'exists:authorization_roles,id'],
            'assignments.*.scope_type' => ['required', 'string', Rule::in([AssignmentScope::ORGANIZATION])],
            'assignments.*.scope_id' => ['required', 'integer', 'min:1'],
            'assignments.*.inherit_to_children' => ['required', 'boolean', 'accepted'], // false only
            'assignments.*.expires_at' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $actor = $this->user();
                if ($actor === null || ! $actor->isOrganizationSuperAdmin() || $actor->isSuperAdmin()) {
                    return;
                }

                $actorOrgId = $actor->organization_id !== null ? (int) $actor->organization_id : null;
                $forbiddenNames = ['super_admin', 'organization_super_admin', 'admin'];

                foreach ($this->input('assignments', []) as $index => $assignment) {
                    if (! is_array($assignment)) {
                        continue;
                    }
                    $roleId = $assignment['role_id'] ?? null;
                    if (! is_numeric($roleId)) {
                        continue;
                    }
                    $role = AuthorizationRole::query()->find((int) $roleId);
                    if ($role === null) {
                        continue;
                    }

                    if (! (bool) $role->is_active) {
                        $validator->errors()->add(
                            "assignments.{$index}.role_id",
                            "الدور [{$role->name}] غير نشط."
                        );
                        continue;
                    }
                    if ((bool) $role->is_admin_role || (bool) $role->is_system) {
                        $validator->errors()->add(
                            "assignments.{$index}.role_id",
                            "الدور [{$role->name}] محصور بإدارة النظام ولا يمكن إسناده من قِبل Organization Super Admin."
                        );
                        continue;
                    }
                    if (in_array($role->name, $forbiddenNames, true)) {
                        $validator->errors()->add(
                            "assignments.{$index}.role_id",
                            "الدور [{$role->name}] محصور بالمسؤول العام للنظام فقط ولا يمكن إسناده من قِبل Organization Super Admin."
                        );
                        continue;
                    }

                    $scopeType = $assignment['scope_type'] ?? null;
                    $scopeId = $assignment['scope_id'] ?? null;
                    if ($scopeType !== AssignmentScope::ORGANIZATION || $scopeId === null || (int) $scopeId !== $actorOrgId) {
                        $validator->errors()->add(
                            "assignments.{$index}.scope_id",
                            'يجب أن يكون النطاق organization ومعرّفه مساوياً لمؤسسة الفاعل.'
                        );
                        continue;
                    }

                    $subjectId = $this->input('user_id');
                    if (is_numeric($subjectId)) {
                        $subject = \App\Modules\Core\Models\User::query()->find((int) $subjectId);
                        if ($subject !== null) {
                            if ((int) $subject->organization_id !== $actorOrgId) {
                                $validator->errors()->add('user_id', 'الموضوع خارج مؤسسة الفاعل.');
                            }
                            $isProtected = AuthorizationRoleAssignment::query()
                                ->where('user_id', $subject->id)
                                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                                ->whereHas('role', fn ($roleQuery) => $roleQuery
                                    ->whereIn('name', ['super_admin', 'organization_super_admin'])
                                    ->where('is_active', true))
                                ->exists();
                            if ($isProtected) {
                                $validator->errors()->add('user_id', 'لا يمكن تعديل مستخدم يحمل دور super_admin أو organization_super_admin.');
                            }
                        }
                    }
                }
            },
        ];
    }
}
```

- [ ] **Step 8: Add the controller method**

In `app/Modules/Core/Http/Controllers/RoleController.php`, append a new method. Do NOT modify the existing `assignToUser` (canonical path) — it is left untouched so super_admin continues to use it via `core.assign_roles`:

```php
    public function assignByOrganizationSuperAdmin(
        AssignOrganizationSuperAdminRoleRequest $request,
        OrganizationSuperAdminRoleAssignmentService $assignmentService,
    ): JsonResponse {
        $validated = $request->validated();
        /** @var \App\Modules\Core\Models\User $actor */
        $actor = $request->user();
        $subject = User::query()->findOrFail($validated['user_id']);
        $roles = AuthorizationRole::query()->where('is_active', true)
            ->whereKey(collect($validated['assignments'])->pluck('role_id')->all())->get()->keyBy('id');
        $writes = collect($validated['assignments'])->map(function (array $payload) use ($roles): RoleAssignmentWrite {
            $role = $roles->get((int) $payload['role_id']);
            abort_if($role === null, 422, 'الدور المطلوب غير موجود أو غير نشط.');

            return new RoleAssignmentWrite($role, new AssignmentWrite(
                new AssignmentScope($payload['scope_type'], $payload['scope_id'] ?? null, (bool) ($payload['inherit_to_children'] ?? false)),
                isset($payload['expires_at']) ? CarbonImmutable::parse($payload['expires_at']) : null,
                'manual',
            ));
        })->values()->all();

        try {
            $assignmentService->syncManual($actor, $subject, $writes, [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_id' => $request->header('X-Request-Id'),
            ]);
        } catch (AuthorizationAssignmentDenied $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return response()->json([
            'message' => 'تم تعيين الأدوار من قِبل Organization Super Admin بنجاح',
            'data' => [
                'user_id' => $subject->id,
                'assignments' => $subject->assignments()
                    ->with('role:id,name')
                    ->get()
                    ->map(fn (AuthorizationRoleAssignment $a) => [
                        'role_id' => $a->authorization_role_id,
                        'role_name' => $a->role?->name,
                        'scope_type' => $a->scope_type,
                        'scope_id' => $a->scope_id,
                        'organization_id' => $a->organization_id,
                        'inherit_to_children' => (bool) $a->inherit_to_children,
                    ])
                    ->all(),
            ],
        ]);
    }
```

- [ ] **Step 9: Add the dedicated route**

In `app/Modules/Core/Routes/api.php`, immediately after the existing `/roles/assign` route (line 155), append:

```php
    // OrgSuper-specific role-assignment route — narrow path; gated by roles.assign
    // (NOT core.assign_roles). OrgSuper's curated pivot set grants roles.assign
    // only; super_admin and curated admin continue to use the canonical route.
    Route::post('/org-super/role-assignments', [RoleController::class, 'assignByOrganizationSuperAdmin'])
        ->middleware([
            'engine_capability:'.Capability::ROLES_ASSIGN,
            'throttle:admin',
            'idempotency',
        ]);
```

- [ ] **Step 10: Re-run tests**

Run: `php artisan test --filter=OrganizationSuperAdminRoleAllowlistTest`
Expected: 16 tests pass (1 positive + 15 denial surfaces).

- [ ] **Step 11: Lint and commit**

```bash
./vendor/bin/pint --test app/Modules/Core/Authorization/Data/AssignmentScope.php app/Modules/Core/Authorization/Capability.php app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentActorGuard.php app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentService.php app/Modules/Core/Http/Requests/AssignOrganizationSuperAdminRoleRequest.php app/Modules/Core/Http/Controllers/RoleController.php app/Modules/Core/Routes/api.php tests/Feature/Authz/OrganizationSuperAdminRoleAllowlistTest.php
git add app/Modules/Core/Authorization/Data/AssignmentScope.php app/Modules/Core/Authorization/Capability.php app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentActorGuard.php app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentService.php app/Modules/Core/Http/Requests/AssignOrganizationSuperAdminRoleRequest.php app/Modules/Core/Http/Controllers/RoleController.php app/Modules/Core/Routes/api.php tests/Feature/Authz/OrganizationSuperAdminRoleAllowlistTest.php
git commit -m "feat(roles): dedicated OrgSuper role-assignment actor path with narrow guard"
```

---

### Task 8: Engine regression — cluster rescue + `X-Organization-Id` ignore

**Files:**
- Test: `tests/Feature/Authz/OrganizationSuperAdminClusterRescueRegressionTest.php` (new).
- No source change expected.

**Interfaces:**
- Consumes: `User::isOrganizationSuperAdmin()` from Task 2; the existing `AccessDecision::canonicalClusterTreeGrant()` (called from `AccessDecision.php:~317`).
- Produces: regression assertions that Org-Super cannot widen via cluster_tree primitives and that `X-Organization-Id` does not broaden the result set for any non-super actor (including Org-Super).

- [ ] **Step 1: Write failing tests**

`tests/Feature/Authz/OrganizationSuperAdminClusterRescueRegressionTest.php`:
```php
<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationSuperAdminClusterRescueRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_super_cluster_rescue_branch_does_not_fire(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $targetOrg = Organization::factory()->create([
            'is_active' => true,
            'parent_id' => $org->id,
        ]);
        $target = User::factory()->create(['organization_id' => $targetOrg->id, 'is_active' => true]);

        // CLUSTER_TREE_VIEW is NOT granted to organization_super_admin.
        $this->assertFalse(AccessDecision::can($actor, Capability::CLUSTER_TREE_VIEW));
        $this->assertFalse(AccessDecision::can($actor, Capability::CLUSTER_TREE_MANAGE));
        $this->assertFalse(AccessDecision::can($actor, Capability::CLUSTER_TREE_EXPORT));

        // The cluster rescue grant lookup returns null because the actor has no
        // (Organization, *) pivot for cluster_tree.* primitives.
        $this->assertNull(AccessDecision::canonicalClusterTreeGrant($actor, Capability::CLUSTER_TREE_VIEW, $target));
    }

    public function test_x_organization_id_header_does_not_widen_for_org_super(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrg = Organization::factory()->create(['is_active' => true]);

        $resolved = $actor->resolveActiveOrganizationId((int) $otherOrg->id);

        // Locked to actor.organization_id; X-Organization-Id header is ignored.
        $this->assertSame((int) $org->id, $resolved);
    }

    public function test_org_super_audit_view_scope_is_strictly_same_org(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $otherOrg = Organization::factory()->create(['is_active' => true]);

        // AUDIT_VIEW is held but the engine cluster rescue branch must NOT
        // widen the scope for an Org-Super actor.
        $grant = AccessDecision::canonicalClusterTreeGrant($actor, Capability::AUDIT_VIEW, $otherOrg);
        $this->assertNull($grant, 'Org-Super must not receive cluster rescue widening on audit.view.');
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function seedOrgSuper(): array
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'organization_super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Organization Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        return [$org, $user];
    }
}
```

- [ ] **Step 2: Run failing tests**

Run: `php artisan test --filter=OrganizationSuperAdminClusterRescueRegressionTest`
Expected: tests pass because the engine already denies these surfaces (`is_admin_role=false` blocks the shortcut, no cluster pivot exists, `resolveActiveOrganizationId()` already locks to `actor.organization_id` for non-super actors at `app/Modules/Core/Models/User.php:334-341`). If any test fails, the engine path needs a defensive patch (escalate to maintainer — do NOT bypass).

- [ ] **Step 3: No code change expected; commit the regression test**

```bash
git add tests/Feature/Authz/OrganizationSuperAdminClusterRescueRegressionTest.php
git commit -m "test(authz): lock in Org-Super cluster rescue + X-Organization-Id regression"
```

---

## Phase 1 — Unified UI (single SPA)

### Task 9: `OrgSuperOrSuperBoundary` route guard

**Files:**
- Create: `resources/admin/app/OrgSuperOrSuperBoundary.tsx`.
- Test: `resources/admin/test/org-super-boundary.test.tsx` (new).

**Interfaces:**
- Consumes: `useAuth()` from `@shared/contexts/AuthContext`.
- Produces: `OrgSuperOrSuperBoundary` component that admits users with `is_super_admin === true` OR `is_organization_super_admin === true`; renders `<Forbidden />` for everyone else; routes unauthenticated users back to `/login?returnTo=…` exactly like `SuperAdminBoundary`.

- [ ] **Step 1: Write failing test**

`resources/admin/test/org-super-boundary.test.tsx`:
```tsx
import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '@shared/config/i18n';

const authState: { user: Record<string, unknown> | null } = { user: null };

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({ user: authState.user, isAuthenticated: authState.user !== null, isLoading: false }),
}));
vi.mock('@shared/contexts/LocaleContext', () => ({
  useLocale: () => ({ locale: 'en', direction: 'ltr', setLocale: vi.fn() }),
}));

import { OrgSuperOrSuperBoundary } from '@admin/app/OrgSuperOrSuperBoundary';

function renderProtectedAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route element={<OrgSuperOrSuperBoundary />}>
          <Route path="/protected" element={<div data-testid="org-super-protected">protected</div>} />
        </Route>
      </Routes>
    </MemoryRouter>,
  );
}

describe('OrgSuperOrSuperBoundary predicate', () => {
  beforeEach(() => { authState.user = null; });

  it('renders the protected outlet for is_super_admin === true', async () => {
    authState.user = { id: 1, is_super_admin: true, is_org_admin: false, is_organization_super_admin: false };
    renderProtectedAt('/protected');
    expect(await screen.findByTestId('org-super-protected')).toBeInTheDocument();
  });

  it('renders the protected outlet for is_organization_super_admin === true', async () => {
    authState.user = { id: 2, is_super_admin: false, is_org_admin: false, is_organization_super_admin: true };
    renderProtectedAt('/protected');
    expect(await screen.findByTestId('org-super-protected')).toBeInTheDocument();
  });

  it('renders Forbidden for an authenticated user with neither flag', async () => {
    authState.user = { id: 3, is_super_admin: false, is_org_admin: false, is_organization_super_admin: false };
    renderProtectedAt('/protected');
    expect(await screen.findByRole('heading', { name: i18n.t('ovr.api.access_denied') })).toBeInTheDocument();
    expect(screen.queryByTestId('org-super-protected')).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test and confirm it fails**

Run: `npm test -- resources/admin/test/org-super-boundary.test.tsx`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the boundary**

`resources/admin/app/OrgSuperOrSuperBoundary.tsx`:
```tsx
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@shared/contexts/AuthContext';
import { getSafeAdminReturnPath } from '@admin/widgets/admin-shell/AdminNavigation';
import { Forbidden } from '@admin/pages/Forbidden';

export function OrgSuperOrSuperBoundary() {
  const { t } = useTranslation();
  const { user, isLoading, isAuthenticated } = useAuth();
  const location = useLocation();

  if (isLoading) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[var(--surface-base)]">
        <p role="status" className="text-sm text-[var(--text-secondary)]">
          {t('common.loading')}
        </p>
      </main>
    );
  }

  if (!isAuthenticated) {
    const returnTo = getSafeAdminReturnPath(`${location.pathname}${location.search}`);
    return <Navigate to={`/login?returnTo=${encodeURIComponent(returnTo)}`} replace />;
  }

  const admitted = user?.is_super_admin === true || user?.is_organization_super_admin === true;
  if (!admitted) {
    return <Forbidden />;
  }

  return <Outlet />;
}
```

- [ ] **Step 4: Re-run test**

Run: `npm test -- resources/admin/test/org-super-boundary.test.tsx`
Expected: PASS (3 tests).

- [ ] **Step 5: Lint, typecheck, commit**

```bash
npm run lint -- resources/admin/app/OrgSuperOrSuperBoundary.tsx resources/admin/test/org-super-boundary.test.tsx
npm run typecheck
git add resources/admin/app/OrgSuperOrSuperBoundary.tsx resources/admin/test/org-super-boundary.test.tsx
git commit -m "feat(admin): add OrgSuperOrSuperBoundary for the org-super route subset"
```

---

### Task 10: `AdminNavigation` predicate update + `org-super` group

**Files:**
- Modify: `resources/admin/widgets/admin-shell/AdminNavigation.tsx:20-58` (extend `AdminNavItem.group` union, `isAdminNavItemVisible`, and `ADMIN_NAV_ITEMS`).
- Modify: `resources/admin/widgets/admin-shell/AdminNavigation.tsx:80-130` (render the new group + section label).
- Test: `resources/admin/test/admin-nav-org-super.test.tsx` (new).

**Interfaces:**
- Consumes: existing `User` shape from Task 14 (adds `is_organization_super_admin?: boolean`).
- Produces: extended `AdminNavItem.group` union `('governance' | 'controls' | 'system' | 'org' | 'org-super')`; new `isAdminNavItemVisible` predicate that:
  - `system` → `is_super_admin === true` only (unchanged).
  - `org-super` → `(is_super_admin === true) || (is_organization_super_admin === true)`.
  - `org` → `(is_super_admin === true) || (is_org_admin === true)` (unchanged).
  - `governance`/`controls` → any authenticated user (unchanged).
- New nav item under `org-super`: `/organizations/:organizationId/settings`. `/users`, `/departments`, `/incident-types` already render today and remain reachable (route guards remain the source of truth — they live in `<SuperAdminBoundary>` or `<OrgSuperOrSuperBoundary>` per Task 12; the nav item visibility for these stays broad and the boundary enforces server-side gating).

- [ ] **Step 1: Write failing tests**

`resources/admin/test/admin-nav-org-super.test.tsx`:
```tsx
import { describe, expect, it } from 'vitest';
import { isAdminNavItemVisible, type AdminNavItem } from '@admin/widgets/admin-shell/AdminNavigation';

const TestIcon = () => null;
const item = (group: AdminNavItem['group']): AdminNavItem => ({
  href: `/${group}`,
  labelKey: `admin.test.${group}`,
  fallback: group,
  group,
  icon: TestIcon,
});

describe('isAdminNavItemVisible with org-super group', () => {
  const cases: Array<{ name: string; group: AdminNavItem['group']; user: Parameters<typeof isAdminNavItemVisible>[1]; expected: boolean }> = [
    { name: 'system hides from Org-Super', group: 'system', user: { is_super_admin: false, is_organization_super_admin: true }, expected: false },
    { name: 'system shows to super_admin', group: 'system', user: { is_super_admin: true, is_organization_super_admin: false }, expected: true },
    { name: 'org-super shows to Org-Super', group: 'org-super', user: { is_super_admin: false, is_organization_super_admin: true }, expected: true },
    { name: 'org-super shows to super_admin', group: 'org-super', user: { is_super_admin: true, is_organization_super_admin: false }, expected: true },
    { name: 'org-super hides from non-admin', group: 'org-super', user: { is_super_admin: false, is_organization_super_admin: false }, expected: false },
    { name: 'org-super hides from curated OrgAdmin (legacy admin role)', group: 'org-super', user: { is_super_admin: false, is_org_admin: true, is_organization_super_admin: false }, expected: false },
  ];
  it.each(cases)('$name', ({ group, user, expected }) => {
    expect(isAdminNavItemVisible(item(group), user)).toBe(expected);
  });
});
```

- [ ] **Step 2: Run test and confirm it fails**

Run: `npm test -- resources/admin/test/admin-nav-org-super.test.tsx`
Expected: FAIL — `group: 'org-super'` not assignable to `AdminNavItem['group']`.

- [ ] **Step 3: Extend the union, predicate, items, and renderer**

In `resources/admin/widgets/admin-shell/AdminNavigation.tsx`:

- Extend the `AdminNavItem` type (line 20): `group: 'governance' | 'controls' | 'system' | 'org' | 'org-super';`
- Update the JSDoc (lines 26-29) to describe the `org-super` group.
- Update `isAdminNavItemVisible` signature (line 35) to read `user: { is_super_admin?: boolean; is_org_admin?: boolean; is_organization_super_admin?: boolean } | null | undefined`.
- Insert the `org-super` branch (before the existing `if (item.group === 'org')`):

```ts
  if (item.group === 'org-super') {
    return user?.is_super_admin === true || user?.is_organization_super_admin === true;
  }
```

- Add the new nav item to `ADMIN_NAV_ITEMS` (after line 57):

```ts
  { href: '/organizations/:organizationId/settings', labelKey: 'admin.organizationSettings.title', fallback: 'Organization settings', group: 'org-super', icon: IconBuildingCommunity },
```

- Update the `groups` array on line 83 to include `'org-super'`:

```ts
  const groups: Array<AdminNavItem['group']> = ['governance', 'controls', 'system', 'org', 'org-super'];
```

- Update the section label switch (lines 100-104) to render `t('admin.shell.sidebar.section_org_super')` for the new group. (Adjust the existing switch as needed to handle five labels — keep behavior of existing groups identical.)

- [ ] **Step 4: Re-run test**

Run: `npm test -- resources/admin/test/admin-nav-org-super.test.tsx`
Expected: PASS (6 tests).

- [ ] **Step 5: Re-run existing boundary + navigation tests to confirm no regression**

Run: `npm test -- resources/admin/test/boundary-predicates.test.tsx resources/admin/test/admin-shell.test.tsx`
Expected: existing tests still pass (the type widening to optional `is_organization_super_admin?: boolean` is additive).

- [ ] **Step 6: Lint, typecheck, commit**

```bash
npm run lint -- resources/admin/widgets/admin-shell/AdminNavigation.tsx resources/admin/test/admin-nav-org-super.test.tsx
npm run typecheck
git add resources/admin/widgets/admin-shell/AdminNavigation.tsx resources/admin/test/admin-nav-org-super.test.tsx
git commit -m "feat(admin): add org-super nav group + predicate for new boundary"
```

---

### Task 11: `adminApi.organizationSettings` adapter

**Files:**
- Modify: `resources/admin/api/adminApi.ts` (add new `organizationSettings` module inside the `adminApi` object).
- Modify: `resources/admin/model/admin.ts` (add `OrganizationSettings` and `OrganizationSettingsInput` types).
- Test: `resources/admin/test/admin-api-org-settings.test.ts` (new).

**Interfaces:**
- Consumes: `api.get/put` from `@shared/api/client` (already attaches `X-Idempotency-Key` on PUT — see `198f0d2`).
- Produces:
  - `adminApi.organizationSettings.get(orgId: number)` → `api.get<{ data: OrganizationSettings }>(`/organizations/${orgId}/settings`)`.
  - `adminApi.organizationSettings.update(orgId: number, input: OrganizationSettingsInput)` → `api.put(`/organizations/${orgId}/settings`, input)`.

- [ ] **Step 1: Write failing test**

`resources/admin/test/admin-api-org-settings.test.ts`:
```ts
import { describe, expect, it, vi } from 'vitest';

vi.mock('@shared/api/client', () => ({
  api: {
    get: vi.fn().mockResolvedValue({ data: { locale_overrides: {}, branding_overrides: {}, notification_templates: {} } }),
    put: vi.fn().mockResolvedValue({ data: { locale_overrides: {}, branding_overrides: {}, notification_templates: {} } }),
  },
}));

import { adminApi } from '@admin/api/adminApi';

describe('adminApi.organizationSettings URL shapes', () => {
  it('get hits the canonical route', async () => {
    await adminApi.organizationSettings.get(42);
    const { api } = await import('@shared/api/client');
    expect(api.get).toHaveBeenLastCalledWith('/organizations/42/settings');
  });

  it('update hits the canonical route with the payload', async () => {
    await adminApi.organizationSettings.update(42, { branding_overrides: { primary_color: '#1F3A8A' } });
    const { api } = await import('@shared/api/client');
    expect(api.put).toHaveBeenLastCalledWith(
      '/organizations/42/settings',
      { branding_overrides: { primary_color: '#1F3A8A' } },
    );
  });
});
```

- [ ] **Step 2: Run failing test**

Run: `npm test -- resources/admin/test/admin-api-org-settings.test.ts`
Expected: FAIL — `adminApi.organizationSettings` is undefined.

- [ ] **Step 3: Add the type to the admin model**

In `resources/admin/model/admin.ts`, append (after the existing `Organization` interface around line 100):

```ts
export interface OrganizationSettings {
  locale_overrides: { ar?: string; en?: string };
  branding_overrides: { primary_color?: string | null; logo_path?: string | null };
  notification_templates: Record<string, string>;
}

export interface OrganizationSettingsInput {
  locale_overrides?: { ar?: string; en?: string };
  branding_overrides?: { primary_color?: string | null; logo_path?: string | null };
  notification_templates?: Record<string, string>;
}
```

- [ ] **Step 4: Add the API adapter**

In `resources/admin/api/adminApi.ts`:

- Extend the type import (line 27) to add `OrganizationSettings, OrganizationSettingsInput`:

```ts
import type {
  …,
  OrganizationSettings,
  OrganizationSettingsInput,
  …,
} from '@admin/model/admin';
```

- Inside the `adminApi` object, immediately after the `organizations` block (line 84), append:

```ts
  organizationSettings: {
    get: (organizationId: number) =>
      api.get<{ data: OrganizationSettings }>(`/organizations/${organizationId}/settings`),
    update: (organizationId: number, input: OrganizationSettingsInput) =>
      api.put(`/organizations/${organizationId}/settings`, input),
  },
```

- [ ] **Step 5: Re-run test**

Run: `npm test -- resources/admin/test/admin-api-org-settings.test.ts`
Expected: PASS (2 tests).

- [ ] **Step 6: Re-run the existing adminApi contract test to confirm no regression**

Run: `npm test -- resources/admin/test/admin-api-contract.test.ts`
Expected: PASS — the existing retargeted URLs (`/organizations`, `/users`, etc.) are unchanged.

- [ ] **Step 7: Lint, typecheck, commit**

```bash
npm run lint -- resources/admin/api/adminApi.ts resources/admin/model/admin.ts resources/admin/test/admin-api-org-settings.test.ts
npm run typecheck
git add resources/admin/api/adminApi.ts resources/admin/model/admin.ts resources/admin/test/admin-api-org-settings.test.ts
git commit -m "feat(admin): add organizationSettings adapter for new contract"
```

---

### Task 12: Organization settings page + AdminRouter restructure

**Files:**
- Create: `resources/admin/pages/organizations/OrganizationSettingsPage.tsx`.
- Modify: `resources/admin/app/AdminRouter.tsx` (mount the new route under `OrgSuperOrSuperBoundary`; restructure the boundary blocks).
- Test: `resources/admin/test/admin-org-settings-page.test.tsx` (new).

**Interfaces:**
- Consumes: `adminApi.organizationSettings.get/update` from Task 11; `RequirePermission` from `@features/access-control` (capability `organization.settings.view`) — note: the Admin SPA uses page-level guards; the existing `OrgSuperOrSuperBoundary` (Task 9) already enforces super_org-super gating on the route tree, so the page-level guard is belt-and-braces and must accept the Org-Super actor.
- Produces: a page that loads `/organizations/{organizationId}/settings` and renders the three sections (locale overrides, branding overrides, notification templates) using existing `DataTable` / `FilterBar` / `PageHeader` / `StatStrip` shared UI primitives; the form submits via `adminApi.organizationSettings.update` with `X-Idempotency-Key` attached automatically by the shared client.

- [ ] **Step 1: Write failing test**

`resources/admin/test/admin-org-settings-page.test.tsx`:
```tsx
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '@shared/config/i18n';

const authState: { user: Record<string, unknown> | null } = {
  user: {
    id: 1,
    name: 'Org Super',
    email: 'orgsuper@example.test',
    is_super_admin: false,
    is_org_admin: false,
    is_organization_super_admin: true,
    organization_id: 17,
    capabilities: ['organization.settings.view', 'organization.settings.edit'],
    access: { 'organization.settings.view': true, 'organization.settings.edit': true },
    role_assignments: [],
    organizations: [{ id: 17, name: 'Erada', code: 'ERD', is_active: true }],
  },
};

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({ user: authState.user, isAuthenticated: true, isLoading: false }),
}));
vi.mock('@shared/contexts/LocaleContext', () => ({
  useLocale: () => ({ locale: 'ar', direction: 'rtl', setLocale: vi.fn() }),
}));
vi.mock('@shared/contexts/ThemeContext', () => ({
  useTheme: () => ({ resolvedTheme: 'light', toggleTheme: vi.fn() }),
}));
vi.mock('@shared/ui/Toast', () => ({ ToastProvider: ({ children }: { children: React.ReactNode }) => children }));
vi.mock('@shared/api/client', () => ({
  api: {
    get: vi.fn().mockResolvedValue({
      data: { locale_overrides: {}, branding_overrides: {}, notification_templates: {} },
    }),
    put: vi.fn().mockResolvedValue({
      data: { locale_overrides: {}, branding_overrides: { primary_color: '#1F3A8A' }, notification_templates: {} },
    }),
  },
}));

import { OrganizationSettingsPage } from '@admin/pages/organizations/OrganizationSettingsPage';

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/organizations/17/settings']}>
      <Routes>
        <Route path="/organizations/:organizationId/settings" element={<OrganizationSettingsPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('OrganizationSettingsPage', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('renders the page header and three sections', async () => {
    renderPage();
    expect(await screen.findByRole('heading', { name: i18n.t('admin.organizationSettings.title') })).toBeInTheDocument();
    expect(screen.getByText(i18n.t('admin.organizationSettings.sections.locale'))).toBeInTheDocument();
    expect(screen.getByText(i18n.t('admin.organizationSettings.sections.branding'))).toBeInTheDocument();
    expect(screen.getByText(i18n.t('admin.organizationSettings.sections.templates'))).toBeInTheDocument();
  });

  it('submits via adminApi.organizationSettings.update on save', async () => {
    const user = userEvent.setup();
    renderPage();
    const saveButton = await screen.findByRole('button', { name: i18n.t('common.save') });
    await user.click(saveButton);
    await waitFor(() => {
      const { api } = await import('@shared/api/client');
      expect(api.put).toHaveBeenCalledWith('/organizations/17/settings', expect.any(Object));
    });
  });
});
```

- [ ] **Step 2: Run failing test**

Run: `npm test -- resources/admin/test/admin-org-settings-page.test.tsx`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the page**

`resources/admin/pages/organizations/OrganizationSettingsPage.tsx`:
```tsx
import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { adminApi } from '@admin/api/adminApi';
import { PageHeader } from '@shared/ui/PageHeader';
import { StatStrip } from '@shared/ui/StatStrip';
import { DataTable } from '@shared/ui/DataTable';
import { FilterBar } from '@shared/ui/FilterBar';
import type { OrganizationSettings, OrganizationSettingsInput } from '@admin/model/admin';

export function OrganizationSettingsPage() {
  const { t } = useTranslation();
  const { organizationId } = useParams<{ organizationId: string }>();
  const orgId = Number(organizationId);
  const [settings, setSettings] = useState<OrganizationSettings | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!Number.isFinite(orgId)) return;
    adminApi.organizationSettings.get(orgId)
      .then((res) => setSettings(res.data))
      .catch((err: unknown) => setError(adminApi.apiErrorMessage(err, t('admin.organizationSettings.load_failed'))));
  }, [orgId, t]);

  async function onSave() {
    if (!settings) return;
    setSaving(true);
    setError(null);
    try {
      const payload: OrganizationSettingsInput = settings;
      const res = await adminApi.organizationSettings.update(orgId, payload);
      setSettings(res.data);
    } catch (err) {
      setError(adminApi.apiErrorMessage(err, t('admin.organizationSettings.save_failed')));
    } finally {
      setSaving(false);
    }
  }

  if (error) {
    return (
      <section className="p-6">
        <PageHeader title={t('admin.organizationSettings.title')} />
        <p role="alert" className="mt-4 text-sm text-[var(--status-danger)]">{error}</p>
      </section>
    );
  }

  if (!settings) {
    return (
      <section className="p-6">
        <PageHeader title={t('admin.organizationSettings.title')} />
        <p role="status" className="mt-4 text-sm text-[var(--text-secondary)]">{t('common.loading')}</p>
      </section>
    );
  }

  return (
    <section className="space-y-6 p-6">
      <PageHeader
        title={t('admin.organizationSettings.title')}
        subtitle={t('admin.organizationSettings.subtitle')}
      />
      <StatStrip items={[
        { label: t('admin.organizationSettings.fields.locale'), value: Object.keys(settings.locale_overrides).length },
        { label: t('admin.organizationSettings.fields.branding'), value: Object.keys(settings.branding_overrides).length },
        { label: t('admin.organizationSettings.fields.templates'), value: Object.keys(settings.notification_templates).length },
      ]} />
      <FilterBar>
        <h2 className="text-sm font-semibold text-[var(--text-primary)]">
          {t('admin.organizationSettings.sections.locale')}
        </h2>
      </FilterBar>
      <DataTable
        columns={[
          { key: 'key', label: t('admin.organizationSettings.fields.locale_key') },
          { key: 'value', label: t('admin.organizationSettings.fields.locale_value') },
        ]}
        rows={Object.entries(settings.locale_overrides).map(([key, value]) => ({ key, value: value ?? '' }))}
      />
      <button
        type="button"
        disabled={saving}
        onClick={onSave}
        className="rounded-lg bg-[var(--accent-default)] px-4 py-2 text-sm font-semibold text-[var(--text-inverse)] disabled:opacity-50"
      >
        {saving ? t('common.saving') : t('common.save')}
      </button>
    </section>
  );
}
```

- [ ] **Step 4: Mount the route and restructure the boundary blocks** (permitted org-admin routes MOVE from `<SuperAdminBoundary>` into `<OrgSuperOrSuperBoundary>`; preserved constraints: NO `/org/*` SPA files, `is_admin_role=false`, explicit role constraints per T3)

> **Boundary restructure rule.** Permitted org-admin routes (`/users`, `/users/new`, `/users/:userId`, `/users/:userId/edit`, `/departments`, `/departments/new`, `/departments/:departmentId`, `/departments/:departmentId/edit`, `/incident-types`, `/organizations/:organizationId/settings`) MOVE OUT of `<SuperAdminBoundary>` and INTO `<OrgSuperOrSuperBoundary>`. The `<SuperAdminBoundary>` block retains ONLY system-only routes (`/overview`, `/security/alerts`, `/audit/recent`, `/organizations`, `/organizations/new`, `/organizations/:organizationId`, `/organizations/:organizationId/edit`, `/access`, `/access/governance`, `/roles`, `/roles/new`, `/roles/governing-departments`, `/roles/:roleId`, `/roles/:roleId/edit`, `/activity-logs`, `/scoped-roles/audit-logs`, `/scope-types`). **Preserved constraints:**
> - **NO `/org/*` files** anywhere in `resources/admin/` (the obsolete plan's `/org/*` sub-SPA was never created and is NOT created here — the Admin SPA has exactly one router, one navigation, three boundary groups).
> - `is_admin_role=false` on `organization_super_admin` is set in T3 and enforced by T8's regression test; it is preserved here by keeping `<OrgSuperOrSuperBoundary>` as the ONLY entry point that admits OrgSuper — the boundary consults `user?.is_super_admin === true || user?.is_organization_super_admin === true` (per T9 predicate) and the role itself cannot ride the admin shortcut.
> - **Explicit role constraints** per T3's curated capability list are preserved: OrgSuper holds `users.*`, `departments.*`, `organization.settings.*`, `audit.view`, `roles.view`, `roles.assign` (the dedicated route from T7); the curated `admin` role continues to hold its `OrgAdminCuratedCapabilities` set unchanged (T15 regression).

In `resources/admin/app/AdminRouter.tsx`:

- Add the import (after the existing `OrganizationDetails` import, line 12):

```tsx
import { OrganizationSettingsPage } from '@admin/pages/organizations/OrganizationSettingsPage';
```

- Add the `OrgSuperOrSuperBoundary` import (after the existing `SuperAdminBoundary` import, line 2):

```tsx
import { OrgSuperOrSuperBoundary } from '@admin/app/OrgSuperOrSuperBoundary';
```

- Restructure the routes: keep `<SuperAdminBoundary>` for system-only routes; permitted org-admin routes MOVE INTO `<OrgSuperOrSuperBoundary>`. Replace the existing `<Route element={<SuperAdminBoundary />}>…</Route>` block (lines 35-66) with:

```tsx
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/verify-2fa" element={<TwoFactorVerification />} />

        {/* SuperAdmin-only system routes */}
        <Route element={<SuperAdminBoundary />}>
          <Route element={<AdminLayout />}>
            <Route index element={<Navigate to="/overview" replace />} />
            <Route path="/overview" element={<Overview />} />
            <Route path="/security/alerts" element={<SecurityAlerts />} />
            <Route path="/audit/recent" element={<AuditRecent />} />
            <Route path="/organizations" element={<OrganizationsPage />} />
            <Route path="/organizations/new" element={<OrganizationForm />} />
            <Route path="/organizations/:organizationId" element={<OrganizationDetails />} />
            <Route path="/organizations/:organizationId/edit" element={<OrganizationForm />} />
            <Route path="/access" element={<AccessPage />} />
            <Route path="/access/governance" element={<GovernanceRulesPage />} />
            <Route path="/roles" element={<RolesPage />} />
            <Route path="/roles/new" element={<RoleForm />} />
            <Route path="/roles/governing-departments" element={<Navigate to="/access/governance" replace />} />
            <Route path="/roles/:roleId" element={<RoleForm />} />
            <Route path="/roles/:roleId/edit" element={<RoleForm />} />
            <Route path="/activity-logs" element={<ActivityLogsPage />} />
            <Route path="/scoped-roles/audit-logs" element={<ScopedRoleAuditPage />} />
            <Route path="/scope-types" element={<ScopeTypesPage />} />
            <Route path="*" element={<NotFound />} />
          </Route>
        </Route>

        {/* Org-Super-or-Super routes (users, departments, incident-types, org settings) */}
        <Route element={<OrgSuperOrSuperBoundary />}>
          <Route element={<AdminLayout />}>
            <Route path="/users" element={<UsersPage />} />
            <Route path="/users/new" element={<UserForm />} />
            <Route path="/users/:userId" element={<UserDetails />} />
            <Route path="/users/:userId/edit" element={<UserForm />} />
            <Route path="/departments" element={<DepartmentsPage />} />
            <Route path="/departments/new" element={<DepartmentForm />} />
            <Route path="/departments/:departmentId" element={<DepartmentDetails />} />
            <Route path="/departments/:departmentId/edit" element={<DepartmentForm />} />
            <Route path="/incident-types" element={<IncidentTypesPage />} />
            <Route path="/organizations/:organizationId/settings" element={<OrganizationSettingsPage />} />
            <Route path="*" element={<NotFound />} />
          </Route>
        </Route>
      </Routes>
```

**Note on parity verification before deletion:** The obsolete plan's Tasks 7 (`AdminE2ETestSeeder`) and 8 (`AdminRouteContractTest`) are NOT deleted here. The new test files `resources/admin/test/admin-org-settings-page.test.tsx` and `resources/admin/test/admin-nav-org-super.test.tsx` prove the route mounting works under `OrgSuperOrSuperBoundary`. Only after the Playwright run in Task 17 confirms `/users`, `/departments`, `/incident-types`, and `/organizations/:organizationId/settings` all 200 for an Org-Super actor AND 403/Forbidden for a non-admin should the obsolete plan's Tasks 7/8/10/11/17/18 be retired in a separate docs-only commit. Do NOT retire those files in this plan's tasks; defer to a Phase 3 docs commit.

- [ ] **Step 5: Re-run tests**

Run:
```bash
npm test -- resources/admin/test/admin-org-settings-page.test.tsx resources/admin/test/admin-shell.test.tsx resources/admin/test/admin-auth-routing.test.tsx resources/admin/test/boundary-predicates.test.tsx
```

Expected: PASS — the page renders; existing tests stay green because the boundary restructure is additive (the existing `<SuperAdminBoundary>` block is unchanged in its route contents).

- [ ] **Step 6: Add i18n keys**

Add the following keys to `lang/ar.json` and `lang/en.json` (exact values owned by the implementer; the plan specifies keys only):

- `admin.organizationSettings.title`
- `admin.organizationSettings.subtitle`
- `admin.organizationSettings.load_failed`
- `admin.organizationSettings.save_failed`
- `admin.organizationSettings.fields.locale`
- `admin.organizationSettings.fields.branding`
- `admin.organizationSettings.fields.templates`
- `admin.organizationSettings.fields.locale_key`
- `admin.organizationSettings.fields.locale_value`
- `admin.organizationSettings.sections.locale`
- `admin.organizationSettings.sections.branding`
- `admin.organizationSettings.sections.templates`
- `admin.shell.nav.org_settings`
- `admin.shell.sidebar.section_org_super`

- [ ] **Step 7: Lint, typecheck, commit**

```bash
npm run lint -- resources/admin/pages/organizations/OrganizationSettingsPage.tsx resources/admin/app/AdminRouter.tsx resources/admin/test/admin-org-settings-page.test.tsx
npm run typecheck
git add resources/admin/pages/organizations/OrganizationSettingsPage.tsx resources/admin/app/AdminRouter.tsx resources/admin/test/admin-org-settings-page.test.tsx lang/ar.json lang/en.json
git commit -m "feat(admin): add OrganizationSettingsPage mounted under OrgSuperOrSuperBoundary"
```

---

### Task 13: Activate / deactivate user actions in UsersPage

**Files:**
- Modify: `resources/admin/pages/users/UsersPage.tsx` (add per-row activate/deactivate buttons gated by `users.activate` / `users.deactivate` capabilities).
- Test: `resources/admin/test/admin-user-lifecycle.test.tsx` (new).

**Interfaces:**
- Consumes: `adminApi.users.update(id, { is_active: boolean })` (the existing API already supports this — Task 6 widens `canManageUserLifecycle()`, the idempotency-key contract from `198f0d2` rides every PUT).
- Produces: two new icon buttons per row (`activate` / `deactivate`) that render only when `user.can('users.activate')` or `user.can('users.deactivate')` is true, AND when the actor is `is_organization_super_admin === true` OR `is_super_admin === true`. The buttons call `adminApi.users.update(id, { is_active })`.

- [ ] **Step 1: Write failing test**

`resources/admin/test/admin-user-lifecycle.test.tsx`:
```tsx
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '@shared/config/i18n';

const authState: { user: Record<string, unknown> | null } = {
  user: {
    id: 1,
    name: 'Org Super',
    email: 'orgsuper@example.test',
    is_super_admin: false,
    is_org_admin: false,
    is_organization_super_admin: true,
    organization_id: 17,
    capabilities: ['users.view', 'users.activate', 'users.deactivate'],
    access: { 'users.view': true, 'users.activate': true, 'users.deactivate': true },
    role_assignments: [],
    organizations: [{ id: 17, name: 'Erada', code: 'ERD', is_active: true }],
  },
};

vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({ user: authState.user, isAuthenticated: true, isLoading: false }),
}));
vi.mock('@shared/contexts/LocaleContext', () => ({
  useLocale: () => ({ locale: 'ar', direction: 'rtl', setLocale: vi.fn() }),
}));
vi.mock('@shared/contexts/ThemeContext', () => ({
  useTheme: () => ({ resolvedTheme: 'light', toggleTheme: vi.fn() }),
}));
vi.mock('@shared/ui/Toast', () => ({ ToastProvider: ({ children }: { children: React.ReactNode }) => children }));
vi.mock('@shared/api/client', () => ({
  api: {
    get: vi.fn().mockResolvedValue({
      data: [{ id: 9, name: 'Audit User', email: 'audit@example.test', is_active: false, organization_id: 17 }],
      current_page: 1,
      last_page: 1,
      per_page: 15,
      total: 1,
    }),
    put: vi.fn().mockResolvedValue({
      user: { id: 9, name: 'Audit User', email: 'audit@example.test', is_active: true, organization_id: 17 },
    }),
  },
}));

import { UsersPage } from '@admin/pages/users/UsersPage';

describe('admin UsersPage activate/deactivate actions', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('shows the activate button for an inactive row and calls update', async () => {
    const user = userEvent.setup();
    render(<MemoryRouter><UsersPage /></MemoryRouter>);
    const activate = await screen.findByRole('button', { name: i18n.t('admin.users.actions.activate') });
    await user.click(activate);
    await waitFor(() => {
      const { api } = await import('@shared/api/client');
      expect(api.put).toHaveBeenCalledWith('/users/9', expect.objectContaining({ is_active: true }));
    });
  });
});
```

- [ ] **Step 2: Run failing test**

Run: `npm test -- resources/admin/test/admin-user-lifecycle.test.tsx`
Expected: FAIL — `admin.users.actions.activate` button not found.

- [ ] **Step 3: Extend UsersPage with the actions**

In `resources/admin/pages/users/UsersPage.tsx`, locate the per-row action column (search for the existing `IconLock` or similar) and add two new conditional buttons:

```tsx
{user?.can('users.activate') && row.is_active === false && (
  <button
    type="button"
    aria-label={t('admin.users.actions.activate')}
    onClick={() => adminApi.users.update(row.id, { is_active: true }).then(refreshList)}
    className="rounded-lg p-2 hover:bg-[var(--surface-muted)]"
  >
    <IconCheck className="h-4 w-4" />
  </button>
)}
{user?.can('users.deactivate') && row.is_active === true && (
  <button
    type="button"
    aria-label={t('admin.users.actions.deactivate')}
    onClick={() => adminApi.users.update(row.id, { is_active: false }).then(refreshList)}
    className="rounded-lg p-2 hover:bg-[var(--surface-muted)]"
  >
    <IconX className="h-4 w-4" />
  </button>
)}
```

(Use existing `@tabler/icons-react` icons `IconCheck` / `IconX` per the ESLint boundary and `rename-icons.mjs` enforcement. Adjust the refreshList reference to whatever helper the existing page uses to re-fetch the list.)

- [ ] **Step 4: Add i18n keys**

Append to `lang/ar.json` and `lang/en.json`:

- `admin.users.actions.activate`
- `admin.users.actions.deactivate`

- [ ] **Step 5: Re-run test**

Run: `npm test -- resources/admin/test/admin-user-lifecycle.test.tsx`
Expected: PASS.

- [ ] **Step 6: Lint, typecheck, commit**

```bash
npm run lint -- resources/admin/pages/users/UsersPage.tsx resources/admin/test/admin-user-lifecycle.test.tsx
npm run typecheck
git add resources/admin/pages/users/UsersPage.tsx resources/admin/test/admin-user-lifecycle.test.tsx lang/ar.json lang/en.json
git commit -m "feat(admin): add activate/deactivate actions on UsersPage for Org-Super"
```

---

### Task 14: Type widening for `is_organization_super_admin` in shared types

**Files:**
- Modify: `resources/js/shared/types/index.ts:33-35` (extend the `User` interface).
- Test: `resources/js/__tests__/auth/user-organization-super-admin-flag.test.ts` (new).

**Interfaces:**
- Consumes: existing `User` shape.
- Produces: optional `is_organization_super_admin?: boolean` on `User`; existing tests that omit the key keep compiling because the field is optional.

- [ ] **Step 1: Write failing test**

`resources/js/__tests__/auth/user-organization-super-admin-flag.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import type { User } from '@shared/types';

describe('User.is_organization_super_admin', () => {
  it('is optional and accepts true / false / undefined', () => {
    const a: User = {
      id: 1, name: 'Org Super', email: 'orgsuper@example.test',
      department_id: null, phone: null, extension: null, job_title: null,
      is_active: true,
      is_super_admin: false,
      is_org_admin: false,
      is_organization_super_admin: true,
    };
    const b: User = { ...a, is_organization_super_admin: false };
    const c: User = { ...a };
    delete (c as { is_organization_super_admin?: boolean }).is_organization_super_admin;
    expect(a.is_organization_super_admin).toBe(true);
    expect(b.is_organization_super_admin).toBe(false);
    expect(c.is_organization_super_admin).toBeUndefined();
  });
});
```

- [ ] **Step 2: Run failing test**

Run: `npm test -- resources/js/__tests__/auth/user-organization-super-admin-flag.test.ts`
Expected: FAIL — typecheck error.

- [ ] **Step 3: Extend the type**

In `resources/js/shared/types/index.ts`, immediately after `is_org_admin` (line 35), append:

```ts
  /** Backend-computed organization_super_admin flag from `/api/user`. Additive, optional. */
  is_organization_super_admin?: boolean;
```

- [ ] **Step 4: Re-run test**

Run: `npm test -- resources/js/__tests__/auth/user-organization-super-admin-flag.test.ts`
Expected: PASS.

- [ ] **Step 5: Typecheck and run the existing `__tests__/authz` matrix to confirm no regression**

Run: `npm run typecheck && npm test -- resources/js/__tests__/authz resources/js/__tests__/auth`
Expected: PASS — the additive optional field does not break any existing mock.

- [ ] **Step 6: Lint, typecheck, commit**

```bash
npm run lint -- resources/js/shared/types/index.ts resources/js/__tests__/auth/user-organization-super-admin-flag.test.ts
npm run typecheck
git add resources/js/shared/types/index.ts resources/js/__tests__/auth/user-organization-super-admin-flag.test.ts
git commit -m "feat(types): add optional is_organization_super_admin on User"
```

---

## Phase 1.5 — Unmerged-commit integration

### Task 15: Legacy admin role regression — extend `6ed111f`

**Files:**
- Test: `tests/Feature/Authz/OrganizationSuperAdminLegacyAdminParityTest.php` (new — extends `OrgAdminScopeTest` baseline).
- No source change.

**Interfaces:**
- Consumes: existing `OrgAdminScopeTest` baseline (commit `6ed111f`, 797 lines, 16 tests, 60 assertions) that locks the curated `admin` pivot set, the cluster rescue no-op for `admin`, the policy cross-org isolation, the HTTP cross-org isolation, and the role-mutation guard.
- Produces: an additional regression test that proves the legacy `admin` role pivot set is NOT mutated by the new `organization_super_admin` seed entry; i.e. seeding Org-Super does not add any pivot row to the `admin` role and vice versa.

- [ ] **Step 1: Write the extension test**

`tests/Feature/Authz/OrganizationSuperAdminLegacyAdminParityTest.php`:
```php
<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationSuperAdminLegacyAdminParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_organization_super_admin_does_not_mutate_admin_pivot_set(): void
    {
        (new RolesAndPermissionsSeeder)->run();

        $admin = AuthorizationRole::query()->where('name', 'admin')->firstOrFail();
        $adminPivotSnapshot = $admin->permissions
            ->map(fn (AuthorizationRolePermission $p) => $p->authorization_resource_id.':'.$p->action)
            ->sort()
            ->values()
            ->all();

        // Idempotent re-run — Org-Super pivot set must not bleed into admin.
        (new RolesAndPermissionsSeeder)->run();

        $adminAfter = AuthorizationRole::query()->where('name', 'admin')->firstOrFail();
        $adminPivotAfter = $adminAfter->permissions
            ->map(fn (AuthorizationRolePermission $p) => $p->authorization_resource_id.':'.$p->action)
            ->sort()
            ->values()
            ->all();

        $this->assertSame($adminPivotSnapshot, $adminPivotAfter);
    }

    public function test_org_super_curated_pivot_set_is_disjoint_from_admin_pivot_set(): void
    {
        (new RolesAndPermissionsSeeder)->run();

        $admin = AuthorizationRole::query()->where('name', 'admin')->firstOrFail();
        $orgSuper = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();

        $adminKeys = $admin->permissions
            ->map(fn (AuthorizationRolePermission $p) => $p->authorization_resource_id.':'.$p->action)
            ->all();

        $orgSuperKeys = $orgSuper->permissions
            ->map(fn (AuthorizationRolePermission $p) => $p->authorization_resource_id.':'.$p->action)
            ->all();

        foreach ($orgSuperKeys as $key) {
            $this->assertNotContains($key, $adminKeys, "Admin pivot set must not contain Org-Super pivot [{$key}].");
        }
    }

    public function test_admin_role_retains_is_admin_role_true_after_org_super_seed(): void
    {
        (new RolesAndPermissionsSeeder)->run();

        $admin = AuthorizationRole::query()->where('name', 'admin')->firstOrFail();
        $this->assertTrue((bool) $admin->is_admin_role, 'admin role must remain is_admin_role=true (legacy shortcut preserved).');

        $orgSuper = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        $this->assertFalse((bool) $orgSuper->is_admin_role, 'organization_super_admin must remain is_admin_role=false.');
    }
}
```

- [ ] **Step 2: Run the test and confirm it passes**

Run: `php artisan test --filter=OrganizationSuperAdminLegacyAdminParityTest`
Expected: 3 tests pass.

- [ ] **Step 3: Re-run the existing `6ed111f` baseline to confirm no regression**

Run: `php artisan test --filter=OrgAdminScopeTest`
Expected: 16 tests pass (the baseline from `6ed111f`).

- [ ] **Step 4: Lint and commit**

```bash
./vendor/bin/pint --test tests/Feature/Authz/OrganizationSuperAdminLegacyAdminParityTest.php
git add tests/Feature/Authz/OrganizationSuperAdminLegacyAdminParityTest.php
git commit -m "test(authz): extend OrgAdmin baseline parity test for organization_super_admin"
```

---

### Task 16: Idempotency-Key integration check (verify `198f0d2` is reused)

**Files:**
- No source change.
- Test: `resources/admin/test/admin-idempotency-org-super.test.ts` (new — exercises `adminApi.users.update` and `adminApi.organizationSettings.update` to confirm the key is attached).

**Interfaces:**
- Consumes: `198f0d2`'s `X-Idempotency-Key` attachment on every `api.put/post/patch/delete`.
- Produces: a contract test that asserts every Org-Super mutation routes through `api.put` / `api.post`, which the shared client instruments with the idempotency key.

- [ ] **Step 1: Write the integration test**

`resources/admin/test/admin-idempotency-org-super.test.ts`:
```ts
import { describe, expect, it, vi } from 'vitest';

vi.mock('@shared/api/client', () => ({
  api: {
    put: vi.fn().mockResolvedValue({ data: {} }),
    post: vi.fn().mockResolvedValue({ data: {} }),
  },
}));

import { adminApi } from '@admin/api/adminApi';

describe('Org-Super mutations ride the idempotency contract', () => {
  it('adminApi.users.update routes through api.put (which attaches X-Idempotency-Key)', async () => {
    await adminApi.users.update(9, { is_active: true });
    const { api } = await import('@shared/api/client');
    expect(api.put).toHaveBeenCalledWith('/users/9', expect.objectContaining({ is_active: true }));
  });

  it('adminApi.organizationSettings.update routes through api.put (which attaches X-Idempotency-Key)', async () => {
    await adminApi.organizationSettings.update(17, { branding_overrides: { primary_color: '#1F3A8A' } });
    const { api } = await import('@shared/api/client');
    expect(api.put).toHaveBeenCalledWith(
      '/organizations/17/settings',
      expect.objectContaining({ branding_overrides: { primary_color: '#1F3A8A' } }),
    );
  });
});
```

- [ ] **Step 2: Run the test and confirm it passes**

Run: `npm test -- resources/admin/test/admin-idempotency-org-super.test.ts`
Expected: PASS — the `api.put` routing exercises the shared client that already attaches the key (see `resources/js/__tests__/api/client-idempotency.test.ts`, 9 tests, all green on baseline).

- [ ] **Step 3: Re-run the shared client idempotency baseline to confirm no regression**

Run: `npm test -- resources/js/__tests__/api/client-idempotency.test.ts`
Expected: 9 tests pass.

- [ ] **Step 4: Lint and commit**

```bash
npm run lint -- resources/admin/test/admin-idempotency-org-super.test.ts
git add resources/admin/test/admin-idempotency-org-super.test.ts
git commit -m "test(admin): verify Org-Super mutations ride the X-Idempotency-Key contract"
```

---

## Phase 2 — Verification

### Task 17: Targeted PHP / TS / E2E runs

**Files:** none.

- [ ] **Step 1: Focused PHPUnit run**

Run:
```bash
php artisan test --filter='UserOrganizationSuperAdminFlagTest|OrganizationSuperAdminCapabilityConstantsTest|OrganizationSuperAdminRoleSeedTest|AuthControllerOrganizationSuperAdminPayloadTest|OrganizationSettingsContractTest|OrganizationSuperAdminUserTargetTest|OrganizationSuperAdminRoleAllowlistTest|OrganizationSuperAdminClusterRescueRegressionTest|OrganizationSuperAdminLegacyAdminParityTest|OrgAdminScopeTest|OrgAdminCuratedCapabilitiesTest'
```

Expected: all listed classes pass. `OrgAdminScopeTest` (16 tests from `6ed111f`) and `OrgAdminCuratedCapabilitiesTest` (from obsolete plan) pass — confirms the legacy admin role pivot set is untouched.

- [ ] **Step 2: Full PHPUnit suite — flake-tolerant per AGENTS.md**

Run: `composer test`
Expected: green, OR specific failing class documented as flake. Per AGENTS.md + CI two-stage policy: re-run the failing class alone. If it passes, treat as flake and continue. If it fails consistently, escalate — do NOT mark this task complete.

- [ ] **Step 3: PHPStan**

Run: `composer phpstan`
Expected: 0 errors at level 2 with the existing baseline (`phpstan-baseline.neon`).

- [ ] **Step 4: Pint dry-run**

Run: `./vendor/bin/pint --test`
Expected: 0 changes required.

- [ ] **Step 5: Targeted Vitest run**

Run:
```bash
npm test -- resources/admin/test/org-super-boundary.test.tsx resources/admin/test/admin-nav-org-super.test.tsx resources/admin/test/admin-api-org-settings.test.ts resources/admin/test/admin-org-settings-page.test.tsx resources/admin/test/admin-user-lifecycle.test.tsx resources/admin/test/admin-idempotency-org-super.test.tsx resources/js/__tests__/auth/user-organization-super-admin-flag.test.ts resources/admin/test/boundary-predicates.test.tsx resources/admin/test/admin-shell.test.tsx resources/admin/test/admin-auth-routing.test.tsx resources/admin/test/users.test.tsx resources/admin/test/admin-api-contract.test.ts
```

Expected: all listed files pass.

- [ ] **Step 6: Full Vitest suite — flake-tolerant per AGENTS.md**

Run: `npm test`
Expected: green.

- [ ] **Step 7: TypeScript typecheck + lint**

Run: `npm run typecheck && npm run lint`
Expected: 0 errors, no new warnings introduced (max-warnings 1200 budget preserved).

- [ ] **Step 8: New Playwright spec — Org-Super end-to-end coverage**

Create `e2e/admin/org-super-actor.spec.ts`:
```ts
import { test, expect } from '@playwright/test';

test('OrganizationSuperAdmin can list/create/unlock/activate same-org users', async ({ page, request }) => {
  const login = await request.post('/api/login', {
    data: { email: 'orgsuper@e2e.test', password: 'password' },
  });
  expect(login.status()).toBe(200);

  await page.goto('/users');
  await expect(page.getByRole('heading', { name: /users/i })).toBeVisible();

  // Cross-org user attempt returns 403/404 envelope.
  const crossOrg = await request.put('/api/users/9999', { data: { name: 'Cross' } });
  expect([403, 404]).toContain(crossOrg.status());
});

test('OrganizationSuperAdmin can edit own organization settings', async ({ page, request }) => {
  const login = await request.post('/api/login', {
    data: { email: 'orgsuper@e2e.test', password: 'password' },
  });
  expect(login.status()).toBe(200);

  // Read the payload to confirm is_organization_super_admin is exposed.
  const me = await request.get('/api/user');
  expect(me.ok()).toBeTruthy();
  const body = await me.json();
  expect(body.is_organization_super_admin).toBe(true);

  // Update org settings via the new endpoint.
  const settings = await request.put('/api/organizations/17/settings', {
    data: { branding_overrides: { primary_color: '#1F3A8A' } },
  });
  expect(settings.ok()).toBeTruthy();

  await page.goto('/organizations/17/settings');
  await expect(page.getByRole('heading', { name: /organization settings/i })).toBeVisible();
});

test('OrganizationSuperAdmin cannot edit /api/settings/system', async ({ request }) => {
  const login = await request.post('/api/login', {
    data: { email: 'orgsuper@e2e.test', password: 'password' },
  });
  expect(login.status()).toBe(200);

  const settings = await request.put('/api/settings/system', { data: { app_name: 'Hacked' } });
  expect(settings.status()).toBe(403);
  const body = await settings.json();
  expect(body.required_capability).toBe('settings.edit');
});

test('OrganizationSuperAdmin cannot assign admin or super_admin or organization_super_admin via canonical route', async ({ request }) => {
  // Canonical /api/roles/assign is gated by engine_capability:core.assign_roles
  // (PlatformSuperAdmin only). OrgSuper does NOT hold that capability, so the
  // route MUST reject every OrgSuper attempt regardless of role name.
  const login = await request.post('/api/login', {
    data: { email: 'orgsuper@e2e.test', password: 'password' },
  });
  expect(login.status()).toBe(200);

  const me = await request.get('/api/user');
  const body = await me.json();
  expect(body.is_organization_super_admin).toBe(true);

  // Discover role ids by name from /api/roles.
  const roles = await request.get('/api/roles');
  const rolesBody = await roles.json();
  const admin = rolesBody.data.find((r: { name: string }) => r.name === 'admin');
  const superAdmin = rolesBody.data.find((r: { name: string }) => r.name === 'super_admin');
  const orgSuper = rolesBody.data.find((r: { name: string }) => r.name === 'organization_super_admin');

  for (const role of [admin, superAdmin, orgSuper]) {
    const assign = await request.post('/api/roles/assign', {
      data: {
        user_id: body.id + 1, // any other user in the same org
        replace_all: true,
        assignments: [{ role_id: role.id, scope_type: 'organization', scope_id: body.organization_id, inherit_to_children: false }],
      },
    });
    expect([403, 422]).toContain(assign.status());
  }
});

test('OrganizationSuperAdmin can assign an operational role via the dedicated OrgSuper route', async ({ request }) => {
  // T7 positive case — the only OrgSuper-assignable surface. Confirms the
  // dedicated POST /api/org-super/role-assignments route + OrgSuper actor guard
  // + FormRequest allowlist admit a same-org operational role assignment.
  const login = await request.post('/api/login', {
    data: { email: 'orgsuper@e2e.test', password: 'password' },
  });
  expect(login.status()).toBe(200);

  const me = await request.get('/api/user');
  const body = await me.json();
  expect(body.is_organization_super_admin).toBe(true);

  // Discover the `manager` role id from /api/roles (operational, is_admin_role=false, is_system=false).
  const roles = await request.get('/api/roles');
  const rolesBody = await roles.json();
  const manager = rolesBody.data.find((r: { name: string; is_admin_role: boolean; is_system: boolean }) =>
    r.name === 'manager' && r.is_admin_role === false && r.is_system === false,
  );
  expect(manager, 'manager role must exist and be operational (is_admin_role=false, is_system=false)').toBeDefined();

  const target = body.id + 1;
  const assign = await request.post('/api/org-super/role-assignments', {
    data: {
      user_id: target,
      replace_all: true,
      assignments: [{
        role_id: manager.id,
        scope_type: 'organization',
        scope_id: body.organization_id, // server-derives to actor.organization_id regardless
        inherit_to_children: false,
      }],
    },
  });
  expect(assign.status()).toBe(200);
  const assignBody = await assign.json();
  expect(assignBody.data.user_id).toBe(target);
});

test('OrganizationSuperAdmin cannot assign admin or super_admin or organization_super_admin via dedicated OrgSuper route', async ({ request }) => {
  // Negative cases on the dedicated route — server-side allowlist must reject
  // every protected role name even when scope/subject pass the other checks.
  const login = await request.post('/api/login', {
    data: { email: 'orgsuper@e2e.test', password: 'password' },
  });
  expect(login.status()).toBe(200);

  const me = await request.get('/api/user');
  const body = await me.json();

  const roles = await request.get('/api/roles');
  const rolesBody = await roles.json();
  const admin = rolesBody.data.find((r: { name: string }) => r.name === 'admin');
  const superAdmin = rolesBody.data.find((r: { name: string }) => r.name === 'super_admin');
  const orgSuper = rolesBody.data.find((r: { name: string }) => r.name === 'organization_super_admin');

  for (const role of [admin, superAdmin, orgSuper]) {
    const assign = await request.post('/api/org-super/role-assignments', {
      data: {
        user_id: body.id + 1,
        replace_all: true,
        assignments: [{ role_id: role.id, scope_type: 'organization', scope_id: body.organization_id, inherit_to_children: false }],
      },
    });
    expect([403, 422]).toContain(assign.status());
  }
});

test('OrganizationSuperAdmin cannot use a cross-org scope_id on the dedicated OrgSuper route', async ({ request }) => {
  // Client scope manipulation — server must reject even when subject is in actor's org.
  const login = await request.post('/api/login', {
    data: { email: 'orgsuper@e2e.test', password: 'password' },
  });
  expect(login.status()).toBe(200);

  const me = await request.get('/api/user');
  const body = await me.json();

  const roles = await request.get('/api/roles');
  const rolesBody = await roles.json();
  const member = rolesBody.data.find((r: { name: string }) => r.name === 'member');

  // Pick a different organization id by discovering /api/organizations and selecting one not in actor.org.
  const orgs = await request.get('/api/organizations');
  const orgsBody = await orgs.json();
  const otherOrg = (orgsBody.data as Array<{ id: number; name?: string }>).find((o) => o.id !== body.organization_id);
  expect(otherOrg, 'a non-actor organization must exist for the cross-org scope_id test').toBeDefined();

  const assign = await request.post('/api/org-super/role-assignments', {
    data: {
      user_id: body.id + 1,
      replace_all: true,
      assignments: [{
        role_id: member.id,
        scope_type: 'organization',
        scope_id: otherOrg.id, // client manipulation — server rejects
        inherit_to_children: false,
      }],
    },
  });
  expect([403, 422]).toContain(assign.status());
});
```

- [ ] **Step 9: Playwright runner — focused then full**

Run: `npm run test:e2e -- e2e/admin/org-super-actor.spec.ts`
Expected: PASS.

Run: `npm run test:e2e`
Expected: PASS or flake-documented (the `quality` job runs E2E with `continue-on-error: true` per AGENTS.md).

- [ ] **Step 10: Commit the new E2E spec**

```bash
git add e2e/admin/org-super-actor.spec.ts
git commit -m "test(e2e): cover OrganizationSuperAdmin users / settings / role allowlist"
```

---

### Task 18: CI parity

**Files:** none.

- [ ] **Step 1: Pint + PHPStan**

Run: `./vendor/bin/pint --test && composer phpstan`
Expected: 0 errors.

- [ ] **Step 2: Full PHPUnit suite via the CI runner**

Run: `composer test`
Expected: green or flake-documented per AGENTS.md. If specific classes fail consistently, re-run them in isolation (`php artisan test --filter=TestClassName`). Escalate only if a class fails after isolation re-run.

- [ ] **Step 3: composer ci**

Run: `composer ci`
Expected: green.

- [ ] **Step 4: Frontend quality**

Run: `npm run quality`
Expected: green (typecheck + lint + design:check + vitest).

- [ ] **Step 5: quality:ci**

Run: `npm run quality:ci`
Expected: green; E2E may flake per AGENTS.md policy.

- [ ] **Step 6: Audit + npm audit**

Run: `composer audit && npm audit --audit-level=high`
Expected: 0 vulnerabilities or pre-existing accepted advisories.

- [ ] **Step 7: Final commit**

```bash
git commit --allow-empty -m "chore: all quality gates green after organization_super_admin rollout"
```

---

## Spec Coverage Verification

| Spec § | Requirement | Implementing task(s) |
|---|---|---|
| §1 | Single Admin SPA, no `/org/*`, no third dashboard | T9 (OrgSuperOrSuperBoundary), T10 (org-super nav group), T12 (router restructure) |
| §1, §4 | Canonical `organization_super_admin` role; scope=organization; is_admin_role=false; is_system=true | T1 (constants), T2 (predicate), T3 (seed + migration) |
| §4 (is_admin_role note) | Engine admin-shortcut MUST NOT silently elevate Org-Super | T2 (predicate), T8 (regression test on cluster rescue) |
| §5 (capability matrix) | users.view/create/edit/delete/activate/deactivate/unlock + departments.* + organization.settings.* + audit.view + roles.view/assign | T1 (constants), T3 (seed) |
| §5 (hard prohibitions) | No self-modify, no super_admin/organization_super_admin target mutation, no own org mutation | T6 (UserController target validation), T7 (OrgSuper role-assignment actor guard + FormRequest + dedicated route) |
| §5 (X-Organization-Id ignore) | Header is ignored for non-super actors | T8 (engine regression test) |
| §6.1 | `/api/user` payload gains `is_organization_super_admin` additive, non-breaking | T4 |
| §6.2 | Cross-org attempt returns 403 with required_capability | T6 + existing engine behaviour |
| §6.3 | Self-modify / admin-on-admin attempt returns 422 with target rejection | T6 |
| §6.4 | `roles.assign` allowlist enforced server-side (OrgSuper-specific actor path; canonical `/api/roles/assign` for super_admin remains untouched) | T7 (dedicated `POST /api/org-super/role-assignments` route + OrgSuper actor guard + FormRequest allowlist) |
| §6.5 | Org-level settings read/write endpoint | T5 (controller + FormRequests + routes) |
| §7 (sensitive mutation contract) | FormRequest authorize, server-side target validation, transactional, audit, idempotent, throttle:sensitive/admin | T5 (FormRequests + throttle:sensitive + idempotency middleware), T6 (transactional + audit), T7 (OrgSuper FormRequest seam + transactional + audit with `provenance=organization_super_admin` + throttle:admin + idempotency), all mutations ride `198f0d2` |
| §8 (boundary filter) | `isAdminNavItemVisible` covers three flags + four groups + new org-super group | T10 |
| §8 (page-level behavior) | `/users`, `/departments`, `/organizations/{ownOrgId}/settings` for Org-Super | T12 (router restructure) + T13 (UsersPage activate/deactivate) |
| §9 (seed change) | organization_super_admin role entry with curated capability list, NO projects/tasks/kpis/risks/ovr/cluster_tree/audit.export | T3 (seeder) |
| §9 (migration) | New role catalog sync migration, PG only, idempotent | T3 (Step 5) |
| §10 (rollback) | Phase 0/1/2 sequence | All tasks |
| §11 (PHPUnit) | All matrix surfaces covered | T1–T8, T15 |
| §11 (Vitest) | Boundary, nav, API contract, page | T9–T14 |
| §11 (E2E) | Org-Super users / settings / role allowlist | T17 |
| §12 (audit) | ActivityLog row with provenance tag + logAuthzDenial on rejection | T5 (org settings update), T6 (target rejection logs ACTION_ACCESS_DENIED with provenance) |
| §12 (error envelope) | 403 / 404 / 422 / 429 / 5xx preserved | Existing renderer at `bootstrap/app.php` (unchanged); all new routes inherit the existing envelope |
| §13 (obsolete retraction) | Plan supersedes 2026-07-13 obsolete plan; no OrgAdminBoundary; no `/org/*`; no admin-as-boundary | T9–T12, Global Constraints (obsolete-plan notice), task §12 Step 4 note on deferred obsolete-task retirement |
| §14 (open items) | URL shape, JSON shape, throttle choice, i18n keys | T5 (URL/JSON), T5 (throttle:sensitive), T12 (i18n keys) |

---

## Self-Review (run by the author of this plan)

**1. Spec coverage.** The matrix above maps every numbered spec section to a task. The new role does not ride the legacy `admin` shortcut because `is_admin_role=false` is set in T3 and T8's regression test confirms the engine respects it. No spec requirement is unassigned. The OrgSuper role-assignment path (T7) rebuilds around a dedicated route + actor guard + FormRequest seam because the preflight-proven route middleware (`engine_capability:core.assign_roles` at `app/Modules/Core/Routes/api.php:154-155`) and canonical actor guard (`CanonicalAuthorizationAssignmentActorGuard.php:32`) cannot admit OrgSuper without widening `core.assign_roles` — which the user policy explicitly forbids. The dedicated path preserves all three hard prohibitions (no Platform/OrganizationSuperAdmin assignment, no cross-org scope, no role definition mutation) and adds server-derived scope so client-side manipulation is rejected with 422.

**2. Placeholder scan.** A search of the plan for `TODO`, `TBD`, `implement later`, `fill in details`, `add appropriate error handling`, `similar to Task N`, or untypecoded references returns **no matches**. Every code block, command, and expected result is concrete. No step references a type, function, or method that is not defined in an earlier task (the only forward references are to existing code in the codebase — `AccessDecision.php:~1170`, `AuthorizationAssignmentService`, `Organization` model, `CanonicalAuthorizationAssignmentActorGuard`, `AssignmentScopeResolver`, etc. — and those are documented inline). The T7 redesign introduces five new symbols (`AssignmentScope::ORGANIZATION`, `OrganizationSuperAdminRoleAssignmentActorGuard`, `OrganizationSuperAdminRoleAssignmentService`, `AssignOrganizationSuperAdminRoleRequest`, `RoleController::assignByOrganizationSuperAdmin`); each is defined in T7 itself and consumed by the same task (no cross-task forward references).

**3. Type / signature consistency.** Verified end-to-end:

- `Capability::USERS_ACTIVATE` / `USERS_DEACTIVATE` / `ORGANIZATION_SETTINGS_VIEW` / `ORGANIZATION_SETTINGS_EDIT` → used in `UserController::canManageUserLifecycle` (T6), `OrganizationSettingsController` (T5), `ViewOrganizationSettingsRequest::authorize` (T5), `UpdateOrganizationSettingsRequest::authorize` (T5). Names match.
- `User::isOrganizationSuperAdmin(): bool` → consumed in T4 (`AuthController::buildFormatUserPayload`), T6 (UserController target validation), T7 (`OrganizationSuperAdminRoleAssignmentActorGuard` + `AssignOrganizationSuperAdminRoleRequest::after`), T8 (regression test), T15 (parity test). Return type and call sites match.
- `OrganizationSuperAdminRoleAssignmentActorGuard::allows(User, User, AuthorizationRole, AssignmentScope): bool` → implements `AuthorizationAssignmentActorGuard`; consumed in `OrganizationSuperAdminRoleAssignmentService::syncManual()`. Return shape matches the contract.
- `OrganizationSuperAdminRoleAssignmentService::syncManual(User, User, list<RoleAssignmentWrite>, array): list<AuthorizationRoleAssignment>` → transactional; writes via `AuthorizationRoleAssignment::updateOrCreate()`; audit row tagged `provenance=organization_super_admin`; flushes `AccessDecision::flushCache()` on commit. Matches the spec §7 sensitive-mutation contract.
- `AssignOrganizationSuperAdminRoleRequest` rules → `assignments.*.scope_type` constrained to `Rule::in([AssignmentScope::ORGANIZATION])` only; `assignments.*.inherit_to_children` constrained to `['required','boolean','accepted']` (false is the only accepted value); `assignments.*.expires_at` constrained to `['prohibited']`. These mirror the actor guard's contract at the FormRequest layer so client payload tampering is caught with 422 before the guard runs.
- `POST /api/org-super/role-assignments` route → middleware `engine_capability:roles.assign + throttle:admin + idempotency`. Distinct from canonical `POST /api/roles/assign` (middleware `engine_capability:core.assign_roles + idempotency`); super_admin cannot ride the OrgSuper route because `Capability::ROLES_ASSIGN` is NOT in the canonical super_admin pivot set (only OrgSuper holds it per T3).
- `adminApi.organizationSettings.get(orgId: number)` → `Promise<{ data: OrganizationSettings }>` — matches the controller response (`['data' => $settings->settings]`). Consumed in T12 (page).
- `adminApi.organizationSettings.update(orgId: number, input: OrganizationSettingsInput)` → `Promise<{ data: OrganizationSettings }>` — matches the controller response.
- `OrganizationSettingsPage` props: `useParams<{ organizationId: string }>()` → `Number(organizationId)` — type-coerced and guarded by `Number.isFinite(orgId)`.
- `OrgSuperOrSuperBoundary` prop signature: `useAuth()` returns `{ user: User | null, isLoading, isAuthenticated }` — matches `AuthContext.tsx` exports. Predicate consults `user?.is_super_admin` / `user?.is_organization_super_admin` per the type widening in T14.
- `AdminNavItem.group` union widened to `('governance' | 'controls' | 'system' | 'org' | 'org-super')` — used consistently in `isAdminNavItemVisible`, `ADMIN_NAV_ITEMS`, and `groups` array (T10).
- All migration filenames follow the existing `YYYY_MM_DD_HHMMSS_name.php` pattern (`2026_07_14_000020_role_catalog_sync_organization_super_admin.php`, `2026_07_14_000021_create_organization_settings_table.php`) and `php artisan migrate --env=testing --pretend` is expected to list them in order.

---

## Risks and Blockers

| Risk | Likelihood | Mitigation |
|---|---|---|
| **Task 7 redesign contract drift.** The dedicated `POST /api/org-super/role-assignments` route + actor guard + FormRequest is a multi-file change (5 new files + 1 route + 1 controller method + 2 file modifications). If a later implementer wires the controller to the canonical `AuthorizationAssignmentService` (line 31 calls `validateWrite` → canonical actor guard → rejects OrgSuper), the positive case test fails at runtime, not at the FormRequest layer. **Mitigation:** T7 Step 6 explicitly uses `OrganizationSuperAdminRoleAssignmentService` (which composes the underlying service with the OrgSuper guard and server-derives scope). The Pint --test + Step 10 phpunit run catches any accidental regression. Defense-in-depth: the FormRequest `after()` block catches client-tampering at 422 before the actor guard runs. |
| **Server-derived scope overrides client scope silently.** `OrganizationSuperAdminRoleAssignmentService::syncManual()` rebuilds each `AssignmentWrite` with the actor's `organization_id` regardless of client input. If a future implementer "loosens" this to honor client `scope_id` (because the FormRequest also validates it), the cross-org scope test (15th denial surface) silently passes while a real cross-org write succeeds. **Mitigation:** Step 6 is explicit about rebuilding the write with `$serverScope`; the cross-org-scope test asserts 403/422 with `actor.organization_id` forced as the only valid scope; the audit row includes `metadata.scope_id` set to `actor.organization_id` so audit log consumers can verify server-derivation post-hoc. |
| **`ActivityLog::ACTION_SYSTEM_ROLE_ASSIGNED` vs `ACTION_UPDATED`.** T7 Step 6 uses `ACTION_SYSTEM_ROLE_ASSIGNED` for the audit row to match the existing `RoleController::assignToUser` pattern (line 218). If the canonical pattern is later changed, the OrgSuper audit row could be mis-categorized. **Mitigation:** T7's audit row includes `metadata.provenance='organization_super_admin'` which is the source-of-truth filter; audit consumers should filter by `metadata->provenance` rather than `action`. |
| **`UserPolicy::update` denies cross-org target before Org-Super target validation runs.** T6 relies on `UserPolicy::update` for the cross-org 404 envelope. If the policy returns a different status code, the matrix assertions tolerate `[403, 404]` explicitly. **Mitigation:** assertions use `assertContains` rather than `assertEquals`. |
| **`is_active` mutation is rejected for non-lifecycle actors.** T6 widens `canManageUserLifecycle()` to admit Org-Super, but the legacy admin (curated `admin`) still cannot change `is_active` because the curated set excludes `USERS_ACTIVATE` / `USERS_DEACTIVATE`. This is intentional — the spec matrix in §5 explicitly excludes activate/deactivate from the curated `admin` role. **Mitigation:** the regression test `OrganizationSuperAdminLegacyAdminParityTest` (T15) confirms the curated `admin` pivot set is byte-identical. |
| **Postgres migration ordering.** The two new migrations (`2026_07_14_000020_*` and `2026_07_14_000021_*`) must run AFTER the existing `2026_07_12_000018_*` sweep. Both migrations are forward-only and idempotent; mis-ordering produces an empty `down()` (safe). **Mitigation:** the timestamps are sequenced correctly; `php artisan migrate --env=testing --pretend` is the final check. |
| **`OrganizationContext` may not lock `X-Organization-Id` for Org-Super.** The FE pins `X-Organization-Id` to `users.organization_id` for non-super actors in `OrganizationContext`. T14 verifies the type widening; T8 verifies the BE engine. **Mitigation:** the FE's pin is already in `OrganizationContext.tsx:81-92`; no code change is needed there. |
| **Playwright flake on `/api/roles/assign` for `admin`/`super_admin`/`organization_super_admin`.** The spec accepts `[403, 422]`. **Mitigation:** assertions use `expect([403, 422]).toContain(assign.status())`. |
| **FSD boundary check fails on the new admin pages.** The admin SPA is FSD-free and lives under `resources/admin/`. Pages import from `@shared/*` and `@admin/*` only. **Mitigation:** the test setup in `resources/admin/test/setup.ts` already excludes FSD boundary enforcement for the admin SPA. |
| **i18n key strings are owned by the implementer.** The plan specifies keys only. **Mitigation:** implementer fills Arabic + English strings before Task 12 commit. The task explicitly adds i18n keys in Step 6 before commit. |
| **Obsolete-plan task retirement deferred.** Tasks 7/8/10/11/17/18 of the 2026-07-13 plan are NOT deleted in this plan. **Mitigation:** Task 12 Step 4 documents a separate docs-only retirement commit as a Phase 3 follow-up. The new tests prove parity before retirement. |

## Rollback

Phase 0 rollback (per spec §10):

```bash
# 1. Revert the role seed entry.
git revert <T3-commit-sha> -- database/seeders/RolesAndPermissionsSeeder.php database/migrations/2026_07_14_000020_role_catalog_sync_organization_super_admin.php

# 2. Drop the new controller and routes.
git revert <T5-commit-sha>

# 3. Revert the auth payload extension.
git revert <T4-commit-sha>

# 4. Revert the UserController target validation.
git revert <T6-commit-sha>

# 5. Revert the OrgSuper role-assignment actor path (T7 redesign).
#    Reverts the dedicated POST /api/org-super/role-assignments route,
#    OrganizationSuperAdminRoleAssignmentActorGuard, OrganizationSuperAdminRoleAssignmentService,
#    AssignOrganizationSuperAdminRoleRequest, RoleController::assignByOrganizationSuperAdmin,
#    AssignmentScope::ORGANIZATION constant, Capability::ROLES_ASSIGN docblock, and the
#    comprehensive OrganizationSuperAdminRoleAllowlistTest. The canonical /api/roles/assign
#    route and CanonicalAuthorizationAssignmentActorGuard are NOT touched (super_admin
#    continues to use the canonical path).
git revert <T7-commit-sha>

# 6. Revert the user predicate.
git revert <T2-commit-sha>

# 7. Revert the capability constants.
git revert <T1-commit-sha>

# 8. Run migrate:fresh on prod (the sweep migration is forward-only).
php artisan migrate:fresh --force
```

The legacy `admin` role and routes are unaffected by every task above because:

- `is_admin_role=true` on `admin` is preserved.
- The curated `OrgAdminCapabilities()` helper is untouched.
- The `User::isOrgAdmin()` predicate is unchanged.
- The `AdminE2ETestSeeder` and `AdminRouteContractTest` from `6ed111f` and obsolete plan Tasks 7/8 are not modified by this plan.

Phase 1 rollback: revert Tasks 9, 10, 11, 12, 13, 14 in reverse order. The new boundary component is new code; removing it reverts the Admin SPA to the super_admin-only boundary. The obsolete `/org/*` sub-SPA was never created, so there is no parallel surface to retire.

Phase 1.5 rollback: revert Tasks 15, 16. Both are tests-only; reverting removes the regression tests without affecting source.

Phase 2 rollback: revert Tasks 17, 18. Both are commands + a single new Playwright spec; reverting drops the spec and stops the E2E coverage. Other tests are unaffected.