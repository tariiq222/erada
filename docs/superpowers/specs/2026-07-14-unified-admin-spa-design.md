# Erada PMO — Unified Admin SPA Design (Single SPA, Org‑Super Admin Role)

**Status:** Approved by owner on 2026-07-14
**Branch:** `feat/orgadmin-and-shipped-admin-spa`
**Supersedes:** `docs/superpowers/specs/2026-07-13-orgadmin-and-shipped-admin-spa-design.md` and `docs/superpowers/plans/2026-07-13-orgadmin-and-shipped-admin-spa.md` (see §13 — obsolete‑design notice).

## 1. Goal

Replace the obsolete `/org/*` sub‑SPA proposal with a single, shipped Admin SPA (`resources/admin/`) that serves two distinct actors from the **same route tree**, split server‑side by a new canonical `organization_super_admin` system role:

1. **PlatformSuperAdmin** — the existing `super_admin` role. Globally manages organizations, roles/capabilities, system settings/audits/security, and **exclusively** assigns/removes `organization_super_admin`.
2. **OrganizationSuperAdmin** — **new** canonical role, organization‑scoped, `is_admin_role=false`, **explicit administrative permissions only**, no automatic operational/project/task/KPI/risk/OVR permissions. Operates strictly inside `users.organization_id`; backend never trusts body/query/`X-Organization-Id` for scope.

## 2. Scope

**In scope (this design + its execution plan):**

- One Admin SPA. Same route tree, same `AdminLayout`, same navigation. Two filter dimensions on the nav, two flag dimensions on `/api/user`. **No** `/org/*` routes, **no** third dashboard.
- Canonical role `organization_super_admin`: organization scope, `is_admin_role=false`. Engine's generic admin shortcut (the `is_admin_role=true` flag used by `AccessDecision`) MUST NOT silently elevate this role.
- `/api/user` payload gains `is_organization_super_admin: bool` (additive, non‑breaking). Existing `is_super_admin` and `is_org_admin` keys stay.
- `User::isOrganizationSuperAdmin(): bool` predicate alongside the existing `isSuperAdmin()` / `isOrgAdmin()` helpers.
- A new, separate, organization‑level settings contract (replacing the prior "OrgAdmin can edit `/api/settings/system`" plan). System settings (`/api/settings/system`) stay `PlatformSuperAdmin`‑only.
- Server‑side allowlist of operational roles that an `organization_super_admin` may assign/revoke. The allowlist is hard‑coded server‑side (not a UI control), only contains operational role names, never `super_admin`, never `organization_super_admin`, never the existing `admin` role.
- Sensitive‑mutation contract: FormRequest `authorize()` + server‑side target validation + transactional + audit‑logged + idempotent + `throttle:sensitive`. Existing `IdempotencyKey` middleware, `audit.view` policy, and `throttle:admin` envelope are reused unchanged.
- Cross‑org isolation is enforced server‑side; `X-Organization-Id`, query, and body parameters are **never** authoritative for `organization_super_admin` scope. The header is permitted but ignored for non‑super actors; for `organization_super_admin` it MUST be ignored.

**Out of scope (this rollout):**

- Removing or repurposing the legacy `admin` role. It keeps the curated OrgAdmin capability set from the previous spec/plan and is **not repurposed** to mean OrganizationSuperAdmin. The eventual cutover plan is captured separately in §10 as phase 3 ("legacy admin cutover"), but no behavioral change to `admin` ships here.
- Removing the operational SPA's `/admin/*` block (`resources/js/app.tsx`) that remains in the rolled‑out commit history; this design introduces no new top‑level routes there.
- Bulk import/export, surveys respond, public short‑URL endpoints, public registration — none are org‑scoped by this design.
- Cluster‑tree audit views that remain `PlatformSuperAdmin` (via existing `Capability::CLUSTER_TREE_*`) — no new cluster surface.
- `AuthorizationRoleAssignment.is_active` schema migration (still an open Task 1 follow‑up; out of scope for this spec).

