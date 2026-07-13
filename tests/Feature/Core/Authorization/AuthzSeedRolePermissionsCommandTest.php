<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthzSeedRolePermissionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Authz seed command test is PostgreSQL-only.');
        }
    }

    public function test_default_and_explicit_dry_run_write_nothing(): void
    {
        $before = [AuthorizationResource::count(), AuthorizationRole::count(), AuthorizationRolePermission::count()];
        foreach ([[], ['--dry-run' => true]] as $arguments) {
            $this->assertSame(0, Artisan::call('authz:seed-role-permissions', $arguments));
            $this->assertStringContainsString('dry-run', strtolower(Artisan::output()));
            $this->assertSame($before, [AuthorizationResource::count(), AuthorizationRole::count(), AuthorizationRolePermission::count()]);
        }
    }

    public function test_apply_seeds_complete_canonical_catalog_and_is_idempotent(): void
    {
        $this->assertSame(0, Artisan::call('authz:seed-role-permissions', ['--apply' => true]));

        $expectedNames = array_keys(RolesAndPermissionsSeeder::roleCatalog());
        $this->assertSame($expectedNames, AuthorizationRole::query()->orderBy('id')->pluck('name')->all());
        $this->assertSame(count($expectedNames), AuthorizationRole::count());
        $this->assertTrue((bool) AuthorizationRole::query()->where('name', 'super_admin')->value('is_admin_role'));
        $this->assertTrue((bool) AuthorizationRole::query()->where('name', 'admin')->value('is_admin_role'));
        $this->assertSame(0, AuthorizationRole::query()->where('is_active', false)->count());
        $this->assertDatabaseHas('authorization_roles', ['name' => 'dept_member', 'scope_type' => 'department']);
        $this->assertDatabaseHas('authorization_roles', ['name' => 'quality_manager', 'scope_type' => 'organization']);
        $this->assertDatabaseHas('authorization_roles', ['name' => 'cluster_auditor', 'scope_type' => 'organization']);

        $counts = [AuthorizationResource::count(), AuthorizationRole::count(), AuthorizationRolePermission::count()];
        $this->assertGreaterThan(0, $counts[0]);
        $this->assertGreaterThan(0, $counts[2]);

        $this->assertSame(0, Artisan::call('authz:seed-role-permissions', ['--apply' => true]));
        $this->assertSame($counts, [AuthorizationResource::count(), AuthorizationRole::count(), AuthorizationRolePermission::count()]);
    }

    public function test_apply_grants_every_mapped_capability_to_super_admin(): void
    {
        Artisan::call('authz:seed-role-permissions', ['--apply' => true]);

        $role = AuthorizationRole::query()->where('name', 'super_admin')->firstOrFail();
        $expected = [];
        foreach (Capability::all() as $capability) {
            $mapping = CapabilityToAuthorizationRolePermission::map($capability);
            $this->assertNotNull($mapping, "Mapper missing for [{$capability}].");
            $expected[$mapping['resource'].'|'.$mapping['action']] = true;
        }

        $actual = AuthorizationRolePermission::query()
            ->where('authorization_role_id', $role->id)
            ->with('resource:id,key')
            ->get()
            ->mapWithKeys(fn (AuthorizationRolePermission $permission): array => [
                $permission->resource->key.'|'.$permission->action => true,
            ])
            ->all();

        $expectedKeys = array_keys($expected);
        $actualKeys = array_keys($actual);
        sort($expectedKeys);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);
    }

    public function test_apply_repairs_a_deleted_canonical_permission(): void
    {
        Artisan::call('authz:seed-role-permissions', ['--apply' => true]);
        $permission = AuthorizationRolePermission::query()->firstOrFail();
        $count = AuthorizationRolePermission::count();

        DB::table('authorization_role_permissions')->where([
            'authorization_role_id' => $permission->authorization_role_id,
            'authorization_resource_id' => $permission->authorization_resource_id,
            'action' => $permission->action,
        ])->delete();

        Artisan::call('authz:seed-role-permissions', ['--apply' => true]);
        $this->assertSame($count, AuthorizationRolePermission::count());
    }

    public function test_canonical_seeders_do_not_reference_legacy_authorization_writers(): void
    {
        foreach ([
            database_path('seeders/RolesAndPermissionsSeeder.php'),
            database_path('seeders/Meetings/MeetingsPermissionsSeeder.php'),
            app_path('Console/Commands/AuthzSeedRolePermissionsCommand.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);

            foreach (['Spatie\\', 'assignRole(', 'givePermissionTo('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "Legacy writer [{$forbidden}] remains in [{$path}].");
            }
        }
    }
}
