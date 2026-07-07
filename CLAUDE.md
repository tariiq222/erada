# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Erada PMO (`erada/pmo`) — a modular Laravel + React SPA for institutional project
management (projects, tasks, strategy / portfolios / programs / meetings, KPIs,
risks, OVR incidents, surveys, HR/departments). Arabic-first UI with RTL and en
fallback. Multi-tenant over `organizations` + `departments` with a capability-based
authorization engine on top of Spatie's flat permissions.

## Stack

| Layer | |
|---|---|
| Backend | Laravel 12 / PHP 8.4 (Docker) — requires `^8.2` |
| Frontend | React 19 + TypeScript 5.7 + Vite 7, Tailwind 4 via `@tailwindcss/vite` |
| DB | PostgreSQL 16 only — **SQLite is forbidden** (CI has a guard job; history of Laravel-default fallbacks breaking tests) |
| Cache / Queue / Session | Redis 7 |
| Auth | Laravel Sanctum 4 (token cookies for SPA) |
| RBAC | Spatie `laravel-permission` 6 (flat) **+ custom `AccessDecision` engine** for migrated modules |
| i18n | i18next + react-i18next; `lang/ar.json` (default), `lang/en.json` |
| Icons | `@tabler/icons-react` — single icon library, ESLint-enforced |
| Drag/Drop, Charts | `@dnd-kit/*`, `recharts` |
| Tests (BE) | PHPUnit 11 — `tests/{Unit,Feature,Architecture}` |
| Tests (FE) | Vitest 3 + `@testing-library/react` |
| E2E | Playwright 1.49 (`e2e/` dir, chromium only) |
| Static analysis | Larastan (PHPStan) level 2, baseline in `phpstan-baseline.neon` |
| Lint/format | Pint (PHP), ESLint 9 + `@typescript-eslint` (FE) |

## Common development commands

### First-time setup

```bash
bash scripts/dev-setup.sh          # starts postgres + postgres-test + redis, migrates, optionally seeds, resets demo passwords
docker compose up -d postgres postgres-test redis   # only the data services
composer dev                       # all-in-one: migrate --force, db:seed --force, vite + queue + pail + dev-serve via concurrently
```

`composer dev` and `php artisan serve` assume the DBs are reachable. Default
credentials: DB host `127.0.0.1` port `5432`, user `iradah` / pw `secret`, db
`iradah_pmo`. Dev login: `admin@admin.com` / `password`.

### Backend

```bash
composer test                              # config:clear + migrate:fresh --env=testing + artisan test
composer test:coverage / :coverage-html    # Same + coverage. CI's coverage step is best-effort
composer phpstan                           # ./vendor/bin/phpstan analyse --memory-limit=512M
composer ci                                # check-task-model + check-residual-hardening + test
./vendor/bin/pint                          # auto-fix
./vendor/bin/pint --test                   # dry-run, used in CI

# Targeted runs (cheaper + reliable for debugging):
php artisan test --filter=TestClassName           # single class
php artisan test tests/Feature/Projects/FooTest.php
php artisan test --filter='method_name'            # single method

# DB lifecycle
php artisan migrate:fresh --seed                 # dev wipe + seed
php artisan users:reset-demo-passwords           # reset demo accounts to 'password'
```

**Test DB trap.** `phpunit.xml` pins `DB_PORT=5433` (the dedicated `postgres-test`
container with `max_locks_per_transaction=512`, tmpfs-backed). Do NOT point tests at
the dev DB on `:5432` — `RefreshDatabase` will wipe `iradah_pmo`. If you forget, you
lose seeded demo data.

### Frontend

```bash
npm run dev                  # vite + HMR
npm run build                # typecheck + lint (max-warnings 1200) + vite build
npm run build:fast           # vite build only — used by Docker stage 1
npm run typecheck            # tsc --noEmit
npm run lint / lint:fix
npm run test                 # vitest run
npm run test:watch / test:coverage
npm run design:check         # design-token / component-shape checks (see scripts/design-check.mjs)
npm run quality              # typecheck + lint + design:check + test
npm run quality:ci           # adds test:e2e

# Targeted:
npm test -- resources/js/__tests__/authz/foo.test.tsx
npm test -- -t "name pattern"      # vitest -t
```

### E2E

```bash
npm run test:e2e                                              # full Playwright run
npm run test:e2e -- e2e/projects/project-form.spec.ts        # single spec
# Playwright auto-starts `php artisan serve --host=0.0.0.0 --port=8000` (reuseExistingServer locally).
```

### CI parity

`.github/workflows/ci.yml` runs three jobs:

