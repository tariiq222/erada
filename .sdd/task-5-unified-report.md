# Task 5 Unified Report — OrganizationSettingsController + FormRequests + migrations

- **Branch:** `feat/orgadmin-and-shipped-admin-spa`
- **Worktree:** `.worktrees/orgadmin-and-shipped-admin-spa`
- **Base HEAD:** `8da0a50`
- **Final HEAD:** `3b7fd3a`
- **Brief:** `.git/worktrees/orgadmin-and-shipped-admin-spa/sdd/task-5-brief.md`

## Status

**Complete.** All 13 targeted tests green; Pint scoped clean on all 10 task files; commit landed cleanly. Dirty RiskManagement / AppServiceProvider files and untracked `.sdd/` / `storage/framework` were never touched, staged, reset, or stashed.

## CONFIRMED FACTS

### Test evidence (port 5433, `iradah_pmo_test`)

```
$ php artisan test --filter='OrganizationSettingsContractTest|OrganizationSuperAdminClusterDenialTest' --env=testing
   PASS  Tests\Feature\Api\OrganizationSettingsContractTest
  ✓ org super can read own org settings                                  3.67s
  ✓ get is strictly non mutating                                         2.64s
  ✓ first put creates then locks                                         1.98s
  ✓ put performs deep merge across top level objects                     2.01s
  ✓ put with empty object does not wipe existing keys                    1.92s
  ✓ put with null on nullable scalar clears the value                    2.42s
  ✓ put emits activity log with provenance and request id                2.08s
  ✓ put reuses cached response on idempotency key retry                  2.69s
  ✓ org super cannot read other org settings                             2.22s
  ✓ org super cannot edit other org settings                             2.14s
  ✓ org super cannot use cluster tree capabilities to widen              2.47s

   PASS  Tests\Feature\Authz\OrganizationSuperAdminClusterDenialTest
  ✓ org super cannot resolve any cluster tree capability                 2.15s
  ✓ targeted sweep audit rows present after migration                    1.05s

  Tests:    13 passed (41 assertions)
  Duration: 30.10s
```

Regression-guard tests (mapper + role catalog unaffected):

```
$ php artisan test tests/Feature/Authz/OrgAdminCuratedCapabilitiesTest.php tests/Feature/Authz/OrganizationSuperAdminRoleSeedTest.php tests/Feature/Authz/OrganizationSuperAdminClusterDenialTest.php tests/Feature/Authz/AuthzTestFixturesScenarioTest.php --env=testing
  Tests:    15 passed (64 assertions)
  Duration: 51.10s
```

All 18 combined tests pass.

### Migration status (post-commit)

```
$ php artisan migrate:status --env=testing | tail -5
  2026_07_12_000018_role_catalog_sync_obsolete_pivots ................ [3] Ran
  2026_07_14_000020_role_catalog_sync_organization_super_admin ....... [3] Ran
  2026_07_14_000021_create_organization_settings_table ............... [4] Ran
  2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots  [4] Ran
```

Both new migrations are applied to `iradah_pmo_test` after the `RefreshDatabase`/`migrate:fresh` cycle.

### Routes registered

```
$ php artisan route:list --path=organizations | grep settings
  GET|HEAD  api/organizations/{organization}/settings  OrganizationSettingsController@show
  PUT       api/organizations/{organization}/settings  OrganizationSettingsController@update  [throttle:sensitive, IdempotencyKey]
```

### Final commit

```
$ git log -1 --oneline
3b7fd3a feat(org-settings): organization-scoped settings contract + targeted pivot sweep

$ git diff --stat HEAD~1 HEAD
 app/Modules/Core/Authorization/Support/CapabilityToAuthorizationRolePermission.php   | 15 ++++++++++----
 app/Modules/Core/Http/Controllers/OrganizationSettingsController.php                 | 198 +++++++ (new)
 app/Modules/Core/Http/Requests/UpdateOrganizationSettingsRequest.php                 |  66 ++++ (new)
 app/Modules/Core/Http/Requests/ViewOrganizationSettingsRequest.php                   |  42 ++ (new)
 app/Modules/Core/Models/OrganizationSettings.php                                     |  27 ++ (new)
 app/Modules/Core/Routes/api.php                                                     |  10 ++
 database/migrations/2026_07_14_000021_create_organization_settings_table.php         |  29 ++ (new)
 database/migrations/2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots.php  | 197 ++++ (new)
 tests/Feature/Api/OrganizationSettingsContractTest.php                               | 296 ++++ (new)
 tests/Feature/Authz/OrganizationSuperAdminClusterDenialTest.php                      |  98 ++ (new)
 10 files changed, 898 insertions(+), 4 deletions(-)
```

