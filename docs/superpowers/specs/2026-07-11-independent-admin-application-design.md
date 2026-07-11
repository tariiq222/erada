# Independent Admin Application Design

**Date:** 2026-07-11  
**Status:** Approved direction; implementation pending  
**System:** Erada PMO

## Summary

Extract the existing `/admin/*` control plane from the operational React SPA into an independently built and deployed React application. The admin application remains in the same repository and continues to use the existing Laravel backend, PostgreSQL database, Redis services, Sanctum session, authorization engine, translations, design system, and domain models.

The target is deployment and frontend-runtime independence, not a second backend or duplicated data platform. The operational application must no longer own admin routes after cutover. The admin application will be served from a dedicated origin such as `admin.erada.sa`, while its `/api` and `/sanctum` paths are reverse-proxied to the existing Laravel service to preserve same-origin Sanctum and CSRF behavior.

## Goals

- Build and test the admin frontend without building the operational frontend.
- Deploy the admin frontend independently on a dedicated admin origin.
- Remove `/admin/*` route ownership from the operational React router after cutover.
- Preserve one source of truth for users, organizations, permissions, audit logs, and tenancy.
- Make `super_admin` the explicit and consistent frontend and backend gate for the control plane.
- Consolidate control-plane APIs under `/api/admin/*` without a flag-day break.
- Preserve Arabic-first, RTL, English fallback, theme, and design-system behavior.
- Provide a reversible cutover that does not require a database migration or data rollback.

## Non-Goals

- A separate Laravel deployment or independently scaled admin backend.
- A second database, user directory, permission store, or audit store.
- A new authentication protocol or token storage mechanism.
- Redesigning operational modules unrelated to administration.
- Changing multi-tenant semantics or weakening organization isolation.
- Adding new administrative capabilities that do not already have real backend behavior.

## Current-State Problems Addressed

The extraction must not carry known control-plane defects into the new application:

1. The overview frontend requires a `registrations` object that the backend does not return.
2. `RequireAdmin` uses `manage_organization`, while governance APIs require the literal `super_admin` role.
3. The scope-type page links to `/admin/scope-types/new`, but the operational router has no matching route or form.
4. Recent-audit pagination calculates the last page from the number returned in the current page rather than a total.
5. The recent-audit response includes actor email although the frontend contract says it is omitted.
6. The desktop-only admin sidebar has no compact-screen navigation alternative.

These are extraction prerequisites, not optional cleanup.

## Repository and Build Boundaries

The repository remains a single Laravel project with two frontend entry surfaces:

```text
erada-platform/
├── resources/js/                  # Operational React application
├── resources/admin/               # Independent admin React application
│   ├── app/                       # Providers, router, bootstrap
│   ├── pages/                     # Admin-owned routed pages
│   ├── widgets/                   # Admin shell and navigation
│   └── main.tsx                   # Admin entry point
├── resources/views/               # Laravel shells where needed locally
├── vite.config.js                 # Operational build
├── vite.admin.config.ts           # Admin-only build
├── tsconfig.json                  # Operational TypeScript graph
├── tsconfig.admin.json            # Admin-only TypeScript graph
└── app/Modules/                   # Shared Laravel backend
```

The admin application may import only from:

- `resources/admin/**`
- explicitly approved shared UI, API, context, configuration, type, and utility modules
- entity API/model modules that do not import operational pages, widgets, or features

It must not import from operational `pages`, `widgets`, `app`, or operational-only `features`. ESLint boundaries and the admin TypeScript configuration will enforce this rule.

The build produces a separate manifest and output directory. Expected commands:

```text
npm run admin:dev
npm run admin:typecheck
npm run admin:lint
npm run admin:test
npm run admin:build
npm run admin:quality
```

An operational frontend failure must not prevent `admin:build` from compiling a valid admin graph. Shared-source failures may correctly fail both builds.

## Application Composition

The admin bootstrap owns its own provider tree:

1. Error boundary
2. Router
3. Authentication provider
4. Locale provider
5. Theme provider
6. System settings provider
7. Toast provider
8. Admin authorization boundary
9. Admin layout and routed page outlet

The admin application does not use `AppLayout`, the NASAQ operational sidebar, the operational organization switcher, or operational route definitions.

The initial route inventory is:

- `/` -> `/overview`
- `/overview`
- `/security/alerts`
- `/audit/recent`
- `/organizations`
- `/organizations/new`
- `/organizations/:id`
- `/access`
- `/roles`
- `/roles/new`
- `/roles/:id`
- `/roles/governing-departments`
- `/users`
- `/users/create`
- `/users/:id`
- `/users/:id/edit`
- `/activity-logs`
- `/scoped-roles/audit-logs`
- `/scope-types`
- `/departments`
- `/incident-types`

