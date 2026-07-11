# Independent Admin Application Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a separately built and deployed admin React application, canonical `/api/admin/*` contracts, and complete backend, integration, E2E, deployment, and rollback evidence.

**Architecture:** A plain Vite application under `resources/admin` builds to `dist-admin` and is served by its own Nginx image. Nginx serves the SPA and proxies same-origin `/api` and `/sanctum` to the existing Laravel backend. Each Laravel module registers its own canonical admin aliases around existing controllers and FormRequests; business logic and data remain shared.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL 16, Redis 7, Sanctum 4, React 19, TypeScript 5.7, Vite 7, Tailwind 4, Vitest 3, Testing Library, Playwright 1.49, Docker, Nginx.

## Global Constraints

- PostgreSQL only. Never add SQLite configuration or tests.
- Never edit an applied migration. This extraction requires no schema migration.
- `super_admin` is the only global control-plane admission rule in the new frontend and canonical API.
- Preserve fail-closed organization and department isolation in reused controllers and requests.
- Keep auth cookies HttpOnly; never store access tokens in browser storage.
- Login and 2FA run on the admin origin through same-origin reverse-proxied Laravel endpoints.
- Admin may import `resources/admin`, `@shared`, and approved `@entities`; it may not import operational pages, widgets, app bootstrap, or operational-only features.
- Arabic is the master locale, RTL must work, English must mirror keys, and Tabler remains the only icon library.
- Mutations retain FormRequest authorization, validation, idempotency, throttling, tenancy invariants, and activity logging.
- Preserve unrelated dirty-tree work. `resources/js/app.tsx` is edited only in the final serialized cutover task after re-reading its current diff.
- Use exact-path Git operations only. Never stash, reset, checkout, rebase, clean, or stage globally.

---

### Task 1: Repair Existing Governance Contracts

**Files:**
- Modify: `app/Modules/Core/Http/Controllers/SuperAdminDashboardController.php`
- Modify: `tests/Feature/Api/Core/SuperAdminDashboardControllerTest.php`
- Modify: `resources/js/entities/admin/model/admin.ts`
- Modify: `resources/js/pages/admin/overview/Overview.tsx`
- Modify: `resources/js/pages/admin/audit-recent/AuditRecent.tsx`
- Modify: `resources/js/pages/admin/scope-types/ScopeTypesList.tsx`
- Modify: `resources/js/__tests__/admin/super-admin-dashboard.test.tsx`
- Modify: `resources/js/__tests__/admin/admin-pages-coverage.test.tsx`

**Interfaces:**
- Produces: `OverviewCounts` without the removed registration-approval KPI.
- Produces: `AuditRecentResponse.meta = {current_page,last_page,per_page,total,limit,returned}`.
- Produces: `AuditRecentRow.actor = {id,name} | null` without email.

- [ ] **Step 1: Add backend RED assertions**

Add assertions for paginator metadata and minimized actor data:

```php
$response->assertJsonStructure([
    'data',
    'meta' => ['current_page', 'last_page', 'per_page', 'total', 'limit', 'returned'],
]);
$this->assertArrayNotHasKey('email', $response->json('data.0.actor'));
```

Assert overview does not advertise a registration queue removed from the product.

- [ ] **Step 2: Prove backend RED**

Run: `php artisan test tests/Feature/Api/Core/SuperAdminDashboardControllerTest.php`

Expected: FAIL on missing pagination fields and actor email.

- [ ] **Step 3: Add frontend RED assertions**

Fixtures omit `registrations`; overview renders only real KPIs; page indicator uses backend `last_page`; scope types expose no create action without a real form.

- [ ] **Step 4: Prove frontend RED**

Run: `npm test -- resources/js/__tests__/admin/super-admin-dashboard.test.tsx resources/js/__tests__/admin/admin-pages-coverage.test.tsx`

- [ ] **Step 5: Implement the minimal contract repair**

Replace `forPage()->get()` with an ordered paginator capped at 50 rows per page. Return full paginator metadata. Select only `id,name` for actor lookup. Remove the registration KPI/type/test fixture. Remove the phantom scope-type Add button.

- [ ] **Step 6: Verify GREEN**

Run:

```bash
php artisan test tests/Feature/Api/Core/SuperAdminDashboardControllerTest.php
npm test -- resources/js/__tests__/admin/super-admin-dashboard.test.tsx resources/js/__tests__/admin/admin-pages-coverage.test.tsx
./vendor/bin/pint --test app/Modules/Core/Http/Controllers/SuperAdminDashboardController.php tests/Feature/Api/Core/SuperAdminDashboardControllerTest.php
```

