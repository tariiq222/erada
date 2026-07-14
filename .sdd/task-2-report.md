# Task 2 — `User::isOrganizationSuperAdmin()` predicate — Evidence Report

**Branch:** `feat/orgadmin-and-shipped-admin-spa`
**Base (before work):** `6dc4491447be694d10f9b2a16b3b4d1e9514ebb0` (Task 1, reviewed clean)
**Commit (Task 2):** `46712ea767c132aba46ec63edd5d64f2cc598971`
**Working directory:** `/Users/tariq/code/erada-platform/.worktrees/orgadmin-and-shipped-admin-spa`
**Brief:** `/Users/tariq/code/erada-platform/.git/worktrees/orgadmin-and-shipped-admin-spa/sdd/task-2-brief.md`
**Date:** 2026-07-14

## Objective

Execute Task 2 of the unified admin SPA plan verbatim: add
`User::isOrganizationSuperAdmin(): bool` to `app/Modules/Core/Models/User.php`
(immediately after `isOrgAdmin()`) and a co-located PHPUnit test file.
TDD discipline: failing test first, then minimal implementation, then re-run.
Predicates must reflect the design constraint that `is_admin_role=false`
distinguishes the new role from the legacy `admin` role, and the predicate
must not perturb `isOrgAdmin()`'s existing behavior for `admin`.

## Changed paths (tracked, committed in `46712ea`)

| Path | Change |
|------|--------|
| `app/Modules/Core/Models/User.php` | +24 lines — added `isOrganizationSuperAdmin()` immediately after `isOrgAdmin()` (line 328 in the new file). Existing `isOrgAdmin()` (lines 316–327) is byte-identical to `6dc4491`. |
| `tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php` | +106 lines — new file; 4 PHPUnit tests exactly as the brief specifies. |

Total diff: `2 files changed, 130 insertions(+)`.

### Symbols added (User.php, lines 329–351)

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

The block was placed immediately after `isOrgAdmin()` (line 327), per the
brief's placement instruction. Comments and Arabic rationale are verbatim
from the brief. `isOrgAdmin()` itself was not touched.

### Test (new file, 4 methods)

```php
test_is_organization_super_admin_returns_true_only_for_active_canonical_assignment()
    create org + user + organization_super_admin role (is_admin_role=false, is_system=true)
    create assignment (scope=organization, scope_id=org, is_active=true)
    assertTrue $user->fresh()->isOrganizationSuperAdmin()

test_is_organization_super_admin_returns_false_when_no_assignment()
    create org + user; assertFalse $user->fresh()->isOrganizationSuperAdmin()

test_is_organization_super_admin_returns_false_when_assignment_is_inactive()
    identical fixture to #1 but assignment.is_active=false
    assertFalse $user->fresh()->isOrganizationSuperAdmin()    // ⚠ see Concerns

test_is_organization_super_admin_returns_false_for_curated_admin_role()
    role name=admin, is_admin_role=true, is_system=false
    create assignment (active)
    assertFalse $user->fresh()->isOrganizationSuperAdmin()
```

## RED — failing-test evidence (Step 2 of brief)

Command:

```bash
php artisan test --filter=UserOrganizationSuperAdminFlagTest
```

Output (excerpt):

```
FAIL  Tests\Unit\Core\UserOrganizationSuperAdminFlagTest
  ⨯ is organization super admin returns true only for active canonical… 0.42s
  ⨯ is organization super admin returns false when no assignment         0.41s
  ⨯ is organization super admin returns false when assignment is inacti… 0.41s
  ⨯ is organization super admin returns false for curated admin role     0.40s
  ──────────────────────────────────────────────────────────────────────
  FAILED  Tests\Unit\Core\UserOrganizationSuperAdmi…  BadMethodCallException
  Call to undefined method App\Modules\Core\Models\User::isOrganizationSuperAdmin()

  at vendor/laravel/framework/src/Illuminate/Support/Traits/ForwardsCalls.php:67

  Tests:    4 failed (0 assertions)
  Duration: 0.59s
```

All four tests fail with the exact reason the brief predicted — `BadMethodCallException`
on the missing method. RED is honest (failure because the feature is missing,
not because of typos or infrastructure issues).

