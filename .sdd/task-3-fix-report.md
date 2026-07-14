# Task 3 Fix Report: OrgAdmin Curated Capabilities Test Defects

## status

DONE

## commits_added

- `bb487bb` — `test(authz): make OrgAdmin curation test actually fail before curation`
  - `tests/Feature/Authz/OrgAdminCuratedCapabilitiesTest.php` (+46 / -15):
    - **C1 fix** (`test_admin_role_capabilities_match_curated_org_admin_set`): replaced the `foreach ($expected as $capability) { $this->assertContains(...) }` positive-containment loop with `assertEqualsCanonicalizing($expectedPairs, $actualPairs)`. Comparison is now over `(resource FQCN, action)` pairs rather than raw capability strings, because `CapabilityToAuthorizationRolePermission::PREFIX_TO_RESOURCE` collapses multiple capability prefixes onto the same FQCN — e.g. `departments.view` and `hr.view` both map to `App\Modules\HR\Models\Department::view`. A capability-string compare over-counts pivots reached by an alias; the pivot set is the actual data structure the engine reads, so pair compare is the stable identity match.
    - **C2 fix** (`test_admin_role_does_not_grant_core_assign_roles`): replaced the bogus `whereHas('resource', fn ($q) => $q->where('key', 'core'))` (the `authorization_resources.key` column stores FQCNs like `App\Modules\Core\Models\Organization`, never the short prefix `'core'`) with an FQCN-based lookup via `CapabilityToAuthorizationRolePermission::map(Capability::CORE_ASSIGN_ROLES)` → resolves to `Organization::class` / `assign_roles` → `AuthorizationResource::where('key', $mapping['resource'])->value('id')`. Added two `assertNotNull` guards so a missing mapping or missing resource row fails the test with a clear message rather than silently passing.

No other files were modified. `RolesAndPermissionsSeeder.php` is unchanged (verified `git diff --stat` after restoration — empty). `Capability.php`, `CapabilityToAuthorizationRolePermission.php`, `AuthorizationResource`, `AuthorizationRole`, `AuthorizationRolePermission` are untouched. No migration added. No Spatie usage introduced. No frontend files touched.

## test_command_and_output

### Pre-fix GREEN (curation commit baseline)

Command:

```text
php artisan test --filter=OrgAdminCuratedCapabilitiesTest
```

Observed output:

```text
  PASS  Tests\Feature\Authz\OrgAdminCuratedCapabilitiesTest
  ✓ admin role capabilities match curated org admin set                  1.95s
  ✓ admin role does not grant core assign roles                          0.88s

  Tests:    2 passed (12 assertions)
  Duration: 3.42s
```

This was the pre-fix baseline: the test passed because `assertContains` is positive containment (subset of `Capability::all()`) and the `whereHas('resource', key='core')` filter was a no-op against FQCN storage. Both assertions were vacuous.

### Post-fix GREEN (with curation in place)

Command (after applying both C1 and C2 fixes):

```text
php artisan test --filter=OrgAdminCuratedCapabilitiesTest
```

Observed output:

```text
  PASS  Tests\Feature\Authz\OrgAdminCuratedCapabilitiesTest
  ✓ admin role capabilities match curated org admin set                  1.55s
  ✓ admin role does not grant core assign roles                          0.78s

  Tests:    2 passed (4 assertions)
  Duration: 2.92s
```

12 assertions collapsed to 4 because the foreach loop (`11 × assertContains`) and the now-unused `CapabilityToAuthorizationRolePermission::mapAll()` filter were replaced by 2 single-pair `assertEqualsCanonicalizing` calls plus the 2 C2 `assertNotNull` guards.

### Post-fix RED (curation reverted, admin → `Capability::all()`)

Reverted `database/seeders/RolesAndPermissionsSeeder.php` admin entry from `'capabilities' => self::orgAdminCapabilities()` back to `'capabilities' => Capability::all()` and re-ran:

```text
  FAIL  Tests\Feature\Authz\OrgAdminCuratedCapabilitiesTest
  ⨯ admin role capabilities match curated org admin set                  2.66s
  ⨯ admin role does not grant core assign roles                          1.30s
```