Expected: PASS.

---

### Task 2: Add Canonical Admin API Aliases Module by Module

**Files:**
- Modify: `app/Modules/Core/Routes/api.php`
- Modify: `app/Modules/Shared/Routes/api.php`
- Modify: `app/Modules/HR/Routes/api.php`
- Modify: `app/Modules/OVR/Routes/api.php`
- Create: `tests/Feature/Api/Admin/AdminRouteContractTest.php`

**Interfaces:**
- Core produces canonical organizations, scope-types, roles, governance-rules, users, scoped-role, overview, security, and audit routes.
- Shared produces canonical activity-log routes.
- HR produces canonical department routes.
- OVR produces canonical incident-type routes.
- Every canonical route is under `auth:sanctum + role:super_admin`; legacy routes remain during compatibility.

- [ ] **Step 1: Write the canonical contract RED suite**

For every route family assert anonymous 401, non-super-admin 403, super-admin success, and representative legacy/canonical JSON equivalence:

```php
$this->getJson('/api/admin/organizations')->assertUnauthorized();

$this->actingAs($regular, 'sanctum')
    ->getJson('/api/admin/organizations')
    ->assertForbidden();

$this->actingAs($super, 'sanctum')
    ->getJson('/api/admin/organizations')
    ->assertOk();
```

Add mutation validation, idempotency, audit side-effect, and cross-organization denial cases where the reused controller is target-scoped.

- [ ] **Step 2: Prove RED**

Run: `php artisan test tests/Feature/Api/Admin/AdminRouteContractTest.php`

Expected: missing routes.

- [ ] **Step 3: Register aliases in owning modules**

Reuse the same controller methods, route model binding parameter names, FormRequests, and middleware. Do not copy controller logic. Canonical families:

```text
/api/admin/organizations
/api/admin/scope-types
/api/admin/roles
/api/admin/governance-rules
/api/admin/users
/api/admin/scoped-roles
/api/admin/activity-logs
/api/admin/departments
/api/admin/incident-types
```

- [ ] **Step 4: Verify backend routes and regressions**

Run:

```bash
php artisan route:list --path=api/admin
php artisan test tests/Feature/Api/Admin/AdminRouteContractTest.php
php artisan test tests/Feature/Api/Core/OrganizationControllerTest.php tests/Feature/Api/RoleControllerTest.php tests/Feature/Core/GovernanceRulesApiTest.php tests/Feature/Api/Core/ScopeTypeControllerTest.php tests/Feature/Security/ActivityLogIsolationTest.php tests/Feature/Api/DepartmentOrganizationScopeTest.php
./vendor/bin/pint --test app/Modules/Core/Routes/api.php app/Modules/Shared/Routes/api.php app/Modules/HR/Routes/api.php app/Modules/OVR/Routes/api.php tests/Feature/Api/Admin/AdminRouteContractTest.php
```

Expected: PASS.

---

### Task 3: Create the Independent Admin Build and Boundary Gates

**Files:**
- Modify: `package.json`
- Create: `vite.admin.config.ts`
- Create: `vitest.admin.config.ts`
- Create: `tsconfig.admin.json`
- Create: `tsconfig.admin.test.json`
- Create: `resources/admin/index.html`
- Create: `resources/admin/main.tsx`
- Create: `resources/admin/vite-env.d.ts`
- Create: `resources/admin/app/AdminApp.tsx`
- Create: `resources/admin/test/setup.ts`
- Modify: `eslint.config.js`
- Modify: `scripts/design-check.mjs`
- Create: `scripts/check-admin-boundaries.mjs`

**Interfaces:**
- Produces commands `admin:dev`, `admin:typecheck`, `admin:lint`, `admin:test`, `admin:build`, `admin:quality`.
- Produces `dist-admin/index.html` and `dist-admin/.vite/manifest.json`.
- Admin aliases expose only `@admin`, `@shared`, and `@entities`.

- [ ] **Step 1: Write the boundary checker in self-test RED mode**

The self-test feeds one allowed and one forbidden import. Forbidden roots include `@pages`, `@widgets`, `@app`, `@features`, and relative paths resolving to operational page/widget/app sources.

- [ ] **Step 2: Prove RED**

Run: `node scripts/check-admin-boundaries.mjs --self-test`

- [ ] **Step 3: Add exact package scripts**