1. `sqlite-guard` — fails the build if SQLite appears in env files or `phpunit.xml`.
2. `test` — full PHPUnit suite against `iradah_pmo_test`. **Two-stage:** first pass
   runs the full suite; on failure it parses `junit-1.xml` and re-runs only the
   failing classes in isolation (>25 fails = real regression, not flake). The full
   suite has a known non-deterministic flake set — each run fails a fresh subset,
   but every failing class passes in isolation.
3. `quality` — Pint `--test`, PHPStan, `npm run typecheck`, `npm run lint`,
   `npm run test`, Playwright install + `npm run build` + db seed +
   `npm run test:e2e` (currently `continue-on-error: true`), `composer audit`,
   `npm audit --audit-level=high`.

## Architecture

### Backend — modular monolith under `app/Modules/`

Eleven modules, each self-contained: `Core, HR, Meetings, OVR, Performance,
Projects, RiskManagement, Shared, Strategy, Surveys, Tasks`. Standard layout:

```
app/Modules/<Name>/
  Http/Controllers/
  Http/Requests/      # FormRequests — authorize() lives here, NOT in controllers
  Models/
  Policies/           # delegate to AccessDecision::can() once migrated
  Providers/<Name>ServiceProvider.php   # routes() + tags Capability providers
  Routes/api.php
  Services/ Enums/ Observers/ Scopes/ Repositories/ Support/ Traits/ Notifications/ (as needed)
```

`bootstrap/providers.php` loads `ModulesServiceProvider`, which discovers each
module's `*ServiceProvider` and auto-loads `app/Modules/<Name>/Routes/api.php`
under the `api` prefix and `api` middleware group. `routes/api.php` at the
project root contains only comments — every endpoint lives in its module.

**Authz: hybrid state.** Two coexisting systems:

- `App\Modules\Core\Authorization\AccessDecision::can(User, capability, ?Model): bool`
  is the unified engine (in-memory Capability map per module via tagged
  `engined_capability_providers`, scoped-role support, org-isolation fail-closed,
  super_admin short-circuit). Policies in `Tasks`, `Projects`, `Risks`, `OVR`,
  `Strategy`, `HR` delegate to it.
- Spatie `hasPermissionTo(...)` still flows in un-migrated modules (User,
  SystemSettings, Meeting, Recommendation, SurveyResponse, Comment, Attachment).

When adding a module-level capability, register a `CapabilityProvider` and tag it
in the module's `ServiceProvider::register()`.

**Multi-tenancy.** Tenancy is `organization_id` + hierarchical `departments`
(parent/child). Cross-org access fails closed. `project.organization_id` MUST
equal its governing department's org. Org-switch is `super_admin`-only.

**API surface.** Sanctum stateful + `EnsureEngineCapability` / form-request
`authorize()` per route. Sensitive mutations also pass `throttle:sensitive` +
`idempotency` middleware. Two live task routes exist (`/api/tasks` and
`api/unified-tasks`); check the consolidation state before adding new task
endpoints.

**Queues, events.** No dedicated `Events/Listeners/Jobs` directories under
`app/` — no module ever calls `->onQueue(...)`, so every queued job lands on the
**default queue**. The Dockerfile runs `numprocs=2` workers
(`php artisan queue:work --sleep=3 --tries=3 --max-time=3600`). Bump to 4 if
KPI import or data import steady-state backlog grows. There is **exactly one
Repository** in the entire codebase — `Tasks/Repositories/EloquentTaskRepository.php` —
every other module talks to Eloquent directly.

**Routes split.** Front controller in `routes/web.php` is intentionally minimal —
`/login` (Sanctum redirect), `/language/{locale}` (session toggle), `/s/{code}`
(public short survey URL), and the SPA catch-all `/{any}` excluding `api/*`.
Wrong-method API requests (`POST /api/foo/.../GET`) get Laravel's 404/405, not the
SPA HTML — this is intentional (`where('any', '^(?!api).*$')`).

### Frontend — Feature-Sliced Design (FSD)

Five layers under strict one-directional imports, enforced by `eslint-plugin-boundaries` (`error`, not `warn`):

```
resources/js/
  app/      # providers, router, ErrorBoundary — top of the dependency graph
  pages/    # routed screens (one per route, lazy-loaded in app.tsx)
  widgets/  # composite UI blocks (app-shell, admin-shell, project, task)
  features/ # user actions (task-create, auth, meetings, access-control, project-expenses, two-factor)
  entities/ # domain models (user, project, task, risk, …) — types + light UI
  shared/   # ui/ (DataTable, FilterBar, PageHeader, StatStrip — canonical components),
            #    api/ (axios client + per-resource modules), contexts/ (Auth/Org/Locale/Theme/System),
            #    nasaq/ (live sidebar builder), lib/ (sentry, utils), config/ (i18n), types/
```