## 3. Architecture

One Admin SPA. Same router, same sidebar. Two boundary conditions are evaluated per request:

```
Browser
└── /admin SPA (resources/admin)
     └── AppLayout + AdminNavigation filtered by user.is_super_admin / is_organization_super_admin / is_org_admin
          ├── Overview, /security/alerts, /audit/recent   (is_super_admin)
          ├── /organizations, /access/governance, /roles, /scope-types, /activity-logs, /scoped-roles/audit-logs,
          │   /system/settings                              (is_super_admin — "System" group)
          ├── /departments, /incident-types                (super_admin OR OrgAdmin)
          ├── /users                                       (PlatformSuperAdmin OR OrganizationSuperAdmin — listing filtered server-side)
          └── (existing OrgAdmin pages from phase 1 remain unchanged)
```

Backend remains structurally unchanged. OrganizationSuperAdmin endpoints reuse the existing canonical routes (`/api/users`, `/api/hr/departments`, `/api/activity-logs`, etc.) gated by **new** capability strings scoped server‑side. The only new backend surface is the organization‑level settings contract (a new controller under `app/Modules/Core/Http/Controllers/OrganizationSettingsController.php` with a new resource).

The AccessDecision engine already:

1. short‑circuits super_admin at the top;
2. enforces org isolation via `sameOrganization()` against `$user->organization_id`;
3. requires the capability, not the role, on policy/form‑request seams.

Therefore adding a new system role with `is_admin_role=false` causes neither an automatic org‑shortcut nor an automatic cluster widening — both at‑risk behaviours called out in the prior spec are structurally avoided.

## 4. Actors & Roles

| Role | Server‑side name | Scope | `is_admin_role` | `is_system` | Notes |
|---|---|---|---|---|---|
| PlatformSuperAdmin | `super_admin` | `all` | `true` | `true` | Global. Existing behavior preserved; exclusively assigns/removes `organization_super_admin` (via `core.assign_roles`). |
| OrganizationSuperAdmin | `organization_super_admin` | `organization` | `false` | `true` | **New**. Server‑derived org from `users.organization_id`. Cannot self‑modify own org. Cannot modify other `super_admin` or `organization_super_admin` users. |
| OrgAdmin (legacy curated) | `admin` | `organization` | `true` | `false` | **Behaviorally unchanged.** Keeps the curated OrgAdmin capability set from the previous design. Cutover is phase 3. |
| Operational roles | `viewer`, `manager`, `member`, `project_manager`, `project_member`, `project_viewer`, `dept_manager`, `dept_member`, `pmo_manager`, `pmo_coordinator`, `quality_manager`, `…` | varies | `false` | varies | Untouched. OrganizationalSuperAdmin may *assign/revoke a fixed allowlist* of these to same‑org users (allowlist enforced server‑side). |

Notes on `is_admin_role=false` for `organization_super_admin`:

- `AccessDecision::whyCan()`'s admin‑shortcut path checks `$assignment->role?->is_admin_role === true` (`AccessDecision.php:~1170`). Setting this flag to `false` is what guarantees OrganizationSuperAdmin cannot ride the legacy "admin shortcut" and pick up cluster tree, `core.view_organizations`, or module‑write capabilities it did not explicitly opt into.
- The role still receives its full curated capability list at seed time. No code path grants implicit capabilities.

## 5. Actor / Permission Matrix (post‑rollout)

`✔` = allowed, `✖` = denied, `·` = applies for own org only, `‡` = super_admin only via separate contract.