The dedicated origin owns these paths without an `/admin` prefix. Compatibility redirects from old `/admin/*` URLs may preserve bookmarks during the transition.

## Authentication and Authorization

Laravel remains the authentication authority. No access token is stored in JavaScript storage.

The preferred production topology is:

```text
Browser -> https://admin.erada.sa/*          -> static admin frontend
Browser -> https://admin.erada.sa/api/*      -> reverse proxy -> Laravel
Browser -> https://admin.erada.sa/sanctum/*  -> reverse proxy -> Laravel
```

This topology keeps browser requests same-origin and avoids introducing a second CORS-based authentication mode. Cookie domain, secure, SameSite, stateful-domain, trusted-proxy, and CSRF settings must be verified in the target environment without embedding credentials in frontend configuration.

The canonical control-plane admission rule is `super_admin`:

- The frontend bootstrap checks the authenticated user's role and renders a dedicated forbidden screen for authenticated non-super-admin users.
- The backend places every control-plane API behind `auth:sanctum` and `role:super_admin`.
- Backend authorization remains authoritative even when the frontend guard passes.
- `manage_organization` may continue to describe bounded administrative behavior elsewhere, but it does not admit a user to the global control plane.
- A 401 redirects to login while preserving the intended admin URL.
- A 403 never redirects to the operational dashboard; it renders the admin forbidden state.

Every mutating endpoint continues to use FormRequest authorization, validation, idempotency, appropriate throttling, organization invariants, and audit logging.

## API Boundary

The final public control-plane contract is grouped under `/api/admin/*`. Examples:

```text
GET    /api/admin/overview
GET    /api/admin/security/alerts
GET    /api/admin/audit/recent
GET    /api/admin/organizations
POST   /api/admin/organizations
GET    /api/admin/roles
POST   /api/admin/roles
GET    /api/admin/users
GET    /api/admin/activity-logs
GET    /api/admin/scope-types
```

Migration uses a compatibility window:

1. Add canonical admin-prefixed routes that point to the existing controllers and requests.
2. Move the new admin frontend to canonical routes.
3. Keep legacy routes temporarily for the operational frontend and external bookmarks.
4. Add contract tests proving canonical and legacy responses are equivalent where both remain live.
5. Remove legacy routes only after the operational router no longer consumes them and repository-wide usage searches are empty.

No controller logic is duplicated to create the namespace. Existing controllers may be reorganized only when their responsibility is genuinely control-plane-specific.

## Data Flow

At startup:

1. Load public system display settings needed for the shell.
2. Resolve the Sanctum session through the existing authenticated-user endpoint.
3. Wait for authentication resolution before rendering protected routes.
4. Reject authenticated non-super-admin users with the forbidden screen.
5. Load the requested page through an admin-owned entity API client.
6. Send mutations with CSRF, idempotency, and request-correlation headers.
7. Render explicit loading, empty, error, forbidden, and success states.

The admin app reads and writes the existing domain tables through Laravel. It does not access PostgreSQL directly and does not maintain a local copy of authorization or tenancy state.

## Error Handling

- **401:** Clear local authentication state and redirect to the shared login flow with a safe return URL.
- **403:** Render a stable admin forbidden page; do not create a redirect loop.
- **419:** Refresh the CSRF cookie once and retry only the eligible request once.
- **422:** Map backend validation errors to the owning form fields and retain user input.
- **429:** Preserve the server message and retry timing; do not auto-loop.
- **5xx/network:** Render a retryable error state with the page request ID for support correlation.
- **Unexpected render error:** Use an admin-owned error boundary that can return to the admin overview without entering the operational app.

## Responsive and Accessibility Requirements

- Provide mobile and compact-screen navigation; the sidebar may collapse but cannot disappear without an alternative.
- Preserve `dir`, `lang`, theme, focus management, skip navigation, keyboard access, and semantic headings.
- All controls use the existing Tabler icon policy and approved shared UI primitives.
- Arabic resources remain the master translation set and English must mirror all new admin-shell keys.

## Migration Phases

### Phase 0: Contract and Routing Repair

- Align the overview response and frontend type.
- Correct recent-audit pagination metadata and email minimization.
- Resolve the scope-type create/edit behavior without exposing phantom controls.
- Add explicit tests for each repaired contract.

### Phase 1: Independent Build Skeleton

