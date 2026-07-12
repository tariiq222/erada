# Task 9 — Independent Admin E2E + Integration Coverage Report

## Status

- **Implementation:** code complete and locally type/lint-clean; `git status` confined to owned files (no Meetings touch).
- **Local real-browser run:** **blocked by sandbox policy**. The runtime does not permit loopback socket operations (`Operation not permitted`) so `php artisan serve` cannot bind :8000 / :4174 and `psql` cannot reach 5432/5433. Every Playwright spec and every PHPUnit test that opens a DB connection fails at setup with the exact same sandbox error. CI provides the only environment that can run the suite end-to-end.
- **Proxy contract + architecture + Pint:** PASS locally (see evidence below).

The remaining work is "prove green on a runner with DB + browser" which is gated on hardware the sandbox cannot provide, not on missing code.

## Owned files (final)

| Path | Status | Purpose |
| --- | --- | --- |
| `package.json` | modified | `test:e2e:admin:setup` default DB flipped to dedicated `iradah_pmo_admin_e2e_test` (still `5433` locally, overrideable via `ADMIN_E2E_DB_*`). |
| `vite.admin.config.ts` | unchanged | Confirmed byte-for-byte unchanged — proxy contract test still passes. |
| `playwright.admin.config.ts` | modified | Added hermetic env so the spawned Laravel server does **not** depend on Redis/mail/maintenance-driver: `CACHE_STORE=file`, `CACHE_PREFIX=iradah_admin_e2e`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `BCRYPT_ROUNDS=4`, `APP_MAINTENANCE_DRIVER=file`. Default DB kept on the dedicated `iradah_pmo_admin_e2e_test`; CI overrides via `ADMIN_E2E_DB_PORT/ADMIN_E2E_DB_DATABASE` to port 5432 + `iradah_pmo_test`. |
| `database/seeders/AdminE2ETestSeeder.php` | unchanged | Reused the existing deterministic fixture — no second seeder was needed. |
| `tests/Architecture/AdminViteDevProxyContractTest.php` | modified (pint auto-fix only) | Replaced `return pathname;` (double quotes) with `'return pathname;'` so Pint --test passes; assertions preserved. |
| `e2e/admin/helpers/admin-auth.ts` | modified | Added `loginAsTwoFactorUser`, `completeTwoFactorChallenge`, `loginAsTwoFactorSuperAdmin`, RFC 6238 TOTP generator (`generateTotp`) using `JBSWY3DPEHPK3PXP` from the existing seeder (no mocks). Also added `uniqueSuffix` for collision-free ephemeral codes. |
| `e2e/admin/admin-auth.spec.ts` | unchanged | Existing 5 authentication tests retained. |
| `e2e/admin/admin-governance.spec.ts` | unchanged | Existing 3 governance tests retained. |
| `e2e/admin/admin-organizations.spec.ts` | rewritten | One unique test (create → reload → update → reload → delete) with **deterministic cleanup** via `try/finally` UI delete using the live SPA cookies (no X-Skip-Csrf, no API mocking). |
| `e2e/admin/admin-access.spec.ts` | new | 4 real-browser tests: access summary open, activity-logs action filter, governance rules department dropdown, incident-type create/delete with deterministic UI cleanup. |
| `e2e/admin/admin-users-departments.spec.ts` | new | 5 real-browser tests: users list seeded email visible, departments list seeded dept visible, unique department create/persist/delete, unique user create/persist/delete, and cross-organization isolation asserted through the page's authenticated request context (`page.context().request`). |
| `.github/workflows/ci.yml` | modified | New **`admin-e2e`** job — blocking (no `continue-on-error`); spins up `postgres:5432 (iradah_pmo_test)` + `redis`; `composer install`, `npm ci`, `npx playwright install --with-deps chromium`, `migrate:fresh --seed` plus `AdminE2ETestSeeder`, then `npm run test:e2e:admin` with `ADMIN_E2E_DB_PORT=5432`, `ADMIN_E2E_DB_DATABASE=iradah_pmo_test`. |
| `.superpowers/sdd/task-9-report.md` | this file | Evidence, RED/GREEN receipts, file coverage, remaining issues. |

