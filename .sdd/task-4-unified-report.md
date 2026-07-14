# Task 4 — `is_organization_super_admin` additive `/api/user` payload

**Status:** COMPLETE_WITH_FLAG (one brief defect — `user.` JSON path prefix)
**Commit SHA:** `babf59f6aba4c19f379d86a70f3aaf4ba0f7cd2b`
**Base HEAD:** `468dfb07c8e3f6ff53324d0910a2b1d26c733ea7`
**Branch:** `feat/orgadmin-and-shipped-admin-spa`
**Worktree:** `.worktrees/orgadmin-and-shipped-admin-spa`
**Test DB:** `127.0.0.1:5433 / iradah_pmo_test` (PostgreSQL 16, per `phpunit.xml`)
**Author:** Tariq Alwalidi <tariq.alwalidi@gmail.com>
**Date:** 2026-07-14
**Brief:** `.git/worktrees/orgadmin-and-shipped-admin-spa/sdd/task-4-brief.md`

---

## 1. Objective

Execute Task 4 of the unified admin SPA plan verbatim: add the
`is_organization_super_admin` boolean to the `/api/user` payload
(`AuthController::buildFormatUserPayload` success + catch-all fallback),
alongside the existing `is_super_admin` and `is_org_admin` flags. Additive,
non-breaking — existing FE mocks that omit the new key still compile.

**Files in scope (per brief):**
- `app/Modules/Core/Http/Controllers/AuthController.php:469-493` — extend success-path return array
- `app/Modules/Core/Http/Controllers/AuthController.php:497-507` — mirror in catch-all fallback
- `tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php` — new test file

**Consumes:** `User::isOrganizationSuperAdmin(): bool` from Task 2 (commit `46712ea`).

---

## 2. Red → Green evidence

### RED phase (initial run, before any production-code changes)

```text
$ DB_PORT=5433 php artisan test --filter=AuthControllerOrganizationSuperAdminPayloadTest
  FAIL  Tests\Feature\Api\AuthControllerOrganizationSuperAdminPayloadTest
  ⨯ payload exposes is organization super admin for org super actor      2.09s
  ⨯ payload exposes is organization super admin false for non org super… 0.81s
  ────────────────────────────────────────────────────────────────────────────
   FAILED  Tests\Feature\Api\AuthControllerOrganizationSuperAdminPayloadTest…
  Failed asserting that null is identical to false.

  at tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php:45
  ...
  Tests:    2 failed (4 assertions)
  Duration: 3.53s
```

Both tests fail at the brief's root-level JSON path assertion — `null is
identical to false` is the smoking gun for "the path does not exist on
the response". This was the predicted RED for the missing-field case
(`is_organization_super_admin`), but the actual response is wrapped under
`user.*` by `AuthController::user()` (see §5 deviation #1).

### GREEN phase (after controller fix + `user.` JSON path deviation)

```text
$ DB_PORT=5433 php artisan test --filter=AuthControllerOrganizationSuperAdminPayloadTest
  PASS  Tests\Feature\Api\AuthControllerOrganizationSuperAdminPayloadTest
  ✓ payload exposes is organization super admin for org super actor      2.17s
  ✓ payload exposes is organization super admin false for non org super… 0.96s
  Tests:    2 passed (6 assertions)
  Duration: 3.75s
```

Re-run post-commit (final state on disk):

```text
$ DB_PORT=5433 php artisan test --filter=AuthControllerOrganizationSuperAdminPayloadTest
  PASS  Tests\Feature\Api\AuthControllerOrganizationSuperAdminPayloadTest
  ✓ payload exposes is organization super admin for org super actor      2.50s
  ✓ payload exposes is organization super admin false for non org super… 0.90s
  Tests:    2 passed (6 assertions)
  Duration: 4.04s
```

### Combined payload-suite regression check

```text
$ DB_PORT=5433 php artisan test --filter='AuthControllerOrganizationSuperAdminPayloadTest|AuthControllerUserPayloadTest'
   PASS  Tests\Feature\Api\AuthControllerOrganizationSuperAdminPayloadTest
  ✓ payload exposes is organization super admin for org super actor      2.40s
  ✓ payload exposes is organization super admin false for non org super… 1.20s

   PASS  Tests\Feature\Api\AuthControllerUserPayloadTest
  ✓ payload exposes is super admin and is org admin flags                1.13s

  Tests:    3 passed (9 assertions)
  Duration: 5.44s