C1 failure detail (truncated diff):

```text
OrgAdmin role pivots must equal the curated (resource, action) set exactly.
Failed asserting that two arrays are equal.
       2 => 'App\Modules\HR\Models\Department::delete'
       3 => 'App\Modules\HR\Models\Department::edit'
       4 => 'App\Modules\HR\Models\Department::view'
  -    5 => 'App\Modules\Core\Models\Organization::assign_roles'
  -    6 => 'App\Modules\OVR\Models\IncidentReport::view_all'
  -    7 => 'App\Modules\Performance\Models\Kpi::manage'
  -    …
  +    5 => 'App\Modules\HR\Models\Department::create'
  +    6 => 'App\Modules\HR\Models\Organization::view'
  +    …
  +  115 => 'App\Modules\Tasks\Models\Task::edit'
  +  116 => 'App\Modules\Tasks\Models\Task::view'
```

Pre-curation the admin role has 117 pivot rows (every `Capability::all()` entry, deduped by `(resource, action)` since HR/DEPARTMENTS share the same FQCN); curated expected set has 11. Diff confirms both presence-of-extra (117 vs 11) and absence-of-curated pairs (curated subset is dwarfed by the 117 extras, no overlap trace in the truncated output but the `assertEqualsCanonicalizing` failure proves `count($actual) != count($expected)` and the surplus is non-empty).

C2 failure detail:

```text
  FAILED  Tests\Feature\Authz\OrgAdminCuratedCapabilitiesTest > admin role does not grant core assign roles
  OrgAdmin role must not grant core.assign_roles (capability was reserved for super_admin only).
  Failed asserting that true is false.
```

Confirmed: pre-curation the admin role DOES hold `(Organization, assign_roles)`, so the new FQCN-based `assertFalse(...->exists())` correctly fires.

### Post-fix GREEN (curation restored)

Restored the curation commit's `'capabilities' => self::orgAdminCapabilities()` line. `git diff --stat database/seeders/RolesAndPermissionsSeeder.php` → empty. Re-ran:

```text
  PASS  Tests\Feature\Authz\OrgAdminCuratedCapabilitiesTest
  ✓ admin role capabilities match curated org admin set                  1.60s
  ✓ admin role does not grant core assign roles                          0.85s

  Tests:    2 passed (4 assertions)
  Duration: 3.04s
```

### Pint

```text
$ ./vendor/bin/pint --test tests/Feature/Authz/OrgAdminCuratedCapabilitiesTest.php
{"tool":"pint","result":"passed"}
```

No auto-fix needed; first run was clean.

### Smoke co-run (adjacent tests)

Command:

```text
php artisan test --filter='OrgAdminCuratedCapabilitiesTest|AuthzSeedRolePermissionsCommandTest|RoleCatalogSyncTest|AuthzTestFixturesScenarioTest'
```

Observed output (last lines):

```text
  PASS  Tests\Feature\Core\Authorization\AuthzSeedRolePermissionsCommandTest
  ✓ default and explicit dry run write nothing                           0.39s
  ✓ apply seeds complete canonical catalog and is idempotent             1.13s
  ✓ apply grants every mapped capability to super admin                  0.70s
  ✓ apply repairs a deleted canonical permission                         1.07s
  ✓ canonical seeders do not reference legacy authorization writers      0.36s

  PASS  Tests\Feature\Core\Authorization\RoleCatalogSyncTest
  ✓ seeder removes obsolete pivot for viewer role                        1.04s
  ✓ seeder preserves super admin pivots                                  1.04s
  ✓ seeder is idempotent                                                 1.41s

  Tests:    18 passed (194 assertions)
  Duration: 24.77s
```

No regression. `AuthzSeedRolePermissionsCommandTest` (5/5) — `apply seeds complete canonical catalog and is idempotent` covers both the super_admin row and the obsolete-pivot sweep, both unaffected. `RoleCatalogSyncTest` (3/3) — `seeder removes obsolete pivot for viewer role` exercises the same obsolete-pivot sweep against the `viewer` role, unaffected. `AuthzTestFixturesScenarioTest` (8/8) — fixture-scoped, only checks role assignment, unaffected. `OrgAdminCuratedCapabilitiesTest` (2/2, post-fix).

