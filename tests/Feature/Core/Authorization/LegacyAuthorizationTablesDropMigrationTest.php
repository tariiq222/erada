<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class LegacyAuthorizationTablesDropMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const LEGACY_TABLES = [
        'model_has_scoped_roles',
        'scoped_role_definitions',
        'scope_types',
        'role_has_permissions',
        'model_has_permissions',
        'model_has_roles',
        'permissions',
        'roles',
    ];

    public function test_upgrade_drops_only_legacy_tables_and_preserves_canonical_assignments_and_audits(): void
    {
        $this->recreateLegacyTables();

        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $roleId = DB::table('authorization_roles')->insertGetId([
            'name' => 'cutover_test_role',
            'label' => 'Cutover test role',
            'label_ar' => 'دور اختبار الانتقال',
            'label_en' => 'Cutover test role',
            'scope_type' => 'organization',
            'is_system' => false,
            'is_admin_role' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assignmentId = DB::table('authorization_role_assignments')->insertGetId([
            'authorization_role_id' => $roleId,
            'user_id' => $user->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'organization_id' => $organization->id,
            'inherit_to_children' => true,
            'expires_at' => null,
            'source' => 'migration',
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $auditId = DB::table('authorization_assignment_audits')->insertGetId([
            'event' => 'legacy_authorization_tables_drop_test',
            'target_user_id' => $user->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'role' => 'cutover_test_role',
            'reason' => 'must survive destructive legacy cleanup',
            'created_at' => now(),
        ]);

        $migration = $this->migration();
        $migration->up();

        foreach (self::LEGACY_TABLES as $table) {
            self::assertFalse(Schema::hasTable($table), "Legacy table [{$table}] was not dropped.");
        }
        $this->assertDatabaseHas('authorization_roles', ['id' => $roleId, 'name' => 'cutover_test_role']);
        $this->assertDatabaseHas('authorization_role_assignments', ['id' => $assignmentId, 'source' => 'migration']);
        $this->assertDatabaseHas('authorization_assignment_audits', [
            'id' => $auditId,
            'reason' => 'must survive destructive legacy cleanup',
        ]);

        $migration->down();

        foreach (self::LEGACY_TABLES as $table) {
            self::assertFalse(Schema::hasTable($table), "Forward-only down recreated [{$table}].");
        }
        $this->assertDatabaseHas('authorization_role_assignments', ['id' => $assignmentId]);
        $this->assertDatabaseHas('authorization_assignment_audits', ['id' => $auditId]);
    }

    public function test_it_fails_before_dropping_any_legacy_table_when_a_canonical_prerequisite_is_missing(): void
    {
        $this->recreateLegacyTables();
        Schema::drop('authorization_assignment_audits');

        try {
            $this->migration()->up();
            self::fail('The destructive migration must reject a missing canonical prerequisite.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('requires authorization_assignment_audits', $exception->getMessage());
        }

        foreach (self::LEGACY_TABLES as $table) {
            self::assertTrue(Schema::hasTable($table), "Precondition failure partially dropped [{$table}].");
        }
    }

    public function test_it_fails_before_any_drop_when_a_legacy_role_assignment_has_no_reconciliation_receipt(): void
    {
        $this->recreateLegacyTables();

        DB::table('model_has_roles')->insert([
            'role_id' => 91,
            'model_type' => User::class,
            'model_id' => 73,
        ]);

        $this->assertMigrationFailsWithoutDropping('unreconciled legacy assignment [spatie_role:91:'.User::class.':73]');
    }

    public function test_it_fails_before_any_drop_when_reconciliation_has_a_rejected_outcome(): void
    {
        $this->recreateLegacyTables();

        $auditId = DB::table('authorization_assignment_audits')->insertGetId([
            'event' => 'authorization_assignment_reconciliation_000009',
            'new_value' => json_encode([
                'migration' => '2026_07_12_000009_reconcile_legacy_authorization_assignments',
                'source_key' => 'scoped_role:44',
                'source_type' => 'scoped_role',
                'outcome' => 'rejected',
                'reason' => 'cross_organization',
            ], JSON_THROW_ON_ERROR),
            'reason' => 'unresolved cross-organization row',
            'created_at' => now(),
        ]);

        $this->assertMigrationFailsWithoutDropping("unresolved reconciliation outcome in authorization_assignment_audits [{$auditId}]");
    }

    public function test_it_fails_before_any_drop_when_direct_permission_assignments_remain(): void
    {
        $this->recreateLegacyTables();

        DB::table('model_has_permissions')->insert([
            'permission_id' => 17,
            'model_type' => User::class,
            'model_id' => 29,
        ]);

        $this->assertMigrationFailsWithoutDropping('unresolved direct permission assignments');
    }

    public function test_it_fails_before_any_drop_when_a_legacy_role_permission_is_missing_from_the_canonical_pivot(): void
    {
        $this->recreateLegacyTables();
        $this->insertLegacyRolePermissionFixture();

        $this->assertMigrationFailsWithoutDropping('missing canonical role permission [spatie_role_permission:81:82]');
    }

    public function test_it_fails_before_any_drop_when_a_reconciled_legacy_permission_is_mutated(): void
    {
        $this->recreateLegacyTables();
        $this->insertLegacyRolePermissionFixture(includeCanonicalPivot: true);

        DB::table('permissions')->where('id', 82)->update(['name' => 'view_tasks']);

        $this->assertMigrationFailsWithoutDropping('missing canonical role permission [spatie_role_permission:81:82]');
    }

    public function test_it_fails_before_any_drop_when_an_accepted_receipt_points_to_a_missing_canonical_assignment(): void
    {
        $this->recreateLegacyTables();
        DB::table('model_has_scoped_roles')->insert(['id' => 52]);
        DB::table('authorization_assignment_audits')->insert([
            'event' => 'authorization_assignment_reconciliation_000009',
            'new_value' => json_encode([
                'migration' => '2026_07_12_000009_reconcile_legacy_authorization_assignments',
                'source_key' => 'scoped_role:52',
                'source_type' => 'scoped_role',
                'outcome' => 'migrated',
                'authorization_role_assignment_id' => 999999,
            ], JSON_THROW_ON_ERROR),
            'reason' => 'stale receipt',
            'created_at' => now(),
        ]);

        $this->assertMigrationFailsWithoutDropping('without a live canonical assignment');
    }

    public function test_it_fails_before_any_drop_when_a_legacy_scope_crosses_organizations_despite_an_accepted_receipt(): void
    {
        $this->recreateLegacyTables();
        $userOrganization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $userOrganization->id]);
        $roleId = DB::table('authorization_roles')->insertGetId([
            'name' => 'cross_org_cutover_role',
            'label' => 'Cross org cutover role',
            'label_ar' => 'دور اختبار عابر للمنظمات',
            'label_en' => 'Cross org cutover role',
            'scope_type' => 'organization',
            'is_system' => false,
            'is_admin_role' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assignmentId = DB::table('authorization_role_assignments')->insertGetId([
            'authorization_role_id' => $roleId,
            'user_id' => $user->id,
            'scope_type' => 'organization',
            'scope_id' => $otherOrganization->id,
            'organization_id' => $otherOrganization->id,
            'inherit_to_children' => true,
            'source' => 'migration',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_scoped_roles')->insert([
            'id' => 61,
            'user_id' => $user->id,
            'role' => 'cross_org_cutover_role',
            'scope_type' => 'organization',
            'scope_id' => $otherOrganization->id,
        ]);
        DB::table('authorization_assignment_audits')->insert([
            'event' => 'authorization_assignment_reconciliation_000009',
            'new_value' => json_encode([
                'migration' => '2026_07_12_000009_reconcile_legacy_authorization_assignments',
                'source_key' => 'scoped_role:61',
                'source_type' => 'scoped_role',
                'outcome' => 'migrated',
                'authorization_role_assignment_id' => $assignmentId,
            ], JSON_THROW_ON_ERROR),
            'reason' => 'tampered accepted receipt',
            'created_at' => now(),
        ]);

        $this->assertMigrationFailsWithoutDropping('unmapped or cross-organization legacy assignment');
    }

    private function recreateLegacyTables(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('guard_name')->default('web');
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('guard_name')->default('web');
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });
        Schema::create('model_has_scoped_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('role')->nullable();
            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
        });
        Schema::create('scoped_role_definitions', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('scope_types', function (Blueprint $table): void {
            $table->id();
        });
    }

    private function insertLegacyRolePermissionFixture(bool $includeCanonicalPivot = false): void
    {
        DB::table('roles')->insert([
            'id' => 81,
            'name' => 'permission_cutover_role',
            'guard_name' => 'web',
        ]);
        DB::table('permissions')->insert([
            'id' => 82,
            'name' => 'view_projects',
            'guard_name' => 'web',
        ]);
        DB::table('role_has_permissions')->insert([
            'role_id' => 81,
            'permission_id' => 82,
        ]);
        $roleId = DB::table('authorization_roles')->insertGetId([
            'name' => 'permission_cutover_role',
            'label' => 'Permission cutover role',
            'scope_type' => 'organization',
            'is_system' => false,
            'is_admin_role' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $resourceId = DB::table('authorization_resources')->where('key', Project::class)->value('id');
        if ($resourceId === null) {
            $resourceId = DB::table('authorization_resources')->insertGetId([
                'key' => Project::class,
                'label' => 'Project',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($includeCanonicalPivot) {
            DB::table('authorization_role_permissions')->insert([
                'authorization_role_id' => $roleId,
                'authorization_resource_id' => $resourceId,
                'action' => 'view',
            ]);
        }
    }

    private function assertMigrationFailsWithoutDropping(string $expectedMessage): void
    {
        try {
            $this->migration()->up();
            self::fail('The destructive migration must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($expectedMessage, $exception->getMessage());
        }

        foreach (self::LEGACY_TABLES as $table) {
            self::assertTrue(Schema::hasTable($table), "Precondition failure partially dropped [{$table}].");
        }
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_07_12_000011_drop_legacy_authorization_tables.php');

        return $migration;
    }
}