| Capability | PlatformSuperAdmin | OrganizationSuperAdmin | OrgAdmin (legacy `admin`) | Operational |
|---|:---:|:---:|:---:|:---:|
| `users.view` / `users.create` / `users.edit` | ✔ | ✔ (own org) | ✔ (own org) | ✖ |
| `users.delete` | ✔ | ✔ (own org; targets only non‑admin/non‑super actors) | ✖ | ✖ |
| `users.activate` / `users.deactivate` (new) | ✔ | ✔ (own org, except self, super_admins, organization_super_admins) | ✖ | ✖ |
| `users.unlock` | ✔ | ✔ (own org) | ✔ (own org) | ✖ |
| `departments.view / create / edit / delete` | ✔ | ✔ (own org) | ✔ (own org) | scope‑bounded |
| `activity‑logs.view` | ✔ (all orgs) | ✔ (own org only) | ✔ (own org only) | scope‑bounded |
| `activity‑logs.export` | ✔ (all orgs, super_admin only) ‡ | ✖ | ✖ | ✖ |
| `system.settings.view / edit` (existing `settings.system`) | ✔ ‡ | ✖ | ✖ | ✖ |
| `organization.settings.view / edit` (new contract) | ✔ | ✔ (own org, view/update only) | ✖ | ✖ |
| `roles.view` | ✔ | ✔ | ✔ | ✖ |
| `roles.assign` (operational allowlist only — server‑side) | ✔ (any role) | ✔ (operational allowlist to same‑org users only) | ✖ | ✖ |
| `core.assign_roles` (any role, including admin/org‑super) | ✔ ‡ | ✖ | ✖ | ✖ |
| `core.view_organizations` / `core.cluster_tree.*` | ✔ ‡ | ✖ | ✖ | ✖ |
| Operational modules (projects, tasks, kpis, risks, ovr, …) | ✔ | ✖ | ✖ | scope‑bounded |

**Hard prohibitions for OrganizationSuperAdmin** (server‑enforced, fail‑closed):

- Modifying any user whose active canonical assignment is to `super_admin` or `organization_super_admin`.
- Modifying its own user record (e.g. changing own `organization_id`, locking self, transferring self).
- Modifying its own organization record (`/api/organizations/{self.orgId}` on `PUT/PATCH`).
- Receiving or sending cluster‑tree widening on `audit.view` or `audit.export` — the `AccessDecision::canonicalClusterTreeGrant()` rescue branch already requires `scope_type='all'` (`AccessDecision.php:~317`), so this is structurally safe and must be confirmed in tests.
- Using `X-Organization-Id`, query, or body to widen the org filter; the server resolver maps all of these to `users.organization_id`.

## 6. Backend Authority / Data Flow

### Login + scope resolution (no change to login flow; only `/api/user` payload gains one flag)

1. `POST /api/login` issues Sanctum token + cookie as today.
2. `GET /api/user` returns `{ id, name, email, organization_id, is_super_admin, is_org_admin, is_organization_super_admin, capabilities, access, role_assignments, organizations }`.
3. The SPA `OrganizationContext` ALWAYS locks `X-Organization-Id` to `users.organization_id` for both `OrganizationSuperAdmin` and `OrgAdmin`. `PlatformSuperAdmin` may set it from the org picker (existing behavior).

### Cross‑org attempt by OrganizationSuperAdmin

1. Org‑Super opens a deep link `/api/users/{otherOrgUser}`.
2. `UserController::show` runs `UserPolicy::view` → `AccessDecision::can('users.view', $target)`. The `sameOrganization()` gate returns `false`; the engine denies.
3. Response is 403 with `{message, code:'forbidden', required_capability:'users.view', request_id}` (existing exception renderer at `bootstrap/app.php`).
4. SPA renders `<AccessDenied />` with `required_capability` text.

### Self‑modification / admin‑on‑admin attempt

1. Org‑Super calls `POST /api/users/{self.id}/unlock` (own id) or `{anotherSuperAdmin.id}` (Platform/Org‑Super).
2. `FormRequest::authorize()` rejects on the target validation rule (`target_user_id !== $request->user()->id` AND `target_user_id !== any(super_admin or organization_super_admin in actor's org)`).
3. Returns 422 (target not allowed), 403 (capability lacking), or 404 (target not in actor org) depending on the surface. Audit logs the rejection via `ActivityLog::logAuthzDenial()`.

### Role assignment via operational allowlist