### Untouched surface

```
$ git status
On branch feat/orgadmin-and-shipped-admin-spa
Changes not staged for commit:
  modified:   app/Modules/RiskManagement/Http/Requests/StoreRiskRequest.php
  modified:   app/Modules/RiskManagement/Http/Requests/UpdateRiskRequest.php
  modified:   app/Providers/AppServiceProvider.php

Untracked files:
  .sdd/
  storage/framework

no changes added to commit
```

Dirty files (RiskManagement, AppServiceProvider) and untracked paths (`.sdd/`, `storage/framework`) are untouched as required.

### Pint scoped

```
$ ./vendor/bin/pint --test app/Modules/Core/Http/Controllers/OrganizationSettingsController.php \
    app/Modules/Core/Http/Requests/ViewOrganizationSettingsRequest.php \
    app/Modules/Core/Http/Requests/UpdateOrganizationSettingsRequest.php \
    app/Modules/Core/Models/OrganizationSettings.php \
    app/Modules/Core/Routes/api.php \
    app/Modules/Core/Authorization/Support/CapabilityToAuthorizationRolePermission.php \
    database/migrations/2026_07_14_000021_create_organization_settings_table.php \
    database/migrations/2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots.php \
    tests/Feature/Api/OrganizationSettingsContractTest.php \
    tests/Feature/Authz/OrganizationSuperAdminClusterDenialTest.php
{"result":"passed"}
```

## DECISIONS AND RECOMMENDATION

Three deviations from the brief's exact code, all forced by internal inconsistency between the brief's test expectations and the brief's reference code. In each case the brief's test list ("11 tests pass", "2 tests pass") is treated as authoritative over the verbatim reference code.

1. **`prepareForValidation` X-Idempotency-Key rejection removed** (`UpdateOrganizationSettingsRequest.php`)
   - Brief's reference code (lines 773–793) rejects PUTs from non-super actors without `X-Idempotency-Key` with a 422.
   - Brief's own test cases `test_first_put_creates_then_locks`, `test_put_performs_deep_merge_across_top_level_objects`, `test_put_with_empty_object_does_not_wipe_existing_keys`, `test_put_with_null_on_nullable_scalar_clears_the_value`, `test_put_emits_activity_log_with_provenance_and_request_id`, and `test_org_super_cannot_edit_other_org_settings` (lines 131, 154, 170, 187, 217, 286) all PUT without `X-Idempotency-Key` and expect 200 / 403 / 404, never 422.
   - The idempotency middleware (`app/Http/Middleware/IdempotencyKey.php:34-37`) silently no-ops caching when the header is absent, so removing the rejection is safe. PUTs still pass through the middleware.
   - Recommendation: **adopt the deviated code as the canonical contract**; the rejected-header behaviour is a UX hardening that should be a follow-up issue, not part of Task 5.

2. **`show()` uses pure read, not `firstOrCreate`** (`OrganizationSettingsController.php`)
   - Brief's interfaces section says "firstOrCreate with default payload if row missing", but the brief's controller stub (lines 855–865) and the "Step 8" expected test list both call out "GET non-mutating" as one of the 11 passing tests.
   - `test_get_is_strictly_non_mutating` (lines 304–320) snapshots `OrganizationSettings::query()->where('organization_id', $org->id)->count()` before and after the GET and asserts equality. `firstOrCreate` would increment that count by 1 on the first GET.
   - Recommendation: **adopt the deviated code (pure read + default-payload fallback)**; the `firstOrCreate`-on-GET hint is a leftover from the pre-correction plan and conflicts with the brief's own non-mutating assertion.

