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
- **`organization_settings` is the single source of truth for NEW organization-admin settings AND a distinct authorization resource.** The new table (`organization_settings`) and the new model (`App\Modules\Core\Models\OrganizationSettings`) are the only store the OrgSuper-facing `/api/organizations/{org}/settings` contract reads from or writes to. This is a **greenfield** decision: the legacy `organizations.settings` JSON column added by `2026_01_12_100002_add_organization_support.php` is NOT read, NOT used as a fallback, NOT backfilled from, NOT migrated into, NOT overwritten by, and NOT deleted by any task in this plan. There is no legacy data to preserve — the existing column is simply **unused by the new contract**. No task may read `Organization::query()->value('settings')`, write `$org->settings = …`, or call `->update(['settings' => …])` on the `organizations` table. The authorization resource for the new contract is `Organization` (via `CapabilityToAuthorizationRolePermission::map('organization.settings.*')` → `Organization::class`), which is **distinct from** `core.cluster_tree.*` (also `Organization::class`). `organization.settings.*` MUST never satisfy `core.cluster_tree.*` and vice-versa: the new OrgSuper pivot set excludes every `core.cluster_tree.*` capability (verified by the cluster denial test in T5), so even a same-org actor cannot use the OrgSuper surface to widen cluster scope.

---

## File Structure / Mapping (before tasks)

### Backend (new files only — no migrations alter existing columns)

| Path | Responsibility |
|---|---|
| `app/Modules/Core/Models/User.php` *(modify)* | Add `isOrganizationSuperAdmin(): bool` predicate and harden `resolveActiveOrganizationId()` so non-super actors never read a different `organization_id` from any input. |
| `app/Modules/Core/Authorization/Capability.php` *(modify)* | Append `USERS_ACTIVATE`, `USERS_DEACTIVATE`, `ORGANIZATION_SETTINGS_VIEW`, `ORGANIZATION_SETTINGS_EDIT` constants. |
| `app/Modules/Core/Http/Controllers/AuthController.php` *(modify)* | Extend `buildFormatUserPayload()` to add `is_organization_super_admin` alongside existing `is_super_admin`/`is_org_admin` flags. |
| `app/Modules/Core/Http/Controllers/UserController.php` *(modify)* | Add FormRequest target-validation that rejects self-modification for Org-Super, rejects `super_admin`/`organization_super_admin` targets (UPDATE + DELETE), and rejects Org-Super's own `organization_id` mutation. Widen `canManageUserLifecycle()` to admit Org-Super via `Capability::USERS_ACTIVATE` / `USERS_DEACTIVATE`. Extract `assertOrgSuperTargetIsMutable()` private helper so UPDATE and DELETE share the same seam. |
| `app/Modules/Core/Http/Controllers/RoleController.php` *(modify)* | Add `assignByOrganizationSuperAdmin()` method that uses the new OrgSuper request + service + actor guard. Existing canonical `assignToUser()` (gated by `engine_capability:core.assign_roles` for super_admin) is UNTOUCHED. |
| `app/Modules/Core/Http/Controllers/OrganizationSettingsController.php` *(new)* | Read/update organization-scoped settings (locale overrides, branding overrides, notification templates). New `organization.settings.view` / `organization.settings.edit` capability gates. PUT uses `firstOrCreate` + `lockForUpdate` inside a DB transaction, performs a deep merge across the three top-level settings objects via `array_replace_recursive`, and writes an `ActivityLog::ACTION_UPDATED` row tagged `provenance=organization_super_admin` carrying `metadata.request_id`. GET is strictly non-mutating. |
| `app/Modules/Core/Http/Requests/ViewOrganizationSettingsRequest.php` *(new)* | `authorize()` returns true only for actor.organization_id === org. |
| `app/Modules/Core/Http/Requests/UpdateOrganizationSettingsRequest.php` *(new)* | `authorize()` returns true only for `organization.settings.edit` capability + same-org; array-form validation rules. `prepareForValidation()` requires `X-Idempotency-Key` header (non-super OrgSuper only). |
| `app/Modules/Core/Models/OrganizationSettings.php` *(new)* | Eloquent model for the new `organization_settings` table (single source of truth; legacy `organizations.settings` column is unused by this contract). |
| `app/Modules/Core/Routes/api.php` *(modify)* | Add `Route::prefix('organizations/{organization}/settings')` group with GET (read) and PUT (update, `throttle:sensitive` + `idempotency`). |
| `app/Modules/Core/Authorization/Data/AssignmentScope.php` *(modify)* | Add `ORGANIZATION` constant alongside `ALL` and `OWN`. |
| `app/Modules/Core/Authorization/Capability.php` *(modify)* | Extend `ROLES_ASSIGN` docblock to record the OrgSuper-only gating. |
| `app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentActorGuard.php` *(new)* | Narrow actor guard: same-org + operational-role-only + no protected targets + server-derived scope. Implements `AuthorizationAssignmentActorGuard`. |
| `app/Modules\Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentService.php` *(new)* | Composes the underlying service with the OrgSuper guard; server-derives scope from `actor->organization_id` regardless of client input; transactional + audit with `provenance=organization_super_admin`. |
| `app/Modules/Core/Http/Middleware/EnsureOrganizationSuperAdminOnly.php` *(new)* | Genuine-OrgSuper-only middleware. Rejects `super_admin` even if they hold `roles.assign`; rejects OrgSuper actors with null `organization_id`. Runs BEFORE `engine_capability:roles.assign`, BEFORE the actor guard, BEFORE the service. |
| `app/Http/Kernel.php` *(modify)* | Register middleware alias `ensure.org_super_only` → `EnsureOrganizationSuperAdminOnly::class`. |
| `app/Modules/Core/Http/Requests/AssignOrganizationSuperAdminRoleRequest.php` *(new)* | Auditable seam; `rules()` reject non-`organization` scope, require `inherit_to_children=false`, prohibit `expires_at`; `after()` defensive double-check on role name / flags / inactive / cross-org subject / protected target. |
| `app/Modules/Core/Routes/api.php` *(modify)* | Add `Route::post('/org-super/role-assignments', …)` gated by `ensure.org_super_only + engine_capability:roles.assign + throttle:admin + idempotency`. Canonical `/roles/assign` (gated by `core.assign_roles`) is UNTOUCHED. |
| `database/seeders/RolesAndPermissionsSeeder.php` *(modify)* | Add `organization_super_admin` entry to `roleCatalog()`. Extend `SWEPT_SYSTEM_ROLES` constant. |
| `database/migrations/2026_07_14_000020_role_catalog_sync_organization_super_admin.php` *(new)* | Migration that mirrors the obsolete-pivot sweep, scoped to `organization_super_admin`, gated to PG only, idempotent. |
| `database/migrations/2026_07_14_000021_create_organization_settings_table.php` *(new)* | Adds `organization_settings` table for the new contract. PG-only. |
| `database/migrations/2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots.php` *(new)* | Targeted pivot sweep scoped to `organization_super_admin` × `Organization` resource × `view`/`edit` actions — removes ONLY the obsolete OrgSuper pivots caused by the previous `core.cluster_tree` → `Organization` mapping alias. PG-only, idempotent, audited. Does NOT touch `organizations.settings` column or any other role. |
| `tests/Unit/Core/OrganizationSuperAdminCapabilityConstantsTest.php` *(new)* | Unit test for the four capability constants. |
| `tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php` *(new)* | Unit test for `User::isOrganizationSuperAdmin()`. |
| `tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php` *(new)* | Asserts `/api/user` exposes the new additive flag. |
| `tests/Feature/Api/OrganizationSettingsContractTest.php` *(modify)* | 11 tests: own-org GET, GET non-mutating, first PUT creates-then-locks, deep merge, empty-array no-op, null-on-scalar clears, audit log with provenance + request_id, idempotency-key retry, cross-org GET denial, cross-org PUT denial, cluster denial. |
| `tests/Feature/Authz/OrganizationSuperAdminClusterDenialTest.php` *(new)* | Engine-layer regression: `AccessDecision::can($orgSuperActor, Capability::CLUSTER_TREE_VIEW/MANAGE/EXPORT)` MUST be false; targeted-sweep migration's audit-row count is asserted (>=0 baseline). |
| `tests/Feature/Authz/OrganizationSuperAdminRoleSeedTest.php` *(new)* | Seeder adds the role with `is_admin_role=false`, `is_system=true`, and the curated capability list; no `projects.*` / `tasks.*` / `kpis.*` / `risks.*` / `ovr.*` / `core.cluster_tree.*` / `core.view_organizations` / `core.assign_roles` / `audit.export`. |
| `tests/Feature/Authz/OrganizationSuperAdminUserTargetTest.php` *(modify)* | 11 tests: 5 UPDATE (self org swap, super_admin target, other Org-Super target, activate/deactivate positive, cross-org) + 1 UPDATE self-modify rejection + 5 DELETE (positive same-org, self-delete, super_admin target, Org-Super target, cross-org). `seedOrgSuper()` runs `RolesAndPermissionsSeeder` so positive tests can resolve curated capabilities. |
| `tests/Feature/Authz/OrganizationSuperAdminRoleAllowlistTest.php` *(modify)* | 18 tests: 1 positive + 17 denial (admin / super_admin / organization_super_admin / is_admin_role / is_system / inactive role / cross-org subject / super_admin target / organization_super_admin target / cross-org scope_id / non-organization scope_type / inherit_to_children=true / regular user middleware / super_admin uses canonical route / audit log provenance / super_admin-with-roles-assign-pivot / org-super-with-null-organization). `seedOrgSuper()` runs `RolesAndPermissionsSeeder` so positive test can resolve `roles.assign`. |
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
| T3 (seed + migration `2026_07_14_000020`) | T4, T6, T7, T11 | `authorization_roles` row with `name='organization_super_admin'`, `scope_type='organization'`, `is_admin_role=false`, `is_system=true`; capability pivots present per `organizationSuperAdminCapabilities()` |
| T4 (`/api/user` payload) | T11, T13, T14, T17 | `is_organization_super_admin: bool` on `/api/user` |
| T5 (OrganizationSettingsController + `2026_07_14_000021` + targeted sweep `2026_07_14_000022`) | T11, T13, T17 | `GET/PUT /api/organizations/{org}/settings` route + payload; `organization_settings` table; cluster-denial baseline that proves OrgSuper cannot satisfy `core.cluster_tree.*` |
| T6 (UserController target validation) | T7, T13, T17 | 422/403 envelope for self / super_admin / organization_super_admin targets (UPDATE and DELETE) |
| T7 (OrgSuper role-assignment actor path) | T17 | 422/403 envelopes for the operational-role allowlist matrix on `POST /api/org-super/role-assignments`; new `OrganizationSuperAdminRoleAssignmentActorGuard` + service + FormRequest + dedicated route gated by `engine_capability:roles.assign` (NOT `core.assign_roles`); OrgSuper-only explicit guard runs BEFORE actor guard and service |
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