1. Org‑Super calls `POST /api/roles/assign` with `{ user_id, role_name }`.
2. `FormRequest::authorize()` returns `true` only if `$user->can('roles.assign')`, `$target->organization_id === $user->organization_id`, `$target` is not `super_admin`/`organization_super_admin`, and `$role_name` is in the hard‑coded server allowlist (rejected roles include `super_admin`, `organization_super_admin`, and the legacy curated `admin`).
3. Capability pivot inside `roles.assign` uses existing engine seeding; the allowlist is a controller‑level gate, not a capability string.

### Org‑level settings (new contract)

1. `GET /api/organizations/{org}/settings` returns the organization‑scoped settings payload (locale overrides, branding overrides, notification templates) — read‑only surface available to both `PlatformSuperAdmin` and `OrganizationSuperAdmin`, scope‑bounded to `actor.organization_id === org` for Org‑Super.
2. `PUT /api/organizations/{org}/settings` accepts the writable subset; `FormRequest::authorize()` requires the new `organization.settings.edit` capability and rejects with 422 if `org !== actor.organization_id`. Transactional + audit + idempotent + `throttle:sensitive`.

## 7. API Contract Requirements (planning‑level — no prescriptive URL shapes beyond reuse)

The implementation plan is responsible for the exact path shapes, status codes, and JSON keys. This section specifies what the contract MUST satisfy:

### Reused, unchanged

- `POST /api/login`, `GET /api/user`, `POST /api/logout` — contract unchanged; `/api/user` payload gains one additive key.
- `GET/POST/PUT/PATCH/DELETE /api/users[/{user}]`, `POST /api/users/{user}/unlock`, `POST /api/users/{user}/security` — paths unchanged; **server adds new self/non‑admin target rejection rules**.
- `GET/POST/PUT /api/hr/departments[/{dept}]`, `DELETE /api/hr/departments/{dept}` — paths unchanged; engine org‑isolation continues to apply.
- `GET /api/activity-logs`, `GET /api/activity-logs/{id}`, `GET /api/activity-logs/export` — paths unchanged; cluster widening remains super_admin only.
- `GET /api/settings/system`, `PUT /api/settings/system` — paths unchanged; new rule: `OrganizationSuperAdmin` gets 403, NOT a payload slice. Front-end must not offer platform settings to Org‑Super.
- Existing `is_super_admin` / `is_org_admin` payload keys — preserved untouched.

### New

- `is_organization_super_admin: bool` in the `/api/user` payload — additive, non‑breaking (boolean, default `false`).
- `organization.settings.view` / `organization.settings.edit` capability constants — added to `app/Modules/Core/Authorization/Capability.php` next to existing settings constants.
- `users.activate` / `users.deactivate` capability constants — required to express the matrix; granted to `super_admin` (all) and `organization_super_admin` (same‑org allowlist).
- A new OrganizationSettings contract — read/write of organization‑scoped settings. Implementation plan chooses the exact URL shape; SPA may call it via `adminApi.organizationSettings.get/update` mirroring existing `adminApi.settings.*` patterns. The shape and field names are intentionally deferred to the plan.

### Sensitive mutation contract (all Org‑Super writes)

For every mutation Org‑Super can perform:

- `FormRequest::authorize()` carries the authz seam (auditable, not duplicated in controllers).
- Server‑side target validation: actor `organization_id`, target `organization_id`, target role assignment actives, target role name allowlist (for `roles.assign`).
- Transactional (DB::transaction) — no half‑states across pivot rows.
- Audit‑logged via the existing `ActivityLog` helper, including actor id, target id, action name, and an `organization_super_admin` provenance tag.
- Idempotent: `X-Idempotency-Key` already minted by the SPA on every POST/PUT/PATCH/DELETE; backend reuses `app/Http/Middleware/IdempotencyKey.php`.
- `throttle:sensitive` (or the existing `throttle:admin`) envelope; mutations against user records / role assignments use `throttle:admin` (existing), mutations against org‑level settings use `throttle:sensitive` (new throttle bucket name may be added by the plan).
- 5xx → render `<ServerError />` with `request_id`; 409 → no retries; 422 → per‑field error messages; 429 → render `<RateLimited />` with `retry_after`. The contract matches the matrix the previous spec established and is preserved here.