```json
{
  "admin:dev": "vite --config vite.admin.config.ts",
  "admin:typecheck": "tsc -p tsconfig.admin.json --noEmit",
  "admin:lint": "eslint resources/admin --max-warnings 0 && node scripts/check-admin-boundaries.mjs",
  "admin:test": "vitest run --config vitest.admin.config.ts",
  "admin:build": "npm run admin:typecheck && npm run admin:lint && vite build --config vite.admin.config.ts",
  "admin:quality": "npm run admin:typecheck && npm run admin:lint && npm run admin:test && npm run admin:build"
}
```

Vite uses React and Tailwind, `root: resources/admin`, output `../../dist-admin`, a manifest, and dev proxies for `/api` and `/sanctum`.

- [ ] **Step 4: Add a minimal bootstrap**

`main.tsx` imports `../css/app.css`, initializes i18n/Sentry, mounts `AdminApp`, and removes the loader after React mounts.

- [ ] **Step 5: Verify independent GREEN**

Run:

```bash
node scripts/check-admin-boundaries.mjs --self-test
npm run admin:typecheck
npm run admin:lint
npm run admin:test
npm run admin:build
test -f dist-admin/index.html
test -f dist-admin/.vite/manifest.json
```

Expected: PASS without invoking the operational build.

---

### Task 4: Implement Admin-Owned Login, 2FA, Role Boundary, and Responsive Shell

**Files:**
- Create: `resources/admin/app/AdminProviders.tsx`
- Create: `resources/admin/app/AdminRouter.tsx`
- Create: `resources/admin/app/SuperAdminBoundary.tsx`
- Create: `resources/admin/pages/Login.tsx`
- Create: `resources/admin/pages/TwoFactorVerification.tsx`
- Create: `resources/admin/pages/Forbidden.tsx`
- Create: `resources/admin/pages/NotFound.tsx`
- Create: `resources/admin/widgets/admin-shell/AdminLayout.tsx`
- Create: `resources/admin/widgets/admin-shell/AdminHeader.tsx`
- Create: `resources/admin/widgets/admin-shell/AdminNavigation.tsx`
- Create: `resources/admin/test/admin-auth-routing.test.tsx`
- Create: `resources/admin/test/admin-shell.test.tsx`

**Interfaces:**
- Consumes existing auth, 2FA, locale, theme, settings, toast, and API clients.
- Produces admin-owned `/login`, `/verify-2fa`, forbidden, not-found, protected outlet, desktop sidebar, and mobile navigation.
- Successful auth returns to an allowlisted same-origin admin path or `/overview`, never `/dashboard`.

- [ ] **Step 1: Write real-tree RED tests**

Assert anonymous deep links reach login with a safe return target; authenticated non-super-admin sees Forbidden; super-admin sees the protected page; login and 2FA return to the saved admin route; mobile navigation exposes all route groups.

- [ ] **Step 2: Prove RED**

Run: `npm run admin:test -- resources/admin/test/admin-auth-routing.test.tsx resources/admin/test/admin-shell.test.tsx`

- [ ] **Step 3: Implement admin-owned auth and boundary**

Use the same backend login/2FA endpoints through the admin origin proxy. Gate with:

```tsx
if (!user?.roles.includes('super_admin')) {
  return <Forbidden />;
}
```

Do not use `manage_organization`. Validate return paths as same-origin relative paths before navigation.

- [ ] **Step 4: Implement one navigation data source**

Use one array for desktop and mobile rendering. Include overview, security, audit, organizations, access, roles, users, activity logs, scoped-role audit, scope types, departments, and incident types.

- [ ] **Step 5: Verify GREEN and RTL**

Run `npm run admin:test -- resources/admin/test/admin-auth-routing.test.tsx resources/admin/test/admin-shell.test.tsx` and `npm run admin:quality`.

---

### Task 5: Migrate Governance Pages and an Admin-Owned API Client

**Files:**
- Create: `resources/admin/api/adminApi.ts`
- Create: `resources/admin/model/admin.ts`
- Create: `resources/admin/pages/overview/Overview.tsx`
- Create: `resources/admin/pages/security-alerts/SecurityAlerts.tsx`
- Create: `resources/admin/pages/audit-recent/AuditRecent.tsx`
- Create: `resources/admin/test/governance-pages.test.tsx`
- Modify: `resources/admin/app/AdminRouter.tsx`

**Interfaces:**
- Consumes canonical `/api/admin/overview`, `security/alerts`, and `audit/recent`.
- Produces loading, empty, error, refresh, pagination, and minimized audit UI.