- Add the admin entry point, Vite config, TypeScript graph, test setup, lint boundary, and build scripts.
- Reuse only approved shared modules.
- Render an authenticated empty admin shell on the dedicated route/origin locally.

### Phase 2: Governance Surface Migration

- Move overview, security alerts, recent audit, AdminLayout, AdminHeader, and responsive navigation.
- Add real-tree tests and backend contract tests.

### Phase 3: Administrative Surface Migration

- Move organizations, access hub, roles, users, activity logs, scoped-role audit, scope types, departments, and incident types.
- Refactor shared page bodies only where needed to remove operational page imports.
- Verify all create, update, delete, forbidden, and cross-organization paths.

### Phase 4: API Namespace Consolidation

- Add canonical `/api/admin/*` routes without duplicating behavior.
- Switch the independent frontend to canonical routes.
- Keep and test compatibility routes during the cutover window.

### Phase 5: Deployment and Cutover

- Add admin-origin hosting and reverse-proxy configuration.
- Validate cookie, CSRF, proxy, security-header, and cache behavior in the deployed topology.
- Change the operational application admin link to the dedicated origin.
- Remove operational `/admin/*` route ownership only after end-to-end proof.

### Phase 6: Compatibility Removal

- Remove unused legacy frontend routes and legacy API aliases after the compatibility window.
- Run repository-wide contract and route-usage checks before removal.

## Testing and Verification

### Frontend

- Admin-only TypeScript, ESLint, design, unit, and build commands.
- Real component-tree tests for authentication, forbidden access, shell navigation, RTL, loading, empty, error, and success states.
- Contract fixtures generated from or checked against real Laravel response shapes; hand-written mocks cannot be the sole contract proof.
- Boundary tests that fail when the admin app imports operational pages, widgets, app bootstrap, or operational-only features.

### Backend

- Authentication tests for 401.
- Role tests proving non-super-admin users receive 403.
- Success tests for every canonical endpoint.
- Validation, forbidden, idempotency, throttling, and audit-side-effect tests for mutations.
- Same-organization and cross-organization denial tests where tenant-scoped records are involved.
- Contract-equivalence tests while canonical and compatibility routes coexist.

### End to End

- Login and safe return to the original admin URL.
- Overview load and refresh.
- Security alert and recent-audit rendering.
- Organization create, update, and protected delete.
- Role and governance-rule workflows.
- User listing and access-summary inspection.
- Activity-log filtering and authorized export.
- Compact-screen navigation.
- Direct deep-link reload on the admin origin.
- Operational application link opens the independent admin origin.

### Independent Build Proof

- `admin:quality` and `admin:build` run without invoking the operational build.
- The operational quality/build gates continue to pass after admin routes are removed.
- Both manifests contain only their expected entry graphs.

## Cutover and Rollback

The extraction introduces no required schema migration. During the compatibility window:

- The old admin frontend remains deployable behind a configuration-controlled fallback link.
- Canonical and legacy API routes use the same controllers and database.
- A rollback changes routing/link configuration, not stored data.
- Old frontend route deletion occurs only after the independent deployment passes end-to-end verification.

If the admin deployment fails, traffic can be returned to the old admin surface while Laravel and the data model remain unchanged. Compatibility removal is a later, separately verified step.

## Definition of Done

The extraction is complete only when all of the following are proven:

1. The admin application has an independent entry point, TypeScript graph, test graph, build, and output manifest.
2. It is deployable on the dedicated admin origin with working same-origin `/api` and `/sanctum` proxying.
3. It imports no operational pages, widgets, app bootstrap, or operational-only features.
4. Every listed admin route is owned by the admin router and works on direct reload.
5. The operational React router no longer owns `/admin/*` routes.
6. The operational application links to the dedicated admin origin.
7. Frontend and backend consistently restrict the global control plane to `super_admin`.
8. Canonical `/api/admin/*` contracts are covered by success, authentication, authorization, validation, and tenant-boundary tests as applicable.
9. Overview, audit pagination, audit minimization, scope-type behavior, and responsive navigation defects are resolved.
10. Admin frontend quality/build, operational frontend quality/build, targeted Laravel suites, and admin end-to-end tests pass.
11. Cutover and rollback evidence demonstrates that reverting frontend routing does not require data rollback.

## Explicit Decisions

- One repository, two independently built frontend applications.
- One Laravel backend and one PostgreSQL database.
- Same-origin reverse proxy for admin API and Sanctum paths.
- Literal `super_admin` admission for the global control plane.
- Canonical `/api/admin/*` namespace with a temporary compatibility window.
- No database schema change required for extraction.
- No speculative controls or capabilities are added during migration.