## 8. Frontend Routing / Navigation

One SPA. No new route group. No `/org/*`.

### Boundary filter

Replace the existing `isAdminNavItemVisible()` predicate so it consults three booleans:

- `is_super_admin === true` — System group.
- `is_super_admin === true` OR `is_org_admin === true` — controls group items already permitted to OrgAdmin (e.g. departments, incident types).
- `is_super_admin === true` OR `is_organization_super_admin === true` — items that the new role can reach (users, departments, org settings). The exact predicate ordering is `group === 'system'` and a new group `org-super` (overlap on users/departments/org‑settings).
- Items reachable ONLY by `super_admin` (e.g. system settings, organizations CRUD, access/governance, role catalog, scoped‑role audit, cluster audits) stay System-only and are hidden from Org‑Super's nav.

### Page‑level behavior

- `/users` page already exists and renders for any authenticated super_admin. The page-level guard stays as `RequirePermission capability="users.view"` for both `super_admin` and `organization_super_admin`. The data loader calls `adminApi.users.list()`; the server scope‑narrows the result. On a 403 from any cross‑org reference, the page renders `<AccessDenied />`.
- `/organizations/{org}/settings` page — new. Permission guard: `RequirePermission capability="organization.settings.view"`. Org‑Super sees only its own org (UI hides the org picker for non‑super actors).
- `/overview`, `/security/alerts`, `/audit/recent`, `/access`, `/access/governance`, `/roles`, `/activity-logs`, `/scoped-roles/audit-logs`, `/scope-types`, `/system/settings` — super_admin‑only. UI never renders them in the nav for Org‑Super (the predicate hides them).
- `/departments`, `/incident-types` — already reachable by both super_admin and OrgAdmin (existing). Org‑Super inherits OrgAdmin visibility by design — both roles are org‑scoped and use the same Allowlist‑gated server routes.
- `/departments/{id}/edit` and similar forms — page guard `RequirePermission capability="departments.edit"`; Org‑Super's `users.edit`‑equivalents follow the existing pattern.
- A single reload or hard navigation to a super_admin‑only URL by Org‑Super returns `<Forbidden />` (the boundary predicate renders it inline; no flash of super‑only navigation chrome).

### Login UX

- `adminApi.users.list()` and other list routes pass `user.organization_id` from `/api/user` payload, never from `X-Organization-Id` the SPA sets by hand for non‑super actors. The existing `OrganizationContext` already pins `X-Organization-Id` to `users.organization_id` for non‑super; ensure this is enforced for the new flag too.

## 9. Migrations & Compatibility

This section specifies the data‑plane shape; exact migration filenames are the implementation plan's responsibility.

- **Seed change (canonical role catalog):** add a new entry to `database/seeders/RolesAndPermissionsSeeder.php`'s role catalog:

  ```text
  organization_super_admin ⇒ scope_type='organization', is_admin_role=false, is_system=true,
  capabilities = [users.view, users.create, users.edit, users.delete, users.activate,
                  users.deactivate, users.unlock, departments.view, departments.create,
                  departments.edit, departments.delete, organization.settings.view,
                  organization.settings.edit, audit.view, roles.view, roles.assign]
  ```

  Note: **no** `projects.*`, **no** `tasks.*`, **no** `kpis.*`, **no** `risks.*`, **no** `ovr.*`, **no** `core.cluster_tree.*`, **no** `core.view_organizations`, **no** `core.assign_roles`, **no** `audit.export`. The `roles.assign` capability is the bare capability; the controller‑level allowlist enforces which `AuthorizationRole.name` values are assignable.