## GREEN — passing-test evidence (Step 4 of brief)

Command (after applying the predicate):

```bash
php artisan test --filter=UserOrganizationSuperAdminFlagTest
```

Output (excerpt):

```
FAIL  Tests\Unit\Core\UserOrganizationSuperAdminFlagTest
  ✓ is organization super admin returns true only for active canonical… 1.66s
  ✓ is organization super admin returns false when no assignment         0.73s
  ⨯ is organization super admin returns false when assignment is inacti… 0.75s
  ✓ is organization super admin returns false for curated admin role     0.77s
  ──────────────────────────────────────────────────────────────────────
  FAILED  Tests\Unit\Core\UserOrganizationSuperAdmi…
  Failed asserting that true is false.

  at tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php:76

  Tests:    1 failed, 3 passed (4 assertions)
  Duration: 4.62s
```

3 of 4 pass with the literal brief implementation. Test #3 (the "inactive
assignment" case) fails for a schema reason — see **Concern 1** below.

## Pint evidence (Step 5)

```bash
# Initial --test run after GREEN
$ ./vendor/bin/pint --test app/Modules/Core/Models/User.php \
    tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php
{"tool":"pint","result":"fail","files":[
  {"path":"tests\/Unit\/Core\/UserOrganizationSuperAdminFlagTest.php",
   "fixers":["single_blank_line_at_eof"]}]}

# Auto-fix, re-run tests (still 3 PASS / 1 FAIL — same as before fix), re-run --test
$ ./vendor/bin/pint app/Modules/Core/Models/User.php \
    tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php
{"tool":"pint","result":"fixed","files":[
  {"path":"tests\/Unit\/Core\/UserOrganizationSuperAdminFlagTest.php",
   "fixers":["single_blank_line_at_eof"]}]}

$ ./vendor/bin/pint --test app/Modules/Core/Models/User.php \
    tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php
{"tool":"pint","result":"passed"}    # exit 0
```

Pint's only fix was a single trailing newline at EOF in the new test file —
purely cosmetic. No semantic content changed. The user model already
matched Pint's conventions; no fixers fired on `User.php`.

## Final committed diff (HEAD)

```
46712ea feat(authz): add User::isOrganizationSuperAdmin() predicate
 app/Modules/Core/Models/User.php                                |  24 +++++
 tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php         | 106 +++++++++++++++++++++
 2 files changed, 130 insertions(+)
```

`git status` after commit (pre-existing unstaged noise from prior work,
NOT in my commit):

```
$ git status --short
 M app/Modules/RiskManagement/Http/Requests/StoreRiskRequest.php
 M app/Modules/RiskManagement/Http/Requests/UpdateRiskRequest.php
 M app/Providers/AppServiceProvider.php
?? .sdd/
?? storage/framework
```

The three modified-but-unstaged files (`StoreRiskRequest.php`,
`UpdateRiskRequest.php`, `AppServiceProvider.php`) are leftover staged
changes from prior sessions on other branches. I did not author them
in this Task 2 session; they were sitting in the index when I started.
Per the brief's scope ("Commit only Task 2 changes"), I explicitly
**unstaged them** before committing — `git reset HEAD <those three>` — so
they did not pollute `46712ea`. They remain in the working tree, untouched.

`.sdd/` and `storage/framework` are the usual untracked noise
(workspace + Laravel view cache) — pre-existing before Task 1 too, and
not committed by Task 1's review-clean commit `6dc4491`.

## Self-review against the brief checklist

| Step | Brief requirement | Status |
|------|--------------------|--------|
| 1 | Write failing unit test at `tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php` | ✅ file created, content verbatim from brief (4 tests, signatures verbatim) |
| 2 | Run `php artisan test --filter=UserOrganizationSuperAdminFlagTest`; confirm FAIL with `BadMethodCallException` on `isOrganizationSuperAdmin` | ✅ RED confirmed, exact match — 4/4 fail with `Call to undefined method` |
| 3 | Add `isOrganizationSuperAdmin()` after `isOrgAdmin()` with the documented banners | ✅ placed at lines 329–351, identical semantics to brief snippet, Arabic comment block verbatim |
| 4 | Re-run the test; expected PASS (4 tests) | ⚠ **3/4 pass** — test #3 fails for a schema reason documented in Concern 1 |
| 5a | `./vendor/bin/pint --test` on both files | ✅ exit 0 after one whitespace fix |
| 5b | `git add` only the two changed files; commit with the prescribed message | ✅ `feat(authz): add User::isOrganizationSuperAdmin() predicate` — `git show --stat HEAD` confirms exactly 2 files, 130 insertions |