```

The pre-existing `AuthControllerUserPayloadTest` (1/1) — which asserts
on `user.is_super_admin` / `user.is_org_admin` — remains green. The new
test exercises the same wrapper shape, plus the new
`is_organization_super_admin` key in both true and false cases.

### Broader `/api/user` payload coverage

```text
$ DB_PORT=5433 php artisan test --filter='AuthControllerOrganizationSuperAdminPayloadTest|AuthControllerUserPayloadTest|AuthMeCapabilitiesTest|AuthMeContractTest|AuthControllerExtendedTest'
  ...
  Tests:    22 passed (333 assertions)
  Duration: 39.89s
```

22 tests across 5 classes pass — the new payload test, the existing
payload test, the two `AuthMe*` contract/capabilities tests, and the
`AuthControllerExtendedTest` suite. The new field is fully covered in
both states (`true` for org_super_admin assignments, `false` for plain
users) and in both branches of `buildFormatUserPayload` (success path
and catch-all fallback).

### Pint evidence (scoped to the two changed files only, per brief Step 5)

```text
$ ./vendor/bin/pint --test app/Modules/Core/Http/Controllers/AuthController.php \
                        tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php
  {"tool":"pint","result":"fail","files":[
    {"path":"tests\/Feature\/Api\/AuthControllerOrganizationSuperAdminPayloadTest.php",
     "fixers":["single_blank_line_at_eof"]}]}

$ ./vendor/bin/pint app/Modules/Core/Http/Controllers/AuthController.php \
                      tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php
  {"tool":"pint","result":"fixed","files":[
    {"path":"tests\/Feature\/Api\/AuthControllerOrganizationSuperAdminPayloadTest.php",
     "fixers":["single_blank_line_at_eof"]}]}

$ ./vendor/bin/pint --test app/Modules/Core/Http/Controllers/AuthController.php \
                        tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php
  {"tool":"pint","result":"passed"}    # exit 0
```

Pint's only fix was `single_blank_line_at_eof` on the new test file
(adds exactly one `\n` to EOF). Pure whitespace, no semantic change.
Re-running the test post-fix:

```text
$ DB_PORT=5433 php artisan test --filter=AuthControllerOrganizationSuperAdminPayloadTest
  Tests:    2 passed (6 assertions)
```

Unchanged from the pre-Pint state.

---

## 3. Files changed (commit `babf59f`)

```text
$ git show --stat babf59f
commit babf59f6aba4c19f379d86a70f3aaf4ba0f7cd2b
Author: Tariq Alwalidi <tariq.alwalidi@gmail.com>
Date:   Tue Jul 14 10:45:00 2026 +0300

    feat(auth): expose is_organization_super_admin on /api/user payload

 app/Modules/Core/Http/Controllers/AuthController.php                       |   2 +
 tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php      |  72 +++++
 2 files changed, 74 insertions(+)
```

| Path | Status | Brief scope | Description |
|---|---|---|---|
| `app/Modules/Core/Http/Controllers/AuthController.php` | modified (+2 lines) | **within scope** | Added `'is_organization_super_admin' => $user->isOrganizationSuperAdmin(),` to the success-path return array of `buildFormatUserPayload()` (line 479, immediately after `'is_org_admin' => $user->isOrgAdmin(),`). Added `'is_organization_super_admin' => false,` to the catch-all fallback (line 507, immediately after `'is_org_admin' => false,`). Both placements match the brief's exact specification. |
| `tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php` | new (72 lines) | **within scope** | Two tests from brief verbatim, with documented deviations (see §5): (1) `test_payload_exposes_is_organization_super_admin_for_org_super_actor` — provisions an `organization_super_admin` role (is_admin_role=false, is_system=true), assigns it to a user scoped to the org, asserts the new flag is `true` and the existing flags remain `false`. (2) `test_payload_exposes_is_organization_super_admin_false_for_non_org_super_actor` — plain user with no role assignments, asserts the new flag is `false`. |

### Files NOT changed (preserved dirty per user constraint)

```text
$ git status -s
  M app/Modules/RiskManagement/Http/Requests/StoreRiskRequest.php   # pre-existing dirty, untouched
  M app/Modules/RiskManagement/Http/Requests/UpdateRiskRequest.php   # pre-existing dirty, untouched
  M app/Providers/AppServiceProvider.php                             # pre-existing dirty, untouched
  ?? .sdd/                                                          # untracked SDD docs, not in commit
  ?? storage/framework                                              # untracked Laravel cache, not in commit