- **Migrations:** No schema migration is strictly required for the catalog change (idempotent `updateOrCreate`); however, follow the established pattern of a "role catalog sync" migration (same approach as `2026_07_12_000018_role_catalog_sync_obsolete_pivots`) so prod reflects the curated list and obsolete pivots are swept on first deploy. No new columns are introduced.

- **Capability constants:** additive constants `USERS_ACTIVATE`, `USERS_DEACTIVATE`, `ORGANIZATION_SETTINGS_VIEW`, `ORGANIZATION_SETTINGS_EDIT`. Existing constants untouched.

- **Backward compatibility:**

  - `/api/user` payload is additive — clients reading `is_super_admin` / `is_org_admin` keep working. The new key is consumed when present.
  - Existing `admin` role pivot set is unchanged. Existing demo/e2e accounts (`AdminE2ETestSeeder`) keep working; phase 3 cutover migrates them.
  - OrgAdmin role still works for users assigned to `admin` with `scope_type='organization'`. No SPA‑visible behavior change for those users.
  - `RequirePermission` guards only need the new flag for nav predicates; existing pages keep their `RequirePermission capability="..."` semantics.

- **Forward compatibility:** A future phase 3 plan (legacy admin cutover) may migrate `admin`‑assigned users to `organization_super_admin` once organization‑level settings and the allowlist gate ship; that plan is a separate docs‑only entry.

## 10. Rollout & Rollback

Phases match the approved sequence.

### Phase 0 — Backend contracts (this spec is approved; the implementation plan tracks the tasks)

- Seed `organization_super_admin`; capability constants; capability provider.
- `User::isOrganizationSuperAdmin()` predicate.
- `/api/user` payload additive key.
- `OrganizationSettings` controller + FormRequest.
- Org‑level `users.activate` / `users.deactivate` controller routes (or amend existing user routes).
- Server‑side target validation on existing controllers (self / super_admin / organization_super_admin rejection).
- Server‑side operational‑allowlist gate in `RoleController::assignToUser` (or relevant method).
- `X-Organization-Id` ignore‑when‑non‑super regression test.

Rollback: revert the seed entry + drop the new controller. Existing `admin` role and routes are unaffected. The capability‑additions are additive so other role pivots remain valid.

### Phase 1 — Unified UI

- Keep the existing `SuperAdminBoundary` strictly `is_super_admin` for system‑only routes (organizations CRUD, access/governance, role catalog, scoped‑role audit, cluster views, platform settings, system audit). Add a parallel route guard (e.g. `RequireOrgSuperOrSuper`) for the routes that OrganizationSuperAdmin can reach (users, departments, org‑level settings). System‑only routes MUST NOT silently widen to admit Org‑Super.
- Update `AdminNavigation` predicates to filter by the three flags. Reuse existing items; no new nav items.
- Add org‑level settings form (reuses the existing `adminApi` patterns and shared form primitives).

Rollback: keep the predicate changes reverted in a single follow‑up commit; the backend remains consistent.

### Phase 2 — Verification

- Focused PHP / TS / E2E runs as in §11 below.
- CI parity: `npm run quality:ci`, `composer ci`. Document any pre‑existing flakes (consistent with AGENTS.md flake policy) without changing the contract.

### Phase 3 — Legacy `admin` cutover (deferred, separate spec)

- Migrate any demo accounts / E2E fixtures away from the curated `admin` role.
- Decide whether to delete the `admin` role or retain it as a documentation backstop.

## 11. Test Acceptance Criteria

### Backend (PHPUnit)