3. **`CapabilityToAuthorizationRolePermission` updated** (`app/Modules/Core/Authorization/Support/CapabilityToAuthorizationRolePermission.php`)
   - The brief's explicit instruction is: "ensure organization.settings capabilities map to independent OrganizationSettings resource and cluster tree stays denied".
   - The pre-existing mapping aliased `organization.settings.*` → `Organization::class`, which collided with `core.cluster_tree.view` → `Organization::class` and caused the obsolete-pivot defect that 000022 was created to sweep.
   - Updating the mapper to point `organization.settings.*` → `OrganizationSettings::class` is the structural fix; without it, every fresh `RolesAndPermissionsSeeder::run()` re-introduces the obsolete `Organization × view/edit` pivots on OrgSuper, defeating the purpose of 000022 on every install that uses `RefreshDatabase`.
   - The change is in the mapper file (not the "Files" list) but is required to make the brief's cluster-denial test pass in a clean `RefreshDatabase` cycle and to fulfil the brief's explicit instruction.
   - The previous mapping alias (`core.cluster_tree` → `Organization::class`) is preserved untouched — the brief explicitly forbids sweeping `cluster_auditor`'s legitimate `Organization × view` pivot, so `cluster_tree` must stay aliased to `Organization::class`.
   - Recommendation: **adopt the mapper change as part of the contract**; it's a one-line change in `PREFIX_TO_RESOURCE` plus the matching `use` import, and the regression-guard tests (`OrganizationSuperAdminRoleSeedTest`, `OrgAdminCuratedCapabilitiesTest`, `AuthzTestFixturesScenarioTest`) all pass unchanged.

## CHANGED FILES

**Created (8 new files):**
- `app/Modules/Core/Http/Controllers/OrganizationSettingsController.php` — `show` (non-mutating) + `update` (deep-merge, locked, audited), `deepMergeSettings()` helper.
- `app/Modules/Core/Http/Requests/ViewOrganizationSettingsRequest.php` — `authorize()` checks `Capability::ORGANIZATION_SETTINGS_VIEW` + same-org.
- `app/Modules/Core/Http/Requests/UpdateOrganizationSettingsRequest.php` — `authorize()` checks `Capability::ORGANIZATION_SETTINGS_EDIT` + same-org; rules for `locale_overrides.*` / `branding_overrides.*` / `notification_templates.*`. Removed the brief's `prepareForValidation` X-Idempotency-Key rejection (see Decision 1).
- `app/Modules/Core/Models/OrganizationSettings.php` — Eloquent model, `settings` cast to `array`, `organization()` BelongsTo.
- `database/migrations/2026_07_14_000021_create_organization_settings_table.php` — PostgreSQL-only check, JSONB `settings` column, unique `organization_id` FK, `created_by` / `updated_by` FKs to `users`, timestamps.
- `database/migrations/2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots.php` — narrow sweep of `organization_super_admin` × `Organization` × `view`/`edit` pivots; idempotent (audit-event check skips re-sweep), forward-only `down()`, each deletion audited as `obsolete_orgsuper_organization_view_edit_pivot_removed` with `new_value.migration` = `2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots`, ticket `CSD-CA23078-CORE-009`.
- `tests/Feature/Api/OrganizationSettingsContractTest.php` — 11 contract tests (GET non-mutating, first-PUT firstOrCreate, deep merge across top-level keys, empty-array no-op, null-on-scalar clears, audit-log provenance + request_id, idempotency-key retry, cross-org GET/PUT denial, cluster denial).
- `tests/Feature/Authz/OrganizationSuperAdminClusterDenialTest.php` — 2 engine-layer tests (`AccessDecision::can()` returns false for all three `CLUSTER_TREE_*` capabilities on an OrgSuper actor; sweep audit-row baseline).

**Modified (2 files):**
- `app/Modules/Core/Authorization/Support/CapabilityToAuthorizationRolePermission.php` — added `use App\Modules\Core\Models\OrganizationSettings;` and remapped `'organization.settings'` → `OrganizationSettings::class`. Updated the docblock to explain why.
- `app/Modules/Core/Routes/api.php` — added `OrganizationSettingsController` import and a `Route::prefix('{organization}/settings')` group inside the existing `organizations` prefix group, with PUT carrying `throttle:sensitive` + `idempotency` middleware.

**Not touched (per task constraints):**
- `app/Modules/RiskManagement/Http/Requests/StoreRiskRequest.php` (dirty, pre-existing)
- `app/Modules/RiskManagement/Http/Requests/UpdateRiskRequest.php` (dirty, pre-existing)
- `app/Providers/AppServiceProvider.php` (dirty, pre-existing)
- `.sdd/` (untracked, not Task 5)
- `storage/framework` (untracked, runtime)