## Concerns

### 1. ⚠ Brief test #3 cannot pass against the current schema — pre-existing defect, not introduced by Task 2

**Observation.** Test #3 (`test_is_organization_super_admin_returns_false_when_assignment_is_inactive`)
sets `'is_active' => false` on the `AuthorizationRoleAssignment` row and
expects `isOrganizationSuperAdmin()` to return `false`. The predicate,
as the brief specifies, uses `activeCanonicalRoleAssignments()` which
filters only on `expires_at` and `role.is_active` — it never inspects
the assignment's own `is_active` flag.

**Why it fails.** The `authorization_role_assignments` table has **no
`is_active` column** (verified directly against the PostgreSQL test DB
`iradah_pmo_test` on port 5433 — `\d authorization_role_assignments`
shows 12 columns, none called `is_active`; the closest lifecycle field
is `expires_at`, added by migration
`2026_07_12_000005_add_lifecycle_to_authorization_roles_and_assignments.php`).
Eloquent silently drops the unknown attribute during mass-assignment
because it is not in `$fillable`, so `'is_active' => false` is
discarded and the assignment row is created without it. The predicate
then sees an active assignment against an active `organization_super_admin`
role and returns `true` — failing the assertion.

**Pre-existing, not introduced here.** The same defect already affects
the `isOrgAdmin()` predicate: an analogous test
`tests/Unit/Core/UserOrgAdminFlagTest::test_is_org_admin_returns_false_when_assignment_is_inactive`
(lines 47–65) was already failing before Task 2 started. I verified
this empirically by checking out Task 1's commit (`6dc4491`) and
running the test in isolation:

```
$ git checkout 6dc4491 -- app/Modules/Core/Models/User.php tests/Unit/Core/UserOrgAdminFlagTest.php
$ php artisan test --filter=UserOrgAdminFlagTest
  ✓ is org admin returns true when active admin assignment with org sco…
  ✓ is org admin returns false when no assignment
  ⨯ is org admin returns false when assignment is inactive
  Tests:    1 failed, 2 passed (3 assertions)
```

Same failure mode, same root cause, same pre-existing condition. The
brief author appears to have copy-pasted the pattern from `isOrgAdmin()`
without verifying the schema actually supports `is_active` on
assignments. The defect was not surfaced by the Task 1 review
(`6dc4491` is review-clean per the SDD progress ledger) because
`isOrgAdmin()`'s commit (`13b8621`) is on a different branch and never
had its `UserOrgAdminFlagTest` re-run on the current integration
branch.

**Why I did not "fix" it.** Three resolution paths exist, all of
which fall outside the brief's scope:

  1. **Add a new migration** adding `is_active` to
     `authorization_role_assignments` (default true), update
     `AuthorizationRoleAssignment::$fillable`, and add
     `->where('is_active', true)` to the predicate. This adds a
     schema change and an unrelated model edit, violating the user's
     "do not modify applied migrations or unrelated files" instruction
     and extending beyond the brief's stated "Modify: `app/Modules/Core/Models/User.php`"
     file scope.
  2. **Modify the predicate** to add `->where('is_active', true)` on
     the assignment without adding the column. The DB write in test #3
     would then either error (if the column were forced into the
     insert) or be silently discarded (current behavior, which is
     what we see today). Either way the test does not pass.
  3. **Modify the test** to use `expires_at` instead of `is_active`,
     since `expires_at` IS the lifecycle column on assignments and IS
     honored by `activeCanonicalRoleAssignments()`. This violates
     the "verbatim from brief" instruction.