- `User::isOrganizationSuperAdmin()` returns `true` only when the active canonical assignment is to a role named `organization_super_admin` with `scope_type='organization'`, `is_admin_role=false`, `is_system=true`.
- `GET /api/user` payload exposes `is_organization_super_admin: bool` alongside the existing flags. Existing `is_super_admin` / `is_org_admin` keys are still present and still match their prior semantics.
- `OrganizationSuperAdmin` cannot mutate any user whose active assignment is `super_admin` or `organization_super_admin` (target validation 422 / 403 / 404 — surface decides).
- `OrganizationSuperAdmin` cannot mutate `users.organization_id` on its own user record (target validation 422).
- `OrganizationSuperAdmin` cannot `PUT /api/organizations/{self.orgId}` (403/404 — surface decides).
- `OrganizationSuperAdmin` cannot `PUT /api/settings/system` (403 with `code:'forbidden', required_capability:'settings.edit'`).
- `OrganizationSuperAdmin` cannot assign `super_admin`, `organization_super_admin`, or `admin` via `roles.assign` (403 / 422 — surface decides; `core.assign_roles` remains super‑admin only).
- `OrganizationSuperAdmin` cluster‑tree widening on `audit.view` / `audit.export` returns no extra rows (engine regression). The existing pre‑condition `scope_type='all'` in `canonicalClusterTreeGrant()` continues to hold.
- `X-Organization-Id` header is ignored for `OrganizationSuperAdmin`: setting it to any value other than `users.organization_id` does not broaden the result set for `OrganizationSuperAdmin` (engine regression).
- All Org‑Super mutations are transactional, audit‑logged, idempotent, and throttled (verified by `ActivityLog` row + `IdempotencyKey` cache hit on second request + 429 on burst).
- The legacy `admin` role pivot set is unchanged from the curated OrgAdmin set: `tests/Feature/Authz/OrgAdminCuratedCapabilitiesTest` still passes.

### Frontend (Vitest)

- New predicate `isAdminNavItemVisible` covers the three flags and the existing four groups (`governance`, `controls`, `system`, `org`) plus a new group label `org-super` for the user/org‑settings surfaces.
- New page guard `RequireOrgSuperOrSuper` (or equivalent) renders `<Forbidden />` for users without `is_organization_super_admin` AND `is_super_admin`.
- Admin SPA nav for a mock `is_organization_super_admin=true` user hides `/overview`, `/security/alerts`, `/audit/recent`, `/access`, `/access/governance`, `/roles`, `/activity-logs`, `/scoped-roles/audit-logs`, `/scope-types`, `/system/settings`, `/organizations*`. It shows `/users`, `/departments`, `/incident-types`, `/organizations/{ownOrgId}/settings`.
- `/api/user` payload adapter in `useAuth` accepts and surfaces the new flag without breaking existing mocks.
- Contract test for new `adminApi.organizationSettings.get/update` matches the canonical backend URL chosen in the implementation plan.

### E2E (Playwright)

- An E2E super_admin can manage organizations, role catalog, system settings, audit, cluster views.
- An E2E OrganizationSuperAdmin (new seed fixture) can list/create/edit/unlock/activate/deactivate same‑org users, cannot delete PlatformSuperAdmins or other OrganizationSuperAdmins, cannot edit own org, can CRUD departments, can view/update org‑level settings, can view own org activity/assignment audit, can assign/revoke only the operational allowlist.
- Cross‑org OrganizationSuperAdmin attempts return the same 403/404/422 envelope as existing admin isolation tests.

### Type & lint parity

- `npm run typecheck`, `npm run lint`, `npm run test`, `composer test`, `composer phpstan`, `./vendor/bin/pint --test` all remain green for the touched files. Pre‑existing test files may flake per AGENTS.md policy; treat any flake the same way CI does (re‑run the failing class alone before assuming a regression).

## 12. Audit & Error Handling

### Audit

- Every Org‑Super mutation writes an `ActivityLog` row with `action` matching the operation, `actor_id`, `target_type`, `target_id`, and an `organization_super_admin` provenance tag. Sensitive mutations (role assignment, user activate/deactivate, org settings update) include a `before`/`after` diff in `properties`.
- Authz denials (self‑modification, cross‑admin target, operational allowlist reject, missing form‑request seam) emit `ActivityLog::logAuthzDenial()` with `actor_id`, `requested_capability`, `target_type`, `target_id`, and `route`.
- Login‑time cluster / org failure paths are preserved unchanged. The existing `organization_inactive` reason from the previous plan is preserved.