- [ ] **Step 1: Write RED render/contract tests**

Fixtures match real Laravel shapes. Cover 403/500, empty states, refresh, backend last-page navigation, and absence of actor email in DOM.

- [ ] **Step 2: Prove RED**

Run: `npm run admin:test -- resources/admin/test/governance-pages.test.tsx`

- [ ] **Step 3: Implement pages without operational imports**

All client paths begin `/admin/...` at the shared `/api` client. Copy behavior, not dependency on operational page files.

- [ ] **Step 4: Verify cross-layer GREEN**

Run:

```bash
npm run admin:test -- resources/admin/test/governance-pages.test.tsx
php artisan test tests/Feature/Api/Core/SuperAdminDashboardControllerTest.php tests/Feature/Api/Admin/AdminRouteContractTest.php
npm run admin:quality
```

---

### Task 6: Migrate Organizations, Roles, Access, Scope Types, and Audit Tools

**Files:**
- Create: `resources/admin/pages/organizations/**`
- Create: `resources/admin/pages/access/**`
- Create: `resources/admin/pages/roles/**`
- Create: `resources/admin/pages/scoped-roles/**`
- Create: `resources/admin/pages/activity-logs/**`
- Create: `resources/admin/pages/scope-types/**`
- Create: `resources/admin/test/organizations.test.tsx`
- Create: `resources/admin/test/access-governance.test.tsx`
- Create: `resources/admin/test/activity-audit.test.tsx`
- Modify: `resources/admin/api/adminApi.ts`
- Modify: `resources/admin/app/AdminRouter.tsx`

**Interfaces:**
- Consumes canonical organizations, roles, users summary, governance rules, scoped-role audit, activity logs, and scope-type APIs.
- Produces admin-owned technical-control pages with no operational page/widget imports.

- [ ] **Step 1: Write RED outcome and failure tests**

Cover organization create/update/delete confirmation and validation; role CRUD and reach mapping; access summary; governance save; activity filter/export authorization; scoped-role filtering/pagination; scope-type read-only list.

- [ ] **Step 2: Prove RED**

Run the three new admin test files.

- [ ] **Step 3: Migrate page logic to admin-owned files**

Use canonical admin adapters and preserve backend FormRequest field names exactly.

- [ ] **Step 4: Verify GREEN**

Run the admin tests, `admin:quality`, and targeted organization/role/governance/activity backend suites from Task 2.

---

### Task 7: Migrate Users, Departments, and Incident-Type Administration

**Files:**
- Create: `resources/admin/pages/users/**`
- Create: `resources/admin/pages/departments/**`
- Create: `resources/admin/pages/incident-types/**`
- Create: `resources/admin/config/links.ts`
- Create: `resources/admin/test/users.test.tsx`
- Create: `resources/admin/test/departments.test.tsx`
- Create: `resources/admin/test/incident-types.test.tsx`
- Modify: `resources/admin/api/adminApi.ts`
- Modify: `resources/admin/app/AdminRouter.tsx`

**Interfaces:**
- Consumes canonical user, department, and incident-type APIs.
- Consumes `VITE_OPERATIONAL_URL` only for project/task record links not owned by admin.
- Produces complete admin user routes and department list/create/view/edit routes plus incident settings.

- [ ] **Step 1: Write RED tests**

Cover list/create/view/edit, validation, delete confirmation, security status, cross-org failures, department hierarchy, incident category mutation, and governing-department selection.

- [ ] **Step 2: Prove RED**

Run the three new test files.

- [ ] **Step 3: Implement admin-owned variants**

Do not import operational user/HR/OVR pages. Build external operational links safely:

```ts
export function operationalUrl(path: string): string {
  const base = import.meta.env.VITE_OPERATIONAL_URL;
  if (!base) throw new Error('VITE_OPERATIONAL_URL is required');
  return new URL(path, base).toString();
}
```

- [ ] **Step 4: Verify GREEN and tenant isolation**

Run admin tests plus `UserIndexIsolationTest`, `UserShowIsolationTest`, `UserUpdateIsolationTest`, `DepartmentOrganizationScopeTest`, and incident-type controller tests.

---

### Task 8: Add Independent Docker/Nginx Deployment and Local Topology