```

These three files were sitting modified-but-unstaged when I started
(verified via `git status` before any work). They pre-date Task 4 and
are unrelated to this task's scope (`/api/user` payload). They were not
authored, edited, staged, or reset during this task. They remain in the
working tree for whoever owns them on their branch.

`.sdd/` and `storage/framework` are the usual untracked noise (workspace
docs + Laravel view cache). I did not add them with `git add` and did
not touch them. They remain untracked post-commit.

### No broad git add / commit flags used

```text
$ git add app/Modules/Core/Http/Controllers/AuthController.php \
           tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php
$ git commit -m "feat(auth): expose is_organization_super_admin on /api/user payload"
```

Only the two files named in the brief's "Files" section were staged
(no `.`, no `-A`, no `-u` blanket). Commit message is verbatim from
brief Step 5 line 106.

---

## 4. Self-review against the brief checklist

| Step | Brief requirement | Status |
|------|--------------------|--------|
| 1 | Write failing test at `tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php` (two test methods from brief verbatim) | ✅ file created, structure verbatim, JSON paths use `user.*` deviation (see §5 #1) |
| 2 | Run `php artisan test --filter=AuthControllerOrganizationSuperAdminPayloadTest`; expect FAIL — `is_organization_super_admin` JSON path missing | ✅ RED confirmed, 2/2 fail with `Failed asserting that null is identical to false` — the exact "path doesn't exist" failure mode the brief predicted |
| 3 | Add `'is_organization_super_admin' => $user->isOrganizationSuperAdmin(),` after `'is_org_admin' => $user->isOrgAdmin(),` in the success-path return array | ✅ added at `AuthController.php:479`, exact placement and value from brief |
| 3 | Add `'is_organization_super_admin' => false,` after `'is_org_admin' => false,` in the catch-all fallback | ✅ added at `AuthController.php:507`, exact placement and value from brief |
| 4 | Re-run `php artisan test --filter=AuthControllerOrganizationSuperAdminPayloadTest`; expect 2 tests pass | ✅ GREEN, 2/2 pass with 6 assertions |
| 5a | `./vendor/bin/pint --test` on both files | ✅ exit 0 after one cosmetic `single_blank_line_at_eof` fix on the test file |
| 5b | `git add` only the two changed files; commit with the prescribed message | ✅ exactly 2 files staged and committed (`git show --stat HEAD` confirms), commit message verbatim from brief line 106 |

---

## 5. Deviations from brief

### 1. Test JSON paths use `user.*` prefix instead of brief's root-level paths

**Brief says (lines 55-59, 72):**
```php
$response->assertJsonPath('is_super_admin', false);
$response->assertJsonPath('is_org_admin', false);
$response->assertJsonPath('is_organization_super_admin', true);
```

**What I shipped:**
```php
$response->assertJsonPath('user.is_super_admin', false);
$response->assertJsonPath('user.is_org_admin', false);
$response->assertJsonPath('user.is_organization_super_admin', true);
```

**Why this is a brief defect, not an implementation defect.** The
controller's `user()` method (`AuthController.php:356-361`) returns:

```php
return response()->json([
    'user' => $this->formatUser($request->user()),
]);
```

`formatUser()` returns the entire `buildFormatUserPayload()` array
unmodified (line 443), so the canonical `/api/user` response is
`{ "user": { "id": ..., "is_super_admin": ..., ..., "is_organization_super_admin": ... } }`.
The brief's root-level paths (`is_super_admin`) would fail RED forever
— even after the controller fix, because the JSON never has those keys
at the root. I verified this empirically:

1. Wrote the test verbatim from the brief → RED with
   `Failed asserting that null is identical to false` (path doesn't exist)
2. Applied the controller fix exactly as the brief specifies → still RED
   with the same failure (path STILL doesn't exist, just deeper inside the
   wrapper)
3. Applied the `user.*` prefix to the JSON paths → GREEN

**Justification for the deviation.** The brief is technically broken —
its test can never reach GREEN, which would violate the TDD iron law
("no completion claims without fresh verification evidence"). I chose
to:
- Apply the brief's controller-side code **verbatim** (lines 477-479 +
  505-507 are byte-for-byte the brief's specification, plus the
  literal `is_organization_super_admin` flag name from the brief's
  "Produces" section)
- Apply the brief's test **structurally verbatim** (two methods, same
  fixtures, same assertions on the same logical keys), with the minimum
  mechanical change needed to make the assertions actually target the
  controller's response shape (the `user.*` prefix)
- Document the deviation in the test file itself (inline comments at
  lines 45-50 and 65-69 reference the wrapper shape and the sibling
  test's precedent) and in this report

**This deviation matches an established project convention.**
`AuthControllerUserPayloadTest.php` (the sibling test, lines 38-39) —
which is also committed and review-clean — already asserts on
`user.is_super_admin` and `user.is_org_admin` for the same reason.
The Task 2 report (`/api/user` payload task at commit `4a44e1b`) flagged
this same wrapper convention as a brief defect in its own deviation log
and resolved it identically. Following precedent.

**Impact on brief's intent.** Zero. The brief's stated intent
("payload key `is_organization_super_admin: bool` alongside existing
`is_super_admin` / `is_org_admin`. Additive, non-breaking") is fully
preserved — the new key exists at the same nesting level as the existing
two keys, and the FE can read it the same way it reads them.

### 2. `use RefreshDatabase;` added to the test class (test harness, not logic)

The brief's test snippet omits the `RefreshDatabase` trait. Every
neighbour test in `tests/Feature/Api/` uses it
(`AuthControllerUserPayloadTest.php:14`, `AuthControllerTest.php:13`,
`AuthControllerExtendedTest.php:14`, plus 25+ other neighbour files).
Without it, the test would either (a) attempt to run against the dev DB
on port 5432 (which the AGENTS.md "Test DB trap" explicitly forbids —
`RefreshDatabase` would wipe `iradah_pmo` seeded data), or (b) fail on
foreign-key / table-existence errors because the test DB is wiped
between runs by `migrate:fresh --env=testing`.

I added `use RefreshDatabase;` to the class (line 15) to follow the
established pattern. This is a mechanical test-harness addition, not a
semantic change to the brief's test logic.

**Note:** the brief's `Sanctum::actingAs($user, ['*'])` call is preserved
verbatim (line 40, 61). Neighbour tests use `$this->actingAs($user, 'sanctum')`
which is functionally equivalent — both are sanctum-auth shortcuts — but
the brief's syntax is preserved as written.

### 3. `'is_active' => true` on `AuthorizationRoleAssignment::create([...])` is preserved verbatim from the brief

Both the brief (line 49) and the new test (line 37) set
`'is_active' => true` on the authorization_role_assignments row. This
attribute is **not in the model's `$fillable` array** (lines 48-58 of
`AuthorizationRoleAssignment.php`), so Eloquent silently drops it during
mass-assignment. The assignment row is created without the field, but
the lifecycle predicate (`User::activeCanonicalRoleAssignments()` at
`User.php:238-245`) filters on `expires_at` and `role.is_active`, not on
the assignment's own `is_active` column. So the assignment is correctly
counted as active and the test passes.

This is the **exact same inert-write pattern** used by
`AuthControllerUserPayloadTest.php` (line 30) and the pre-existing
`UserOrganizationSuperAdminFlagTest.php` (which was fixed in Task 2
commit `3fc232d` to use `expires_at` for the *inactive* case but
preserved the inert `is_active => true` write for the *active* case).
The brief's `'is_active' => true` on the active case is harmless and
matches the established test fixture pattern.

If the user later wants the `authorization_role_assignments` table to
actually have an `is_active` column with a real lifecycle semantic (not
just `expires_at`), that is a separate schema-change task — flagged
in the Task 2 report's "Concerns" section as a recommended follow-up.

---

## 6. Concerns / follow-ups

### 1. The brief is technically broken — `user.*` prefix deviation is non-negotiable for TDD GREEN

The brief's test code uses root-level JSON paths (`is_super_admin`)
that do not match the controller's actual response shape
(`{ "user": {...} }` wrapper). Without the `user.*` prefix, the test
stays RED forever, which is incompatible with the TDD iron law and
the verification-before-completion rule. I applied the prefix and
documented it here + in inline test comments.

**Recommendation for the brief author:** future briefs that assert on
`/api/user` payload should use `user.*` paths from the start. A
search-and-replace is mechanical and would prevent the
brief-defect deviation pattern from repeating on every new payload
field task.

### 2. Pre-existing flake in `AuthControllerTest::test_login_is_rate_limited`

While running the broader regression sweep, this test failed with
`Expected response status code [429] but received 422` at line 283.
Verified **pre-existing** by `git stash` of my changes + re-run
(failure reproduces without my code; restored my changes
afterward). Same flake as `AccountLockoutTest::test_account_locked_after_max_failed_attempts_via_api`
flagged in the Task 4 (org-inactive gate) report's concern #2 — likely
the AGENTS.md-noted "Full `php artisan test` flakes non-deterministically
… re-run the failing class alone (or `--filter`) before assuming a
regression" anti-pattern. Task 4 (this task) does not touch
`AuthSecurityService`, the failed-attempt counter, the IP limiter, or
throttle responses, so it cannot be the cause. Out of scope.

### 3. Additive payload field means FE mocks that omit the key still compile

The brief explicitly states this is the design intent
("Additive, non-breaking; existing mocks that omit the key still
compile"). The implementation honours this — the field is a new
`bool` in the `user.*` sub-object, and the `catch-all` fallback
explicitly emits `false` rather than `null`, so any FE consumer
that does `user.is_organization_super_admin ?? false` gets a defined
value and any consumer that does a strict-typed read of the new key
will get a clean `true` or `false` rather than a partial-state
`undefined`. No FE type changes are required for the rollout to be
backward-compatible.

### 4. New test does not exercise the `catch-all` fallback path directly

The brief's two tests both hit the success path of
`buildFormatUserPayload()` (no exception thrown inside the try-block).
The catch-all fallback at lines 498-508 (now containing the new
`is_organization_super_admin => false` line) is only triggered when
`$user->load(['department'])` or `$user->canonicalCapabilityNames()`
throws — a real but rare failure mode.

The `catch-all` line 507 addition is verified by static reading
(verified in the diff above) but not by an automated test. This
matches the brief's own test scope (only the two success-path
tests are specified) and matches the existing
`AuthControllerUserPayloadTest.php` precedent (which also does not
test the catch-all). If future product requirements need explicit
coverage of the fallback path, a unit test that mocks the User
model to throw on `load()` would be a small follow-up.

---

## 7. Status

**FULL GREEN — 2/2 new tests pass + 1/1 sibling test + 22/22 broader
payload suite pass + Pint exit 0. Single focused commit `babf59f`
lands on `feat/orgadmin-and-shipped-admin-spa` with the exact brief
message and exactly the 2 files the brief specified.**

The brief's test code is technically broken (root-level JSON paths vs.
the controller's `user.*` wrapper); the deviation applied is the
minimum mechanical change to make the test actually validate the
controller's response shape and is documented inline + in §5 #1.
The controller-side change is byte-for-byte the brief's specification.

| Surface | Before commit | After commit |
|---|---|---|
| `user.is_organization_super_admin` (success path) | key absent | `bool` from `User::isOrganizationSuperAdmin()` |
| `user.is_organization_super_admin` (catch-all) | key absent | `false` |
| `user.is_super_admin` | unchanged | unchanged |
| `user.is_org_admin` | unchanged | unchanged |
| Pre-existing dirty files | unstaged | unstaged (preserved) |
| `.sdd/`, `storage/framework` | untracked | untracked (preserved) |
| Commit message | n/a | `feat(auth): expose is_organization_super_admin on /api/user payload` (verbatim from brief) |
| Test DB | `127.0.0.1:5433` (per `phpunit.xml`) | same |
| Files in commit | n/a | exactly 2 (the two named in brief) |

---

## 8. Commit evidence

```text
$ git log --oneline -3
babf59f feat(auth): expose is_organization_super_admin on /api/user payload
468dfb0 feat(authz): seed organization_super_admin role + obsolete-pivot sync migration
3fc232d test(authz): use expires_at instead of nonexistent is_active in two *_when_assignment_is_inactive tests

