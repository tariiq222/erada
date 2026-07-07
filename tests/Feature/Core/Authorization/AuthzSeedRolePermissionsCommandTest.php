<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
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
 * AuthzSeedRolePermissionsCommandTest -- Phase 1 Task 1.2.1.
 *
 * Drives `php artisan authz:seed-role-permissions` and asserts:
 *   - default invocation (no flag) is a dry-run preview and writes nothing;
 *   - --dry-run prints the preview but writes nothing;
 *   - --apply idempotently creates authorization_resources rows, the
 *     authorization_roles.super_admin row, and authorization_role_permissions
 *     pivot rows;
 *   - second --apply leaves the row counts stable (idempotent);
 *   - deleting one pivot row and re-applying re-creates it;
 *   - no Spatie legacy rows are deleted or modified.
 */
class AuthzSeedRolePermissionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Authz seed command test is PostgreSQL-only.');
        }

        AccessDecision::flushCache();

        // Bring the Spatie legacy data online so the "no legacy rows mutated"
        // assertion has something concrete to compare against. The seeder is
        // additive and idempotent; running it before the command under test
        // mirrors how the command would be invoked in real environments.
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        AccessDecision::flushCache();

        parent::tearDown();
    }

    public function test_default_invocation_is_a_dry_run_and_writes_nothing(): void
    {
        $resourceCountBefore = AuthorizationResource::count();
        $roleCountBefore = AuthorizationRole::count();
        $pivotCountBefore = AuthorizationRolePermission::count();

        $exitCode = Artisan::call('authz:seed-role-permissions');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, "Command exited with non-zero status [{$exitCode}].");
        $this->assertStringContainsString('dry-run', strtolower($output));

        $this->assertSame($resourceCountBefore, AuthorizationResource::count(), 'Resources were written on default invocation.');
        $this->assertSame($roleCountBefore, AuthorizationRole::count(), 'Roles were written on default invocation.');
        $this->assertSame($pivotCountBefore, AuthorizationRolePermission::count(), 'Pivot rows were written on default invocation.');
    }

    public function test_dry_run_flag_writes_nothing(): void
    {
        $resourceCountBefore = AuthorizationResource::count();
        $roleCountBefore = AuthorizationRole::count();
        $pivotCountBefore = AuthorizationRolePermission::count();

        $exitCode = Artisan::call('authz:seed-role-permissions', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, "Command exited with non-zero status [{$exitCode}].");
        $this->assertStringContainsString('dry-run', strtolower($output));

        $this->assertSame($resourceCountBefore, AuthorizationResource::count(), 'Resources were written on --dry-run.');
        $this->assertSame($roleCountBefore, AuthorizationRole::count(), 'Roles were written on --dry-run.');
        $this->assertSame($pivotCountBefore, AuthorizationRolePermission::count(), 'Pivot rows were written on --dry-run.');
    }

    public function test_apply_writes_resources_super_admin_role_and_pivot_rows(): void
    {
        $expectedCapabilities = Capability::all();
        $expectedResources = [];
        $expectedDistinctPairs = [];
        foreach ($expectedCapabilities as $capability) {
            $row = CapabilityToAuthorizationRolePermission::map($capability);
            $this->assertNotNull($row, "Mapper missing for [{$capability}].");
            $expectedResources[$row['resource']] = true;
            $expectedDistinctPairs[$row['resource'].'|'.$row['action']] = true;
        }

        $exitCode = Artisan::call('authz:seed-role-permissions', ['--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, "Command exited with non-zero status [{$exitCode}].");
        $this->assertStringContainsString('apply', strtolower($output));

        $this->assertSame(
            count($expectedResources),
            AuthorizationResource::count(),
            'AuthorizationResource row count does not match the number of unique mapped resources.'
        );

        $role = AuthorizationRole::where('name', 'super_admin')->first();
        $this->assertNotNull($role, 'super_admin AuthorizationRole was not created.');
        $this->assertSame('Super Admin', $role->label, 'super_admin label is not "Super Admin".');

        // The composite primary key dedupes when two capabilities collide on
        // the same (resource, action) pair -- by design (user-approved
        // `hr.view` and `departments.view` both target Department::view).
        // The pivot count must equal the number of DISTINCT pairs, NOT the
        // raw capability count.
        $this->assertSame(
            count($expectedDistinctPairs),
            AuthorizationRolePermission::count(),
            'Pivot row count does not match the number of distinct (resource, action) pairs.'
        );

        // Spot-check: each pivot row resolves to a real AuthorizationResource + action suffix
        // that came from the mapper output for at least one Capability::all() entry.
        foreach (AuthorizationRolePermission::all() as $pivot) {
            $this->assertSame(
                'super_admin',
                $pivot->role()->value('name'),
                'Pivot row is not attached to the super_admin role.'
            );

            $resource = $pivot->resource;
            $this->assertNotNull($resource, 'Pivot row has a NULL resource relation.');

            $matchedCapability = false;
            foreach ($expectedCapabilities as $capability) {
                $row = CapabilityToAuthorizationRolePermission::map($capability);
                if ($row['resource'] === $resource->key && $row['action'] === $pivot->action) {
                    $matchedCapability = true;
                    break;
                }
            }

            $this->assertTrue(
                $matchedCapability,
                "Pivot row ({$resource->key}, {$pivot->action}) does not correspond to any Capability::all() entry."
            );
        }
    }

    public function test_apply_is_idempotent(): void
    {
        Artisan::call('authz:seed-role-permissions', ['--apply' => true]);

        $resourceCountAfterFirst = AuthorizationResource::count();
        $roleCountAfterFirst = AuthorizationRole::count();
        $pivotCountAfterFirst = AuthorizationRolePermission::count();

        Artisan::call('authz:seed-role-permissions', ['--apply' => true]);

        $this->assertSame(
            $resourceCountAfterFirst,
            AuthorizationResource::count(),
            'AuthorizationResource count changed between two consecutive --apply invocations.'
        );
        $this->assertSame(
            $roleCountAfterFirst,
            AuthorizationRole::count(),
            'AuthorizationRole count changed between two consecutive --apply invocations.'
        );
        $this->assertSame(
            $pivotCountAfterFirst,
            AuthorizationRolePermission::count(),
            'AuthorizationRolePermission count changed between two consecutive --apply invocations.'
        );
    }

    public function test_apply_recreates_pivot_row_after_manual_deletion(): void
    {
        Artisan::call('authz:seed-role-permissions', ['--apply' => true]);

        $pivotBefore = AuthorizationRolePermission::all();
        $this->assertGreaterThan(1, $pivotBefore->count(), 'Expected more than one pivot row before deletion.');

        $sample = $pivotBefore->first();
        $deletedResourceKey = $sample->resource->key;
        $deletedAction = $sample->action;

        // AuthorizationRolePermission is a pure Pivot (composite primary
        // key, $primaryKey = null), so the Eloquent delete() cannot build
        // a WHERE clause -- we delete via the query builder instead, which
        // is the same path an operator would take from `psql` or a DBA
        // dashboard. The point of this test is the seeder's idempotency,
        // not the deletion mechanism.
        DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $sample->authorization_role_id)
            ->where('authorization_resource_id', $sample->authorization_resource_id)
            ->where('action', $sample->action)
            ->delete();

        $this->assertSame(
            $pivotBefore->count() - 1,
            AuthorizationRolePermission::count(),
            'Pivot row was not actually deleted.'
        );

        // The AuthorizationResource for the deleted row should NOT have been
        // deleted -- only the pivot row was. The seeder must not chase a
        // deletion back into the resource catalog.
        $this->assertNotNull(
            AuthorizationResource::where('key', $deletedResourceKey)->first(),
            'AuthorizationResource row was deleted alongside its pivot row.'
        );

        Artisan::call('authz:seed-role-permissions', ['--apply' => true]);

        $this->assertSame(
            $pivotBefore->count(),
            AuthorizationRolePermission::count(),
            'Pivot row was not recreated by the second --apply invocation.'
        );

        $recreated = AuthorizationRolePermission::query()
            ->whereHas('resource', fn ($q) => $q->where('key', $deletedResourceKey))
            ->where('action', $deletedAction)
            ->exists();

        $this->assertTrue($recreated, 'The specific deleted pivot row was not recreated.');
    }

    public function test_apply_does_not_mutate_legacy_spatie_tables(): void
    {
        $spatiePermissionCountBefore = SpatiePermission::count();
        $spatieRoleCountBefore = SpatieRole::count();

        // Take a fingerprint of every Spatie row we expect to survive.
        $permissionsBefore = SpatiePermission::orderBy('id')->get(['id', 'name', 'guard_name'])->toArray();
        $rolesBefore = SpatieRole::orderBy('id')->get(['id', 'name', 'guard_name'])->toArray();

        Artisan::call('authz:seed-role-permissions', ['--apply' => true]);

        $this->assertSame(
            $spatiePermissionCountBefore,
            SpatiePermission::count(),
            'Spatie permissions row count changed after --apply.'
        );
        $this->assertSame(
            $spatieRoleCountBefore,
            SpatieRole::count(),
            'Spatie roles row count changed after --apply.'
        );

        $this->assertSame(
            $permissionsBefore,
            SpatiePermission::orderBy('id')->get(['id', 'name', 'guard_name'])->toArray(),
            'Spatie permissions rows were modified after --apply.'
        );
        $this->assertSame(
            $rolesBefore,
            SpatieRole::orderBy('id')->get(['id', 'name', 'guard_name'])->toArray(),
            'Spatie roles rows were modified after --apply.'
        );
    }

    public function test_apply_emits_super_admin_role_with_unique_name_and_expected_label(): void
    {
        $exitCode = Artisan::call('authz:seed-role-permissions', ['--apply' => true]);
        $this->assertSame(0, $exitCode);

        $role = AuthorizationRole::where('name', 'super_admin')->first();
        $this->assertNotNull($role);
        $this->assertSame('Super Admin', $role->label);

        // Re-apply: the super_admin role row must remain unique by name.
        Artisan::call('authz:seed-role-permissions', ['--apply' => true]);

        $this->assertSame(1, AuthorizationRole::where('name', 'super_admin')->count(), 'super_admin role is not unique after re-apply.');
    }

    public function test_dry_run_preview_lists_resource_action_pairs(): void
    {
        $exitCode = Artisan::call('authz:seed-role-permissions', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        // The preview must reference at least one resource FQCN + action suffix
        // so an operator can confirm the seed before running --apply.
        $this->assertStringContainsString('super_admin', $output);
        $this->assertStringContainsString('App\\Modules\\', $output);
    }

    /**
     * Pin the --apply telemetry contract: the printed counters must reflect
     * the actual DB state after the transaction commits, using deterministic
     * "present" labels so the report is accurate on first apply AND stable
     * on re-apply (idempotent). Counts are derived from the mapper output,
     * not from `updateOrInsert`'s ambiguous boolean return value, which can
     * return true on both insert and "no-op update" paths.
     */
    public function test_apply_output_reports_present_resource_and_pivot_counts(): void
    {
        $expectedDistinctResources = [];
        $expectedDistinctPairs = [];
        foreach (Capability::all() as $capability) {
            $row = CapabilityToAuthorizationRolePermission::map($capability);
            $this->assertNotNull($row, "Mapper missing for [{$capability}].");
            $expectedDistinctResources[$row['resource']] = true;
            $expectedDistinctPairs[$row['resource'].'|'.$row['action']] = true;
        }
        $expectedResourceCount = count($expectedDistinctResources);
        $expectedPivotCount = count($expectedDistinctPairs);
        $this->assertGreaterThan(0, $expectedResourceCount, 'Mapper produced no distinct resources; expected count cannot be derived.');
        $this->assertGreaterThan(0, $expectedPivotCount, 'Mapper produced no distinct (resource, action) pairs; expected count cannot be derived.');

        // First --apply: counters must be non-zero and exactly match the
        // distinct resource / pair counts the mapper emits.
        Artisan::call('authz:seed-role-permissions', ['--apply' => true]);
        $firstOutput = Artisan::output();

        $this->assertStringContainsString(
            "Resources present: {$expectedResourceCount}",
            $firstOutput,
            'First --apply output does not report the expected non-zero Resources present count.'
        );
        $this->assertStringContainsString(
            "Pivot rows present: {$expectedPivotCount}",
            $firstOutput,
            'First --apply output does not report the expected non-zero Pivot rows present count.'
        );
        $this->assertMatchesRegularExpression(
            '/Resources present:\s*[1-9]\d*/',
            $firstOutput,
            'Resources present count must be a positive integer on first --apply.'
        );
        $this->assertMatchesRegularExpression(
            '/Pivot rows present:\s*[1-9]\d*/',
            $firstOutput,
            'Pivot rows present count must be a positive integer on first --apply.'
        );

        // Second --apply: counters must be IDENTICAL -- the seed is
        // idempotent and the present count is the same.
        Artisan::call('authz:seed-role-permissions', ['--apply' => true]);
        $secondOutput = Artisan::output();

        $this->assertStringContainsString(
            "Resources present: {$expectedResourceCount}",
            $secondOutput,
            'Second --apply output drifted from the first apply Resources present count.'
        );
        $this->assertStringContainsString(
            "Pivot rows present: {$expectedPivotCount}",
            $secondOutput,
            'Second --apply output drifted from the first apply Pivot rows present count.'
        );
    }
}