**Files:**
- Create: `Dockerfile.admin`
- Create: `deploy/admin-nginx.conf.template`
- Modify: `docker-compose.yml`
- Modify: `.env.example`
- Modify: `config/sanctum.php`
- Modify: `config/session.php` only if tests prove the proxy topology needs a change
- Modify: `config/cors.php` only if local direct-origin development needs it
- Create: `tests/Architecture/AdminDeploymentContractTest.php`
- Modify: `.github/workflows/ci.yml`
- Modify: `.github/workflows/deploy.yml` only where the existing workflow can publish a second image without new credentials

**Interfaces:**
- Consumes `BACKEND_URL`, `VITE_OPERATIONAL_URL`, and `dist-admin`.
- Produces a static admin image with SPA fallback and same-origin backend proxy.

- [ ] **Step 1: Write deployment RED tests**

Assert admin build stage, `dist-admin` copy, `try_files $uri $uri/ /index.html`, restricted environment substitution, proxy headers, and no-cache index behavior.

- [ ] **Step 2: Prove RED**

Run: `php artisan test tests/Architecture/AdminDeploymentContractTest.php`

- [ ] **Step 3: Implement image and proxy**

Use restricted substitution so Nginx variables survive:

```sh
envsubst '$BACKEND_URL' < /etc/nginx/templates/admin.conf.template   > /etc/nginx/conf.d/default.conf
exec nginx -g 'daemon off;'
```

Proxy `/api/` and `/sanctum/` to Laravel with host, forwarded proto, client IP, and request ID headers.

- [ ] **Step 4: Add compose and CI gates**

Add an admin service/port and blocking `admin:quality` plus admin image build in CI.

- [ ] **Step 5: Verify GREEN**

Run:

```bash
php artisan test tests/Architecture/AdminDeploymentContractTest.php
npm run admin:build
docker build -f Dockerfile.admin -t erada-admin:test .
docker compose config --quiet
```

Expected: PASS.

---

### Task 9: Add Integration and Admin E2E Coverage

**Files:**
- Create: `playwright.admin.config.ts`
- Create: `e2e/admin/helpers/admin-auth.ts`
- Create: `e2e/admin/admin-auth.spec.ts`
- Create: `e2e/admin/admin-governance.spec.ts`
- Create: `e2e/admin/admin-organizations.spec.ts`
- Create: `e2e/admin/admin-access.spec.ts`
- Create: `e2e/admin/admin-users-departments.spec.ts`
- Modify: `package.json`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes real Laravel, PostgreSQL test data, and independent admin origin.
- Produces browser proof through canonical APIs to persistence or observable audit effects.

- [ ] **Step 1: Add a two-server Playwright config**

Use a dedicated admin base URL/port and start Laravel plus admin Vite/preview. Never point tests to the development database.

- [ ] **Step 2: Add auth/governance E2E**

Cover login, 2FA state routing, safe deep-link return, non-super-admin forbidden, direct reload, overview refresh, security/audit rendering, pagination, and compact navigation.

- [ ] **Step 3: Add mutation E2E**

Create a uniquely coded organization, verify persistence, update it, and delete it. Cover roles/governance, access summary, activity export, users, departments, and incident settings with deterministic cleanup.

- [ ] **Step 4: Run each spec to GREEN**

Run `npm run test:e2e:admin -- <spec>` for every file, then the full `npm run test:e2e:admin`.

---

### Task 10: Cut Over the Operational Application

**Files:**
- Modify: `resources/js/app.tsx`
- Modify: `resources/js/widgets/app-shell/ui/Sidebar.tsx`
- Modify: `resources/js/widgets/app-shell/ui/Header.tsx`
- Modify: `resources/js/widgets/app-shell/ui/AppLayout.tsx`
- Modify: `resources/js/shared/nasaq/app.tsx`
- Create or Modify: `resources/js/shared/config/urls.ts`
- Modify: `resources/js/__tests__/app/admin-shell-routing.test.tsx`
- Modify: relevant layout/NASAQ tests found by search

**Interfaces:**
- Consumes `VITE_ADMIN_URL`.
- Produces zero operational admin route ownership and external admin-origin links.

- [ ] **Step 1: Re-read dirty files before editing**

Run:

```bash
git status --short -- resources/js/app.tsx
git diff -- resources/js/app.tsx
rg -n "pages/admin|admin-shell|path=\"/admin|to=\"/admin|navigate\('/admin" resources/js
```

Stop on unmerged entries. Preserve all meeting changes.

- [ ] **Step 2: Write RED cutover tests**

Assert operational router does not render `/admin/*`, has no admin lazy imports, and eligible admin actions use an absolute configured admin URL.

- [ ] **Step 3: Prove RED**

Run routing/layout/NASAQ tests.