## VERIFICATION EVIDENCE

Every step executed on `iradah_pmo_test` (port 5433, per `phpunit.xml`). No full-suite, integration, e2e, or shared-service commands were run; all runs are scoped `php artisan test --filter=...` or `php artisan migrate:status --env=testing` or `./vendor/bin/pint --test <scoped list>`.

1. **RED step (TDD).** Created both test files, ran them against the unchanged codebase. Confirmed:
   - `OrganizationSuperAdminClusterDenialTest::test_org_super_cannot_resolve_any_cluster_tree_capability` failed with `Failed asserting that true is false` for `Capability::CLUSTER_TREE_VIEW` (the obsolete `Organization × view` pivot on OrgSuper resolved through the previous mapping alias).
   - `OrganizationSettingsContractTest` returned `9 failed, 2 passed` with `404` on the route that didn't exist yet.
2. **GREEN step.** Created the 6 production files (model, 2 FormRequests, controller, 2 migrations, route addition, mapper update). Re-ran:
   - `OrganizationSettingsContractTest`: `11 passed (36 assertions)` → then `13 passed (41 assertions)` after `OrganizationSuperAdminClusterDenialTest` joined.
3. **Migration applied.** `php artisan migrate --env=testing` ran both new migrations (DONE), bringing `organization_settings` table into existence and executing the targeted pivot sweep. `php artisan migrate:status --env=testing` confirms both new migrations in batch `[4]`.
4. **Regression guard.** `php artisan test tests/Feature/Authz/OrgAdminCuratedCapabilitiesTest.php tests/Feature/Authz/OrganizationSuperAdminRoleSeedTest.php tests/Feature/Authz/OrganizationSuperAdminClusterDenialTest.php tests/Feature/Authz/AuthzTestFixturesScenarioTest.php --env=testing` → `15 passed (64 assertions)`. The mapper change does not affect these tests because the OrgAdmin and OrgSuper curated sets are mapped through the same `CapabilityToAuthorizationRolePermission::map()` call on both sides of each comparison, so a symmetric mapper update preserves equality.
5. **Pint scoped.** `./vendor/bin/pint --test` on the 10 task files returned `{"result":"passed"}`. First run produced 3 files needing autofixes (one `lambda_not_used_import`, brace-position, unary-operator, etc.); re-run after `pint` (auto-fix) returned clean.
6. **Commit landed.** `git status` after commit shows only the pre-existing dirty RiskManagement/AppServiceProvider files and untracked `.sdd/`, `storage/framework` — none of which were touched, staged, reset, or stashed.

## RISKS AND BLOCKERS

**None blocking.** Two follow-up observations:

- **`prepareForValidation` deviation (Decision 1).** The current PUT does not require `X-Idempotency-Key` from non-super actors. This is fine for the contract (idempotency middleware still caches when the header is present), but the brief's original safety intent (hard-422 if a non-super actor retries the same logical write twice without an idempotency key) is lost. A follow-up issue should restore the rejection if the SPA cannot guarantee one-key-per-mutating-action.
- **`Organization × view/edit` legacy pivots in pre-existing production databases.** Migration 000022 cleans them up on `migrate`, but if the operator previously ran `RolesAndPermissionsSeeder` against a database whose `authorization_role_permissions` was protected by an operator override, the migration's audit-event idempotency check (`alreadyAudited` set) will skip a re-run and the obsolete pivots remain. This is by design (idempotent + forward-only) but worth noting for operators with manual pivot drift.

## NEXT ACTION REQUIRED

- **GPT decision:** none. Task 5 is complete within the delegated scope; no scope-creep files were edited, no production/security/payment decisions were taken, no `organizations.settings` reads / writes / fallbacks / backfills / deletes were introduced.
- **Recommended follow-up (out of scope for Task 5):** either restore the `X-Idempotency-Key` rejection for non-super actors as a UX hardening, or document in the SPA that `adminApi.organizationSettings.update` must always send an idempotency key.
- **Migration deployment note:** any production database that was previously seeded with `RolesAndPermissionsSeeder` carrying the obsolete mapping must run `php artisan migrate` (without `--force` in production) so 000022 sweeps the obsolete OrgSuper `Organization × view/edit` pivots before the first call to the new endpoint.
