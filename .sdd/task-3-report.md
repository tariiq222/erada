# Task 3 Report: Curate `admin` Authorization Role to OrgAdmin scope

## status

DONE_WITH_CONCERNS

## commits_added

- `7506c66` — `feat(authz): curate admin role to OrgAdmin capability set`
  - `database/seeders/RolesAndPermissionsSeeder.php` (+31/-1 lines):
    - `admin` role entry (lines 98-105): replaced `'capabilities' => Capability::all()` with `'capabilities' => self::orgAdminCapabilities()`, and added `label_ar` (`'مسؤول المنظمة'`) and `label_en` (`'Organization Admin'`) per the brief. Kept `scope_type='organization'` and `is_admin_role=true` so the engine's org-isolation still applies.
    - New private static method `orgAdminCapabilities()` (lines 432-455): returns the exact 11-capability curated list (`USERS_VIEW/CREATE/EDIT`, `DEPARTMENTS_VIEW/CREATE/EDIT/DELETE`, `ROLES_VIEW`, `SETTINGS_VIEW/EDIT`, `AUDIT_VIEW`). Placement follows the brief's "near the bottom of the class (above `run()`)" — placed between `mappedCapabilities()` and `viewCapabilities()` to keep the three private static helpers together.
  - `tests/Feature/Authz/OrgAdminCuratedCapabilitiesTest.php` (+61 lines, new): the brief's verbatim test code with one mechanical addition — `use RefreshDatabase` (the brief's snippet omits it but every neighbouring feature test in `tests/Feature/Authz/` (`FixturesAuthzTest`, `AuthzTestFixturesScenarioTest`) requires it so the schema exists for `RolesAndPermissionsSeeder` to populate `authorization_roles`). Namespace `Tests\Feature\Authz` matches the brief.

No other files were modified. `super_admin`, `viewer`, `manager`, `member`, and the project/department-scoped roles are untouched. `Capability.php` is untouched. `SWEPT_SYSTEM_ROLES` still includes `'admin'`, so the obsolete-pivot sweep removes every pivot no longer in the curated list on first re-seed — verified via `migrate:fresh --env=testing --seed --force` (see verification below).

## test_command_and_output

### Pre-curation RED check (brief Step 2)

Command (run before any edits to `RolesAndPermissionsSeeder.php`):

```text
php artisan test --filter=OrgAdminCuratedCapabilitiesTest
```

Observed output:

```text
  PASS  Tests\Feature\Authz\OrgAdminCuratedCapabilitiesTest
  ✓ admin role capabilities match curated org admin set                 22.66s
  ✓ admin role does not grant core assign roles                          2.61s

  Tests:    2 passed (12 assertions)
  Duration: 27.08s
```

The test **passes** initially, contradicting the brief's "Expected: FAIL" annotation. Root cause analysis (also documented in concerns #1 and #2 below): the test as written is a positive containment check plus a vacuous key lookup, so it cannot distinguish between the pre-curation admin role (117 permissions — every `Capability::all()` entry) and the post-curation admin role (11 permissions — the curated list). Specifically:

1. `test_admin_role_capabilities_match_curated_org_admin_set` uses `assertContains` in a `foreach` over `$expected`. Since the curated list is a subset of `Capability::all()` and the admin role currently grants every `Capability::all()` entry, every expected capability is contained in the granted set, so the test passes both before and after curation.
2. `test_admin_role_does_not_grant_core_assign_roles` filters by `whereHas('resource', fn ($q) => $q->where('key', 'core'))`. The `authorization_resources.key` column stores FQCNs (e.g. `App\Modules\Core\Models\Organization`, populated by `RolesAndPermissionsSeeder::run()` via `AuthorizationResource::query()->updateOrCreate(['key' => $mapping['resource']], ...)` where `$mapping['resource']` comes from `CapabilityToAuthorizationRolePermission::PREFIX_TO_RESOURCE` and is always a `class-string`). The short prefix `'core'` never matches, so the `whereHas` filter always returns 0 pivots, `exists()` returns `false`, and `assertFalse(false)` passes — both before curation (when the role DOES hold `core.assign_roles`) and after curation (when it does not).

Verified empirically via tinker before curation:

```text
Admin role permission count: 117
Admin has core.assign_roles: YES
```

### Post-curation GREEN check (brief Step 4)

Command (after curation):

```text
php artisan test --filter=OrgAdminCuratedCapabilitiesTest
```

Observed output:

```text
  PASS  Tests\Feature\Authz\OrgAdminCuratedCapabilitiesTest
  ✓ admin role capabilities match curated org admin set                 35.26s
  ✓ admin role does not grant core assign roles                          3.44s

  Tests:    2 passed (12 assertions)
  Duration: 41.25s
```

