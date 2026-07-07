<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\CapabilityAlias;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

/**
 * BackfillAuthorizationRolePermissionsTest -- Phase 2.1.1.
 *
 * The migration `2026_07_03_000010_backfill_authorization_role_permissions`
 * is an ADDITIVE backfill from the legacy Spatie `role_has_permissions`
 * pivot (guard_name='web') onto the new `authorization_role_permissions`
 * pivot, joined through `CapabilityAlias` and
 * `CapabilityToAuthorizationRolePermission`. This test pins:
 *
 *  1. up() creates AuthorizationRole / AuthorizationResource /
 *     AuthorizationRolePermission rows for every mappable legacy pair.
 *  2. up() is idempotent -- second up() produces zero new pivots and zero
 *     new audit markers.
 *  3. A pre-existing pivot row that the migration did NOT write survives
 *     down().
 *  4. down() deletes only the pivot rows this migration wrote and the
 *     matching audit markers; AuthorizationRole / AuthorizationResource /
 *     Spatie tables are left intact.
 *  5. Unmapped / transition-alias legacy permissions (those whose
 *     CapabilityAlias::toCapability() returns null) produce no pivot row
 *     and no audit marker (no widening).
 *  6. The full Spatie fingerprint (roles, permissions, role_has_permissions,
 *     model_has_permissions, model_has_roles) is unchanged across an
 *     up/down/up cycle.
 *  7. Phase 1 super_admin pivot rows that exist BEFORE the backfill runs
 *     are not deleted, not modified, and not audit-marked by it.
 */
class BackfillAuthorizationRolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const AUDIT_EVENT = 'legacy_backfill_000010';

    private const MIGRATION_NAME = '2026_07_03_000010_backfill_authorization_role_permissions';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Phase 2.1.1 backfill test is PostgreSQL-only.');
        }

        // The backfill reads Spatie legacy data, so the seeder must run
        // before any assertion checks pivot counts. RefreshDatabase
        // already ran migrate:fresh BEFORE setUp is called -- which
        // means our backfill migration ran with an empty
        // `role_has_permissions` table and produced no rows.
        //
        // To make the test environment match the production scenario
        // (Spatie seed -> backfill migrate), we re-run the seeder, then
        // invoke the migration's up() DIRECTLY via the anonymous class
        // returned by the migration file. This avoids the destructive
        // migrate:rollback dance (which would un-apply migrations that
        // earlier phases depend on) and re-runs only our backfill.
        $this->seed(RolesAndPermissionsSeeder::class);

        $migration = require database_path('migrations/2026_07_03_000010_backfill_authorization_role_permissions.php');
        $migration->up();
    }

    // ---------------------------------------------------------------------
    // Test 1
    // ---------------------------------------------------------------------

    public function test_up_creates_authz_roles_resources_and_pivots_for_mapped_legacy_pairs(): void
    {
        // Compute expected pivot count from the seeded Spatie state, NOT from
        // a hardcoded number. This makes the test resilient to new entries in
        // Permission / Capability / CapabilityAlias.
        $expectedPivotKeys = $this->expectedBackfillPivotKeys();
        $expectedResourceKeys = $this->expectedBackfillResourceKeys();
        $expectedRoleNames = $this->expectedBackfillRoleNames();

        $this->assertNotEmpty($expectedPivotKeys, 'Mapper produced no mappable pairs; the test cannot assert anything meaningful.');
        $this->assertNotEmpty($expectedResourceKeys, 'Mapper produced no distinct resources; the test cannot assert anything meaningful.');
        $this->assertNotEmpty($expectedRoleNames, 'Seeder produced no Spatie roles; the test cannot assert anything meaningful.');

        // Every Spatie role that has at least one mappable permission must
        // have a corresponding authorization_roles row.
        $authzRoleNames = AuthorizationRole::pluck('name')->all();
        foreach (array_keys($expectedRoleNames) as $roleName) {
            $this->assertContains(
                $roleName,
                $authzRoleNames,
                "AuthorizationRole row missing for legacy Spatie role [{$roleName}]."
            );
        }

        // Every resource FQCN referenced by any mappable pair must exist as
        // an AuthorizationResource row (the key column is the unique FQCN).
        $authzResourceKeys = AuthorizationResource::pluck('key')->all();
        foreach (array_keys($expectedResourceKeys) as $resourceKey) {
            $this->assertContains(
                $resourceKey,
                $authzResourceKeys,
                "AuthorizationResource row missing for resource [{$resourceKey}]."
            );
        }

        // Pivot row count must equal the number of distinct
        // (role_name, resource_key, action) triples that the mapper resolves.
        $this->assertSame(
            count($expectedPivotKeys),
            AuthorizationRolePermission::count(),
            'AuthorizationRolePermission count does not match the expected number of distinct mapped (role, resource, action) triples.'
        );

        // Every expected triple must exist in the pivot table.
        $actualPivotKeys = DB::table('authorization_role_permissions as arp')
            ->join('authorization_roles as ar', 'ar.id', '=', 'arp.authorization_role_id')
            ->join('authorization_resources as ares', 'ares.id', '=', 'arp.authorization_resource_id')
            ->get(['ar.name as role_name', 'ares.key as resource_key', 'arp.action'])
            ->map(fn ($row) => $row->role_name.'|'.$row->resource_key.'|'.$row->action)
            ->all();

        foreach (array_keys($expectedPivotKeys) as $expectedKey) {
            $this->assertContains(
                $expectedKey,
                $actualPivotKeys,
                "Expected pivot row [{$expectedKey}] is missing after up()."
            );
        }
    }

    // ---------------------------------------------------------------------
    // Test 2
    // ---------------------------------------------------------------------

    public function test_up_is_idempotent(): void
    {
        $pivotCountBefore = AuthorizationRolePermission::count();
        $auditCountBefore = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->count();

        $this->assertGreaterThan(0, $pivotCountBefore, 'First up() did not produce any pivot rows; cannot assert idempotency.');
        $this->assertGreaterThan(0, $auditCountBefore, 'First up() did not produce any audit markers; cannot assert idempotency.');

        // Roll back and re-apply the backfill DIRECTLY (the migration is an
        // anonymous class, so `migrate:rollback --step=1` would roll back a
        // later unrelated migration in this branch). Invoking the class
        // keeps the rollback/re-apply tight to this backfill.
        $this->rollbackBackfillMigration();
        $this->assertSame(0, DB::table('permission_audits')->where('event', self::AUDIT_EVENT)->count(),
            'down() did not remove the audit markers it should own.');
        $this->assertSame(0, AuthorizationRolePermission::count(),
            'down() did not remove the pivot rows it should own.');

        $this->reapplyBackfillMigration();

        $this->assertSame(
            $pivotCountBefore,
            AuthorizationRolePermission::count(),
            'Second up() produced a different pivot count; backfill is not idempotent.'
        );
        $this->assertSame(
            $auditCountBefore,
            DB::table('permission_audits')->where('event', self::AUDIT_EVENT)->count(),
            'Second up() produced a different audit marker count; backfill is not idempotent.'
        );
    }

    // ---------------------------------------------------------------------
    // Test 3
    // ---------------------------------------------------------------------

    public function test_down_preserves_pre_existing_pivot_rows_not_audit_marked(): void
    {
        // Roll back the backfill to a clean Phase 1 state.
        $this->rollbackBackfillMigration();

        // Insert a synthetic pivot row that the backfill did NOT write (it
        // has no audit marker and uses a (resource, action) pair that the
        // backfill never produces from any legacy permission). This
        // simulates a Phase 1 / hand-written pivot row.
        $superAdminRole = AuthorizationRole::firstOrCreate(
            ['name' => 'super_admin'],
            ['label' => 'Super Admin']
        );
        $projectResource = AuthorizationResource::firstOrCreate(
            ['key' => 'App\\Modules\\Projects\\Models\\Project'],
            ['label' => 'Project']
        );

        $markerAction = 'phase1_only_marker_'.uniqid('', true);
        DB::table('authorization_role_permissions')->insert([
            'authorization_role_id' => $superAdminRole->id,
            'authorization_resource_id' => $projectResource->id,
            'action' => $markerAction,
        ]);

        // Confirm the marker pivot is present and the audit table has no
        // marker for it (so down() cannot find a marker to follow).
        $this->assertDatabaseHas('authorization_role_permissions', [
            'authorization_role_id' => $superAdminRole->id,
            'authorization_resource_id' => $projectResource->id,
            'action' => $markerAction,
        ]);
        $markerAuditCountBefore = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->whereJsonContains('new_value', ['action' => $markerAction])
            ->count();
        $this->assertSame(0, $markerAuditCountBefore, 'Pre-test invariant: the marker action should not have an audit row yet.');

        // Re-apply the backfill; then roll it back again. The Phase 1 row
        // must survive the second down() because down() only deletes pivot
        // rows that have a matching audit marker.
        $this->reapplyBackfillMigration();
        $this->rollbackBackfillMigration();

        $this->assertDatabaseHas('authorization_role_permissions', [
            'authorization_role_id' => $superAdminRole->id,
            'authorization_resource_id' => $projectResource->id,
            'action' => $markerAction,
        ]);

        // Leave the environment consistent for any later test in the class.
        $this->reapplyBackfillMigration();
    }

    // ---------------------------------------------------------------------
    // Test 4
    // ---------------------------------------------------------------------

    public function test_down_only_removes_audit_marked_pivots_and_audit_markers(): void
    {
        $spatieRolesBefore = SpatieRole::orderBy('id')->pluck('id', 'name')->all();
        $spatiePermissionsBefore = SpatiePermission::orderBy('id')->pluck('id', 'name')->all();
        $spatieRoleHasPermissionsBefore = DB::table('role_has_permissions')
            ->orderBy('permission_id')->orderBy('role_id')
            ->get(['permission_id', 'role_id'])->toArray();
        $spatieModelHasRolesBefore = DB::table('model_has_roles')
            ->orderBy('role_id')->orderBy('model_id')->orderBy('model_type')
            ->get(['role_id', 'model_id', 'model_type'])->toArray();

        $rolesCountBefore = AuthorizationRole::count();
        $resourcesCountBefore = AuthorizationResource::count();
        $pivotCountBefore = AuthorizationRolePermission::count();
        $auditMarkerCountBefore = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->count();

        $this->assertGreaterThan(0, $pivotCountBefore, 'Pre-test invariant: pivot table should have rows.');
        $this->assertGreaterThan(0, $auditMarkerCountBefore, 'Pre-test invariant: audit table should have markers.');

        $this->rollbackBackfillMigration();

        // 1. Every audit marker this migration wrote is gone.
        $this->assertSame(
            0,
            DB::table('permission_audits')->where('event', self::AUDIT_EVENT)->count(),
            'down() left legacy_backfill_000010 audit markers behind.'
        );

        // 2. Every pivot row this migration wrote is gone (composite
        //    primary key dedupes, so all post-backfill pivot rows came
        //    from this migration in this test environment).
        $this->assertSame(
            0,
            AuthorizationRolePermission::count(),
            'down() left backfilled pivot rows behind.'
        );

        // 3. AuthorizationRole / AuthorizationResource rows are intact --
        //    down() must NEVER delete role or resource catalog rows.
        $this->assertSame(
            $rolesCountBefore,
            AuthorizationRole::count(),
            'down() deleted authorization_roles rows; it must leave the catalog intact.'
        );
        $this->assertSame(
            $resourcesCountBefore,
            AuthorizationResource::count(),
            'down() deleted authorization_resources rows; it must leave the catalog intact.'
        );

        // 4. Legacy Spatie tables are fingerprint-identical.
        $this->assertSame(
            $spatieRolesBefore,
            SpatieRole::orderBy('id')->pluck('id', 'name')->all(),
            'Spatie roles were mutated by down().'
        );
        $this->assertSame(
            $spatiePermissionsBefore,
            SpatiePermission::orderBy('id')->pluck('id', 'name')->all(),
            'Spatie permissions were mutated by down().'
        );
        $this->assertEquals(
            $spatieRoleHasPermissionsBefore,
            DB::table('role_has_permissions')
                ->orderBy('permission_id')->orderBy('role_id')
                ->get(['permission_id', 'role_id'])->toArray(),
            'Spatie role_has_permissions was mutated by down().'
        );
        $this->assertEquals(
            $spatieModelHasRolesBefore,
            DB::table('model_has_roles')
                ->orderBy('role_id')->orderBy('model_id')->orderBy('model_type')
                ->get(['role_id', 'model_id', 'model_type'])->toArray(),
            'Spatie model_has_roles was mutated by down().'
        );

        // Leave the environment consistent for any later test in the class.
        $this->reapplyBackfillMigration();
    }

    // ---------------------------------------------------------------------
    // Test 5
    // ---------------------------------------------------------------------

    public function test_unmapped_or_transition_alias_legacy_permissions_produce_no_pivot_or_widening(): void
    {
        // Snapshot what existed before we look at the backfill's output.
        $resourceCountBefore = AuthorizationResource::count();
        $pivotCountBefore = AuthorizationRolePermission::count();
        $auditMarkerCountBefore = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->count();

        // Find every legacy permission name the seeder granted to a Spatie
        // role that CapabilityAlias::toCapability() cannot resolve (i.e.
        // transition aliases).
        $unmappedLegacyNames = [];
        foreach (SpatiePermission::where('guard_name', 'web')->get(['name']) as $permission) {
            if (CapabilityAlias::toCapability($permission->name) === null) {
                $unmappedLegacyNames[] = $permission->name;
            }
        }
        $this->assertNotEmpty($unmappedLegacyNames, 'Test invariant: there must be at least one transition-alias permission to assert against.');

        // The backfill may not have written audit markers for these
        // (zero new pivot rows from unmapped pairs). Assert NO audit row
        // references an unmapped legacy_permission_name.
        $auditRows = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->get();

        foreach ($auditRows as $auditRow) {
            $newValue = json_decode($auditRow->new_value, true);
            $legacyName = $newValue['legacy_permission_name'] ?? null;

            $this->assertNotContains(
                $legacyName,
                $unmappedLegacyNames,
                "Backfill wrote an audit row for an unmapped transition alias [{$legacyName}]; the migration must skip pairs whose CapabilityAlias resolves to null."
            );
        }

        // Pivot table growth: the backfill did NOT add pivots beyond the
        // expected mapped set. Compute the expected mapped count and assert
        // it equals the live count.
        $expectedPivotKeys = $this->expectedBackfillPivotKeys();
        $this->assertSame(
            count($expectedPivotKeys),
            AuthorizationRolePermission::count(),
            'Pivot count drifted from the mapped-pair count; backfill may be widening onto unmapped pairs.'
        );

        // Resource catalog growth: the backfill must not introduce a new
        // resource FQCN that did not come from the mapped set.
        $expectedResourceKeys = array_keys($this->expectedBackfillResourceKeys());
        $actualResourceKeys = AuthorizationResource::pluck('key')->all();
        foreach ($actualResourceKeys as $actualKey) {
            $this->assertContains(
                $actualKey,
                $expectedResourceKeys,
                "AuthorizationResource [{$actualKey}] was created by the backfill but does not appear in the mapper output; the migration may be widening onto unmapped pairs."
            );
        }

        // Sanity: invariants hold even though we did not mutate state.
        $this->assertGreaterThan(0, $pivotCountBefore);
        $this->assertGreaterThan(0, $auditMarkerCountBefore);
        $this->assertSame($resourceCountBefore, AuthorizationResource::count());
        $this->assertSame($pivotCountBefore, AuthorizationRolePermission::count());
        $this->assertSame($auditMarkerCountBefore, DB::table('permission_audits')->where('event', self::AUDIT_EVENT)->count());
    }

    // ---------------------------------------------------------------------
    // Test 6
    // ---------------------------------------------------------------------

    public function test_spatie_tables_are_not_mutated_across_up_down_cycle(): void
    {
        $spatieRolesBefore = SpatieRole::orderBy('id')->get(['id', 'name', 'guard_name'])->toArray();
        $spatiePermissionsBefore = SpatiePermission::orderBy('id')->get(['id', 'name', 'guard_name'])->toArray();
        $spatieRoleHasPermissionsBefore = DB::table('role_has_permissions')
            ->orderBy('permission_id')->orderBy('role_id')
            ->get(['permission_id', 'role_id'])->toArray();
        $spatieModelHasPermissionsBefore = DB::table('model_has_permissions')
            ->orderBy('permission_id')->orderBy('model_id')->orderBy('model_type')
            ->get(['permission_id', 'model_id', 'model_type'])->toArray();
        $spatieModelHasRolesBefore = DB::table('model_has_roles')
            ->orderBy('role_id')->orderBy('model_id')->orderBy('model_type')
            ->get(['role_id', 'model_id', 'model_type'])->toArray();

        // Up/down/up cycle on the backfill itself -- we deliberately use the
        // migration class directly so a later unrelated migration is not
        // rolled back by `migrate:rollback --step=1`.
        $this->rollbackBackfillMigration();
        $this->reapplyBackfillMigration();
        $this->rollbackBackfillMigration();
        $this->reapplyBackfillMigration();

        $this->assertSame(
            $spatieRolesBefore,
            SpatieRole::orderBy('id')->get(['id', 'name', 'guard_name'])->toArray(),
            'Spatie roles table mutated across up/down cycle.'
        );
        $this->assertSame(
            $spatiePermissionsBefore,
            SpatiePermission::orderBy('id')->get(['id', 'name', 'guard_name'])->toArray(),
            'Spatie permissions table mutated across up/down cycle.'
        );
        $this->assertEquals(
            $spatieRoleHasPermissionsBefore,
            DB::table('role_has_permissions')
                ->orderBy('permission_id')->orderBy('role_id')
                ->get(['permission_id', 'role_id'])->toArray(),
            'Spatie role_has_permissions table mutated across up/down cycle.'
        );
        $this->assertEquals(
            $spatieModelHasPermissionsBefore,
            DB::table('model_has_permissions')
                ->orderBy('permission_id')->orderBy('model_id')->orderBy('model_type')
                ->get(['permission_id', 'model_id', 'model_type'])->toArray(),
            'Spatie model_has_permissions table mutated across up/down cycle.'
        );
        $this->assertEquals(
            $spatieModelHasRolesBefore,
            DB::table('model_has_roles')
                ->orderBy('role_id')->orderBy('model_id')->orderBy('model_type')
                ->get(['role_id', 'model_id', 'model_type'])->toArray(),
            'Spatie model_has_roles table mutated across up/down cycle.'
        );
    }

    // ---------------------------------------------------------------------
    // Test 7
    // ---------------------------------------------------------------------

    public function test_phase1_pre_existing_super_admin_rows_survive_backfill_and_are_not_marked(): void
    {
        // 1. Roll back the backfill so we can simulate "Phase 1 ran first".
        $this->rollbackBackfillMigration();

        // 2. Run the Phase 1 seed command -- this is what produced the
        //    super_admin pivot rows that must survive the backfill.
        Artisan::call('authz:seed-role-permissions', ['--apply' => true]);

        $phase1PivotKeys = $this->currentPivotKeys();
        $this->assertNotEmpty($phase1PivotKeys, 'Phase 1 seed did not produce any pivot rows; the test cannot assert anything meaningful.');

        // 2b. Snapshot the Phase 1 pivot identity in
        //     (authorization_role_id, authorization_resource_id, action)
        //     form so we can detect "audit row references a Phase 1 row".
        $phase1IdentityKeys = DB::table('authorization_role_permissions')
            ->get(['authorization_role_id', 'authorization_resource_id', 'action'])
            ->map(fn ($row) => $row->authorization_role_id.'|'.$row->authorization_resource_id.'|'.$row->action)
            ->all();
        $phase1IdentitySet = array_fill_keys($phase1IdentityKeys, true);

        // 3. Re-apply the backfill on top of the Phase 1 state.
        $this->reapplyBackfillMigration();

        // 4. Every Phase 1 pivot row must still exist with the same identity.
        $postBackfillPivotKeys = $this->currentPivotKeys();
        foreach ($phase1PivotKeys as $key) {
            $this->assertContains(
                $key,
                $postBackfillPivotKeys,
                "Phase 1 pivot row [{$key}] was lost across the backfill up()."
            );
        }

        // 5. Every audit marker the backfill wrote must reference a freshly
        //    created pivot (not a Phase 1 row). Phase 1 rows are NOT marked.
        $auditRows = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->get();

        $this->assertNotEmpty($auditRows, 'Backfill wrote no audit markers after re-applying onto Phase 1 state; cannot assert marker hygiene.');

        foreach ($auditRows as $auditRow) {
            $newValue = json_decode($auditRow->new_value, true);

            // Audit markers must carry the migration tag and both legacy ids.
            $this->assertSame(
                self::MIGRATION_NAME,
                $newValue['migration'] ?? null,
                'Backfill audit row is missing the migration tag in new_value.'
            );
            $this->assertNotEmpty(
                $newValue['legacy_permission_id'] ?? null,
                'Backfill audit row is missing legacy_permission_id; cannot have come from this backfill.'
            );
            $this->assertNotEmpty(
                $newValue['legacy_permission_name'] ?? null,
                'Backfill audit row is missing legacy_permission_name; cannot have come from this backfill.'
            );

            // The (authorization_role_id, authorization_resource_id, action)
            // triple on the audit row must NOT belong to a Phase 1 row --
            // if it did, the backfill should have skipped it (existed check).
            $auditIdentityKey = $newValue['authorization_role_id'].'|'
                .$newValue['authorization_resource_id'].'|'
                .$newValue['action'];

            $this->assertArrayNotHasKey(
                $auditIdentityKey,
                $phase1IdentitySet,
                "Backfill audit-marks a Phase 1 pre-existing pivot row [{$auditIdentityKey}]; it must skip rows the migration did not create."
            );
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Distinct (role_name, resource_key, action) triples the mapper resolves
     * from the seeded Spatie state. Used by Test 1 and Test 5 to assert the
     * backfill did NOT widen onto unmapped pairs.
     *
     * @return array<string, true>
     */
    private function expectedBackfillPivotKeys(): array
    {
        $keys = [];
        foreach (SpatieRole::where('guard_name', 'web')->get() as $role) {
            foreach ($role->permissions as $permission) {
                if ($permission->guard_name !== 'web') {
                    continue;
                }
                $capability = CapabilityAlias::toCapability($permission->name);
                if ($capability === null) {
                    continue;
                }
                $map = CapabilityToAuthorizationRolePermission::map($capability);
                if ($map === null) {
                    continue;
                }
                $keys[$role->name.'|'.$map['resource'].'|'.$map['action']] = true;
            }
        }

        return $keys;
    }

    /**
     * Distinct resource FQCNs the mapper resolves from the seeded Spatie
     * state. Used by Test 1 and Test 5 to assert the resource catalog was
     * not widened with unmapped resources.
     *
     * @return array<string, true>
     */
    private function expectedBackfillResourceKeys(): array
    {
        $keys = [];
        foreach (array_keys($this->expectedBackfillPivotKeys()) as $compositeKey) {
            // Keys are shaped "role|resource|action"; defensive parse in
            // case a future mapping introduces a `|` in any segment.
            $parts = explode('|', $compositeKey, 3);
            $resourceKey = $parts[1] ?? null;
            if ($resourceKey === null) {
                continue;
            }
            $keys[$resourceKey] = true;
        }

        return $keys;
    }

    /**
     * Distinct Spatie role names that have at least one mappable permission.
     *
     * @return array<string, true>
     */
    private function expectedBackfillRoleNames(): array
    {
        $names = [];
        foreach (SpatieRole::where('guard_name', 'web')->get() as $role) {
            foreach ($role->permissions as $permission) {
                if ($permission->guard_name !== 'web') {
                    continue;
                }
                $capability = CapabilityAlias::toCapability($permission->name);
                if ($capability === null) {
                    continue;
                }
                $map = CapabilityToAuthorizationRolePermission::map($capability);
                if ($map === null) {
                    continue;
                }
                $names[$role->name] = true;
                break;
            }
        }

        return $names;
    }

    /**
     * Snapshot of the live authorization_role_permissions table as
     * `role_name|resource_key|action` strings, ordered by the composite.
     *
     * @return list<string>
     */
    private function currentPivotKeys(): array
    {
        return DB::table('authorization_role_permissions as arp')
            ->join('authorization_roles as ar', 'ar.id', '=', 'arp.authorization_role_id')
            ->join('authorization_resources as ares', 'ares.id', '=', 'arp.authorization_resource_id')
            ->orderBy('ar.name')->orderBy('ares.key')->orderBy('arp.action')
            ->get(['ar.name as role_name', 'ares.key as resource_key', 'arp.action'])
            ->map(fn ($row) => $row->role_name.'|'.$row->resource_key.'|'.$row->action)
            ->all();
    }

    /**
     * Invoke the backfill migration's down() directly. The migration is
     * declared as an anonymous class returned from the file, so the
     * standard `migrate:rollback --step=1` artisan command would target
     * whichever migration is actually last in the registry (a later
     * unrelated migration in this branch), not our backfill. Loading the
     * class via `require` and calling down() keeps the rollback scoped to
     * this migration.
     */
    private function rollbackBackfillMigration(): void
    {
        $migration = require database_path(
            'migrations/'.self::MIGRATION_NAME.'.php'
        );
        $migration->down();
    }

    /**
     * Invoke the backfill migration's up() directly (see rollbackBackfillMigration
     * for the rationale). FirstOrCreate semantics make this safe to re-run.
     */
    private function reapplyBackfillMigration(): void
    {
        $migration = require database_path(
            'migrations/'.self::MIGRATION_NAME.'.php'
        );
        $migration->up();
    }

    /**
     * Wrap a list of composite audit-key strings into a set for O(1)
     * membership checks. Reserved for future tests that need a Phase 1
     * audit-key membership probe; Test 7 inlines the comparison today.
     *
     * @param  list<string>  $compositeKeys  keys shaped "auth_role_id|auth_resource_id|action"
     * @return array<string, true>
     */
    private function phase1AuditKeySet(array $compositeKeys): array
    {
        return array_fill_keys($compositeKeys, true);
    }
}