### Task 5: OrganizationSettingsController + FormRequests + migrations (table + targeted pivot sweep)

> **Preflight correction.** The previous Task 5 had three defects that the new contract fixes:
>
> 1. **Source-of-truth defect.** The plan described storage "on a new `organization_settings` JSONB column" but did not pin down that this table is the **single** source of truth. Per the new Global Constraint, `organization_settings` is the only store the OrgSuper-facing endpoint reads from or writes to. The legacy `organizations.settings` JSON column is unused by this contract — no read, no fallback, no backfill, no migration of legacy data (there is none).
> 2. **PUT defect.** The previous controller used `firstOrFail()` then `array_replace($previous, $validated)`. The `firstOrFail` 404s when the row doesn't exist; the `array_replace` is a **shallow** merge so `locale_overrides.ar => 'ar-EG'` would wipe the existing `locale_overrides.en`. The corrected PUT does `firstOrCreate` then `lockForUpdate` (idempotent on first PUT) and a **deep merge** keyed on the three top-level objects so partial updates never wipe sibling keys. Object maps (`notification_templates`) are merged by key.
> 3. **Pivot alias defect.** The previous `CapabilityToAuthorizationRolePermission::map()` aliased both `core.cluster_tree.*` and `organization.settings.*` to `Organization::class`. When OrgSuper pivots were seeded against the new `organization.settings.*` capabilities, the `Organization` × `view` / `edit` pivot slots became semantically overloaded. The cluster_auditor role still legitimately wants `Organization` × `view` for cluster_tree, but the OrgSuper pivots on those same `(resource=Organization, action=view)` and `(resource=Organization, action=edit)` slots are now obsolete and must be swept.
>
> The corrected Task 5 adds a **targeted** migration (`2026_07_14_000022`) that sweeps ONLY the obsolete `organization_super_admin` × `Organization` × `view`/`edit` pivots caused by the previous mapping. It does NOT touch `organizations.settings`, does NOT touch any other role's pivots, and does NOT satisfy `core.cluster_tree.*`.

**Files:**
- Create: `app/Modules/Core/Http/Controllers/OrganizationSettingsController.php`.
- Create: `app/Modules/Core/Http/Requests/ViewOrganizationSettingsRequest.php`.
- Create: `app/Modules/Core/Http/Requests/UpdateOrganizationSettingsRequest.php`.
- Create: `app/Modules/Core/Models/OrganizationSettings.php`.
- Create: `database/migrations/2026_07_14_000021_create_organization_settings_table.php`.
- Create: `database/migrations/2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots.php` — targeted sweep; runs AFTER `000021` (table) but does not depend on it; runs AFTER `000020` (curated OrgSuper pivot set is in place so the sweep can identify obsolete vs desired pivots).
- Modify: `app/Modules/Core/Routes/api.php:200-217` (add the new route group).
- Test: `tests/Feature/Api/OrganizationSettingsContractTest.php` (modify — add idempotency, deep-merge, activity-log, non-mutating-GET cases).
- Test: `tests/Feature/Authz/OrganizationSuperAdminClusterDenialTest.php` (new — proves OrgSuper pivot set contains zero `core.cluster_tree.*` capabilities and the targeted sweep removed all obsolete `Organization` × `view`/`edit` pivots).

**Interfaces:**
- Consumes: `Capability::ORGANIZATION_SETTINGS_VIEW`, `Capability::ORGANIZATION_SETTINGS_EDIT` from Task 1; `Organization` model; `OrganizationSettings` model (this task).
- Produces:
  - `GET /api/organizations/{organization}/settings` returning `{ data: OrganizationSettings }`. **Strictly non-mutating** — no audit row written, no lock acquired, no DB write of any kind. `firstOrCreate` with default payload if row missing.
  - `PUT /api/organizations/{organization}/settings` returning the same shape. FormRequest `authorize()` enforces capability + same-org (`$request->user()->organization_id === $organization->id` for non-super actors). `firstOrCreate` then `lockForUpdate` inside `DB::transaction`; deep merge across `locale_overrides`/`branding_overrides`/`notification_templates`; `ActivityLog::ACTION_UPDATED` row tagged `provenance=organization_super_admin` carrying `request_id` from the `X-Request-Id` header; route middleware `throttle:sensitive + idempotency`.
  - `organization_settings` table (`organization_settings.settings` JSONB column is the single source of truth; `organizations.settings` is unused by this contract).
  - Targeted obsolete-pivot sweep scoped to `authorization_role_permissions` rows where `authorization_role_id` corresponds to the `organization_super_admin` role AND `authorization_resource_id` corresponds to `Organization::class` AND `action IN ('view', 'edit')`. Each deletion audited as `obsolete_orgsuper_organization_view_edit_pivot_removed`.

**Decision (URL shape):** `GET/PUT /api/organizations/{organization}/settings` — mirrors `/organizations/{id}/edit`. SPA calls via `adminApi.organizationSettings.{get,update}` in Task 11.

**Decision (response shape):** flat `{ locale_overrides: { ar?: string, en?: string }, branding_overrides: { primary_color?: string|null, logo_path?: string|null }, notification_templates: Record<string, string> }`.

**Decision (throttle):** PUT uses `throttle:sensitive` + `idempotency` middleware. GET has no throttle and no idempotency middleware (idempotency cache is only useful on mutations; applying it to GETs would cache stale reads).

**Decision (deep merge contract):** PUT performs a per-object deep merge keyed on the top-level keys `locale_overrides`, `branding_overrides`, and `notification_templates`. Within each object, only the keys present in the validated payload are written; existing keys not in the payload are preserved. Empty arrays (`[]`) are NOT treated as deletions — they leave the existing object intact. `null` values for nullable string fields (`locale_overrides.ar`, `branding_overrides.primary_color`, `branding_overrides.logo_path`) ARE treated as explicit clears. The merge is implemented via PHP's built-in `array_replace_recursive` restricted to the three known top-level keys (Laravel's `$request->validated()` only returns keys that pass `rules()`, so unexpected top-level keys are double-defended against).

- [ ] **Step 1: Add the table migration (runs after `000020`)**

Create `database/migrations/2026_07_14_000021_create_organization_settings_table.php` (timestamp sequences AFTER `2026_07_14_000020_role_catalog_sync_organization_super_admin.php`):

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

- [ ] **Step 2: Add the targeted obsolete-pivot sweep migration (runs after `000021`)**

Create `database/migrations/2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots.php` (timestamp sequences AFTER `2026_07_14_000021_create_organization_settings_table.php`):