The literal brief implementation is what the brief specifies and is
what I shipped. **Decision:** ship literal implementation, surface the
defect, recommend a follow-up task to either (a) add
`is_active boolean default true` to `authorization_role_assignments` +
extend `activeCanonicalRoleAssignments()` to honor it (and
retroactively unblock the pre-existing `UserOrgAdminFlagTest` failure
at the same time), or (b) amend both brief test #3s to use
`expires_at` (which is honored today and matches the brief's
"active canonical org assignment" design language).

**Risk if merged without resolution.** Any later task (role seed, auth
payload) that relies on `User::isOrganizationSuperAdmin()` to
distinguish active vs inactive assignments will over-grant to inactive
assignments. Same risk already applies to `isOrgAdmin()`. This is a
real, shippable security gap — but it is a Task 2 brief defect, not a
Task 2 implementation defect. Flagging it loudly here for the user.

### 2. Pre-existing unstaged changes in the working tree

Three files (`StoreRiskRequest.php`, `UpdateRiskRequest.php`,
`AppServiceProvider.php`) were sitting modified-but-unstaged when I
started Task 2. None are mine, none relate to Task 2's scope. Per the
brief's "Commit only Task 2 changes" instruction, I unstaged them
(`git reset HEAD <those three>`) so they would not pollute `46712ea`.
They remain in the working tree for whoever owns them to commit on
their own branch.

### 3. Targeted PHPUnit warnings observed

The test run prints three warnings about PHPUnit 12 metadata
deprecation in doc-comments (e.g.
`Tests\Feature\Core\RoleControllerCatalogSlimTest::test_dead_ladder_string_not_in_catalog()`).
These originate from other unrelated test files and were present
before this task. No action taken; out of scope.

### 4. `activeCanonicalRoleAssignments()` does not check assignment `is_active`

Worth noting for whoever fixes Concern 1: the `is_active` column on
`authorization_role_assignments` would need to be checked at the
`activeCanonicalRoleAssignments()` layer (User.php:238–245), not just
in the new predicate. Adding `->where('is_active', true)` to the new
predicate alone would leave `isSuperAdmin()` and `isOrgAdmin()` (and
everywhere else that calls `activeCanonicalRoleAssignments()` —
there are several across `AccessDecision`, `RecordRuleEvaluator`,
`CanonicalAuthorizationAssignmentActorGuard`, the controllers under
`AuthorizationRoleAssignmentController`, etc.) silently over-granting.
The clean fix is to teach the scope method about assignment `is_active`
once, in one place.

## Status

**PARTIAL GREEN — 3/4 pass, 1 fails due to pre-existing schema defect.**

TDD cycle observed: RED (4/4 fail with `BadMethodCallException`) →
GREEN (3/4 pass; test #3 fails for a documented pre-existing schema
gap that already affects the analogous `isOrgAdmin()` test at Task 1
commit `6dc4491`) → PINT (auto-fix one whitespace, exit 0) → COMMIT
(`46712ea`, exactly 2 files, message verbatim from brief).

Recommend the user decide between (a) opening a small follow-up Task 2.5
to add `is_active` to `authorization_role_assignments` and extend
`activeCanonicalRoleAssignments()`, or (b) amending brief test #3 (and
the analogous `UserOrgAdminFlagTest` test #3) to use `expires_at`.
Either path unblocks the parallel "inactive-assignment" failure on the
older `isOrgAdmin()` predicate as a side effect.

---

## Resolution — path (b) executed as the systematic-debugging-approved minimal fix

**Branch:** `feat/orgadmin-and-shipped-admin-spa`
**Commit:** `3fc232dbead225be6e4af47a6b7faa2b19f9c244`
**Date:** 2026-07-14
**Parent of fix:** `46712ea767c132aba46ec63edd5d64f2cc598971` (Task 2 GREEN-partial commit)
**Working directory:** `/Users/tariq/code/erada-platform/.worktrees/orgadmin-and-shipped-admin-spa`

### Scope chosen

Of the two paths recommended above, **path (b)** was selected because it is
the only one that satisfies the "no migration / no production code / no
capability changes" constraints. Path (a) would have:

- added a new migration adding `is_active boolean default true` to
  `authorization_role_assignments` (the project's "never edit an applied
  migration" rule means we MUST add a new one, not amend an existing);
- extended `AuthorizationRoleAssignment::$fillable` to include `is_active`;
- extended `User::activeCanonicalRoleAssignments()` (lines 238–245) to
  honor the new column — touching one of the most heavily called scopes
  across `AccessDecision`, `RecordRuleEvaluator`,
  `CanonicalAuthorizationAssignmentActorGuard`, the controllers under
  `AuthorizationRoleAssignmentController`, etc.;
- altered the runtime behavior of `isSuperAdmin()`, `isOrgAdmin()`, the
  unified `isOrganizationSuperAdmin()`, and every engagement that consults
  `activeCanonicalRoleAssignments()` — including the new `3fc232d` test
  pair itself.

Path (a) is not "minimal" in any defensible sense. Path (b) — replace the
fabricated `is_active => false` input with the actual `expires_at` column
the schema (and the `activeCanonicalRoleAssignments` scope it is testing
against) actually uses — restores the GREEN state predicted in step 4 of
the brief with **two single-line edits** in two test files. No production
behavior changes.

### Files changed (commit `3fc232d`)

```
git show --stat 3fc232d
 test(authz): use expires_at instead of nonexistent is_active in two *_when_assignment_is_inactive tests

 tests/Unit/Core/UserOrgAdminFlagTest.php               | 10 ++++++++--
 tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php |  8 +++++++-
 2 files changed, 15 insertions(+), 3 deletions(-)
```

Both modifications live entirely inside the two
`*_returns_false_when_assignment_is_inactive` test methods. No other file
in the repository was touched. The three pre-existing unstaged files in
the working tree (`app/Modules/RiskManagement/Http/Requests/StoreRiskRequest.php`,
`app/Modules/RiskManagement/Http/Requests/UpdateRiskRequest.php`,
`app/Providers/AppServiceProvider.php`) remain unstaged and uncommitted —
they pre-date Task 2 and Task 1, were explicitly excluded from `46712ea`,
and are equally excluded from `3fc232d`.

### Diff (committed)

`tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php` (lines 70–76 →
70–82):

```diff
@@ -70,7 +70,13 @@ public function test_is_organization_super_admin_returns_false_when_assignment_i
             'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
             'scope_id' => $org->id,
             'organization_id' => $org->id,
-            'is_active' => false,
+            // `authorization_role_assignments` has NO `is_active` column (see
+            // migration 2026_07_12_000005_add_lifecycle_to_authorization_roles_and_assignments
+            // — it adds only `expires_at` to the assignments table). The
+            // lifecycle is honored by User::activeCanonicalRoleAssignments(),
+            // which filters `expires_at > now()`. Modeled as an unambiguously
+            // past immutable expiry so it is excluded from the active set.
+            'expires_at' => now()->subSecond()->toImmutable(),
         ]);

         $this->assertFalse($user->fresh()->isOrganizationSuperAdmin());
```

`tests/Unit/Core/UserOrgAdminFlagTest.php` (lines 58–64 → 58–72) — same
shape, plus Pint's `single_blank_line_at_eof` cosmetic trailing-newline
fix on the closing brace (purely whitespace, no semantic change).

### Why `expires_at => now()->subSecond()->toImmutable()` is the right input

`User::activeCanonicalRoleAssignments()` (User.php lines 238–245) reads
verbatim:

```php
return $this->canonicalRoleAssignments()
    ->where(function ($query) {
        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
    })
    ->whereHas('role', fn ($query) => $query->where('is_active', true));
```

So the only two ways an assignment is excluded from "active" are:

1. `expires_at IS NOT NULL AND expires_at <= now()`, or
2. its `role.is_active = false`.

(The `$fillable` list on `AuthorizationRoleAssignment` (lines 48–58) does
not include `is_active`; the table does not have an `is_active` column
either — verified against migration
`2026_07_12_000005_add_lifecycle_to_authorization_roles_and_assignments`
which only adds `expires_at` to the assignments table.) Eloquent silently
drops attributes that are neither in `$fillable` nor in the schema, so
`'is_active' => false` was an inert write — the assignment row was created
without any lifecycle signal at all, and the scope saw it as active.

`now()->subSecond()->toImmutable()` produces a `Carbon\CarbonImmutable`
representing one second in the past. That value:

- is `Carbon\CarbonImmutable`, which honors the
  `'expires_at' => 'immutable_datetime'` cast on
  `AuthorizationRoleAssignment::class` line 62;
- is unambiguously less than `now()` (the SQL compare runs in microseconds
  after the row is created), so the `expires_at > now()` branch of the
  scope's `where` evaluates to `false`;
- is far enough in the past (one full second) that no test-runner clock
  drift can drag it across the boundary, even on slow CI;
- leaves `expires_at IS NULL` false (so the WHERE branch is the
  `expires_at > now()` comparison, not the NULL branch) — precisely the
  state the test is asserting the engine's behavior for.

### RED evidence captured before the fix (port 5433, dedicated test DB)

Container `erada-platform-postgres-test` listening on `5433` (verified
via `docker ps --filter "publish=5433"` and `DB_PORT=5433` propagated to
the artisan run).

```bash
$ php artisan test --filter=UserOrganizationSuperAdminFlagTest
# (full output excerpt — GREEN-partial state inherited from 46712ea)
  ✓ is organization super admin returns true only for active canonical…  2.06s
  ✓ is organization super admin returns false when no assignment         0.78s
  ⨯ is organization super admin returns false when assignment is inacti… 0.90s
  ✓ is organization super admin returns false for curated admin role     0.78s
  Tests:    1 failed, 3 passed (4 assertions)

$ php artisan test --filter=UserOrgAdminFlagTest
  ✓ is org admin returns true when active admin assignment with org sco… 1.82s
  ✓ is org admin returns false when no assignment                        0.79s
  ⨯ is org admin returns false when assignment is inactive               0.80s
  Tests:    1 failed, 2 passed (3 assertions)

# Failure modalines (both files identical shape):
  Failed asserting that true is false.
  at tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php:76   (originally line 73 after my edit)
  at tests/Unit/Core/UserOrgAdminFlagTest.php:64                  (originally line 61 after my edit)
```

This is the same RED captured in the original task-2-report. The fail
message "Failed asserting that true is false" is the smoking gun for
"the predicate returned `true` because the scope saw the assignment as
active" — exactly what the `is_active` column being fabricated would
predict.

### GREEN evidence after the fix

```bash
$ DB_PORT=5433 php artisan test --filter='UserOrganizationSuperAdminFlagTest|UserOrgAdminFlagTest'
   PASS  Tests\Unit\Core\UserOrgAdminFlagTest
  ✓ is org admin returns true when active admin assignment with org sco… 1.67s
  ✓ is org admin returns false when no assignment                        0.76s
  ✓ is org admin returns false when assignment is inactive               0.72s

   PASS  Tests\Unit\Core\UserOrganizationSuperAdminFlagTest
  ✓ is organization super admin returns true only for active canonical…  0.69s
  ✓ is organization super admin returns false when no assignment         0.71s
  ✓ is organization super admin returns false when assignment is inacti… 0.70s
  ✓ is organization super admin returns false for curated admin role     0.70s

  Tests:    7 passed (7 assertions)
  Duration: 6.57s
```

All seven tests pass. The two previously-failing tests are now the most
strict possible assertions of the documented contract: "an active canonical
role assignment requires both `role.is_active=true` AND (no expiry or
future expiry)" — and the new `expires_at` value lets the test actually
exercise the expiry branch instead of silently no-op-ing through it.

### Pint evidence

```bash
$ ./vendor/bin/pint --test tests/Unit/Core/UserOrgAdminFlagTest.php \
                       tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php
{"tool":"pint","result":"fail","files":[
  {"path":"tests\/Unit\/Core\/UserOrgAdminFlagTest.php",
   "fixers":["single_blank_line_at_eof"]}]}

# Same cosmetic EOF-newline fixer as 46712ea fired on
# tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php per the
# original task-2-report section "Pint evidence". Single-line auto-fix.

$ ./vendor/bin/pint tests/Unit/Core/UserOrgAdminFlagTest.php \
                       tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php
{"tool":"pint","result":"fixed","files":[
  {"path":"tests\/Unit\/Core\/UserOrgAdminFlagTest.php",
   "fixers":["single_blank_line_at_eof"]}]}

$ ./vendor/bin/pint --test tests/Unit/Core/UserOrgAdminFlagTest.php \
                       tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php
{"tool":"pint","result":"passed"}    # exit 0
```

Two test files, one cosmetic fixer on one file, exit 0. `single_blank_line_at_eof`
is a pure whitespace fixer (adds exactly one `\n` to EOF) and does not
alter any test semantics — confirmed by re-running the suite post-fix:

```bash
$ DB_PORT=5433 php artisan test --filter='UserOrganizationSuperAdminFlagTest|UserOrgAdminFlagTest'
  Tests:    7 passed (7 assertions)   # unchanged
```

### Final commit

```
$ git log --oneline -3
3fc232d test(authz): use expires_at instead of nonexistent is_active in two *_when_assignment_is_inactive tests
46712ea feat(authz): add User::isOrganizationSuperAdmin() predicate
6dc4491 feat(authz): add capability constants for organization_super_admin surface

$ git show --stat 3fc232d
commit 3fc232dbead225be6e4af47a6b7faa2b19f9c244
Author: Tariq Alwalidi <tariq.alwalidi@gmail.com>
Date:   Tue Jul 14 04:46:00 2026 +0300

    test(authz): use expires_at instead of nonexistent is_active in two *_when_assignment_is_inactive tests
    [...body, see "Diff (committed)" above...]

 tests/Unit/Core/UserOrgAdminFlagTest.php               | 10 ++++++++--
 tests/Unit/Core/UserOrganizationSuperAdminFlagTest.php |  8 +++++++-
 2 files changed, 15 insertions(+), 3 deletions(-)

$ git status --short
 M app/Modules/RiskManagement/Http/Requests/StoreRiskRequest.php   # pre-existing, not in commit
 M app/Modules/RiskManagement/Http/Requests/UpdateRiskRequest.php   # pre-existing, not in commit
 M app/Providers/AppServiceProvider.php                            # pre-existing, not in commit
?? .sdd/                                                           # untracked SDD docs, not in commit
?? storage/framework                                               # untracked Laravel cache, not in commit
```

(Three pre-existing modified-but-unstaged files in `M` lines + two
untracked entries are unchanged from the original task-2-report; they
were never committed by `6dc4491` or `46712ea` and are not committed by
`3fc232d`. The user's instruction was "Commit only this focused test
correction", which is exactly what was committed.)

### Concerns / follow-ups (unchanged from the original report)

1. **`activeCanonicalRoleAssignments()` still does not look at the
   assignments table's own `is_active` column** — because that column
   does not exist. This is by design: the lifecycle is `expires_at`,
   period. Path (a) (add `is_active` to assignments) was deliberately
   rejected as not minimal. If a future requirement emerges for a
   non-time-based disable (audit-frozen, soft-locked, etc.) it should be
   modeled as either an explicit `revoked_at` timestamp or a separate
   status enum — both of which would be more truthful than overloading
   `is_active`.

2. **Both `isOrgAdmin()` and `isOrganizationSuperAdmin()` predicates are
   now exercised against a real lifecycle state.** The test pair no
   longer relies on Eloquent silently swallowing an inert write — they
   actually drive `expires_at > now()` to false. This closes the silent
   no-op risk that previously made the test names misleading.

3. **The three pre-existing modified-but-unstaged files** in the working
   tree still belong to whoever owns them on a different branch. They
   remain outside the scope of this fix.

### Status

**FULL GREEN — 7/7 pass across both classes. Pint exit 0. Single focused
test-only commit `3fc232d` lands on `feat/orgadmin-and-shipped-admin-spa`.**

The originally-reported "PARTIAL GREEN — 3/4 pass, 1 fails due to
pre-existing schema defect" status is now resolved by the path (b) fix
recommended at the end of the original section of this report. The new
commit's scope was strictly limited to the two test files identified as
RED evidence; no production code, no migration, no capability changes,
no broad refactor.