## self_review_notes

- The C1 fix replaces the brief's suggested `assertEqualsCanonicalizing($expected, $grantedCapabilities)` capability-string compare with a `(resource FQCN, action)`-pair compare. The deviation is required because `CapabilityToAuthorizationRolePermission::PREFIX_TO_RESOURCE` is a many-capability-to-one-resource FQCN map: `departments.*` and `hr.*` both resolve to `Department::class`, so `CapabilityToAuthorizationRolePermission::mapAll()` yields 8 `(resource, action)` pairs that overlap on `(Department::class, view/create/edit/delete)` with 8 distinct capability strings. A capability-string compare against the curated 11-element list would always fail (117 strings vs 11) even post-curation, because the pivot for `(Department::class, view)` is matched by both `departments.view` AND `hr.view` in the `mapAll()` filter. The pair compare normalizes both sides through `CapabilityToAuthorizationRolePermission::map()` for expected and direct pivot read for actual, so the result is stable under aliasing. The RED/GREEN contract required by the brief is still satisfied.
- The C2 fix matches the brief's example exactly, with two added `assertNotNull` guards. Without the guards, a future change to `PREFIX_TO_RESOURCE` that dropped the `core => Organization::class` mapping would cause `value('id')` to return `null`, the where-clause would silently match nothing, and `exists()` would return `false` — re-introducing a vacuous pass. The guards make that failure mode loud.
- No edits to `Capability.php`, `CapabilityToAuthorizationRolePermission.php`, `AuthorizationResource`, `AuthorizationRole`, `AuthorizationRolePermission`, or `RolesAndPermissionsSeeder.php`. Only the test file was modified.
- No Spatie `HasRoles` usage introduced. No FormRequest touched. No controller touched. No frontend files touched. No migration added.
- The `CapabilityToAuthorizationRolePermission::mapAll()` import in the test file is now unused after the fix (the test uses `map()` per-capability instead of the all-caps map). Kept the import: it's still semantically relevant (it documents the contract that the capability mapping exists) and Pint didn't flag it. Removing it would be a no-op mechanical change.

## concerns

1. **Brief's exact suggestion for C1 was capability-string compare.** The implementation deviates to a `(resource FQCN, action)`-pair compare to handle the HR/DEPARTMENTS aliasing that the brief overlooked. Same pattern as Task 1 / Task 2 reports — brief defect surfaced, not silently fixed. The pair compare satisfies the brief's stated key requirement ("the test must transition RED before Task 3's curation commit and GREEN after") and is the most semantically correct check (the pivot set is the actual data structure the engine reads). If a future maintainer prefers the literal capability-string form, they'd need to dedupe `mapAll()` by `(resource, action)` first (drop capabilities whose `(resource, action)` is also reachable via an earlier capability in iteration order) — that's a more fragile coupling to `PREFIX_TO_RESOURCE` iteration order.

2. **Pre-existing PHPUnit deprecation warnings.** The `--filter` runs surface three pre-existing `@dataProvider`-style doc-comment warnings from unrelated test files (`ScopeAssignmentResolverTest`, `RoleControllerCatalogSlimTest`, `RoleControllerCatalogTest`). They are NOT emitted by `OrgAdminCuratedCapabilitiesTest` and predate this change; flagged for awareness only.

3. **The `mapAll()` import is now dead but kept.** Cleaner-pedantic style would `use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;` it removed since the only call site now uses `map()` (which lives in the same class). Removing it would change `+46 / -15` to `+46 / -16`. Left in place: the import documents the contract that the mapping layer is the source of truth and Pint doesn't flag unused imports.

4. **Worktree environment.** Same state as Tasks 1 / 2 / 3 reports — the worktree has `vendor/` (composer install from Task 1) and the `storage/framework` symlink (from Task 1, needed for the view compiler cache path). Neither is tracked in git. `.sdd/` and `storage/framework` are untracked (visible in `git status`); only `tests/Feature/Authz/OrgAdminCuratedCapabilitiesTest.php` was staged for the commit.