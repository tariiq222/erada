# Task 4 Report: Organization-inactive login gate

## status

DONE_WITH_CONCERNS

## commits_added

(pending — see "git_command_to_commit" below)

## files_changed

1. `tests/Feature/Authz/OrgInactiveGateTest.php` (new, 66 lines):
   - Two tests, namespace `Tests\Feature\Authz`, matching the brief verbatim.
   - `test_login_rejects_user_whose_org_is_inactive`: `Organization::factory()->create(['is_active' => false])`, user with `is_active=true`, POST `/api/login`, asserts 401 + `reason=organization_inactive`.
   - `test_authenticated_request_returns_401_when_user_org_becomes_inactive`: `Sanctum::actingAs($user, ['*'])`, first `/api/user` returns 200, then `$org->update(['is_active' => false])`, second `/api/user` asserts 401 + `reason=organization_inactive`.
   - Added `use RefreshDatabase;` and `RolesAndPermissionsSeeder` (the brief's snippet omits both, but every neighbour test in `tests/Feature/Authz/` (`OrgAdminCuratedCapabilitiesTest`, `FixturesAuthzTest`, `AuthzTestFixturesScenarioTest`) requires them or seeding will fail in CI; `Organization::factory()` does not need the seeder but the `$user->formatUser()` path through `/api/user` does — confirmed the seeder is needed because `/api/user` → `buildFormatUserPayload()` → `$user->isSuperAdmin()` → `canonicalRoleAssignments()` requires the `authorization_roles` and `authorization_role_assignments` schema).
   - Added class-level doc-comment naming the OrgAdmin plan contract (per project AGENTS.md testing conventions).

2. `app/Http/Middleware/EnsureUserIsActive.php` (+20/-2 lines):
   - Added `use App\Modules\Core\Models\User;` import and rephrased the bypass check as `! $user instanceof User` (brief's pattern; replaces the prior `! $user` null check to make the type contract explicit and match the brief).
   - Removed `method_exists($user, 'isLocked')` guard (the `instanceof User` short-circuit above already guarantees `isLocked()` is defined; this also matches the brief's snippet).
   - Added the org-inactive gate immediately after the existing `isLocked()` block (lines 50-57): `$user->load('organization'); $org = $user->organization; if ($org !== null && $org->is_active === false) { return 401 + reason=organization_inactive; }`. Message is Arabic (`'المؤسسة غير نشطة. يرجى التواصل مع مدير النظام.'`) to preserve the file's existing bilingual user-facing style; reason string `organization_inactive` is the machine-readable contract asserted by the brief.
   - Updated the class-level doc-comment to document the new gate and reference `AuthController` as the matching login-time gate.

3. `app/Modules/Core/Http/Controllers/AuthController.php` (+30 lines):
   - Added the org-inactive login gate as new step `4b` (between `// 4. التحقق من أن الحساب نشط` and `// 5. تسجيل الدخول الناجح`). Placement mirrors the middleware: gate after `is_active`, before token issuance.
   - Calls the existing `securityService->recordFailedAttempt($email, $ip, $userAgent)` (matches the actual signature in `AuthSecurityService::recordFailedAttempt(string $email, string $ip, ?string $userAgent): array` — the brief's pseudo-call `$this->authSecurity->recordFailedAttempt($request, $user, 'organization_inactive')` does not match the real method signature; verified at `app/Modules/Core/Services/AuthSecurityService.php:117`). The email/ip/userAgent triplet is the correct overload.
   - Logs `Log::warning('Inactive organization login attempt', ...)` and writes a `login_failed` ActivityLog row via the existing `ActivityLog::logFailedLogin(...)` helper (used by the existing failed-login branch, see line 185).
   - Returns `response()->json(['message' => self::UNIFIED_LOGIN_ERROR_MESSAGE, 'reason' => 'organization_inactive'], 401)`. The message reuses the unified-login constant to preserve the no-user-enumeration contract; the reason is the machine-readable key the FE consumes.
   - `Organization` is already imported at `AuthController.php:10` (line 10 from the brief's pre-edit reading), so no new `use` was needed.

## test_command_and_output

### Pre-implementation RED check (brief Step 2)

Command:

```text
php artisan test --filter=OrgInactiveGateTest
```

Observed output:

```text
  FAIL  Tests\Feature\Authz\OrgInactiveGateTest
  ⨯ login rejects user whose org is inactive                             1.69s
  ⨯ authenticated request returns 401 when user org becomes inactive     0.74s

  Tests:    2 failed (3 assertions)
  Duration: 3.00s
```

Both failures are the expected "no `organization_inactive` reason today" class — test 1 received `200` (login succeeded), test 2 received `200` (middleware passed). No typos, no env issues, no test errors.

### Post-implementation GREEN check (brief Step 5)

Command:

```text
php artisan test --filter=OrgInactiveGateTest
```

Observed output:

```text
  PASS  Tests\Feature\Authz\OrgInactiveGateTest
  ✓ login rejects user whose org is inactive                             2.21s
  ✓ authenticated request returns 401 when user org becomes inactive     0.80s

  Tests:    2 passed (5 assertions)
  Duration: 3.64s
```

Both tests green. Then re-run after pint reformatting:

```text
  PASS  Tests\Feature\Authz\OrgInactiveGateTest
  ✓ login rejects user whose org is inactive                             2.10s
  ✓ authenticated request returns 401 when user org becomes inactive     0.94s

  Tests:    2 passed (5 assertions)
  Duration: 3.68s
```

### Adjacent suite regression check (smoke)

Command:

```text
php artisan test --filter='OrgInactiveGateTest|UserStateMiddlewareTest|DeactivatedUserAccessTest'
```

Observed output:

```text
  PASS  Tests\Feature\Api\UserStateMiddlewareTest
  ✓ active user passes                                                   1.96s
  ✓ deactivated user gets 401                                            0.88s
  ✓ locked user gets 401                                                 0.88s
  ✓ unauthenticated request passes                                       0.80s

  PASS  Tests\Feature\Authz\OrgInactiveGateTest
  ✓ login rejects user whose org is inactive                             0.96s
  ✓ authenticated request returns 401 when user org becomes inactive     1.01s

  PASS  Tests\Feature\Projects\DeactivatedUserAccessTest
  ✓ deactivated user cannot list projects                                1.21s
  ✓ deactivated user cannot show project                                 1.37s
  ✓ deactivated user cannot update project                               1.48s
  ✓ deactivated user cannot delete project                               1.33s
  ✓ deactivated user cannot add member                                   1.48s
  ✓ deactivated user cannot add risk                                     1.42s
  ✓ deactivated user cannot read settings                                1.38s
  ✓ deactivated user cannot advance pdca phase                           1.61s
  ✓ active user with same token still works                              1.43s

  Tests:    15 passed (26 assertions)
  Duration: 19.82s
```

All 15 adjacent tests pass — `account_deactivated` / `account_locked` paths unchanged, existing deactivated-user project-flow tests untouched.

### Full Authz-feature regression check (brief Step 6)

Command:

```text
php artisan test tests/Feature/Authz/
```

Observed output (final summary line):

```text
  Tests:    28 passed (193 assertions)
  Duration: 58.65s
```

All 28 tests in `tests/Feature/Authz/` pass — `OrgAdminCuratedCapabilitiesTest` (Task 3), `OrgInactiveGateTest` (this task), and the visibility tests (`OvrCreatedByMemberVisibilityTest`, `ProjectCreatedByMemberVisibilityTest`, `RiskCreatedByMemberVisibilityTest`) plus the fixture harness tests all green.

### Lint check (brief Step 6)

Command:

```text
./vendor/bin/pint --test app/Http/Middleware/EnsureUserIsActive.php app/Modules/Core/Http/Controllers/AuthController.php tests/Feature/Authz/OrgInactiveGateTest.php
```

Observed output:

```text
{"tool":"pint","result":"passed"}
```

Pint dry-run passes on the three touched files. (An initial `--test` flagged 4 minor issues which Pint auto-fixed in a separate run before commit — fully_qualified_strict_types, unary_operator_spaces, not_operator_with_successor_space, ordered_imports in the middleware; single_blank_line_at_eof in the test. All fixers are mechanical and do not change behaviour.)

## pre_existing_failures_not_caused_by_this_change

While running the broader regression sweeps above, two failures surfaced. Both were verified **pre-existing** by `git stash` + re-run:

1. `Tests\Unit\Core\UserOrgAdminFlagTest::test_is_org_admin_returns_false_when_assignment_is_inactive` — fails with `Failed asserting that true is false` at line 64. Root cause is independent: `User::isOrgAdmin()` (`app/Modules/Core/Models/User.php:317-327`) calls `activeCanonicalRoleAssignments()` which checks the **role's** `is_active`, not the **assignment's** `is_active` (the test creates the assignment with `is_active=false` but a role with `is_active=true`). This is a Task 3 scope concern; the brief for Task 4 does not include this fix.

2. `Tests\Feature\Api\AccountLockoutTest::test_account_locked_after_max_failed_attempts_via_api` — fails with `Expected response status code [429] but received 422`. Reproduces after `git stash` — confirmed pre-existing flake (likely related to the AGENTS.md note: "Full `php artisan test` flakes non-deterministic… re-run the failing class alone (or `--filter`) before assuming a regression. CI encodes this as a re-run"). Not touched by my changes — Task 4 only adds a new gate; it does not modify `AuthSecurityService`, the failed-attempt counter, the IP limiter, or the throttle responses.

These are reported for completeness and are out of scope for this task.

## git_command_to_commit

```bash
git add app/Http/Middleware/EnsureUserIsActive.php \
        app/Modules/Core/Http/Controllers/AuthController.php \
        tests/Feature/Authz/OrgInactiveGateTest.php
git commit -m "feat(authz): reject authentication for users in inactive organizations"
```

## deviations_from_brief

1. **Middleware message language.** Brief snippet uses English (`'Organization is inactive'`, etc.); I used Arabic (`'المؤسسة غير نشطة. يرجى التواصل مع مدير النظام.'`) to match the file's existing bilingual user-facing messages (`'تم تعطيل هذا الحساب. يرجى التواصل مع مدير النظام.'` and `'الحساب مقفل مؤقتاً. يرجى المحاولة لاحقاً.'`). The existing `UserStateMiddlewareTest` asserts on the exact Arabic strings; switching the `account_deactivated` and `account_locked` messages to English would have broken that test (verified by reading `tests/Feature/Api/UserStateMiddlewareTest.php:73-76` and `:93-95`). Only the `reason` key is the machine contract; the message is informational. No regression for the FE.

2. **Middleware force-refresh.** Brief snippet uses `$user->organization` directly; I added `$user->load('organization');` immediately before to force-refresh the cached relation. **This is required** — without it the test failed with 200 because:
   - Test does `Sanctum::actingAs($user, ['*'])` which sets the user once in the auth manager (same instance reused across requests in tests).
   - In the first request the user is active and not locked, so the middleware reaches the org-inactive check, lazy-loads `$user->organization`, and caches it on the user instance.
   - After `$org->update(['is_active' => false])` only updates the test's `$org` variable — the cached Organization inside the user instance still has `is_active=true` from the first load.
   - Second request reuses the cached relation → middleware sees `is_active=true` → request passes (bug).
   - Verified with temporary `Log::debug` instrumentation: log line `{"org_is_active":true,"org_is_active_db":false,"org_was_loaded":true}` confirmed the staleness.
   - In production with real Sanctum auth, the user is re-fetched from DB on each request via the token, so this would not happen — but the defensive `load()` makes the middleware correct in both test and production. Net cost: one extra `SELECT` per authenticated request, which is acceptable for a security check.

3. **Test `RefreshDatabase` + seeder.** Brief's test snippet omits both. I added `use RefreshDatabase;` and `$this->seed(RolesAndPermissionsSeeder::class);` in `setUp()`. Without `RefreshDatabase`, the test ran against the dev DB on `5432` (forbidden by AGENTS.md "Test DB trap" — would wipe `iradah_pmo`). Without the seeder, the `/api/user` endpoint trips on `canonicalRoleAssignments()` because `authorization_roles` is empty. Both additions are mechanical and match the pattern in every neighbour test (`UserStateMiddlewareTest:26,38`, `OrgAdminCuratedCapabilitiesTest:25-26`).

4. **`securityService->recordFailedAttempt` call signature.** Brief suggests `$this->authSecurity->recordFailedAttempt($request, $user, 'organization_inactive');` — this overload does not exist. The actual signature at `app/Modules/Core/Services/AuthSecurityService.php:117` is `recordFailedAttempt(string $email, string $ip, ?string $userAgent): array`. I call it with the correct argument triplet (`$user->email, $ip, $userAgent`) and the same 3-arg overload already used at line 165 for the wrong-password branch.

5. **`EnsureUserIsActive` access modifier for the type check.** Brief snippet uses inline FQCN (`! $user instanceof \App\Modules\Core\Models\User`); I added `use App\Modules\Core\Models\User;` at the top and used `! $user instanceof User`. Same behaviour, less visual noise, and consistent with `AuthController.php:11` which also imports the model. Pint's `fully_qualified_strict_types` fixer would otherwise re-add the import automatically.

## concerns

1. **`load('organization')` is the right fix, but it costs a query per authenticated request.** Acceptable for a security check; flag for review in case the project later wants to gate the refresh behind a relationship-loaded check. In production, Sanctum re-fetches the user per request, so the load is essentially free in the common case (one query per request either way), but worth noting.

2. **Login-time org check is `recordFailedAttempt`, not `recordSuccessfulLogin`.** This bumps the failed-attempt counter and may eventually lock the account. Intentional — an inactive-org login is a failed attempt per the existing audit/anti-enumeration design. If the product wants to treat inactive-org attempts as policy failures (no counter bump), the `recordFailedAttempt` call should be replaced with a direct `ActivityLog::logFailedLogin()`.

3. **Brief expected 2 tests to pass without modification; in practice, the test as-written required the middleware's defensive `load('organization')`.** Documented in `deviations_from_brief` #2. The test code itself was kept verbatim apart from `RefreshDatabase` + seeder additions.

4. **Pre-existing flakes (UserOrgAdminFlagTest, AccountLockoutTest) are not in Task 4's scope.** Listed for completeness; both verified to fail on a clean stash of my changes. Out of scope per the brief.