### Error envelope

Preserve the existing renderer at `bootstrap/app.php`:

- 401 → existing redirect to `/login` (no change).
- 403 → `{message, code:'forbidden', required_capability, request_id}`. SPA renders `<AccessDenied />`.
- 404 → SPA renders `<NotFound />` with a back link.
- 409 → SPA renders `<ConflictError />`, no retry.
- 422 → field‑level errors; `<FormFieldError />` surfaces them.
- 429 → SPA renders `<RateLimited />` with `retry_after` countdown.
- 5xx → SPA renders `<ServerError />` with `request_id` for support correlation.

## 13. Obsolete‑design Notice

**The previous OrgAdmin design is hereby superseded.** The following artifacts describe an obsolete approach and must NOT be used as a source of truth going forward:

- `docs/superpowers/specs/2026-07-13-orgadmin-and-shipped-admin-spa-design.md` — proposed an `/org/*` sub‑SPA and reused the curated `admin` role as OrgAdmin. This spec supersedes it.
- `docs/superpowers/plans/2026-07-13-orgadmin-and-shipped-admin-spa.md` — implementation plan built on the obsolete design above. Phase 10 (OrgAdminBoundary + `/org/*` pages), Phase 11 (adminApi retargeting applied to the obsolete `/org/*` shape), Phase 13 (operational route guards — keep), Phase 6 (cluster rescue regression — keep), Phase 7 (AdminE2ETestSeeder — keep), Phase 8 (AdminRouteContract — keep) are still applicable where they don't depend on the obsolete `admin` role and `/org/*` shape. Phases tied to `/org/*` or to the curated `admin` role are obsolete.

**Specifically retracted from the obsolete design:**

- The notion that `admin` (curated OrgAdmin) is the org‑scoped admin boundary role. It is now an internal curated role; the **boundary role** for OrganizationSuperAdmin is the new `organization_super_admin`.
- The notion that org settings live in `/api/settings/system`. They do not. System settings stay Platform‑super‑admin only. Org settings are a new contract.
- The notion that OrganizationSuperAdmin is gated by `OrgAdminBoundary` with `/org/*` routes. There is no `OrgAdminBoundary` and no `/org/*` route group. The boundary is a single predicate on a single SPA, filtered through `/api/user` payload flags.
- The notion of integrating organization‑level "OrgAdmin can edit `/api/settings/system`". Replaced by the new `organization.settings.view` / `organization.settings.edit` capability pair and a new controller.

The previous plan rows 1–5 (User flags, payload, admin curation, org‑inactive gate, dept hardening), 9 (SuperAdminBoundary predicate), and 13 (operational guards) remain valid and progress as already committed on branch `feat/orgadmin-and-shipped-admin-spa`. Phases 6, 7, 8, 11 (adminApi retargeting), 12 (idempotency), 14 (dead‑code removal), 15 (dev login isolation), 16 (org context cleanup), P1 (login/2FA contract), and the legacy admin cutover implementation patterns are reusable where independent of the obsolete `OrgAdminBoundary` / `/org/*` framing.

The previous plan's "OrgAdmin‑Curated Capabilities" remains true for the **legacy** `admin` role, and the new `organization_super_admin` adds the explicit additional capabilities spelled out in §5 above.

## 14. Open Items (explicit, not TBDs)

These are deliverables owned by the implementation plan, not unfinished design:

- Exact URL and JSON shape of the new `organization.settings.*` contract — owned by the implementation plan.
- Exact URL/JSON for `users.activate` / `users.deactivate` if a separate route is preferred over patching `users.update` — owned by the implementation plan.
- Whether to introduce a new `throttle:sensitive` bucket or reuse `throttle:admin` for org‑settings writes — owned by the implementation plan.
- Locale string keys for new nav labels and form copy — owned by the implementation plan and the i18n keys ledger.

All other design decisions in this spec are final; implementation will not re‑open them without a new spec revision.
