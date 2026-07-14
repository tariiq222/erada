# Task 1 Report: `User::isOrgAdmin()` Backend Flag

## status

DONE_WITH_CONCERNS

## commits_added

- `13b8621` — `feat(authz): add User::isOrgAdmin() for org-scoped admin boundary`
  - `app/Modules/Core/Models/User.php` (+13 lines): `isOrgAdmin()` appended at line 317, immediately after `isSuperAdmin()` (line 304). Implementation matches the brief's Step 3 verbatim — reuses `activeCanonicalRoleAssignments()`, scopes to `AuthorizationRoleAssignment::SCOPE_ORGANIZATION` with `whereNotNull('scope_id')`, and constrains the related `AuthorizationRole` to `name='admin'`, `scope_type='organization'`, `is_admin_role=true`.
  - `tests/Unit/Core/UserOrgAdminFlagTest.php` (+66 lines, new): the exact three test methods from the brief's Step 1, namespaced under `Tests\Unit\Core`, using `RefreshDatabase` plus the project's `TestCase` (which auto-seeds `RolesAndPermissionsSeeder` when `authorization_roles` exists).

No other files were modified. `git status --short` after commit shows only `.sdd/` (untracked report dir) and `storage/framework` (symlink created so the worktree's artisan could resolve the view cache path; ignored as untracked).

## test_command_and_output

Command (per brief Step 4):

```text
php artisan test --filter=UserOrgAdminFlagTest
```

GREEN run result:

```text
   WARN  Metadata found in doc-comment for method …   (3 pre-existing PHPUnit deprecation warnings on unrelated tests; not from this file)

  FAIL  Tests\Unit\Core\UserOrgAdminFlagTest
  ✓ is org admin returns true when active admin assignment with org sc…  16.08s
  ✓ is org admin returns false when no assignment                         1.86s
  ⨯ is org admin returns false when assignment is inactive                1.79s
  ──────────────────────────────────────────────────────────────────────────────
  FAILED  Tests\Unit\Core\UserOrgAdminFlagTest > is org admin returns false when assignment is inactive
  Failed asserting that true is false.

  at tests/Unit/Core/UserOrgAdminFlagTest.php:64
     60▕             'organization_id' => $org->id,
     61▕             'is_active' => false,
     62▕         ]);
     63▕
  ➜  64▕         $this->assertFalse($user->fresh()->isOrgAdmin());

  Tests:    1 failed, 2 passed (3 assertions)
  Duration: 22.10s
```

RED run (brief Step 2, captured before adding `isOrgAdmin()`):

```text
  ⨯ is org admin returns true when active admin assignment with org sc…  17.88s
  ⨯ is org admin returns false when no assignment                        3.16s
  ⨯ is org admin returns false when assignment is inactive               0.81s

  FAILED  Tests\Unit\Core\UserOrgAdminFlagTest > is org admin returns false when no assignment
  Call to undefined method App\Modules\Core\Models\User::isOrgAdmin()
  … 2 more identical undefined-method failures …

  Tests:    3 failed (0 assertions)
  Duration: 29.99s
```

## self_review_notes

- Implementation is verbatim from the brief's Step 3. Uses the existing `activeCanonicalRoleAssignments()` relationship helper as required by the interface contract; no schema, model, or migration changes elsewhere.
- The first two tests prove the positive contract: an active admin assignment with `scope_type=organization` and non-null `scope_id` flags the user, and absence of any such assignment does not.
- The third test cannot pass with the brief's exact implementation (see concern). The exact failure path is documented above; the SQL hits no error — `isOrgAdmin()` returns `true` because the "inactive" assignment is, from the database's perspective, indistinguishable from an active one.

## concerns

1. **Brief expectation vs. current schema conflict (BLOCKING for "3/3 pass").** The third test (`test_is_org_admin_returns_false_when_assignment_is_inactive`) creates the assignment with `'is_active' => false`, but `authorization_role_assignments` has no `is_active` column. Verified: `php artisan tinker` reports `Schema::hasColumn('authorization_role_assignments','is_active') === false`, and `AuthorizationRoleAssignment::$fillable` (lines 48–58 of `AuthorizationRoleAssignment.php`) does NOT include `is_active`. Eloquent's mass-assignment guard therefore silently drops the key, the row inserts cleanly as "active" from the engine's perspective, and `activeCanonicalRoleAssignments()` (line 238) — which the brief explicitly says to "reuse as-is" — has no column to filter on. The brief's "Expected: 3 tests pass" therefore cannot be reached without one of:
   - adding a migration to introduce `is_active` on `authorization_role_assignments`,
   - editing `AuthorizationRoleAssignment.php` to whitelist `is_active` in `$fillable` (the brief says "no change"),
   - or modifying `activeCanonicalRoleAssignments()` to consult another active-state signal (the brief says "reuse as-is").
   I chose to follow the brief literally and document the gap rather than silently expand scope. Downstream tasks that depend on `isOrgAdmin()` returning `false` for soft-deactivated assignments will need to make one of the above choices in a follow-up (most plausibly a focused migration + fillable tweak in a Task 2.5-style patch).

2. **Pre-existing PHPUnit deprecation warnings.** The `--filter` run surfaces three pre-existing `@dataProvider`-style doc-comment warnings from unrelated test files (`ScopeAssignmentResolverTest`, `RoleControllerCatalogSlimTest`, `RoleControllerCatalogTest`). They are NOT emitted by the new test and predate this change; flagged here for awareness only.

3. **Worktree environment.** The shared `.worktrees/orgadmin-and-shipped-admin-spa/` did not ship `vendor/` or `.env`; I ran `composer install --no-progress --prefer-dist` inside the worktree (now isolated from the main checkout's vendor symlink) and added an `storage/framework` symlink so Laravel's view compiler had a cache path. Neither the `vendor/` install nor the symlink is tracked in git; only `User.php` and `UserOrgAdminFlagTest.php` are staged/committed.

4. **Out-of-scope guardrails honored.** No edits to `AuthorizationRoleAssignment.php`, no migration added, no Spatie `HasRoles` usage, no FormRequest or controller touched, no frontend files touched, no legacy flat permission strings referenced. Capability constants untouched (none were needed — the implementation only uses `AuthorizationRoleAssignment::SCOPE_ORGANIZATION`). TypeScript untouched.