Aliases in `tsconfig.json` and `vite.config.js`:
`@app @pages @widgets @features @entities @shared`. New code respects FSD; legacy
files (`resources/js/pages/*` not yet migrated) may still import freely — they're
the rolling cutover target. When you touch a legacy page, the place to consider
its layer home.

**State.** React Context (`AuthContext`, `OrganizationContext`, `LocaleContext`,
`ThemeContext`, `SystemSettingsContext`, `ToastContext`) + per-entity fetch
helpers under `entities/*/api/` + `shared/api/`. No Redux, no TanStack Query.
List state is local (URL search params). `ToastContext` value MUST be memoized —
an unstable value here causes infinite refetch + toast storms.

**API client.** `resources/js/shared/api/client.ts` is a custom `ApiClient`
class wrapping `fetch`, not axios:

- Sanctum **HttpOnly cookies** for auth (no `localStorage` tokens).
- In-flight request **deduplication** via a `pendingRequests` map.
- 401/419 **redirect-loop guard**.
- Lazy **CSRF cookie** refresh promise.
- Per-page-load `X-Request-Id` header for log correlation.

Legacy `axios ^1.17` is in `package.json` but unused by the core client. Each
entity exposes its own thin module (`entities/project/api`, `entities/task/api`,
…) that wraps the client with typed methods.

**Routing & auth.** All routes mounted inside `<AppLayout>` (or `<AdminLayout>`
for `/admin/*`) inside `<AuthProvider>`. Every protected route is wrapped in
`<RequirePermission config={{ permission: 'X' | permissions: [...] | allPermissions: [...] }} />`
or `<RequireAdmin>`. Capacities match backend Spatie permission strings
(`projects.view`, `ovr.view_all`, `meetings.record_decisions`, …). Look up the
exact strings in `App\Modules\<Domain>\Services\<Domain>CapabilityProvider` —
the FE mirrors them.

**Design system.** Tokens in `resources/css/design-tokens.css` are the single
source. Component primitives live in `shared/ui/` (`DataTable`, `PageHeader`,
`FilterBar`, `StatStrip`, `IconButton`, `StatusBadge`, etc.). Internal pages
must use these — duplicate table/header implementations are forbidden.
`@tabler/icons-react` is the **only** icon library; ESLint boundary plus
`scripts/rename-icons.mjs` keep it that way.

**i18n.** `lang/ar.json` is the master (Arabic-first); `lang/en.json` mirrors.
`resources/js/shared/config/i18n.ts` boots it. `lang/ar/`, `lang/en/` directories
hold PHP translations loaded by Laravel. Public short survey URLs are `/s/{code}`
(no auth, returns SPA shell, React reads the code).

**E2E.** Specs under `e2e/*.spec.ts` cover full user flows (project create/edit,
task PDCA completion, OVR incident lifecycle, surveys create/publish, risk
register, registration, cross-org isolation, departments hierarchy, meetings
recommendation defer/flow, etc.). Playwright config pins chromium only and
auto-starts the dev server.

## Things that will silently break you

- **Editing a migration.** Add a new migration; never modify an applied one.
  Several P1 bugs trace back to retro-edits.
- **`FormRequest::authorize()` is the authz seam.** Don't add `authorize()` to
  controllers — audits won't see it and IDOR lands. Check `Http/Requests/` first
  for an existing request; use it.
- **Pint array-form validation rules.** `'a|b'` gets rewritten to `['a','b']`
  on save; write new requests in array form to skip the reformat churn.
- **Full `php artisan test` flakes non-deterministically** — different tests
  fail each pass, all pass in isolation. Re-run the failing class alone (or
  `--filter`) before assuming a regression. CI encodes this as a re-run.
- **Pint + TypeScript errors as commit gating.** No Husky hook in this tree
  (`ls .husky/` is empty/missing) — failures surface only in CI. Fix them
  promptly; don't bundle lint fixes into the same commit as feature work.
- **FSD boundaries are `error`, not `warn.** Don't import `@entities` from
  `@features` or `@shared` from `@pages` — build will break.
- **Demo/seed accounts.** `composer dev` runs `db:seed --force`; passwords reset
  to `password`. Don't write tests that assume the original demo password.
- **`storage/framework/views/`** is watched by Vite — won't break, but whitelist
  it if you set up a custom IDE file watcher.
- **`storage/` and `.env`** are bind-mounted in Docker — permissions drift if
  you run `chown` from outside.
- **Session/stateful domain.** Test env pins `SANCTUM_STATEFUL_DOMAINS=localhost`.
  CI swaps to `.env.example`; do not rely on whatever `.env` happens to set.