## RED → GREEN evidence

### Pass (locally, no DB needed)

```text
$ ./vendor/bin/pint --test
{"tool":"pint","result":"passed"}

$ npm run admin:typecheck
> admin:typecheck
> tsc -p tsconfig.admin.json --noEmit
(no diagnostics — exit 0)

$ npm run typecheck
> typecheck
> tsc --noEmit
(no diagnostics — exit 0)

$ npm run admin:lint
> admin:lint
> eslint resources/admin --max-warnings 0 && node scripts/check-admin-boundaries.mjs
admin-boundaries — PASS (47 files scanned)

$ php artisan test tests/Architecture/AdminViteDevProxyContractTest.php
   PASS  Tests\Architecture\AdminViteDevProxyContractTest
  ✓ admin vite bypasses only source module extensions and proxies real… 0.04s
  Tests:    1 passed (7 assertions)

$ php artisan test tests/Architecture/
   PASS  Tests\Architecture\AdminDeploymentContractTest           (6 tests)
   PASS  Tests\Architecture\AdminViteDevProxyContractTest        (1 test)
   PASS  Tests\Architecture\ScopeAwareCoverageTest              (2 tests)
  Tests:    9 passed (89 assertions)

$ npx playwright test --config=playwright.admin.config.ts --list
Listing tests:
  [admin-chromium] › admin-access.spec.ts             › 4 tests
  [admin-chromium] › admin-auth.spec.ts               › 5 tests
  [admin-chromium] › admin-governance.spec.ts         › 3 tests
  [admin-chromium] › admin-organizations.spec.ts      › 1 test
  [admin-chromium] › admin-users-departments.spec.ts  › 5 tests
Total: 18 tests in 5 files
```

### Live sandbox limits (the open work)

Every command that requires loopback network or DB hits the sandbox policy:

```text
$ php artisan serve --host=127.0.0.1 --port=8000   # Playwright webServer
  Failed to listen on 127.0.0.1:8000 (reason: Operation not permitted)

$ nc -zv 127.0.0.1 5433
nc: connectx to 127.0.0.1 port 5433 (tcp) failed: Operation not permitted

$ composer test
In Connection.php line 838:
  SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed:
  Operation not permitted

$ npx playwright test --config=playwright.admin.config.ts e2e/admin/admin-organizations.spec.ts
Error: Process from config.webServer was not able to start. Exit code: 1
```

The blocker is identical for both ports 5432 and 5433, which means the sandbox is **not** letting us bind loopback TCP at all, regardless of which Admin E2E DB we target. CI is therefore the only available runner for end-to-end RED/GREEN receipts.

### CI evidence — what CI will execute (and will not)

The new `admin-e2e` job is appended verbatim:

```yaml
  admin-e2e:
    name: 🎭 Admin E2E (Playwright)
    runs-on: ubuntu-latest
    needs: sqlite-guard

    services:
      postgres:
        image: postgres:16-alpine
        env: { POSTGRES_DB: iradah_pmo_test, POSTGRES_USER: iradah, POSTGRES_PASSWORD: secret }
        ports: [5432:5432]
        options: >-
          --health-cmd pg_isready
          --health-interval 10s --health-timeout 5s --health-retries 5
      redis: { image: redis:7-alpine, ports: [6379:6379] }

    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2 (php 8.4 + pdo_pgsql/redis)
      - composer install --prefer-dist --no-progress
      - actions/setup-node@v4 (node 20, cache npm)
      - npm ci
      - npx playwright install --with-deps chromium
      - cp .env.testing .env 2>/dev/null || cp .env.example .env
      - php artisan key:generate
      - DB_PORT=5432 DB_DATABASE=iradah_pmo_test php artisan migrate:fresh --seed --force
      - DB_PORT=5432 php artisan db:seed --class=AdminE2ETestSeeder --force
      - env:
          DB_PORT=5432 DB_DATABASE=iradah_pmo_test
          ADMIN_E2E_DB_PORT='5432'
          ADMIN_E2E_DB_DATABASE='iradah_pmo_test'
        run: npm run test:e2e:admin    # blocking — no continue-on-error
```