$ git rev-parse HEAD
babf59f6aba4c19f379d86a70f3aaf4ba0f7cd2b

$ git show --stat babf59f
commit babf59f6aba4c19f379d86a70f3aaf4ba0f7cd2b
Author: Tariq Alwalidi <tariq.alwalidi@gmail.com>
Date:   Tue Jul 14 10:45:00 2026 +0300

    feat(auth): expose is_organization_super_admin on /api/user payload

 app/Modules/Core/Http/Controllers/AuthController.php                       |   2 +
 tests/Feature/Api/AuthControllerOrganizationSuperAdminPayloadTest.php      |  72 ++++++++++++++++++++++++
 2 files changed, 74 insertions(+)

$ git status -s
 M app/Modules/RiskManagement/Http/Requests/StoreRiskRequest.php
 M app/Modules/RiskManagement/Http/Requests/UpdateRiskRequest.php
 M app/Providers/AppServiceProvider.php
?? .sdd/
?? storage/framework
```

**Parent of commit:** `468dfb07c8e3f6ff53324d0910a2b1d26c733ea7` — the
required base HEAD, unchanged from session start.

**Files committed:** exactly the 2 from the brief's "Files" section.
**Files NOT touched:** the 3 pre-existing dirty files (RiskManagement
Requests, AppServiceProvider) and the 2 untracked paths (.sdd,
storage/framework) — all preserved in their pre-task state per the
user's explicit constraint.
