<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthorizationAssignmentReconciliationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT = 'authorization_assignment_reconciliation_000009';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Authorization assignment reconciliation is PostgreSQL-only.');
        }

        $this->createLegacyAuthorizationFixtureSchema();
    }

    public function test_it_reconciles_representative_spatie_and_scoped_assignments_with_migration_provenance(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $orgRole = $this->canonicalRole('manager');
        $departmentRole = $this->canonicalRole('department_manager');

        $legacyRoleId = DB::table('roles')->insertGetId([
            'name' => 'manager', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $legacyRoleId,
            'model_type' => User::class,
            'model_id' => $user->id,
        ]);
        $expiry = '2032-04-05 06:07:08+00';
        $legacyScopedId = DB::table('model_has_scoped_roles')->insertGetId([
            'user_id' => $user->id,
            'role' => 'department_manager',
            'scope_type' => 'department',
            'scope_id' => $department->id,
            'inherit_to_children' => false,
            'granted_by' => $user->id,
            'expires_at' => $expiry,
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $orgRole->id,
            'user_id' => $user->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'organization_id' => $organization->id,
            'source' => 'migration',
        ]);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $departmentRole->id,
            'user_id' => $user->id,
            'scope_type' => 'department',
            'scope_id' => $department->id,
            'organization_id' => $organization->id,
            'inherit_to_children' => false,
            'source' => 'migration',
            'granted_by' => $user->id,
        ]);
        $this->assertNotNull(DB::table('authorization_role_assignments')
            ->where('authorization_role_id', $departmentRole->id)->value('expires_at'));

        $payloads = $this->payloads();
        $this->assertTrue($payloads->contains(fn (array $p) => $p['source_key'] === "scoped_role:{$legacyScopedId}"
            && $p['outcome'] === 'migrated'
            && $p['source'] === 'migration'));
        $this->assertDatabaseHas('model_has_roles', ['role_id' => $legacyRoleId, 'model_id' => $user->id]);
        $this->assertDatabaseHas('model_has_scoped_roles', ['id' => $legacyScopedId]);
    }

    public function test_it_rejects_cross_org_orphan_and_unmapped_rows_without_creating_assignments(): void
    {
        $home = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $home->id]);
        $foreignDepartment = Department::factory()->create(['organization_id' => $foreign->id]);
        $this->canonicalRole('department_manager');

        foreach ([
            ['role' => 'department_manager', 'scope_id' => $foreignDepartment->id],
            ['role' => 'department_manager', 'scope_id' => 999999999],
            ['role' => 'role_missing_from_canonical_catalog', 'scope_id' => $foreignDepartment->id],
        ] as $row) {
            DB::table('model_has_scoped_roles')->insert([
                'user_id' => $user->id,
                'role' => $row['role'],
                'scope_type' => 'department',
                'scope_id' => $row['scope_id'],
                'inherit_to_children' => true,
                'granted_by' => null,
                'expires_at' => null,
                'source' => 'manual',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->migration()->up();

        $this->assertDatabaseCount('authorization_role_assignments', 0);
        $reasons = $this->payloads()->pluck('reason');
        $this->assertTrue($reasons->contains('cross_organization'));
        $this->assertTrue($reasons->contains('orphan_scope'));
        $this->assertTrue($reasons->contains('unmapped_role'));
        $this->assertSame(3, DB::table('model_has_scoped_roles')->count(), 'Legacy assignments must remain untouched.');
    }

    public function test_it_is_idempotent_preserves_existing_canonical_rows_and_audits_direct_permissions(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $role = $this->canonicalRole('viewer');
        DB::table('authorization_role_assignments')->insert([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'organization_id' => $organization->id,
            'inherit_to_children' => true,
            'expires_at' => null,
            'source' => 'manual',
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $legacyRoleId = DB::table('roles')->insertGetId([
            'name' => 'viewer', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $legacyRoleId, 'model_type' => User::class, 'model_id' => $user->id,
        ]);
        $permissionId = DB::table('permissions')->insertGetId([
            'name' => 'projects.view', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('model_has_permissions')->insert([
            'permission_id' => $permissionId, 'model_type' => User::class, 'model_id' => $user->id,
        ]);

        $migration = $this->migration();
        $migration->up();
        $assignmentCount = DB::table('authorization_role_assignments')->count();
        $auditCount = DB::table('permission_audits')->where('event', self::EVENT)->count();
        $migration->up();

        $this->assertSame($assignmentCount, DB::table('authorization_role_assignments')->count());
        $this->assertSame($auditCount, DB::table('permission_audits')->where('event', self::EVENT)->count());
        $this->assertSame('manual', DB::table('authorization_role_assignments')->sole()->source);
        $reasons = $this->payloads()->pluck('reason');
        $this->assertTrue($reasons->contains('already_canonical'));
        $this->assertTrue($reasons->contains('direct_permission_requires_manual_role_mapping'));

        $migration->down();
        $this->assertSame($auditCount, DB::table('permission_audits')->where('event', self::EVENT)->count());
        $this->assertSame($assignmentCount, DB::table('authorization_role_assignments')->count());
    }

    private function canonicalRole(string $name): AuthorizationRole
    {
        return AuthorizationRole::query()->updateOrCreate(['name' => $name], [
            'label' => ucfirst(str_replace('_', ' ', $name)),
            'label_ar' => $name,
            'label_en' => $name,
            'scope_type' => 'organization',
            'is_system' => false,
            'is_admin_role' => false,
            'is_active' => true,
        ]);
    }

    /**
     * Recreate only the pre-cutover tables consumed by migration 000009.
     *
     * Fresh test databases load the post-cutover schema dump, where these
     * tables no longer exist. Keeping this fixture local proves the historical
     * reconciliation without restoring the legacy runtime or Spatie package.
     */
    private function createLegacyAuthorizationFixtureSchema(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
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

        Schema::create('model_has_scoped_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('role', 50);
            $table->string('scope_type', 50);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->boolean('inherit_to_children')->default(true);
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });

        Schema::create('permission_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 50);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->string('scope_type', 50)->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('role')->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function payloads()
    {
        return DB::table('permission_audits')->where('event', self::EVENT)->orderBy('id')->pluck('new_value')
            ->map(fn ($value): array => json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR));
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_07_12_000009_reconcile_legacy_authorization_assignments.php');

        return $migration;
    }
}