The brief says "wire blocking CI Admin E2E with CI database port 5432"; that is exactly the job above. The existing `continue-on-error: true` on the operational `npm run test:e2e` step at the end of the legacy `quality` job is left untouched (operational E2E is not in this task's owned scope).

The `AdminDeploymentContractTest::test_ci_and_deploy_workflows_block_on_admin_quality_and_image_build` already asserts:

- `run: npm run admin:quality` is present in `ci.yml` without `continue-on-error: true` — still satisfied.
- `run: docker build -f Dockerfile.admin -t erada-admin:ci .` is present — still satisfied.
- `testJob` of `deploy.yml` contains both — still satisfied.
- No `continue-on-error:` in `testJob` — still satisfied.

## Deterministic cleanup contract

Every spec that mutates real rows runs the SPEC cleanup inside `try/finally`:

- `admin-organizations.spec.ts`: any failure between CREATE and DELETE triggers an inline UI delete via the same page (cookie + CSRF intact).
- `admin-access.spec.ts`: same pattern for incident types.
- `admin-users-departments.spec.ts`: same pattern for both departments and users.

No `X-Skip-Csrf`. No requests fixture that bypasses Sanctum. No DB wipe. Leftover rows from a failed assertion are cleaned up before the next test runs against the dedicated DB.

## File coverage of the brief

| Brief requirement | Where it lives |
| --- | --- |
| Organization create/persist/update/delete with deterministic cleanup | `e2e/admin/admin-organizations.spec.ts` (single test, try/finally UI delete) |
| Auth E2E (login, 2FA challenge, deep-link, non-super forbidden, deep-link safety, mobile nav) | `e2e/admin/admin-auth.spec.ts` (unchanged — existing 5 tests) |
| Governance E2E (overview/security/audit render + refresh + pagination) | `e2e/admin/admin-governance.spec.ts` (unchanged — existing 3 tests) |
| Access E2E (canonical reads + scoped summary) | `e2e/admin/admin-access.spec.ts` (4 tests, including access summary open) |
| Activity E2E (canonical read + filter) | `e2e/admin/admin-access.spec.ts` › "renders the seeded activity logs" |
| Incident E2E (canonical read + persisted mutation + cleanup) | `e2e/admin/admin-access.spec.ts` › "creates and deterministically deletes" |
| Users E2E (canonical read + persisted mutation + cleanup) | `e2e/admin/admin-users-departments.spec.ts` › users subset (3 tests) |
| Departments E2E (canonical read + persisted mutation + cleanup) | `e2e/admin/admin-users-departments.spec.ts` › departments subset (2 tests) |
| Cross-org tenant isolation | `e2e/admin/admin-users-departments.spec.ts` › "cross-organization tenant isolation" |
| Dedicated Playwright config booting Laravel against PostgreSQL test DB and admin preview on separate port | `playwright.admin.config.ts` (modified: hermetic env + correct default DB) |
| `test:e2e:admin` script | `package.json` (modified: dedicated DB default) |
| CI blocks on Admin E2E | `.github/workflows/ci.yml` › new `admin-e2e` job, no `continue-on-error` |
| Vite proxy contract | `tests/Architecture/AdminViteDevProxyContractTest.php` (passes locally) |
| 2FA TOTP helper (no mocks for behavior under test) | `e2e/admin/helpers/admin-auth.ts` `generateTotp` uses `JBSWY3DPEHPK3PXP` (encrypted by the seeder) |
| Determinism / no mocks | Cleanup uses the same UI the test exercises; the existing `AdminE2ETestSeeder` is reused, no new seeder was needed |
| DB isolation | Local default = `iradah_pmo_admin_e2e_test` on `5433`; CI override = `iradah_pmo_test` on `5432`. Development DB on `5432/iradah_pmo` is **never** targetable from Admin E2E because the seeder/setup script explicitly sets `DB_PORT` and `DB_DATABASE`. |
| Never touch development port 5432 locally | Default `DB_PORT=5433` and `DB_DATABASE=iradah_pmo_admin_e2e_test`; CI override uses port 5432 only because CI's services block is a separate runtime that explicitly defines `postgres:5432` only as a test service |

## Cleanup proof & DB-isolation proof

The sandbox does not let us open loopback TCP, so a literal `psql` audit cannot run here. Below is the strongest proxy available: the orchestration that PROVES the development DB on `5432/iradah_pmo` cannot be wiped by Admin E2E.

```text
$ grep -n "DB_PORT\|DB_DATABASE\|5432" playwright.admin.config.ts playwright.admin.config.ts
playwright.admin.config.ts:5:const adminDatabasePort = process.env.ADMIN_E2E_DB_PORT ?? '5433';
playwright.admin.config.ts:6:const adminDatabaseName = process.env.ADMIN_E2E_DB_DATABASE ?? 'iradah_pmo_admin_e2e_test';

$ grep -n "DB_PORT\|DB_DATABASE\|5432" package.json
package.json:23:        "test:e2e:admin:setup": "...DB_PORT=${ADMIN_E2E_DB_PORT:-5433} DB_DATABASE=${ADMIN_E2E_DB_DATABASE:-iradah_pmo_admin_e2e_test}...",

$ grep -n "ADMIN_E2E_DB_PORT\|ADMIN_E2E_DB_DATABASE" .github/workflows/ci.yml
.github/workflows/ci.yml: … ADMIN_E2E_DB_PORT: '5432'
                           ADMIN_E2E_DB_DATABASE: 'iradah_pmo_test'
(only inside the dedicated `admin-e2e` job — CI services section also scopes
postgres:5432 → POSTGRES_DB=iradah_pmo_test, never iradah_pmo)
```

Two guards in series:

1. **Local default:** `DB_PORT=5433` + `DB_DATABASE=iradah_pmo_admin_e2e_test`. The development DB at `127.0.0.1:5432/iradah_pmo` is unreachable from the Admin E2E webServer command, so a `migrate:fresh` cannot point at it without explicitly overriding `ADMIN_E2E_DB_*`.
2. **CI override:** the only path where port 5432 is reachable is the dedicated `admin-e2e` job, where the postgres service is configured with `POSTGRES_DB=iradah_pmo_test` (no `iradah_pmo` is ever created in that container). So even CI cannot reach the development DB from this job.

## Remaining issues

1. **Real-browser GREEN receipt in this sandbox.** All five Playwright specs and the existing PHPUnit tests need to run on a runner with loopback sockets and a PostgreSQL service. The CI job is wired but a green CI run has not been observed yet — only the structure is verified.
2. **`vitest` write to `node_modules/.vite-temp/`.** A pre-existing sandbox quirk: vitest's config bundle step cannot write into `.vite-temp/` under the symlinked `node_modules`. Unrelated to Task 9 — observed in `npm run admin:test` both in the main checkout and the worktree. CI runners do not hit this because they have a real `node_modules`. If local repro is required outside this sandbox, `npm run admin:test` works as designed (verified during Task 6 era).
3. **`AdminDeploymentContractTest::test_ci_and_deploy_workflows_block_on_admin_quality_and_image_build`** asserts there is no `continue-on-error: true` on `npm run admin:quality` and no `continue-on-error:` in the deploy `testJob`. The new `admin-e2e` job was added under `needs: sqlite-guard`, **not** under `needs: quality`, by design — it does not gate admin:quality or build:ci. Both pre-existing assertions remain satisfied.
4. **Live `migrate:fresh --seed --force` in the CI `admin-e2e` job** runs both `DatabaseSeeder` and the explicit `AdminE2ETestSeeder`. In a CI-only test service the `DatabaseSeeder` runs first (its seed users have `organization_id = Organization::query()->orderBy('id')->value('id')` and may resolve to `null` because no orgs exist yet). The AdminE2ETestSeeder creates two orgs afterwards and assigns its own users to the primary org. The seeder order intentionally does NOT depend on `DatabaseSeeder`'s users; the test fixtures only ever log in as `admin-e2e@example.test` which AdminE2ETestSeeder owns. Confirmed by code review; cannot be observed live in this sandbox.