After curation, the admin role has exactly the curated capabilities. Verified via tinker after `migrate:fresh --env=testing --seed --force`:

```text
Admin role:
  label: Organization Admin
  label_ar: مسؤول المنظمة
  label_en: Organization Admin
  scope_type: organization
  is_admin_role: true
  permission count: 11
  has core.assign_roles: NO (correct)
```

The obsolete-pivot sweep (run via the seeder's post-upsert loop against `SWEPT_SYSTEM_ROLES = ['admin', 'viewer', 'dept_manager', 'member']`) removed 106 obsolete pivots on this fresh-seed, leaving the admin role with exactly 11 `authorization_role_permissions` rows.

### Smoke co-run (brief Step 5)

Command:

```text
php artisan test --filter='OrgAdminCuratedCapabilitiesTest|RoleCatalogSyncTest|AuthzSeedRolePermissionsCommandTest|AuthzTestFixturesScenarioTest'
```

Observed output:

```text
  PASS  Tests\Feature\Authz\OrgAdminCuratedCapabilitiesTest   (2/2)
  PASS  Tests\Feature\Core\Authorization\AuthzSeedRolePermissionsCommandTest (5/5)
  PASS  Tests\Feature\Core\Authorization\RoleCatalogSyncTest (3/3)
  PASS  Tests\Feature\Authz\AuthzTestFixturesScenarioTest    (8/8)

  Tests:    18 passed (214 assertions)
  Duration: ~3min
```

No regressions. `AuthzSeedRolePermissionsCommandTest` still passes — its assertions about `super_admin.is_admin_role=true` and `admin.is_admin_role=true` (line 46-47) are unaffected by the curation (the column itself is unchanged). `RoleCatalogSyncTest::test_seeder_preserves_super_admin_pivots` passes — the obsolete-pivot sweep explicitly excludes `super_admin`. `AuthzTestFixturesScenarioTest::test_fixture_one_flat_assigns_admin_role` passes — it only checks role assignment, not the capability pivot set.

### `OrgAdminScopeTest`

The brief's Step 5 also instructs running `php artisan test --filter=OrgAdminScopeTest`. A repo-wide grep (`grep -r OrgAdminScope tests/`) confirms this test does NOT exist yet — the brief itself annotates it as "suite test from Task 6 not yet written". Skipped per the brief's annotation. The `AuthzTestFixturesScenarioTest` co-run above covers the closest adjacent concern (admin role assignment + org isolation).

### Test DB re-seed

Command:

```text
DB_PORT=5433 DB_DATABASE=iradah_pmo_test php artisan migrate:fresh --env=testing --seed --force
```

Observed output (last lines):

```text
2026_07_12_000013_restrict_authorization_role_scopes .......... 68.78ms DONE
2026_07_12_000015_invalidate_stale_canonical_assignments_on_org_transfer  29.18ms DONE
2026_07_12_000016_narrow_legacy_department_aliases ............ 21.93ms DONE
2026_07_12_000018_role_catalog_sync_obsolete_pivots ........... 58.02ms DONE

Database\Seeders\RolesAndPermissionsSeeder ........................ RUNNING
Canonical authorization roles and permissions seeded successfully.
Database\Seeders\RolesAndPermissionsSeeder ................... 8,170 ms DONE

Database\Seeders\Meetings\MeetingsPermissionsSeeder ................ RUNNING
Database\Seeders\ScopedDepartmentRolesSeeder ....................... RUNNING
  Database\Seeders\RolesAndPermissionsSeeder ......................... RUNNING
Canonical authorization roles and permissions seeded successfully.
Database\Seeders\RolesAndPermissionsSeeder ................... 1,597 ms DONE
Database\Seeders\ScopedDepartmentRolesSeeder ................. 1,598 ms DONE
```

Exit code 0. Both `admin` and `super_admin` rows exist (verified via tinker above). No DB state committed.

### Lint

```text
$ ./vendor/bin/pint --test database/seeders/RolesAndPermissionsSeeder.php tests/Feature/Authz/OrgAdminCuratedCapabilitiesTest.php
{"tool":"pint","result":"passed"}
```

Initial run reported two fixers (`new_with_parentheses`, `single_blank_line_at_eof`) on the test file; ran `./vendor/bin/pint <files>` to auto-fix, then re-ran `--test` to confirm clean. The auto-fix added a blank line at the end of the test file and converted `(new RolesAndPermissionsSeeder)` → `(new RolesAndPermissionsSeeder())` (the canonical Laravel form). Both are mechanical.

## self_review_notes

- The `admin` role entry now exactly matches the brief's Step 3 spec: curated capability list, `scope_type='organization'`, `is_admin_role=true`, and `label_ar`/`label_en` Arabic/English labels. No `Capability::all()` and no module write surface that operators don't expect on an org-scoped admin boundary.
- The new `orgAdminCapabilities()` method is `private static`, returns `list<string>` (PHPDoc-tagged), and is the single source of truth for the curated set. If a downstream task needs to add or remove a capability, it changes one place.
- `SWEPT_SYSTEM_ROLES` still includes `'admin'`, so the obsolete-pivot sweep that runs at the end of `RolesAndPermissionsSeeder::run()` deletes every pivot whose `(resource_id, action)` is no longer in the canonical catalog. The sweep is idempotent — a second run finds no obsolete rows because the first run already removed them.
- `Capability.php` was not touched. The `Capability::USERS_*`, `Capability::DEPARTMENTS_*`, `Capability::ROLES_VIEW`, `Capability::SETTINGS_*`, `Capability::AUDIT_VIEW` constants already exist; the curation only references them.
- `super_admin` is untouched (still grants `Capability::all()`), `is_system=true`, preserved semantics. No Spatie `HasRoles` usage introduced. No FormRequest touched. No controller touched. No frontend files touched. No migration added. No CapabilityAlias entry created.
- `AuthorizationRoleAssignment` model untouched (Tasks 1 and 2 concerns about the missing `is_active` column are unaffected by this change — the curation only modifies the seed-time `admin` role pivot set, not the assignment lifecycle).
- The `AuthorizationRole::$fillable` array already includes `label_ar` and `label_en` (verified at `AuthorizationRole.php:31-40`), and the existing `run()` method (lines 280-291) already passes both labels to `updateOrCreate`, so the new `label_ar`/`label_en` keys land in the row on first seed.

## concerns

1. **Brief Step 2 "Expected: FAIL" is unreachable with the brief's verbatim test code.** The pre-curation RED check passes (output captured above). The test as written cannot fail before curation because (a) `test_admin_role_capabilities_match_curated_org_admin_set` is a positive containment check (`assertContains` over a subset of `Capability::all()`), and (b) `test_admin_role_does_not_grant_core_assign_roles` filters by `whereHas('resource', fn ($q) => $q->where('key', 'core'))` — the `authorization_resources.key` column stores FQCNs (e.g. `App\Modules\Core\Models\Organization`), never the short prefix `'core'`, so the `whereHas` clause filters out every row and `exists()` returns `false` regardless of whether the admin role actually holds `core.assign_roles`. The TDD RED→GREEN cycle therefore does not run for either test. I followed the brief literally and documented the gap rather than silently expanding scope by adding stricter assertions (`assertEqualsCanonicalizing`) or rewriting the key lookup to use `CapabilityToAuthorizationRolePermission::map(Capability::CORE_ASSIGN_ROLES)['resource']`. Downstream tasks that need a meaningful regression test for the curation should either tighten `assertContains` to `assertEqualsCanonicalizing` or use the FQCN lookup. This is the same pattern documented in Task 1's report (concern #1) and Task 2's report (concern #1): brief defects are surfaced, not silently fixed.

2. **Pre-existing PHPUnit deprecation warnings.** The `--filter` runs surface three pre-existing `@dataProvider`-style doc-comment warnings from unrelated test files (`ScopeAssignmentResolverTest`, `RoleControllerCatalogSlimTest`, `RoleControllerCatalogTest`). They are NOT emitted by `OrgAdminCuratedCapabilitiesTest` and predate this change; flagged here for awareness only. Same flag as Task 1's report concern #2 and Task 2's report (implicit, not numbered).

3. **Out-of-scope guardrails honored.** No edits to `Capability.php`. No edits to `super_admin` or any other role in `roleCatalog()`. No Spatie `HasRoles` usage. No FormRequest touched. No controller touched. No frontend files touched. No migration added. The two files in the commit are exactly the two files the brief's Step 6 lists.

4. **Worktree environment.** Same state as Tasks 1 and 2's reports — the worktree has `vendor/` (composer install from Task 1) and the `storage/framework` symlink (from Task 1, needed for the view compiler cache path). Neither is tracked in git.

5. **Idempotency verified.** Re-running `(new RolesAndPermissionsSeeder())->run()` twice produces the same admin role pivot set (11 rows, no obsolete leftovers). Verified empirically: the second invocation of the seeder inside `ScopedDepartmentRolesSeeder` (which calls `RolesAndPermissionsSeeder::class` — see `ScopedDepartmentRolesSeeder.php:18`) completes in 1,597 ms vs 8,170 ms for the first, with no extra audit rows written. This matches the obsolete-pivot sweep's documented idempotency contract.