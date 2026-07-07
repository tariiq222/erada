<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 4b — RoleController single-source tests
 *
 * scoped_role_definitions is the SOLE source for role definitions. Verifies:
 * 1. index()/show() surface scoped-only definitions
 * 2. store/update/destroy write ONLY scoped_role_definitions (NO Spatie Role)
 * 3. permission_audits is written after each write
 * 4. store writes a single source (Spatie role count unchanged)
 * 5. system (compat-set) definitions are protected from deletion
 * 6. permissions() API contract is stable
 */
class RoleControllerUnifiedSourceTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected User $superAdmin;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->superAdmin = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');
    }

    // =========================================================
    // 1. index() enriched response
    // =========================================================

    public function test_index_returns_enriched_roles_with_scoped_def_fields(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/roles');

        $response->assertStatus(200);

        // التحقق من وجود البنية الأساسية
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'display_name',
                    'permissions',
                    'is_system',
                    // الحقول الجديدة (مرحلة د)
                    'scoped_def_id',
                    'label_ar',
                    'label_en',
                    'capabilities',
                    'is_admin_role',
                ],
            ],
        ]);

        // التحقق من أنواع البيانات لعنصر واحد على الأقل
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        foreach ($data as $item) {
            $this->assertIsArray($item['capabilities']);
            $this->assertIsBool($item['is_admin_role']);
            $this->assertArrayHasKey('label_ar', $item);
            $this->assertArrayHasKey('label_en', $item);
            $this->assertArrayHasKey('scoped_def_id', $item);
        }
    }

    // =========================================================
    // 2. store() writes scoped def only — NO Spatie role
    // =========================================================

    public function test_store_creates_scoped_def_and_no_spatie_role(): void
    {
        $orgScopeType = ScopeType::findByKey('organization');
        $this->assertNotNull($orgScopeType);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'test_role_single_source',
                'label_ar' => 'دور تجريبي',
                'label_en' => 'Test Role',
                'permissions_capabilities' => ['projects.view', 'tasks.create'],
            ]);

        $response->assertStatus(201);

        $roleName = $response->json('data.name');
        $this->assertEquals('test_role_single_source', $roleName);
        // The definition id is the primary id the API returns (FE keys on it).
        $this->assertNotNull($response->json('data.id'));

        // 1. NO Spatie role is created for a role definition.
        $this->assertNull(
            Role::where('name', $roleName)->first(),
            'store() must NOT create a Spatie Role for a definition'
        );

        // 2. scoped_role_definition is the single write.
        $def = ScopedRoleDefinition::where('scope_type_id', $orgScopeType->id)
            ->where('role_key', $roleName)
            ->first();

        $this->assertNotNull($def, 'scoped_role_definition should exist after store');
        $this->assertEquals($response->json('data.id'), $def->id);
        $this->assertEquals('دور تجريبي', $def->label_ar);
        $this->assertEquals('Test Role', $def->label_en);
        $this->assertTrue((bool) $def->is_active);
        $this->assertContains('projects.view', $def->permissions ?? []);
        $this->assertContains('tasks.create', $def->permissions ?? []);

        // 3. permission_audits recorded.
        $audit = DB::table('permission_audits')
            ->where('event', 'role_created')
            ->where('role', $roleName)
            ->where('scope_type', 'organization')
            ->first();

        $this->assertNotNull($audit, 'permission_audits row should exist after store');
        $this->assertEquals(auth()->id() ?? $this->superAdmin->id, $audit->actor_id);
    }

    public function test_assign_to_user_accepts_scoped_definition_without_spatie_role(): void
    {
        $orgId = $this->department->organization_id;

        $createResp = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'pmo_member',
                'label_ar' => 'عضو مكتب المشاريع',
                'label_en' => 'PMO Member',
                'permissions_capabilities' => [Capability::PROJECTS_VIEW],
            ]);

        $createResp->assertStatus(201);
        $this->assertDatabaseMissing('roles', ['name' => 'pmo_member']);

        $target = User::factory()->create([
            'organization_id' => $orgId,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles/assign', [
                'user_id' => $target->id,
                'roles' => ['pmo_member'],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.roles.0', 'pmo_member');

        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $target->id,
            'role' => 'pmo_member',
            'scope_type' => 'organization',
            'scope_id' => $orgId,
            'source' => 'manual',
        ]);
        $this->assertFalse($target->fresh()->hasRole('pmo_member'));
    }

    // =========================================================
    // 3. update() writes scoped def only — bound by definition id
    // =========================================================

    public function test_update_writes_scoped_def_only_by_definition_id(): void
    {
        $orgScopeType = ScopeType::findByKey('organization');

        // Create a role first (data.id is the definition id).
        $createResp = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'updatable_role_single',
                'label_ar' => 'دور قابل للتحديث',
                'label_en' => 'Updatable Role',
                'permissions_capabilities' => ['projects.view'],
            ]);

        $createResp->assertStatus(201);
        $defId = $createResp->json('data.id');
        $roleName = $createResp->json('data.name');

        // Update by the DEFINITION id (not a Spatie role id).
        $updateResp = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/roles/{$defId}", [
                'label_ar' => 'دور محدّث',
                'label_en' => 'Updated Role',
                'permissions_capabilities' => ['projects.view', 'tasks.create', 'projects.edit'],
            ]);

        $updateResp->assertStatus(200);

        // Still no Spatie role for this definition.
        $this->assertNull(Role::where('name', $roleName)->first());

        $def = ScopedRoleDefinition::where('scope_type_id', $orgScopeType->id)
            ->where('role_key', $roleName)
            ->first();

        $this->assertNotNull($def, 'scoped_role_definition should still exist after update');
        $this->assertEquals('دور محدّث', $def->label_ar);
        $this->assertEquals('Updated Role', $def->label_en);
        $this->assertContains('tasks.create', $def->permissions ?? []);

        $audit = DB::table('permission_audits')
            ->where('event', 'role_updated')
            ->where('role', $roleName)
            ->first();

        $this->assertNotNull($audit, 'permission_audits row should exist after update');
    }

    // =========================================================
    // 4. destroy() soft-disables scoped_def
    // =========================================================

    public function test_destroy_deactivates_scoped_def_not_hard_delete(): void
    {
        $orgScopeType = ScopeType::findByKey('organization');

        // إنشاء دور
        $createResp = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'deletable_role_'.time(),
                'label_ar' => 'دور قابل للحذف',
                'label_en' => 'Deletable Role',
            ]);

        $createResp->assertStatus(201);
        $roleId = $createResp->json('data.id');
        $roleName = $createResp->json('data.name');

        // Delete by the definition id.
        $deleteResp = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/roles/{$roleId}");

        $deleteResp->assertStatus(200);

        // No Spatie role was ever created for this definition.
        $this->assertNull(Role::where('name', $roleName)->first());

        if ($orgScopeType) {
            // scoped_def موجود لكن is_active = false
            $def = ScopedRoleDefinition::withoutGlobalScopes()
                ->where('scope_type_id', $orgScopeType->id)
                ->where('role_key', $roleName)
                ->first();

            $this->assertNotNull($def, 'scoped_role_definition row should NOT be hard-deleted');
            $this->assertFalse((bool) $def->is_active, 'scoped_def should be deactivated (is_active=false)');

            // permission_audits للحذف
            $audit = DB::table('permission_audits')
                ->where('event', 'role_deleted')
                ->where('role', $roleName)
                ->first();

            $this->assertNotNull($audit, 'permission_audits row should exist after delete');
        }
    }

    // =========================================================
    // 5. system (compat-set) definitions are protected from deletion
    // =========================================================

    public function test_system_role_definition_is_protected_from_deletion(): void
    {
        $orgScopeType = ScopeType::findByKey('organization');
        $this->assertNotNull($orgScopeType);

        // admin is a compat-set (system) role: its definition may not be deleted.
        $adminDef = ScopedRoleDefinition::where('scope_type_id', $orgScopeType->id)
            ->where('role_key', 'admin')
            ->first();
        $this->assertNotNull($adminDef, 'admin org-scope definition should be seeded');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/roles/{$adminDef->id}");

        $response->assertStatus(403);

        // The definition stays active.
        $this->assertTrue((bool) $adminDef->fresh()->is_active, 'admin definition should remain active');
    }

    // =========================================================
    // 6. permissions() backward-compatible contract
    // =========================================================

    public function test_permissions_response_has_backward_compatible_keys(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/roles/permissions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'scoped' => [],
                    'flat' => [],
                ],
            ]);

        // scoped يحتوي على عناصر
        $scoped = $response->json('data.scoped');
        $flat = $response->json('data.flat');

        $this->assertIsArray($scoped);
        $this->assertIsArray($flat);
        $this->assertNotEmpty($scoped, 'scoped permissions should not be empty');
        $this->assertNotEmpty($flat, 'flat permissions should not be empty');

        // كل عنصر scoped له key و label و actions
        foreach ($scoped as $resource) {
            $this->assertArrayHasKey('key', $resource);
            $this->assertArrayHasKey('label', $resource);
            $this->assertArrayHasKey('actions', $resource);
        }

        // كل عنصر flat له key و label و permissions
        foreach ($flat as $group) {
            $this->assertArrayHasKey('key', $group);
            $this->assertArrayHasKey('label', $group);
            $this->assertArrayHasKey('permissions', $group);
        }
    }

    // =========================================================
    // 7. store writes a single source — scoped def only, no Spatie role
    // =========================================================

    public function test_store_writes_single_scoped_def_source(): void
    {
        $orgScopeType = ScopeType::findByKey('organization');
        $this->assertNotNull($orgScopeType);

        $roleName = 'single_source_role';

        $countBefore = DB::table('scoped_role_definitions')
            ->where('scope_type_id', $orgScopeType->id)
            ->count();

        $spatieCountBefore = Role::count();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles', [
                'name' => $roleName,
                'label_ar' => 'دور موحّد المصدر',
                'label_en' => 'Single Source Role',
                'permissions_capabilities' => ['projects.view'],
            ]);

        $response->assertStatus(201);

        // Exactly one scoped_role_definition added...
        $this->assertEquals(
            $countBefore + 1,
            DB::table('scoped_role_definitions')
                ->where('scope_type_id', $orgScopeType->id)
                ->count(),
            'scoped_role_definition should be created'
        );

        // ...and NO Spatie role added.
        $this->assertEquals($spatieCountBefore, Role::count(), 'no Spatie role should be created');
    }

    // =========================================================
    // 8. Cross-org privilege escalation guard
    //    assignToUser must reject a non-super_admin actor assigning
    //    roles to a user in a different organization, even if they
    //    hold CORE_ASSIGN_ROLES in their own org (regression test
    //    for HR audit 2026-07-06).
    // =========================================================

    public function test_non_super_admin_cannot_assign_role_to_user_in_different_org(): void
    {
        // Two distinct orgs + departments.
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        // Actor in orgA with CORE_ASSIGN_ROLES at their org scope.
        // NOT a super_admin — must be blocked by the same-org floor.
        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::CORE_ASSIGN_ROLES, 'organization', $orgA->id);

        // Target user in a DIFFERENT organization.
        $target = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        // admin is part of the Spatie compat set, so keep its compat row
        // available while the same-org guard is exercised.
        Role::findOrCreate('admin', 'web');

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/roles/assign', [
                'user_id' => $target->id,
                'roles' => ['admin'],
            ]);

        $response->assertStatus(403);

        // Target user's roles must NOT have changed.
        $this->assertNotContains('admin', $target->fresh()->roles->pluck('name')->all());
    }

    public function test_super_admin_can_assign_role_to_user_in_different_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $target = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        Role::findOrCreate('admin', 'web');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/roles/assign', [
                'user_id' => $target->id,
                'roles' => ['admin'],
            ]);

        $response->assertStatus(200);
        $this->assertContains('admin', $target->fresh()->roles->pluck('name')->all());
    }
}