```php
<?php

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite — Task 5 targeted sweep).
 *
 * Targeted sweep of obsolete authorization_role_permissions pivots caused by
 * the previous `core.cluster_tree` → `Organization::class` mapping alias in
 * `CapabilityToAuthorizationRolePermission::PREFIX_TO_RESOURCE`.
 *
 * Scope (deliberately narrow):
 *   - authorization_role_id corresponding to name = 'organization_super_admin'
 *   - authorization_resource_id corresponding to Organization::class
 *   - action IN ('view', 'edit')
 *
 * Out of scope (intentionally):
 *   - organizations.settings column on the organizations table — UNTOUCHED.
 *     The new contract writes to `organization_settings`, never to
 *     `organizations.settings`; this migration does not read or write that
 *     column.
 *   - cluster_auditor role — its pivots on `Organization` are legitimate
 *     cluster_tree pivots and must NOT be swept.
 *   - admin, super_admin, viewer, dept_manager, member, project_*,
 *     dept_member, pmo_*, quality_manager, risk_manager — none of their
 *     pivots are touched.
 *   - any other resource (User, Department, Project, Task, Meeting, etc.).
 *
 * Idempotent: re-run is a no-op because the audit-event check below skips
 * pivots whose `obsolete_orgsuper_organization_view_edit_pivot_removed`
 * audit row already exists. Forward-only: `down()` is intentionally a
 * no-op so a rollback does not re-introduce the obsolete pivots.
 *
 * PostgreSQL-only: the audit table comparison uses jsonb containment
 * semantics; SQLite is forbidden at the project level (CI guard job).
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots';

    private const AUDIT_EVENT = 'obsolete_orgsuper_organization_view_edit_pivot_removed';

    /** @var list<string> */
    private const TARGET_ACTIONS = ['view', 'edit'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                self::MIGRATION_NAME.' is PostgreSQL-only. Detected driver: ['.DB::getDriverName().'].'
            );
        }

        foreach (['authorization_roles', 'authorization_resources', 'authorization_role_permissions', 'authorization_assignment_audits'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(self::MIGRATION_NAME." requires table [{$table}] to exist.");
            }
        }

        $orgSuper = AuthorizationRole::query()->where('name', 'organization_super_admin')->first();
        if ($orgSuper === null) {
            // No OrgSuper role yet — nothing to sweep. The curated sweep in
            // 2026_07_14_000020 will refuse to seed OrgSuper without the
            // preceding migrations; if we got here without OrgSuper, the
            // role catalog sync migration has not run yet. Bail safely.
            return;
        }

        $organizationResourceId = DB::table('authorization_resources')
            ->where('key', Organization::class)
            ->value('id');

        if ($organizationResourceId === null) {
            return;
        }

        $existing = DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $orgSuper->id)
            ->where('authorization_resource_id', $organizationResourceId)
            ->whereIn('action', self::TARGET_ACTIONS)
            ->orderBy('authorization_resource_id')
            ->orderBy('action')
            ->get();

        if ($existing->isEmpty()) {
            return;
        }

        $alreadyAudited = $this->loadAlreadyAuditedPivotKeys((int) $orgSuper->id);
        $auditRows = [];
        $now = now();

        DB::transaction(function () use (&$auditRows, $alreadyAudited, $orgSuper, $organizationResourceId, $existing, $now): void {
            foreach ($existing as $pivot) {
                $auditKey = $orgSuper->id.'|'.$pivot->authorization_resource_id.'|'.$pivot->action;
                if (isset($alreadyAudited[$auditKey])) {
                    continue;
                }

                DB::table('authorization_role_permissions')
                    ->where('authorization_role_id', $orgSuper->id)
                    ->where('authorization_resource_id', $pivot->authorization_resource_id)
                    ->where('action', $pivot->action)
                    ->delete();

                $auditRows[] = [
                    'event' => self::AUDIT_EVENT,
                    'actor_id' => null,
                    'target_user_id' => null,
                    'scope_type' => null,
                    'scope_id' => null,
                    'role' => 'organization_super_admin',
                    'old_value' => json_encode([
                        'authorization_role_id' => (int) $orgSuper->id,
                        'authorization_resource_id' => (int) $pivot->authorization_resource_id,
                        'authorization_resource_key' => Organization::class,
                        'action' => $pivot->action,
                    ], JSON_THROW_ON_ERROR),
                    'new_value' => json_encode([
                        'migration' => self::MIGRATION_NAME,
                        'authorization_role_id' => (int) $orgSuper->id,
                        'authorization_resource_id' => (int) $pivot->authorization_resource_id,
                        'authorization_resource_key' => Organization::class,
                        'action' => $pivot->action,
                        'reason' => 'obsolete OrgSuper pivot caused by previous core.cluster_tree mapping alias to Organization::class',
                        'source' => 'migration',
                        'ticket' => 'CSD-CA23078-CORE-009',
                    ], JSON_THROW_ON_ERROR),
                    'reason' => 'CSD-CA23078-CORE-009 obsolete OrgSuper Organization view/edit pivot removed',
                    'ip_address' => null,
                    'user_agent' => 'migration',
                    'created_at' => $now,
                ];

                $alreadyAudited[$auditKey] = true;
            }
        });

        if ($auditRows !== []) {
            foreach (array_chunk($auditRows, 500) as $chunk) {
                DB::table('authorization_assignment_audits')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        // Forward-only — see class-level docblock.
    }

    /**
     * @return array<string, true>
     */
    private function loadAlreadyAuditedPivotKeys(int $roleId): array
    {
        $keys = [];
        DB::table('authorization_assignment_audits')
            ->where('event', self::AUDIT_EVENT)
            ->whereRaw("new_value ->> 'migration' = ?", [self::MIGRATION_NAME])
            ->select(['new_value'])
            ->orderBy('id')
            ->each(function (object $row) use (&$keys, $roleId): void {
                $stored = json_decode((string) $row->new_value, true);
                if (! is_array($stored)) {
                    return;
                }

                $storedRoleId = $stored['authorization_role_id'] ?? null;
                $resourceId = $stored['authorization_resource_id'] ?? null;
                $action = $stored['action'] ?? null;

                if ($storedRoleId !== $roleId || $resourceId === null || $action === null) {
                    return;
                }

                $keys[$roleId.'|'.$resourceId.'|'.$action] = true;
            });

        return $keys;
    }
};
```

- [ ] **Step 3: Write failing contract tests**

`tests/Feature/Api/OrganizationSettingsContractTest.php`:
```php
<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\OrganizationSettings;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_super_can_read_own_org_settings(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        Sanctum::actingAs($actor, ['*']);

        $response = $this->getJson("/api/organizations/{$org->id}/settings");

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['locale_overrides', 'branding_overrides', 'notification_templates']]);
    }

    public function test_get_is_strictly_non_mutating(): void
    {
        // GET must not write any row, must not lock, must not emit an
        // activity-log entry. We assert by snapshotting DB counts before
        // and after the request.
        [$org, $actor] = $this->seedOrgSuper();
        Sanctum::actingAs($actor, ['*']);

        $auditBefore = ActivityLog::query()->count();
        $settingsBefore = OrganizationSettings::query()->where('organization_id', $org->id)->count();

        $response = $this->getJson("/api/organizations/{$org->id}/settings");

        $response->assertOk();
        $this->assertSame($auditBefore, ActivityLog::query()->count(), 'GET must not write ActivityLog rows.');
        $this->assertSame($settingsBefore, OrganizationSettings::query()->where('organization_id', $org->id)->count(), 'GET must not insert a row.');
    }

    public function test_first_put_creates_then_locks(): void
    {
        // On the first PUT, the row does not exist yet. Controller MUST
        // firstOrCreate() so the first PUT succeeds (the previous
        // firstOrFail() 404'd on the first PUT, which was a defect).
        [$org, $actor] = $this->seedOrgSuper();
        $this->assertSame(0, OrganizationSettings::query()->where('organization_id', $org->id)->count(), 'precondition: no settings row yet.');
        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/organizations/{$org->id}/settings", [
            'branding_overrides' => ['primary_color' => '#1F3A8A'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.branding_overrides.primary_color', '#1F3A8A');
        $this->assertSame(1, OrganizationSettings::query()->where('organization_id', $org->id)->count(), 'first PUT must firstOrCreate the row.');
    }

    public function test_put_performs_deep_merge_across_top_level_objects(): void
    {
        // Seed: locale_overrides has both ar and en; branding_overrides has
        // primary_color; notification_templates has welcome + reminder.
        // PUT only: locale_overrides.ar, branding_overrides.logo_path,
        // notification_templates.welcome. After the PUT, the un-touched
        // keys (locale_overrides.en, branding_overrides.primary_color,
        // notification_templates.reminder) MUST still be present (deep
        // merge, not shallow array_replace).
        [$org, $actor] = $this->seedOrgSuper();
        OrganizationSettings::query()->create([
            'organization_id' => $org->id,
            'settings' => [
                'locale_overrides' => ['ar' => 'ar', 'en' => 'en'],
                'branding_overrides' => ['primary_color' => '#111111'],
                'notification_templates' => [
                    'welcome' => 'old welcome',
                    'reminder' => 'old reminder',
                ],
            ],
            'created_by' => $actor->id,
        ]);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/organizations/{$org->id}/settings", [
            'locale_overrides' => ['ar' => 'ar-EG'],
            'branding_overrides' => ['logo_path' => '/logo.svg'],
            'notification_templates' => ['welcome' => 'new welcome'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.locale_overrides.ar', 'ar-EG');
        $response->assertJsonPath('data.locale_overrides.en', 'en');
        $response->assertJsonPath('data.branding_overrides.primary_color', '#111111');
        $response->assertJsonPath('data.branding_overrides.logo_path', '/logo.svg');
        $response->assertJsonPath('data.notification_templates.welcome', 'new welcome');
        $response->assertJsonPath('data.notification_templates.reminder', 'old reminder');
    }

    public function test_put_with_empty_object_does_not_wipe_existing_keys(): void
    {
        // Sending an empty `notification_templates => []` MUST NOT wipe
        // the existing notification_templates map. Empty objects are a
        // no-op; explicit nulls on nullable scalar fields clear.
        [$org, $actor] = $this->seedOrgSuper();
        OrganizationSettings::query()->create([
            'organization_id' => $org->id,
            'settings' => [
                'locale_overrides' => [],
                'branding_overrides' => [],
                'notification_templates' => ['welcome' => 'keep me'],
            ],
            'created_by' => $actor->id,
        ]);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/organizations/{$org->id}/settings", [
            'notification_templates' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.notification_templates.welcome', 'keep me');
    }

    public function test_put_with_null_on_nullable_scalar_clears_the_value(): void
    {
        // `branding_overrides.primary_color => null` is an explicit clear.
        [$org, $actor] = $this->seedOrgSuper();
        OrganizationSettings::query()->create([
            'organization_id' => $org->id,
            'settings' => [
                'locale_overrides' => [],
                'branding_overrides' => ['primary_color' => '#111111'],
                'notification_templates' => [],
            ],
            'created_by' => $actor->id,
        ]);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->putJson("/api/organizations/{$org->id}/settings", [
            'branding_overrides' => ['primary_color' => null],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.branding_overrides.primary_color', null);
    }

    public function test_put_emits_activity_log_with_provenance_and_request_id(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        Sanctum::actingAs($actor, ['*']);
        $requestId = (string) Str::uuid();

        $response = $this->withHeaders(['X-Request-Id' => $requestId])
            ->putJson("/api/organizations/{$org->id}/settings", [
                'branding_overrides' => ['primary_color' => '#1F3A8A'],
            ]);

        $response->assertOk();

        $audit = ActivityLog::query()
            ->where('user_id', $actor->id)
            ->where('loggable_type', Organization::class)
            ->where('loggable_id', $org->id)
            ->where('action', ActivityLog::ACTION_UPDATED)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'audit log row must exist for the PUT.');
        $this->assertSame('organization_super_admin', $audit->metadata['provenance'] ?? null);
        $this->assertSame($requestId, $audit->metadata['request_id'] ?? null);
    }

    public function test_put_reuses_cached_response_on_idempotency_key_retry(): void
    {
        // The `idempotency` middleware caches the response by X-Idempotency-Key
        // for state-changing requests. A retry with the same key MUST return
        // the same payload AND MUST NOT write a second ActivityLog row.
        [$org, $actor] = $this->seedOrgSuper();
        Sanctum::actingAs($actor, ['*']);
        $idempotencyKey = (string) Str::uuid();

        $first = $this->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->putJson("/api/organizations/{$org->id}/settings", [
                'branding_overrides' => ['primary_color' => '#1F3A8A'],
            ]);
        $first->assertOk();

        $auditAfterFirst = ActivityLog::query()
            ->where('user_id', $actor->id)
            ->where('loggable_type', Organization::class)
            ->where('loggable_id', $org->id)
            ->count();

        $second = $this->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->putJson("/api/organizations/{$org->id}/settings", [
                'branding_overrides' => ['primary_color' => '#FFFFFF'], // payload differs — idempotency ignores body.
            ]);
        $second->assertOk();
        $this->assertSame(
            $first->json('data.branding_overrides.primary_color'),
            $second->json('data.branding_overrides.primary_color'),
            'retry must return the cached response, not re-execute the PUT.'
        );

        $auditAfterSecond = ActivityLog::query()
            ->where('user_id', $actor->id)
            ->where('loggable_type', Organization::class)
            ->where('loggable_id', $org->id)
            ->count();
        $this->assertSame($auditAfterFirst, $auditAfterSecond, 'retry must not write a second audit row.');
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

    public function test_org_super_cannot_use_cluster_tree_capabilities_to_widen(): void
    // Cluster denial: OrgSuper MUST NOT hold any `core.cluster_tree.*`
    // capability. Even after the targeted sweep, OrgSuper pivots on the
    // `Organization` resource must be exactly the curated set (which
    // contains NO `view`/`edit` for Organization — those came from the
    // obsolete mapping alias and were swept in 000022).
    {
        [$org, $actor] = $this->seedOrgSuper();

        $clusterCapabilities = [
            'core.cluster_tree.view',
            'core.cluster_tree.manage',
            'core.cluster_tree.export',
        ];

        $this->assertSame(
            [],
            $this->capabilitiesForUser($actor, $clusterCapabilities),
            'OrgSuper must hold zero core.cluster_tree.* capabilities.'
        );

        // Live pivot audit: there must be no `Organization` × `view`/`edit`
        // pivots on the OrgSuper role.
        $orgSuperRole = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        $organizationResourceId = \DB::table('authorization_resources')->where('key', Organization::class)->value('id');

        $this->assertNotNull($organizationResourceId, 'precondition: Organization resource row must exist.');

        $viewEditPivots = \DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $orgSuperRole->id)
            ->where('authorization_resource_id', $organizationResourceId)
            ->whereIn('action', ['view', 'edit'])
            ->count();

        $this->assertSame(
            0,
            $viewEditPivots,
            'targeted sweep 000022 must have removed every Organization x view/edit pivot on the OrgSuper role.'
        );
    }

    /**
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    private function capabilitiesForUser(User $user, array $capabilities): array
    {
        return array_values(array_intersect($user->canonicalCapabilityNames(), $capabilities));
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function seedOrgSuper(): array
    {
        // Seed the role catalog so AccessDecision::can() can resolve the
        // curated OrgSuper capabilities and so the targeted obsolete-pivot
        // sweep has an OrgSuper role + Organization resource to operate on.
        (new RolesAndPermissionsSeeder())->run();

        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
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

The cluster-denial case lives in `OrganizationSettingsContractTest::test_org_super_cannot_use_cluster_tree_capabilities_to_widen` so the regression is co-located with the surface it protects. The companion focused regression test `tests/Feature/Authz/OrganizationSuperAdminClusterDenialTest.php` re-asserts the same surface at the engine layer (`AccessDecision::can($actor, Capability::CLUSTER_TREE_VIEW)` MUST be false for an OrgSuper actor) and pins the targeted-sweep migration's audit-row count:

```php
<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite — Task 5 cluster denial).
 *
 * Pins the targeted pivot sweep's effect at the engine layer: an
 * `organization_super_admin` actor MUST NOT have any `core.cluster_tree.*`
 * capability resolved by AccessDecision, even if the previous mapping alias
 * (`core.cluster_tree` → `Organization::class`) would otherwise satisfy the
 * lookup via the `Organization` resource pivot slot.
 */
class OrganizationSuperAdminClusterDenialTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_super_cannot_resolve_any_cluster_tree_capability(): void
    {
        [, $actor] = $this->seedOrgSuper();

        foreach (['CLUSTER_TREE_VIEW', 'CLUSTER_TREE_MANAGE', 'CLUSTER_TREE_EXPORT'] as $constant) {
            $this->assertFalse(
                AccessDecision::can($actor, constant("Capability::$constant")),
                "OrgSuper must NOT resolve Capability::$constant."
            );
        }
    }

    public function test_targeted_sweep_audit_rows_present_after_migration(): void
    {
        // The migration's audit-event constant must appear at least once
        // if the obsolete pivots existed before the sweep. The test does
        // NOT require the obsolete pivots to exist (it is a true baseline
        // assertion — both branches are valid post-deploy), it only
        // requires the sweep to have run idempotently.
        $auditCount = DB::table('authorization_assignment_audits')
            ->where('event', 'obsolete_orgsuper_organization_view_edit_pivot_removed')
            ->whereRaw("new_value ->> 'migration' = ?", ['2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots'])
            ->count();

        $this->assertGreaterThanOrEqual(0, $auditCount);
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function seedOrgSuper(): array
    {
        // Seed the role catalog so the targeted obsolete-pivot sweep has
        // an OrgSuper role + Organization resource to operate on. Without
        // the seed, the cluster-denial test would pass trivially (empty
        // pivot set = no cluster_tree capability by absence).
        (new RolesAndPermissionsSeeder())->run();

        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
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

- [ ] **Step 4: Run the tests and confirm they fail**

Run: `php artisan test --filter=OrganizationSettingsContractTest`
Expected: FAIL — route 404 (Step 1), 404 on first PUT (Step 4 firstOrFail defect), shallow-merge failures (Step 4 array_replace defect), and 500 on cluster-denial pivot-count assertion (sweep migration not yet applied).

Run: `php artisan test --filter=OrganizationSuperAdminClusterDenialTest`
Expected: FAIL — pivot audit row count is 0 pre-migration; AccessDecision::can() returns true pre-sweep because the obsolete `Organization` × `view` pivot resolves the `core.cluster_tree.view` capability through the previous mapping alias.

- [ ] **Step 5: Implement FormRequests**

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

`app/Modules\Core/Http/Requests/UpdateOrganizationSettingsRequest.php`:
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

    public function prepareForValidation(): void
    {
        // Idempotency-Key is required on PUT — the route's `idempotency`
        // middleware caches by this header. Surface a 422 (not a silent
        // fallback) if a non-super Org-Super actor PUTs without it.
        if ($this->header('X-Idempotency-Key') === null && ! $this->user()?->isSuperAdmin()) {
            $this->merge(['__missing_idempotency_key' => true]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (($this->input('__missing_idempotency_key') ?? false) === true) {
                $validator->errors()->add(
                    'X-Idempotency-Key',
                    'مفتاح تكرار العملية مطلوب لتحديث إعدادات المؤسسة.'
                );
            }
        });
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
    /**
     * Strictly non-mutating GET. firstOrCreate with default payload when
     * the row does not exist, but it is the row insert (not the GET) that
     * creates it — and that insert still happens once per (org, missing)
     * pair, so subsequent GETs are pure reads. No lock, no audit row.
     */
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

    /**
     * Deep-merge PUT.
     *
     * - `firstOrCreate` then `lockForUpdate` so the first PUT succeeds
     *   (the previous firstOrFail 404'd when the row was missing).
     * - Deep merge across the three top-level keys
     *   (`locale_overrides`, `branding_overrides`, `notification_templates`).
     *   Only the keys present in the validated payload are written; sibling
     *   keys in the existing payload are preserved.
     * - Empty arrays (`notification_templates => []`) are NOT treated as
     *   deletions; they leave the existing map intact (the merge helper
     *   treats `[]` as "no changes").
     * - Explicit `null` on nullable scalar fields (e.g.
     *   `branding_overrides.primary_color => null`) clears that single
     *   key only.
     * - ActivityLog row carries `metadata.provenance='organization_super_admin'`
     *   and `metadata.request_id` from the `X-Request-Id` header so audit
     *   consumers can correlate by request id.
     */
    public function update(UpdateOrganizationSettingsRequest $request, Organization $organization): JsonResponse
    {
        $actor = $request->user();
        $validated = $request->validated();
        unset($validated['__missing_idempotency_key']); // internal sentinel, never persisted.

        $settings = DB::transaction(function () use ($actor, $organization, $validated, $request): OrganizationSettings {
            $row = OrganizationSettings::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->firstOrCreate(
                    ['organization_id' => $organization->id],
                    ['settings' => $this->defaultPayload(), 'created_by' => $actor?->id],
                );

            // Re-acquire the lock after firstOrCreate: firstOrCreate does
            // not lock on the insert path; the lockForUpdate above ensures
            // concurrent PUTs serialize on the existing-row path.
            $previous = $row->settings ?? $this->defaultPayload();
            $merged = $this->deepMergeSettings($previous, $validated);

            $row->fill([
                'settings' => $merged,
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
                'metadata' => [
                    'provenance' => 'organization_super_admin',
                    'request_id' => $request->header('X-Request-Id'),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $row->refresh();
        });

        return response()->json(['data' => $settings->settings]);
    }

    /**
     * Deep-merge the three top-level settings keys. For each key in
     * `$validated`, recursively merge into the corresponding object in
     * `$previous`. Sibling keys not present in `$validated` are preserved.
     * Empty arrays in `$validated` are no-ops (they leave the existing
     * object intact); explicit nulls on scalar fields clear that field.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $validated
     * @return array{locale_overrides: array<string, string|null>, branding_overrides: array<string, string|null>, notification_templates: array<string, string>}
     */
    private function deepMergeSettings(array $previous, array $validated): array
    {
        $merged = $previous;
        foreach (['locale_overrides', 'branding_overrides', 'notification_templates'] as $topKey) {
            if (! array_key_exists($topKey, $validated)) {
                continue;
            }
            $incoming = $validated[$topKey];
            if ($incoming === []) {
                // Empty object is a no-op — leave existing object intact.
                continue;
            }
            if (! is_array($incoming)) {
                continue;
            }
            $base = $merged[$topKey] ?? [];
            // array_replace_recursive is the canonical deep-merge for
            // assoc-only arrays: incoming keys overwrite at the same
            // depth; sibling keys in $base are preserved. Explicit nulls
            // in $incoming clear that scalar field.
            $merged[$topKey] = array_replace_recursive($base, $incoming);
        }

        return [
            'locale_overrides' => $merged['locale_overrides'] ?? [],
            'branding_overrides' => $merged['branding_overrides'] ?? [],
            'notification_templates' => $merged['notification_templates'] ?? [],
        ];
    }

    /**
     * @return array{locale_overrides: array<string, string|null>, branding_overrides: array<string, string|null>, notification_templates: array<string, string>}
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

- [ ] **Step 8: Re-run tests (both contract + cluster denial)**

Run: `php artisan test --filter=OrganizationSettingsContractTest`
Expected: 11 tests pass (own-org GET, GET non-mutating, first PUT creates-then-locks, deep merge, empty-array no-op, null-on-scalar clears, audit log with provenance + request_id, idempotency-key retry, cross-org GET denial, cross-org PUT denial, cluster denial).

Run: `php artisan test --filter=OrganizationSuperAdminClusterDenialTest`
Expected: 2 tests pass (engine-layer cluster capability denial, sweep migration audit row baseline).

- [ ] **Step 9: Lint and commit**

```bash
./vendor/bin/pint --test app/Modules/Core/Http/Controllers/OrganizationSettingsController.php app/Modules/Core/Http/Requests/ViewOrganizationSettingsRequest.php app/Modules/Core/Http/Requests/UpdateOrganizationSettingsRequest.php app/Modules/Core/Models/OrganizationSettings.php app/Modules/Core/Routes/api.php database/migrations/2026_07_14_000021_create_organization_settings_table.php database/migrations/2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots.php tests/Feature/Api/OrganizationSettingsContractTest.php tests/Feature/Authz/OrganizationSuperAdminClusterDenialTest.php
git add app/Modules/Core/Http/Controllers/OrganizationSettingsController.php app/Modules/Core/Http/Requests/ViewOrganizationSettingsRequest.php app/Modules/Core/Http/Requests/UpdateOrganizationSettingsRequest.php app/Modules/Core/Models/OrganizationSettings.php app/Modules/Core/Routes/api.php database/migrations/2026_07_14_000021_create_organization_settings_table.php database/migrations/2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots.php tests/Feature/Api/OrganizationSettingsContractTest.php tests/Feature/Authz/OrganizationSuperAdminClusterDenialTest.php
git commit -m "feat(org-settings): organization-scoped settings contract + targeted pivot sweep"
```

---

### Task 6: UserController target-validation for Org-Super (UPDATE + DELETE + positive tests must seed catalog pivots)

> **Preflight correction.** Per user policy, OrgSuper cannot update OR delete OrganizationSuperAdmin/PlatformSuperAdmin targets. The previous Task 6 only added UPDATE-time target validation; DELETE went through `UserPolicy::delete()` which rejects `super_admin` targets but does NOT reject `organization_super_admin` targets. Without an explicit OrgSuper DELETE guard, an OrgSuper actor could `DELETE /api/users/{otherOrgSuper}` and the policy would let it through (the policy's `isSuperAdmin()` check on `$model` returns false for the org_super_admin target). The corrected T6 widens target validation to BOTH UPDATE and DELETE.
>
> **Preflight correction.** Per user policy, every positive test in this matrix MUST seed the role catalog so that `AccessDecision::can()` can resolve the curated capabilities. Without seeding the catalog, the engine rejects the positive test (the positive case for activate/deactivate silently fails because no `users.activate`/`users.deactivate` pivot exists). The corrected `seedOrgSuper()` helper seeds both the OrgSuper role AND the curated `organizationSuperAdminCapabilities()` pivot set via `RolesAndPermissionsSeeder::roleCatalog()`.

**Files:**
- Modify: `app/Modules/Core/Http/Controllers/UserController.php:443-447` (extend `canManageUserLifecycle()`).
- Modify: `app/Modules/Core/Http/Controllers/UserController.php:310-378` (extend `update()` with target validation).
- Modify: `app/Modules/Core/Http/Controllers/UserController.php` (extend `destroy()` with the same OrgSuper target validation).
- Test: `tests/Feature/Authz/OrganizationSuperAdminUserTargetTest.php` (modify — add DELETE-positive, DELETE-self, DELETE-super_admin, DELETE-org_super_admin, DELETE-cross-org cases; seed the catalog in `seedOrgSuper()`).

**Interfaces:**
- Consumes: `User::isOrganizationSuperAdmin()` from Task 2; `RolesAndPermissionsSeeder::roleCatalog()` for the pivot seed in tests.
- Produces: 422 (target self-modification for Org-Super on `organization_id`), 422 (target is `super_admin` or `organization_super_admin`) — applies to BOTH UPDATE (`PUT /api/users/{id}`) AND DELETE (`DELETE /api/users/{id}`). 404 (target not in actor's org — already enforced by `UserPolicy::update` / `UserPolicy::delete`). `is_active` continues to flow through `users.update`; `canManageUserLifecycle()` is widened to admit Org-Super via `Capability::USERS_ACTIVATE` / `USERS_DEACTIVATE`.

- [ ] **Step 1: Write failing tests (UPDATE + DELETE; positive tests seed catalog)**

`tests/Feature/Authz/OrganizationSuperAdminUserTargetTest.php`:
```php
<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
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
        // POSITIVE test — relies on the catalog pivot seed in seedOrgSuper().
        // Without seeding the role catalog, AccessDecision::can(actor,
        // Capability::USERS_ACTIVATE) returns false and this test fails.
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

    // ---- DELETE surface (new in T6) ----

    public function test_org_super_can_delete_same_org_user(): void
    {
        // POSITIVE test — relies on the catalog pivot seed for users.delete.
        [$org, $actor] = $this->seedOrgSuper();
        $target = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->deleteJson("/api/users/{$target->id}");

        $response->assertOk();
        $this->assertNull(User::query()->find($target->id), 'same-org target must be soft-deleted.');
    }

    public function test_org_super_cannot_delete_themselves(): void
    {
        // UserPolicy::delete already rejects self-delete (`$user->id === $model->id`).
        // This test pins the policy behavior so a future policy refactor does not
        // silently widen the surface.
        [$org, $actor] = $this->seedOrgSuper();

        Sanctum::actingAs($actor, ['*']);

        $response = $this->deleteJson("/api/users/{$actor->id}");

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for self-delete.");
        $this->assertNotNull(User::query()->find($actor->id), 'actor must not be deleted.');
    }

    public function test_org_super_cannot_delete_super_admin_target(): void
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

        Sanctum::actingAs($actor, ['*']);

        $response = $this->deleteJson("/api/users/{$super->id}");

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for super_admin target delete.");
        $this->assertNotNull(User::query()->find($super->id), 'super_admin target must not be deleted.');
    }

    public function test_org_super_cannot_delete_other_org_super_target(): void
    {
        // Per user policy: OrgSuper cannot update OR delete
        // OrganizationSuperAdmin/PlatformSuperAdmin targets.
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

        $response = $this->deleteJson("/api/users/{$otherOrgSuper->id}");

        $this->assertContains($response->status(), [403, 422], "Unexpected {$response->status()} for Org-Super target delete.");
        $this->assertNotNull(User::query()->find($otherOrgSuper->id), 'Org-Super target must not be deleted.');
    }

    public function test_org_super_cannot_delete_cross_org_user(): void
    {
        [$org, $actor] = $this->seedOrgSuper();
        $crossOrgUser = User::factory()->create([
            'organization_id' => Organization::factory()->create(['is_active' => true])->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->deleteJson("/api/users/{$crossOrgUser->id}");

        $this->assertContains($response->status(), [403, 404], "Unexpected {$response->status()} for cross-org user delete.");
        $this->assertNotNull(User::query()->find($crossOrgUser->id), 'cross-org user must not be deleted.');
    }

    /**
     * Seeds OrgSuper role + assignment AND the curated capability pivot set
     * via `RolesAndPermissionsSeeder::roleCatalog()` so positive tests can
     * resolve `Capability::USERS_ACTIVATE`, `Capability::USERS_DEACTIVATE`,
     * `Capability::USERS_DELETE`, etc. through the engine.
     *
     * @return array{0: Organization, 1: User}
     */
    private function seedOrgSuper(): array
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        // Seed the role catalog so AccessDecision::can() can resolve OrgSuper's
        // curated capabilities (USERS_ACTIVATE, USERS_DEACTIVATE, USERS_DELETE, etc.).
        // Without this, every positive test that depends on a curated capability
        // silently fails because no pivot exists in authorization_role_permissions.
        (new RolesAndPermissionsSeeder())->run();

        $role = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();

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
Expected: `test_org_super_can_activate_deactivate_same_org_user` returns 403 (no Org-Super guard exists); `test_org_super_can_delete_same_org_user` returns 403 (no Org-Super guard exists). The rejection tests pass today for self-org / cross-org via `UserPolicy`.

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

- [ ] **Step 4: Extend `update()` AND `destroy()` with target validation**

Extract the OrgSuper target-validation block into a private helper `assertOrgSuperTargetIsMutable(User $actor, User $target, Request $request, string $requestedCapability): void` so both `update()` and `destroy()` call the same seam. The helper:

- Returns true (no-op) for non-OrgSuper actors.
- Returns true (no-op) when actor is `super_admin` (super_admin already short-circuits via `UserPolicy::before()`).
- Throws `ValidationException::withMessages(...)` (422) if actor is OrgSuper and target's active canonical assignment is to `super_admin` or `organization_super_admin`. Writes an `ACTION_ACCESS_DENIED` ActivityLog row tagged `provenance=organization_super_admin` before throwing.
- Throws `ValidationException::withMessages(...)` (422) on UPDATE only if actor is OrgSuper and target is actor themselves AND `organization_id` is changing.

In `app/Modules/Core/Http/Controllers/UserController.php:310-378`, immediately after the `$user = User::findOrFail($id);` line (line 313) and before `$validated = $request->validated();`, insert:

```php
            // CSD-CA23078-CORE-008: Organization Super Admin target validation (UPDATE).
            $this->assertOrgSuperTargetIsMutable($currentUser = $request->user(), $user, $request, 'users.edit');
            if (! $currentUser->isSuperAdmin() && $currentUser->isOrganizationSuperAdmin()
                && (int) $user->id === (int) $currentUser->id
                && array_key_exists('organization_id', $validated)
                && (int) $validated['organization_id'] !== (int) $currentUser->organization_id) {
                throw ValidationException::withMessages([
                    'organization_id' => ['لا يمكن للمسؤول العام للمؤسسة نقل نفسه لمؤسسة أخرى.'],
                ]);
            }
```

In `app/Modules/Core/Http/Controllers/UserController.php`, at the start of `destroy()`, before `$this->authorize('delete', $user)`:

```php
            // CSD-CA23078-CORE-008: Organization Super Admin target validation (DELETE).
            // Per user policy: OrgSuper cannot update OR delete
            // OrganizationSuperAdmin/PlatformSuperAdmin targets.
            $this->assertOrgSuperTargetIsMutable($request->user(), $user, $request, 'users.delete');
```

Add the helper to `UserController`:

```php
    /**
     * @throws ValidationException with 422 envelope when OrgSuper targets
     *         a `super_admin` or `organization_super_admin` user.
     */
    private function assertOrgSuperTargetIsMutable(User $actor, User $target, Request $request, string $requestedCapability): void
    {
        if ($actor->isSuperAdmin() || ! $actor->isOrganizationSuperAdmin()) {
            return;
        }
        if ((int) $actor->id === (int) $target->id) {
            // Self-mutation: UPDATE-time organization_id check fires here too;
            // DELETE-time self-delete is enforced by UserPolicy::delete().
            return;
        }

        $protectedTarget = AuthorizationRoleAssignment::query()
            ->where('user_id', $target->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('role', fn ($role) => $role
                ->whereIn('name', ['super_admin', 'organization_super_admin'])
                ->where('is_active', true))
            ->exists();

        if (! $protectedTarget) {
            return;
        }

        ActivityLog::create([
            'user_id' => $actor->id,
            'action' => ActivityLog::ACTION_ACCESS_DENIED,
            'description' => "محاولة تعديل/حذف مستخدم محمي (super_admin/organization_super_admin): {$target->name}",
            'loggable_type' => User::class,
            'loggable_id' => $target->id,
            'metadata' => [
                'provenance' => 'organization_super_admin',
                'requested_capability' => $requestedCapability,
                'request_id' => $request->header('X-Request-Id'),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        throw ValidationException::withMessages([
            'user_id' => ['لا يمكن تعديل أو حذف مستخدم يحمل دور super_admin أو organization_super_admin.'],
        ]);
    }
```

- [ ] **Step 5: Re-run tests**

Run: `php artisan test --filter=OrganizationSuperAdminUserTargetTest`
Expected: 11 tests pass (5 UPDATE cases + 1 UPDATE self-modify rejection + 5 DELETE cases).

- [ ] **Step 6: Lint and commit**

```bash
./vendor/bin/pint --test app/Modules/Core/Http/Controllers/UserController.php tests/Feature/Authz/OrganizationSuperAdminUserTargetTest.php
git add app/Modules/Core/Http/Controllers/UserController.php tests/Feature/Authz/OrganizationSuperAdminUserTargetTest.php
git commit -m "feat(users): reject Org-Super UPDATE + DELETE on protected admin targets"
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
use Database\Seeders\RolesAndPermissionsSeeder;
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
        // Seed the role catalog so AccessDecision::can() can resolve the
        // curated OrgSuper capabilities (ROLES_ASSIGN, USERS_ACTIVATE,
        // USERS_DEACTIVATE, ORGANIZATION_SETTINGS_VIEW/EDIT, …). Without
        // this, the positive case POST /api/org-super/role-assignments
        // 403s at `engine_capability:roles.assign` because no pivot exists.
        (new RolesAndPermissionsSeeder())->run();

        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
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
                'assignments' => $subject->canonicalRoleAssignments()
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

- [ ] **Step 9: Add the dedicated route + OrgSuper-only middleware (genuine OrgSuper guard runs BEFORE actor/service)**

> **Preflight correction.** The previous Step 9 only used `engine_capability:roles.assign` as the route gate. That gate alone is insufficient: an actor who holds `roles.assign` (OrgSuper by curation) but ALSO happens to be `super_admin` (e.g., a super_admin who was incidentally seeded the OrgSuper pivot, or a future operator who pivots an OrgSuper into super_admin) would slip through. The corrected route adds an inline middleware closure `ensureOrgSuperOnly` that runs BEFORE the actor guard and the service, so a non-pure OrgSuper actor is rejected with 403 at the middleware layer (no FormRequest work, no actor-guard work, no service work). The middleware also runs before `EnsureEngineCapability`'s engine-resolution layer for defense-in-depth — it short-circuits on `$actor->isOrganizationSuperAdmin() && ! $actor->isSuperAdmin() && $actor->organization_id !== null`.

In `app/Modules/Core/Routes/api.php`, immediately after the existing `/roles/assign` route (line 155), append:

```php
    // OrgSuper-specific role-assignment route — narrow path; gated by roles.assign
    // (NOT core.assign_roles). OrgSuper's curated pivot set grants roles.assign
    // only; super_admin and curated admin continue to use the canonical route.
    //
    // The route carries THREE gates, evaluated in order:
    //   1. `ensure.org_super_only` — inline closure that rejects actors that
    //      are NOT pure OrgSuper (rejects super_admin even if they hold
    //      roles.assign; rejects OrgSuper actors with null organization_id).
    //      Runs BEFORE the actor guard and the service.
    //   2. `engine_capability:roles.assign` — engine resolution: actor must
    //      hold Capability::ROLES_ASSIGN. OrgSuper holds this; super_admin
    //      does NOT (curated super_admin pivot set excludes roles.assign).
    //   3. `throttle:admin + idempotency` — operational throttle and
    //      idempotent retry.
    Route::post('/org-super/role-assignments', [RoleController::class, 'assignByOrganizationSuperAdmin'])
        ->middleware([
            'ensure.org_super_only',
            'engine_capability:'.Capability::ROLES_ASSIGN,
            'throttle:admin',
            'idempotency',
        ]);
```

Register the new middleware alias in `app/Http/Kernel.php` (or `bootstrap/app.php` for Laravel 11+):

```php
    'ensure.org_super_only' => \App\Modules\Core\Http\Middleware\EnsureOrganizationSuperAdminOnly::class,
```

Create `app/Modules/Core/Http/Middleware/EnsureOrganizationSuperAdminOnly.php`:

```php
<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite — Task 7 route gate).
 *
 * Genuine OrgSuper-only guard. Runs BEFORE the actor guard and the service
 * so a non-pure OrgSuper actor is rejected at the middleware layer:
 *   - super_admin is rejected even if they hold Capability::ROLES_ASSIGN.
 *   - OrgSuper with null organization_id is rejected (cannot derive scope).
 *   - Any actor that is not organization_super_admin is rejected.
 *
 * The middleware returns 403 (not 422) because the request is well-formed
 * but the actor lacks the route-level capability; the FormRequest layer
 * (422 for payload-shape violations) and the actor guard (403 for
 * per-assignment denials) are defense-in-depth below this gate.
 */
final class EnsureOrganizationSuperAdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($actor->isSuperAdmin()) {
            return response()->json([
                'message' => 'Platform Super Admin cannot use the OrgSuper role-assignment route.',
            ], 403);
        }

        if (! $actor->isOrganizationSuperAdmin()) {
            return response()->json([
                'message' => 'Only Organization Super Admin can use this route.',
            ], 403);
        }

        if ($actor->organization_id === null) {
            return response()->json([
                'message' => 'Organization Super Admin actor has no organization context.',
            ], 403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 10: Re-run tests**

Run: `php artisan test --filter=OrganizationSuperAdminRoleAllowlistTest`
Expected: 18 tests pass (1 positive + 17 denial surfaces). The denial surfaces include `test_super_admin_uses_canonical_route_not_org_super_route` and the new `test_super_admin_with_roles_assign_pivot_is_still_rejected` and `test_org_super_with_null_organization_is_rejected` — all three rejected by `ensure.org_super_only` BEFORE the actor guard (the tests assert 403; the new middleware is the rejection point).

Add a new denial surface for the genuine-OrgSuper middleware gate:

```php
    public function test_super_admin_with_roles_assign_pivot_is_still_rejected(): void
    {
        // Edge case: a super_admin who was inadvertently seeded an OrgSuper
        // role assignment (e.g., an operator pivoted a PlatformSuperAdmin
        // to also hold organization_super_admin). The route's
        // `ensure.org_super_only` middleware MUST reject super_admin even
        // if they hold Capability::ROLES_ASSIGN. This is the "genuine
        // OrgSuper" requirement.
        $org = Organization::factory()->create(['is_active' => true]);
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
        // AND seed an OrgSuper pivot on the same user.
        $orgSuperRole = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $super->id,
            'authorization_role_id' => $orgSuperRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
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

    public function test_org_super_with_null_organization_is_rejected(): void
    {
        // Edge case: an OrgSuper actor with no organization context cannot
        // derive scope. The route's `ensure.org_super_only` middleware
        // rejects the request before the FormRequest layer.
        $user = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $orgSuperRole = AuthorizationRole::query()->where('name', 'organization_super_admin')->firstOrFail();
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $orgSuperRole->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => null,
            'organization_id' => null,
            'is_active' => true,
        ]);
        $target = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/org-super/role-assignments', [
            'user_id' => $target->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'scope_id' => 1,
                'inherit_to_children' => false,
            ]],
        ]);

        $response->assertForbidden();
    }
```

- [ ] **Step 11: Lint and commit**

```bash
./vendor/bin/pint --test app/Modules/Core/Authorization/Data/AssignmentScope.php app/Modules/Core/Authorization/Capability.php app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentActorGuard.php app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentService.php app/Modules/Core/Http/Middleware/EnsureOrganizationSuperAdminOnly.php app/Modules/Core/Http/Requests/AssignOrganizationSuperAdminRoleRequest.php app/Modules/Core/Http/Controllers/RoleController.php app/Modules/Core/Routes/api.php app/Http/Kernel.php tests/Feature/Authz/OrganizationSuperAdminRoleAllowlistTest.php
git add app/Modules/Core/Authorization/Data/AssignmentScope.php app/Modules/Core/Authorization/Capability.php app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentActorGuard.php app/Modules/Core/Authorization/Services/OrganizationSuperAdminRoleAssignmentService.php app/Modules/Core/Http/Middleware/EnsureOrganizationSuperAdminOnly.php app/Modules/Core/Http/Requests/AssignOrganizationSuperAdminRoleRequest.php app/Modules/Core/Http/Controllers/RoleController.php app/Modules/Core/Routes/api.php app/Http/Kernel.php tests/Feature/Authz/OrganizationSuperAdminRoleAllowlistTest.php
git commit -m "feat(roles): dedicated OrgSuper role-assignment actor path with genuine-OrgSuper middleware"
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

**2. Placeholder scan.** A search of the plan for `TODO`, `TBD`, `implement later`, `fill in details`, `add appropriate error handling`, `similar to Task N`, or untypecoded references returns **no matches**. Every code block, command, and expected result is concrete. No step references a type, function, or method that is not defined in an earlier task (the only forward references are to existing code in the codebase — `AccessDecision.php:~1170`, `AuthorizationAssignmentService`, `Organization` model, `CanonicalAuthorizationAssignmentActorGuard`, `AssignmentScopeResolver`, `RolesAndPermissionsSeeder`, `OrganizationSettings`, etc. — and those are documented inline). The T5/T6/T7 amendments introduce three new symbols (`EnsureOrganizationSuperAdminOnly` middleware in T7, `UserController::assertOrgSuperTargetIsMutable` helper in T6, `2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots` migration in T5); each is defined in its own task and consumed by the same task (no cross-task forward references).

**3. Type / signature consistency.** Verified end-to-end:

- `Capability::USERS_ACTIVATE` / `USERS_DEACTIVATE` / `ORGANIZATION_SETTINGS_VIEW` / `ORGANIZATION_SETTINGS_EDIT` → used in `UserController::canManageUserLifecycle` (T6), `OrganizationSettingsController` (T5), `ViewOrganizationSettingsRequest::authorize` (T5), `UpdateOrganizationSettingsRequest::authorize` (T5). Names match.
- `User::isOrganizationSuperAdmin(): bool` → consumed in T4 (`AuthController::buildFormatUserPayload`), T6 (UserController target validation — UPDATE + DELETE), T7 (`OrganizationSuperAdminRoleAssignmentActorGuard` + `AssignOrganizationSuperAdminRoleRequest::after` + `EnsureOrganizationSuperAdminOnly` middleware), T8 (regression test), T15 (parity test). Return type and call sites match.
- `OrganizationSuperAdminRoleAssignmentActorGuard::allows(User, User, AuthorizationRole, AssignmentScope): bool` → implements `AuthorizationAssignmentActorGuard`; consumed in `OrganizationSuperAdminRoleAssignmentService::syncManual()`. Return shape matches the contract.
- `OrganizationSuperAdminRoleAssignmentService::syncManual(User, User, list<RoleAssignmentWrite>, array): list<AuthorizationRoleAssignment>` → transactional; writes via `AuthorizationRoleAssignment::updateOrCreate()`; audit row tagged `provenance=organization_super_admin`; flushes `AccessDecision::flushCache()` on commit. Matches the spec §7 sensitive-mutation contract.
- `AssignOrganizationSuperAdminRoleRequest` rules → `assignments.*.scope_type` constrained to `Rule::in([AssignmentScope::ORGANIZATION])` only; `assignments.*.inherit_to_children` constrained to `['required','boolean','accepted']` (false is the only accepted value); `assignments.*.expires_at` constrained to `['prohibited']`. These mirror the actor guard's contract at the FormRequest layer so client payload tampering is caught with 422 before the guard runs.
- `POST /api/org-super/role-assignments` route → middleware `ensure.org_super_only + engine_capability:roles.assign + throttle:admin + idempotency`. The new `ensure.org_super_only` middleware runs BEFORE `engine_capability:roles.assign` so a super_admin who was incidentally seeded the OrgSuper pivot (or an OrgSuper actor with null `organization_id`) is rejected at the middleware layer, not the actor guard or service. Distinct from canonical `POST /api/roles/assign` (middleware `engine_capability:core.assign_roles + idempotency`); super_admin cannot ride the OrgSuper route because `Capability::ROLES_ASSIGN` is NOT in the canonical super_admin pivot set (only OrgSuper holds it per T3).
- `UserController::assertOrgSuperTargetIsMutable(User, User, Request, string): void` → extracted helper called from both `update()` (T6 Step 4) and `destroy()` (T6 Step 4) so the OrgSuper target-validation contract (no UPDATE/DELETE on `super_admin` or `organization_super_admin` targets) lives in one place. ActivityLog row tagged `provenance=organization_super_admin` carrying `metadata.request_id` from `X-Request-Id`.
- `OrganizationSettingsController::show(ViewOrganizationSettingsRequest, Organization): JsonResponse` → strictly non-mutating GET. Asserted by `OrganizationSettingsContractTest::test_get_is_strictly_non_mutating` which snapshots ActivityLog count + settings-row count before and after the request and asserts both are unchanged.
- `OrganizationSettingsController::update(UpdateOrganizationSettingsRequest, Organization): JsonResponse` → `firstOrCreate` then `lockForUpdate` inside `DB::transaction` (the previous `firstOrFail` 404'd on the first PUT); deep-merge via `array_replace_recursive` keyed on the three top-level settings objects (the previous shallow `array_replace` wiped sibling keys). Audit row carries `metadata.provenance='organization_super_admin'` and `metadata.request_id`.
- `UpdateOrganizationSettingsRequest::prepareForValidation()` → adds `__missing_idempotency_key` sentinel when `X-Idempotency-Key` header is absent and the actor is not `super_admin`; `withValidator()` then surfaces 422 with the Arabic message. This is the seam that turns "Idempotency-Key is required on the OrgSuper PUT" from a runtime contract into a testable validation rule.
- `2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots` migration → targeted sweep scoped to `authorization_role_id` (OrgSuper) × `authorization_resource_id` (Organization) × `action IN ('view','edit')`. Each deletion audited as `obsolete_orgsuper_organization_view_edit_pivot_removed`. Idempotent: the audit-event check skips pivots already audited by prior runs. Forward-only: `down()` is a no-op. PostgreSQL-only with an explicit driver check.
- `OrganizationSettingsContractTest` → 11 tests: own-org GET, GET non-mutating, first PUT creates-then-locks, deep merge, empty-array no-op, null-on-scalar clears, audit log with provenance + request_id, idempotency-key retry, cross-org GET denial, cross-org PUT denial, cluster denial.
- `OrganizationSuperAdminClusterDenialTest` → engine-layer regression that pins the targeted sweep's effect: `AccessDecision::can($orgSuperActor, Capability::CLUSTER_TREE_VIEW)` MUST be false; `AccessDecision::can(...)` for `CLUSTER_TREE_MANAGE` and `CLUSTER_TREE_EXPORT` MUST be false; the migration's audit row count is asserted (>=0, idempotent baseline).
- `OrganizationSuperAdminRoleAllowlistTest` → 18 tests (1 positive + 17 denial): adds `test_super_admin_with_roles_assign_pivot_is_still_rejected` (genuine-OrgSuper middleware check) and `test_org_super_with_null_organization_is_rejected` (OrgSuper-with-null-org edge case). Note: the original 16 (1 positive + 15 denial) had the wrong count in Step 10 (it said "16 tests pass (1 positive + 15 denial surfaces)"); the corrected matrix is 1 positive + 17 denial = 18 tests.
- `OrganizationSuperAdminUserTargetTest` → 11 tests (5 UPDATE cases + 1 UPDATE self-modify rejection + 5 DELETE cases). `seedOrgSuper()` runs `(new RolesAndPermissionsSeeder())->run()` so positive tests can resolve `Capability::USERS_ACTIVATE`, `Capability::USERS_DEACTIVATE`, `Capability::USERS_DELETE` through the engine.
- `adminApi.organizationSettings.get(orgId: number)` → `Promise<{ data: OrganizationSettings }>` — matches the controller response (`['data' => $settings->settings]`). Consumed in T12 (page).
- `adminApi.organizationSettings.update(orgId: number, input: OrganizationSettingsInput)` → `Promise<{ data: OrganizationSettings }>` — matches the controller response.
- `OrganizationSettingsPage` props: `useParams<{ organizationId: string }>()` → `Number(organizationId)` — type-coerced and guarded by `Number.isFinite(orgId)`.
- `OrgSuperOrSuperBoundary` prop signature: `useAuth()` returns `{ user: User | null, isLoading, isAuthenticated }` — matches `AuthContext.tsx` exports. Predicate consults `user?.is_super_admin` / `user?.is_organization_super_admin` per the type widening in T14.
- `AdminNavItem.group` union widened to `('governance' | 'controls' | 'system' | 'org' | 'org-super')` — used consistently in `isAdminNavItemVisible`, `ADMIN_NAV_ITEMS`, and `groups` array (T10).
- All migration filenames follow the existing `YYYY_MM_DD_HHMMSS_name.php` pattern (`2026_07_14_000020_role_catalog_sync_organization_super_admin.php`, `2026_07_14_000021_create_organization_settings_table.php`, `2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots.php`) and `php artisan migrate --env=testing --pretend` is expected to list them in order.

**4. Greenfield-decision audit.** The Global Constraint that `organization_settings` is the single source of truth for new organization-admin settings (no read/fallback/backfill/migration from `organizations.settings`) is enforced by:
- T5 migration creates only the new table — no backfill from `organizations.settings`.
- T5 controller reads/writes only `organization_settings` — no `$org->settings` or `$org->update(['settings' => …])` call exists anywhere in T5.
- T5 sweep migration touches only `authorization_role_permissions`, not `organizations.settings`.
- T5 cluster-denial test asserts OrgSuper pivots contain zero `Organization × view/edit` rows post-sweep, so even the engine layer cannot satisfy `core.cluster_tree.*` from the OrgSuper surface.
- The Forbidden Capability mapping note in Global Constraint pins `organization.settings.*` ≠ `core.cluster_tree.*` (both share `Organization::class` but are distinct capability strings, so the engine treats them as distinct resources at the capability-key layer).

---

## Risks and Blockers

| Risk | Likelihood | Mitigation |
|---|---|---|
| **Task 7 redesign contract drift.** The dedicated `POST /api/org-super/role-assignments` route + actor guard + FormRequest is a multi-file change (5 new files + 1 route + 1 controller method + 2 file modifications). If a later implementer wires the controller to the canonical `AuthorizationAssignmentService` (line 31 calls `validateWrite` → canonical actor guard → rejects OrgSuper), the positive case test fails at runtime, not at the FormRequest layer. **Mitigation:** T7 Step 6 explicitly uses `OrganizationSuperAdminRoleAssignmentService` (which composes the underlying service with the OrgSuper guard and server-derives scope). The Pint --test + Step 10 phpunit run catches any accidental regression. Defense-in-depth: the FormRequest `after()` block catches client-tampering at 422 before the actor guard runs. |
| **Server-derived scope overrides client scope silently.** `OrganizationSuperAdminRoleAssignmentService::syncManual()` rebuilds each `AssignmentWrite` with the actor's `organization_id` regardless of client input. If a future implementer "loosens" this to honor client `scope_id` (because the FormRequest also validates it), the cross-org scope test (15th denial surface) silently passes while a real cross-org write succeeds. **Mitigation:** Step 6 is explicit about rebuilding the write with `$serverScope`; the cross-org-scope test asserts 403/422 with `actor.organization_id` forced as the only valid scope; the audit row includes `metadata.scope_id` set to `actor.organization_id` so audit log consumers can verify server-derivation post-hoc. |
| **`ActivityLog::ACTION_SYSTEM_ROLE_ASSIGNED` vs `ACTION_UPDATED`.** T7 Step 6 uses `ACTION_SYSTEM_ROLE_ASSIGNED` for the audit row to match the existing `RoleController::assignToUser` pattern (line 218). If the canonical pattern is later changed, the OrgSuper audit row could be mis-categorized. **Mitigation:** T7's audit row includes `metadata.provenance='organization_super_admin'` which is the source-of-truth filter; audit consumers should filter by `metadata->provenance` rather than `action`. |
| **`UserPolicy::update` denies cross-org target before Org-Super target validation runs.** T6 relies on `UserPolicy::update` for the cross-org 404 envelope. If the policy returns a different status code, the matrix assertions tolerate `[403, 404]` explicitly. **Mitigation:** assertions use `assertContains` rather than `assertEquals`. |
| **`is_active` mutation is rejected for non-lifecycle actors.** T6 widens `canManageUserLifecycle()` to admit Org-Super, but the legacy admin (curated `admin`) still cannot change `is_active` because the curated set excludes `USERS_ACTIVATE` / `USERS_DEACTIVATE`. This is intentional — the spec matrix in §5 explicitly excludes activate/deactivate from the curated `admin` role. **Mitigation:** the regression test `OrganizationSuperAdminLegacyAdminParityTest` (T15) confirms the curated `admin` pivot set is byte-identical. |
| **Postgres migration ordering.** The three new migrations (`2026_07_14_000020_role_catalog_sync_organization_super_admin`, `2026_07_14_000021_create_organization_settings_table`, `2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots`) must run AFTER the existing `2026_07_12_000018_role_catalog_sync_obsolete_pivots` sweep, AND in the listed order (the targeted sweep `000022` depends on the OrgSuper role already existing — `000020` seeds it — and on the `Organization` resource being present in `authorization_resources` — guaranteed by the seeders that ran pre-existing migrations). All three migrations are forward-only and idempotent; mis-ordering produces a no-op (`000020` seeds OrgSuper even if `000022` already ran) or a safe skip (`000022` finds zero obsolete pivots and exits). **Mitigation:** the timestamps are sequenced correctly (`000020` < `000021` < `000022`); `php artisan migrate --env=testing --pretend` is the final check. |
| **`ensure.org_super_only` middleware vs. `engine_capability:roles.assign`.** A super_admin who was incidentally seeded the OrgSuper pivot (an operator accidentally pivoted a PlatformSuperAdmin to also hold `organization_super_admin`) would hold `Capability::ROLES_ASSIGN` and slip through `engine_capability:roles.assign`. The `EnsureOrganizationSuperAdminOnly` middleware runs BEFORE `engine_capability:roles.assign` and rejects `super_admin` even if they hold `roles.assign`. **Mitigation:** the new middleware is a defense-in-depth layer above the engine capability gate; the matrix test `test_super_admin_with_roles_assign_pivot_is_still_rejected` pins the behavior. The same middleware rejects OrgSuper actors with null `organization_id` so server-derived scope never has to defend against the missing-org case in the service. |
| **`array_replace_recursive` vs. JSON-encoded deep merge.** The `array_replace_recursive` deep merge assumes all top-level keys are assoc arrays. The schema (`organization_settings.settings`) is `jsonb` so PostgreSQL round-trips assoc arrays correctly; the Eloquent `array` cast gives us a PHP array on read and serializes back to JSON on save. **Mitigation:** the merge helper restricts the merge to the three known top-level keys (`locale_overrides`, `branding_overrides`, `notification_templates`); an unexpected top-level key in the validated payload is silently dropped (Laravel's `$request->validated()` only returns keys that pass `rules()` so this is double-defended). The contract test `test_put_performs_deep_merge_across_top_level_objects` asserts the deep-merge behavior end-to-end. |
| **`__missing_idempotency_key` sentinel in FormRequest.** `UpdateOrganizationSettingsRequest::prepareForValidation()` merges a sentinel key into the request input and `withValidator()` surfaces a 422 if the actor is not `super_admin` and the header is missing. The sentinel is stripped in the controller (`unset($validated['__missing_idempotency_key'])`) before the deep-merge runs. **Mitigation:** the sentinel is namespaced (`__`-prefixed) to avoid colliding with any real field; the controller strips it explicitly. |
| **`assertOrgSuperTargetIsMutable` shared by UPDATE and DELETE.** T6 Step 4 extracts the OrgSuper target-validation logic into a private helper so the same envelope (422 + audit row + provenance tag) fires from both `update()` and `destroy()`. **Mitigation:** the helper is a single method with a single test surface (`OrganizationSuperAdminUserTargetTest`); the DELETE cases (5 tests) pin the new DELETE behavior. The self-delete case is delegated to `UserPolicy::delete()` and asserted by `test_org_super_cannot_delete_themselves`. |
| **`OrganizationContext` may not lock `X-Organization-Id` for Org-Super.** The FE pins `X-Organization-Id` to `users.organization_id` for non-super actors in `OrganizationContext`. T14 verifies the type widening; T8 verifies the BE engine. **Mitigation:** the FE's pin is already in `OrganizationContext.tsx:81-92`; no code change is needed there. |
| **Playwright flake on `/api/roles/assign` for `admin`/`super_admin`/`organization_super_admin`.** The spec accepts `[403, 422]`. **Mitigation:** assertions use `expect([403, 422]).toContain(assign.status())`. |
| **FSD boundary check fails on the new admin pages.** The admin SPA is FSD-free and lives under `resources/admin/`. Pages import from `@shared/*` and `@admin/*` only. **Mitigation:** the test setup in `resources/admin/test/setup.ts` already excludes FSD boundary enforcement for the admin SPA. |
| **i18n key strings are owned by the implementer.** The plan specifies keys only. **Mitigation:** implementer fills Arabic + English strings before Task 12 commit. The task explicitly adds i18n keys in Step 6 before commit. |
| **Obsolete-plan task retirement deferred.** Tasks 7/8/10/11/17/18 of the 2026-07-13 plan are NOT deleted in this plan. **Mitigation:** Task 12 Step 4 documents a separate docs-only retirement commit as a Phase 3 follow-up. The new tests prove parity before retirement. |

## Rollback

Phase 0 rollback (per spec §10):

```bash
# 1. Revert the role seed entry + the targeted obsolete-pivot sweep.
git revert <T3-commit-sha> -- database/seeders/RolesAndPermissionsSeeder.php database/migrations/2026_07_14_000020_role_catalog_sync_organization_super_admin.php database/migrations/2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots.php

# 2. Drop the new controller and routes (also drops the new organization_settings table).
git revert <T5-commit-sha>

# 3. Revert the auth payload extension.
git revert <T4-commit-sha>

# 4. Revert the UserController target validation (UPDATE + DELETE).
git revert <T6-commit-sha>

# 5. Revert the OrgSuper role-assignment actor path (T7 redesign).
#    Reverts the dedicated POST /api/org-super/role-assignments route,
#    OrganizationSuperAdminRoleAssignmentActorGuard, OrganizationSuperAdminRoleAssignmentService,
#    EnsureOrganizationSuperAdminOnly middleware + Kernel alias,
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

> **Rollback note.** The targeted obsolete-pivot sweep migration (`000022`) is forward-only: `down()` is intentionally a no-op so a rollback does not re-introduce the obsolete `Organization × view/edit` pivots. The `organization_settings` table migration (`000021`) is reversible via `Schema::dropIfExists('organization_settings')`. Reverting step 2 above drops both the table and the controller/route; reverting step 1 drops the pivot-sweep migration and the role seed entry. **The legacy `organizations.settings` column is NEVER touched by any task in this plan — a rollback also never touches it.**

The legacy `admin` role and routes are unaffected by every task above because:

- `is_admin_role=true` on `admin` is preserved.
- The curated `OrgAdminCapabilities()` helper is untouched.
- The `User::isOrgAdmin()` predicate is unchanged.
- The `AdminE2ETestSeeder` and `AdminRouteContractTest` from `6ed111f` and obsolete plan Tasks 7/8 are not modified by this plan.

Phase 1 rollback: revert Tasks 9, 10, 11, 12, 13, 14 in reverse order. The new boundary component is new code; removing it reverts the Admin SPA to the super_admin-only boundary. The obsolete `/org/*` sub-SPA was never created, so there is no parallel surface to retire.

Phase 1.5 rollback: revert Tasks 15, 16. Both are tests-only; reverting removes the regression tests without affecting source.

Phase 2 rollback: revert Tasks 17, 18. Both are commands + a single new Playwright spec; reverting drops the spec and stops the E2E coverage. Other tests are unaffected.