- [ ] **Step 4: Apply narrow cutover patches**

Remove only admin imports, lazy declarations, routes, and stale redirects. Replace internal admin navigation with external links. Do not reformat unrelated route blocks.

- [ ] **Step 5: Verify GREEN and zero ownership**

Run:

```bash
npm test -- resources/js/__tests__/app/admin-shell-routing.test.tsx
npm run typecheck
rg -n "pages/admin|admin-shell|path=\"/admin" resources/js/app.tsx
```

Expected: tests/typecheck PASS and ownership search empty.

---

### Task 11: Cleanup, Full Verification, and Independent R3 Audit

**Files:**
- Delete: `resources/js/pages/admin/**` after zero-consumer proof
- Delete: `resources/js/widgets/admin-shell/**` after zero-consumer proof
- Delete or modify: replaced operational admin tests
- Modify: `lang/ar.json`
- Modify: `lang/en.json`
- Modify: legacy API aliases only where searches prove they are admin-only and unused

**Interfaces:**
- Produces final independent graphs, translation parity, rollback evidence, and requirement-by-requirement completion proof.

- [ ] **Step 1: Prove files are dead before deletion**

Run:

```bash
rg -n "resources/js/pages/admin|@pages/admin|widgets/admin-shell|@widgets/admin-shell|/admin/" resources/js resources/admin tests e2e app
node scripts/check-admin-boundaries.mjs
```

Classify every remaining hit. Do not delete general user/directory APIs still used operationally.

- [ ] **Step 2: Remove only proven-dead compatibility files**

Keep canonical APIs and any general legacy endpoint with a real non-admin consumer.

- [ ] **Step 3: Verify Arabic/English key parity**

Compare complete key sets and run real-tree RTL tests using actual resource strings.

- [ ] **Step 4: Run the full matrix**

```bash
npm run admin:quality
npm run quality
npm run test:e2e:admin
php artisan test tests/Feature/Api/Core/SuperAdminDashboardControllerTest.php tests/Feature/Api/Admin/AdminRouteContractTest.php tests/Feature/Api/Core/OrganizationControllerTest.php tests/Feature/Api/RoleControllerTest.php tests/Feature/Core/GovernanceRulesApiTest.php tests/Feature/Api/Core/ScopeTypeControllerTest.php tests/Feature/Security/ActivityLogIsolationTest.php tests/Feature/Core/UserIndexIsolationTest.php tests/Feature/Core/UserShowIsolationTest.php tests/Feature/Core/UserUpdateIsolationTest.php tests/Feature/Api/DepartmentOrganizationScopeTest.php tests/Architecture/AdminDeploymentContractTest.php
./vendor/bin/pint --test
composer phpstan
docker build -f Dockerfile.admin -t erada-admin:verification .
docker compose config --quiet
```

For any known full-suite flake, rerun the exact failing class in isolation and preserve both receipts. A targeted failure is never dismissed as a flake.

- [ ] **Step 5: Audit every design Definition of Done item**

Map each item to an artifact or command: independent manifests; dedicated origin/deep links; forbidden-import gate; complete route inventory; no operational admin router; external admin link; strict super-admin gates; canonical API auth/validation/tenancy; repaired defects; frontend/backend/E2E gates; routing-only rollback.

- [ ] **Step 6: Request independent Terra verification**

The verifier is read-only, inspects actual diffs, reruns R3 gates, and returns PASS/FAIL with command output. It never fixes. Any FAIL returns to the owning task.

---

## Serial Execution and Ownership

Execute Tasks 1-11 serially. Shared configuration, module route files, CI, API adapters, and `resources/js/app.tsx` are integration points. A bounded worker owns one task at a time. Task 10 waits until the unrelated `app.tsx` work is stable and re-read. No worker runs global Git operations.

## Evidence Matrix

| Requirement | Primary evidence |
| --- | --- |
| Independent build | `admin:quality`, `admin:build`, separate manifest |
| Forbidden imports | boundary checker, TS aliases, repository search |
| Canonical API | route inventory and `AdminRouteContractTest` |
| Strict super-admin | real-tree frontend + backend 401/403 + E2E |
| Tenant isolation | canonical cross-org tests and reused isolation suites |
| Complete surfaces | admin router inventory + page tests + E2E |
| Operational cutover | router tests and zero ownership search |
| Deployment | admin image build, Nginx contract, deep-link E2E |
| Integration | Playwright mutations to persisted/observable outcomes |
| Rollback | no schema diff and routing-only rollback evidence |
