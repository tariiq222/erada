<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DepartmentOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected User $adminA;

    protected User $adminB;

    protected User $superAdmin;

    protected User $nullOrgUser;

    protected Department $deptA;

    protected Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        // Admin in orgA
        $this->deptA = Department::factory()->create([
            'organization_id' => $this->orgA->id,
            'level' => 4,
        ]);
        $this->adminA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->adminA, 'admin');

        // Admin in orgB
        $this->deptB = Department::factory()->create([
            'organization_id' => $this->orgB->id,
            'level' => 4,
        ]);
        $this->adminB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->adminB, 'admin');

        // super_admin without org
        $this->superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);

        // Null-org non-super user has no canonical assignment and is denied.
        $this->nullOrgUser = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);

        Cache::flush();
    }

    // ========== SC1: Cross-org read rejection ==========

    public function test_admin_from_org_a_cannot_view_department_in_org_b(): void
    {
        $response = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/hr/departments/{$this->deptB->id}");

        // show() uses sharesOrganization directly — message preserved
        $response->assertStatus(403)
            ->assertJson(['message' => 'غير مصرح بالوصول إلى هذا القسم']);
    }

    public function test_admin_from_org_a_sees_only_org_a_departments_in_index(): void
    {
        Department::factory()->count(3)->create([
            'organization_id' => $this->orgA->id,
            'level' => 4,
        ]);
        Department::factory()->count(2)->create([
            'organization_id' => $this->orgB->id,
            'level' => 4,
        ]);

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/hr/departments');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(4, $data); // deptA + 3 created
        foreach ($data as $dept) {
            $this->assertEquals($this->orgA->id, $dept['organization_id'] ?? null);
        }
    }

    public function test_admin_from_org_a_sees_only_org_a_in_list(): void
    {
        Department::factory()->create([
            'organization_id' => $this->orgA->id,
            'level' => 4,
            'is_active' => true,
        ]);
        Department::factory()->create([
            'organization_id' => $this->orgB->id,
            'level' => 4,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/hr/departments/list');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(2, $data); // deptA + 1 created
    }

    public function test_admin_from_org_a_sees_only_org_a_in_tree(): void
    {
        Department::factory()->create([
            'organization_id' => $this->orgA->id,
            'level' => 1,
            'parent_id' => null,
        ]);
        Department::factory()->create([
            'organization_id' => $this->orgB->id,
            'level' => 1,
            'parent_id' => null,
        ]);

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/hr/departments/tree');

        $response->assertStatus(200);
        $data = $response->json();
        // deptA from setUp has parent_id=null (factory default), so it's a root too
        // plus the root created above = 2 roots for orgA
        $this->assertCount(2, $data);
    }

    public function test_admin_from_org_a_sees_only_org_a_in_hierarchy(): void
    {
        Department::factory()->create([
            'organization_id' => $this->orgA->id,
            'level' => 1,
        ]);
        Department::factory()->create([
            'organization_id' => $this->orgB->id,
            'level' => 1,
        ]);

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/hr/departments/hierarchy');

        $response->assertStatus(200);
        $data = $response->json('all');
        $this->assertCount(2, $data); // deptA + 1 created
    }

    // ========== SC2: Cross-org write rejection ==========

    public function test_admin_from_org_a_cannot_update_department_in_org_b(): void
    {
        $response = $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/hr/departments/{$this->deptB->id}", [
                'name' => 'تم التعديل',
                'level' => 4,
            ]);

        // Engine isolates by org — adminA has no role on orgB → 403 (cross-org denial enforced)
        $response->assertStatus(403);
    }

    public function test_admin_from_org_a_cannot_delete_department_in_org_b(): void
    {
        $response = $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson("/api/hr/departments/{$this->deptB->id}");

        // Engine isolates by org — adminA has no role on orgB → 403 (cross-org denial enforced)
        $response->assertStatus(403);
    }

    public function test_admin_cannot_create_department_with_parent_in_different_org(): void
    {
        $response = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => 'قسم غير مصرح',
                'parent_id' => $this->deptB->id,
                'level' => 4,
                'is_active' => true,
            ]);

        // Non-super callers preserve the controller's existing explicit
        // cross-organization denial contract.
        $response->assertStatus(403);
    }

    // ========== SC3: Null-org non-super denial ==========

    public function test_null_org_user_cannot_store_department(): void
    {
        $response = $this->actingAs($this->nullOrgUser, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => 'قسم جديد',
                'level' => 1,
                'is_active' => true,
            ]);

        // null-org user has no org-level scoped role → engine denies (403)
        $response->assertStatus(403);
    }

    public function test_null_org_user_cannot_update_department(): void
    {
        $response = $this->actingAs($this->nullOrgUser, 'sanctum')
            ->putJson("/api/hr/departments/{$this->deptA->id}", [
                'name' => 'تم التعديل',
                'level' => 4,
            ]);

        // null-org user has no org-level scoped role → engine denies (403)
        $response->assertStatus(403);
    }

    public function test_null_org_user_cannot_destroy_department(): void
    {
        $response = $this->actingAs($this->nullOrgUser, 'sanctum')
            ->deleteJson("/api/hr/departments/{$this->deptA->id}");

        // null-org user has no org-level scoped role → engine denies (403)
        $response->assertStatus(403);
    }

    public function test_null_org_user_index_returns_empty(): void
    {
        Department::factory()->create([
            'organization_id' => $this->orgA->id,
            'level' => 4,
        ]);

        $response = $this->actingAs($this->nullOrgUser, 'sanctum')
            ->getJson('/api/hr/departments');

        $response->assertStatus(403)
            ->assertJson(['message' => 'المستخدم لا ينتمي لمؤسسة']);
    }

    // ========== SC4: super_admin bypass ==========

    public function test_super_admin_can_view_any_department(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/hr/departments/{$this->deptA->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['id', 'name']);
    }

    public function test_super_admin_can_update_any_department(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/hr/departments/{$this->deptA->id}", [
                'name' => 'تم التعديل من super_admin',
                'level' => 4,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('departments', [
            'id' => $this->deptA->id,
            'name' => 'تم التعديل من super_admin',
        ]);
    }

    public function test_super_admin_can_delete_any_department(): void
    {
        // Create a department without users for clean delete
        $dept = Department::factory()->create([
            'organization_id' => $this->orgA->id,
            'level' => 4,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/hr/departments/{$dept->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('departments', ['id' => $dept->id]);
    }

    public function test_super_admin_can_create_department_in_any_org(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => 'قسم super_admin',
                'level' => 1,
                'organization_id' => $this->orgB->id,
                'is_active' => true,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('departments', [
            'name' => 'قسم super_admin',
            'organization_id' => $this->orgB->id,
        ]);
    }

    public function test_super_admin_cannot_create_department_with_parent_from_another_target_org(): void
    {
        $parentA = Department::factory()->create([
            'organization_id' => $this->orgA->id,
            'level' => 1,
            'parent_id' => null,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/admin/departments', [
                'name' => 'Cross-org child',
                'organization_id' => $this->orgB->id,
                'parent_id' => $parentA->id,
                'level' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_super_admin_cannot_update_department_with_parent_from_another_target_org(): void
    {
        $parentA = Department::factory()->create([
            'organization_id' => $this->orgA->id,
            'level' => 1,
            'parent_id' => null,
        ]);
        $targetB = Department::factory()->create([
            'organization_id' => $this->orgB->id,
            'level' => 2,
            'parent_id' => null,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/admin/departments/{$targetB->id}", [
                'name' => $targetB->name,
                'parent_id' => $parentA->id,
                'level' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_super_admin_manager_must_belong_to_selected_department_org(): void
    {
        $managerA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/admin/departments', [
                'name' => 'Org B department',
                'organization_id' => $this->orgB->id,
                'level' => 1,
                'manager_id' => $managerA->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['manager_id']);
    }

    public function test_super_admin_sees_all_departments_in_index(): void
    {
        Department::factory()->count(2)->create([
            'organization_id' => $this->orgA->id,
            'level' => 4,
        ]);
        Department::factory()->count(3)->create([
            'organization_id' => $this->orgB->id,
            'level' => 4,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/hr/departments');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(7, $data); // deptA + deptB + 2 + 3
    }

    public function test_super_admin_can_filter_admin_index_by_explicit_organization(): void
    {
        Department::factory()->count(2)->create([
            'organization_id' => $this->orgA->id,
            'level' => 4,
        ]);
        Department::factory()->count(3)->create([
            'organization_id' => $this->orgB->id,
            'level' => 4,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/admin/departments?organization_id={$this->orgA->id}&per_page=2");

        $response->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('last_page', 2);

        foreach ($response->json('data') as $department) {
            $this->assertSame($this->orgA->id, $department['organization_id']);
            $this->assertNotSame($this->deptB->id, $department['id']);
        }
    }

    public function test_non_super_admin_cannot_override_organization_with_query(): void
    {
        $response = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/hr/departments?organization_id={$this->orgB->id}");

        $response->assertOk();
        foreach ($response->json('data') as $department) {
            $this->assertSame($this->orgA->id, $department['organization_id']);
        }
    }

    public function test_super_admin_organization_filter_must_be_numeric_and_exist(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/admin/departments?organization_id=not-a-number')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/admin/departments?organization_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);
    }

    // ========== SC5: Same-org positive control ==========

    public function test_admin_can_crud_in_same_org(): void
    {
        // adminA has org-level scoped admin role for orgA → can CREATE/UPDATE/VIEW
        // Create
        $response = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => 'قسم جديد',
                'level' => 1,
                'is_active' => true,
            ]);
        $response->assertStatus(201);
        $newDeptId = $response->json('department.id');

        // Update
        $response = $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/hr/departments/{$newDeptId}", [
                'name' => 'تم التعديل',
                'level' => 1,
            ]);
        $response->assertStatus(200);

        // View
        $response = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/hr/departments/{$newDeptId}");
        $response->assertStatus(200);
    }

    public function test_cross_org_manager_id_is_rejected(): void
    {
        $managerB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => 'قسم مع مدير خاطئ',
                'level' => 1,
                'manager_id' => $managerB->id,
                'is_active' => true,
            ]);

        // adminA can CREATE in orgA, but manager_id validation rejects cross-org manager
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['manager_id']);
    }

    public function test_same_org_manager_id_is_accepted(): void
    {
        $managerA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => 'قسم مع مدير صحيح',
                'level' => 1,
                'manager_id' => $managerA->id,
                'is_active' => true,
            ]);

        $response->assertStatus(201);
    }

    // ========== SC6: Null-org department orphan ==========

    public function test_null_org_department_is_invisible_to_non_super(): void
    {
        $orphanDept = Department::factory()->create([
            'organization_id' => null,
            'level' => 4,
        ]);

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/hr/departments/{$orphanDept->id}");

        $response->assertStatus(403);
    }

    public function test_null_org_department_visible_to_super_admin(): void
    {
        $orphanDept = Department::factory()->create([
            'organization_id' => null,
            'level' => 4,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/hr/departments/{$orphanDept->id}");

        $response->assertStatus(200);
    }

    public function test_null_org_department_filtered_from_index(): void
    {
        Department::factory()->create([
            'organization_id' => null,
            'level' => 4,
        ]);
        Department::factory()->create([
            'organization_id' => $this->orgA->id,
            'level' => 4,
        ]);

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/hr/departments');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data); // deptA + 1 created
        foreach ($data as $dept) {
            $this->assertNotNull($dept['organization_id'] ?? null);
        }
    }
}
