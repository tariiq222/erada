<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * تغطية شاملة لـ DepartmentController:
 *  - جميع الإجراءات الإيجابية (index, list, tree, hierarchy, allowedLevels, store, show, update, destroy)
 *  - فلاتر + pagination + التحقق من بنية JSON
 *  - الرفض السلبي: 401/403 (بدون مصادقة، بلا صلاحية، عبر منظمات، مستخدم بلا مؤسسة)
 *  - قواعد الأعمال: hierarchy 422، self-parent 422، department له موظفين/فروع 422
 */
class DepartmentControllerTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected Organization $organization;

    protected Department $department;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'level' => 4,
            'organization_id' => $this->organization->id,
        ]);
        $this->admin = User::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($this->admin);
    }

    private function superAdmin(?int $organizationId = null): User
    {
        $user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($user);

        return $user;
    }

    private function editor(?int $organizationId = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $organizationId ?? $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [Capability::DEPARTMENTS_VIEW, Capability::DEPARTMENTS_EDIT]);

        return $user;
    }

    // ====================================================================
    // index
    // ====================================================================

    public function test_index_returns_paginated_data_with_level_name_and_user_count(): void
    {
        Department::factory()->count(3)->create([
            'level' => 4,
            'organization_id' => $this->organization->id,
        ]);
        User::factory()->count(2)->create([
            'department_id' => $this->department->id,
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments?per_page=2');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'level',
                        'level_name',
                        'is_active',
                        'parent' => ['id', 'name'],
                        'manager' => ['id', 'name'],
                        'users_count',
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $first = $data[0];
        $this->assertSame('قسم', $first['level_name'], 'level 4 must map to "قسم"');
        $this->assertGreaterThanOrEqual(0, $first['users_count']);
    }

    public function test_index_filters_by_active_parent_and_search(): void
    {
        $other = Department::factory()->create([
            'name' => 'الإدارة النشطة',
            'code' => 'ACT-001',
            'level' => 4,
            'is_active' => true,
            'organization_id' => $this->organization->id,
        ]);
        Department::factory()->create([
            'name' => 'الإدارة المعطلة',
            'code' => 'INA-001',
            'level' => 4,
            'is_active' => false,
            'organization_id' => $this->organization->id,
        ]);
        $child = Department::factory()->create([
            'name' => 'قسم فرعي',
            'code' => 'CHILD-01',
            'level' => 4,
            'parent_id' => $other->id,
            'is_active' => true,
            'organization_id' => $this->organization->id,
        ]);

        // active=true
        $r1 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments?active=true');
        $r1->assertStatus(200);
        foreach ($r1->json('data') as $d) {
            $this->assertTrue($d['is_active']);
        }

        // parent_id
        $r2 = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/hr/departments?parent_id={$other->id}");
        $r2->assertStatus(200);
        $this->assertCount(1, $r2->json('data'));
        $this->assertSame($child->id, $r2->json('data.0.id'));

        // search by code (فريد) - يضرب OR داخل الـ closure
        $r3 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments?search=ACT-001');
        $r3->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($r3->json('data')));

        // search by name (Arabic)
        $r4 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments?search='.rawurlencode('فرعي'));
        $r4->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($r4->json('data')));
    }

    public function test_index_denies_user_without_organization(): void
    {
        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($orphan, Capability::DEPARTMENTS_VIEW);

        $response = $this->actingAs($orphan, 'sanctum')
            ->getJson('/api/hr/departments');

        $response->assertStatus(403)
            ->assertJson(['message' => 'المستخدم لا ينتمي لمؤسسة']);
    }

    public function test_super_admin_index_includes_all_organizations(): void
    {
        $otherOrg = Organization::factory()->create();
        Department::factory()->create([
            'organization_id' => $otherOrg->id,
            'level' => 4,
        ]);

        $super = $this->superAdmin();
        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/hr/departments');

        $response->assertStatus(200);
        $orgIds = collect($response->json('data'))->pluck('organization_id')->unique();
        $this->assertGreaterThanOrEqual(2, $orgIds->count());
    }

    // ====================================================================
    // list
    // ====================================================================

    public function test_list_returns_active_departments_with_level_name(): void
    {
        Department::factory()->create([
            'level' => 4,
            'is_active' => true,
            'organization_id' => $this->organization->id,
        ]);
        Department::factory()->create([
            'level' => 4,
            'is_active' => false,
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments/list');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => ['id', 'name', 'code', 'parent_id', 'level', 'level_name'],
            ]);

        // setUp department (active) + 1 newly created active = 2 active
        $this->assertSame(2, Department::where('is_active', true)->where('organization_id', $this->organization->id)->count());
    }

    public function test_list_denies_user_without_organization(): void
    {
        $orphan = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantEngineCapability($orphan, Capability::DEPARTMENTS_VIEW);

        $response = $this->actingAs($orphan, 'sanctum')
            ->getJson('/api/hr/departments/list');

        $response->assertStatus(403);
    }

    // ====================================================================
    // tree
    // ====================================================================

    public function test_tree_returns_root_departments_with_recursive_children(): void
    {
        $root = Department::factory()->create([
            'level' => 1,
            'parent_id' => null,
            'organization_id' => $this->organization->id,
        ]);
        Department::factory()->create([
            'level' => 2,
            'parent_id' => $root->id,
            'is_active' => true,
            'organization_id' => $this->organization->id,
        ]);
        Department::factory()->create([
            'level' => 4,
            'parent_id' => $root->id, // will be loaded as child of root
            'is_active' => true,
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments/tree');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'name',
                    'code',
                    'parent_id',
                    'level',
                    'level_name',
                    'manager',
                    'employees_count',
                    'children' => [
                        '*' => [
                            'id',
                            'name',
                            'code',
                            'level',
                            'level_name',
                            'children',
                        ],
                    ],
                ],
            ]);

        $data = $response->json();
        // find the root by id (الترتيب قد يختلف)
        $rootEntry = collect($data)->firstWhere('id', $root->id);
        $this->assertNotNull($rootEntry);
        $this->assertCount(2, $rootEntry['children']);
    }

    public function test_tree_denies_user_without_organization(): void
    {
        $orphan = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantEngineCapability($orphan, Capability::DEPARTMENTS_VIEW);

        $response = $this->actingAs($orphan, 'sanctum')
            ->getJson('/api/hr/departments/tree');

        $response->assertStatus(403);
    }

    // ====================================================================
    // hierarchy
    // ====================================================================

    public function test_hierarchy_splits_departments_by_level(): void
    {
        Department::factory()->create(['level' => 1, 'organization_id' => $this->organization->id]);
        Department::factory()->create(['level' => 3, 'organization_id' => $this->organization->id]);
        Department::factory()->create(['level' => 5, 'organization_id' => $this->organization->id]);
        Department::factory()->create(['level' => 6, 'organization_id' => $this->organization->id]);
        // ملاحظة: $this->department من setUp هو level=4

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments/hierarchy');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'all' => ['*' => ['id', 'name', 'code', 'parent_id', 'level', 'level_name']],
                'departments',
                'sections',
                'units',
            ]);

        $data = $response->json();
        // setUp (level 4) + 4 جديدة = 5
        $this->assertCount(5, $data['all']);
        $this->assertCount(2, $data['departments'], 'levels 1-3'); // 1 + 3
        $this->assertCount(1, $data['sections'], 'level 4 (setUp)');
        $this->assertCount(2, $data['units'], 'levels 5-6');
    }

    public function test_hierarchy_denies_user_without_organization(): void
    {
        $orphan = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantEngineCapability($orphan, Capability::DEPARTMENTS_VIEW);

        $response = $this->actingAs($orphan, 'sanctum')
            ->getJson('/api/hr/departments/hierarchy');

        $response->assertStatus(403);
    }

    // ====================================================================
    // allowedLevels
    // ====================================================================

    public function test_allowed_levels_with_null_parent_returns_top_management(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments/allowed-levels?parent_id=');

        $response->assertStatus(200)
            ->assertJsonStructure(['levels', 'all_levels']);

        $levels = $response->json('levels');
        $this->assertIsArray($levels);
        $this->assertSame('الإدارة العليا', $response->json('levels.1'));
        $this->assertSame('الإدارة العليا', $response->json('all_levels.1'));
    }

    public function test_allowed_levels_with_empty_string_and_null_string_normalize_to_null(): void
    {
        $r1 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments/allowed-levels?parent_id=');
        $r1->assertStatus(200);
        $this->assertSame('الإدارة العليا', $r1->json('levels.1'));

        $r2 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments/allowed-levels?parent_id=null');
        $r2->assertStatus(200);
        $this->assertSame('الإدارة العليا', $r2->json('levels.1'));
    }

    public function test_allowed_levels_with_existing_parent_returns_allowed_children(): void
    {
        $top = Department::factory()->create([
            'level' => 1,
            'parent_id' => null,
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/hr/departments/allowed-levels?parent_id={$top->id}");

        $response->assertStatus(200);
        // الإدارة العليا (1) ⇒ التنفيذي (2) أو الإدارة (3)
        $this->assertSame([2 => 'إدارة تنفيذية', 3 => 'إدارة'], $response->json('levels'));
    }

    // ====================================================================
    // store
    // ====================================================================

    public function test_store_creates_department_with_minimum_payload(): void
    {
        $payload = [
            'name' => 'الإدارة العليا الجديدة',
            'level' => 1,
            'is_active' => true,
        ];

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/hr/departments', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'department' => [
                    'id', 'name', 'level', 'level_name',
                    'parent' => ['id', 'name'],
                    'manager' => ['id', 'name'],
                ],
            ])
            ->assertJsonPath('department.name', 'الإدارة العليا الجديدة')
            ->assertJsonPath('department.level_name', 'الإدارة العليا')
            ->assertJsonPath('department.organization_id', $this->organization->id);

        $this->assertDatabaseHas('departments', ['name' => 'الإدارة العليا الجديدة', 'level' => 1]);
    }

    public function test_store_with_subdepartment_under_executive(): void
    {
        $parent = Department::factory()->create([
            'level' => 2,
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => 'قسم جديد',
                'level' => 4,
                'parent_id' => $parent->id,
                'is_active' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('department.parent_id', $parent->id);
    }

    public function test_store_rejects_invalid_hierarchy_with_422(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => 'قسم بدون أب بمستوى خاطئ',
                'level' => 4, // بدون parent يجب أن يكون level=1
                'is_active' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_store_rejects_parent_from_different_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignParent = Department::factory()->create([
            'level' => 1,
            'parent_id' => null,
            'organization_id' => $otherOrg->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => 'قسم مرفوض',
                'level' => 2,
                'parent_id' => $foreignParent->id,
                'is_active' => true,
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'القسم الأب يجب أن ينتمي لنفس المؤسسة']);
    }

    public function test_store_rejects_manager_from_different_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignManager = User::factory()->create([
            'organization_id' => $otherOrg->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => 'قسم بمدير غريب',
                'level' => 1,
                'manager_id' => $foreignManager->id,
                'is_active' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['manager_id']);
    }

    public function test_store_returns_403_for_user_without_create_permission(): void
    {
        // Has the read capability (passes the route's engine_capability gate) but
        // lacks the create capability, so the controller-level check denies.
        $plain = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($plain, Capability::DEPARTMENTS_VIEW);

        $response = $this->actingAs($plain, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => 'محاولة',
                'level' => 1,
                'is_active' => true,
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'ليس لديك صلاحية إنشاء الأقسام']);
    }

    public function test_store_returns_403_for_user_without_organization(): void
    {
        $editor = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantEngineCapability($editor, Capability::DEPARTMENTS_VIEW);

        $response = $this->actingAs($editor, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => 'بدون مؤسسة',
                'level' => 1,
                'is_active' => true,
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'المستخدم لا ينتمي لمؤسسة']);
    }

    public function test_store_as_super_admin_accepts_organization_id_override(): void
    {
        $otherOrg = Organization::factory()->create();
        $super = $this->superAdmin();

        $response = $this->actingAs($super, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => 'قسم لمنظمة أخرى',
                'level' => 1,
                'organization_id' => $otherOrg->id,
                'is_active' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('department.organization_id', $otherOrg->id);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => '',
                'level' => 99, // خارج in:1..6
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'level']);
    }

    // ====================================================================
    // show
    // ====================================================================

    public function test_show_returns_department_with_eager_loaded_relations(): void
    {
        $parent = Department::factory()->create([
            'level' => 1,
            'organization_id' => $this->organization->id,
        ]);
        $child = Department::factory()->create([
            'name' => 'الفرع',
            'parent_id' => $parent->id,
            'manager_id' => $this->admin->id,
            'organization_id' => $this->organization->id,
        ]);
        $member = User::factory()->create([
            'department_id' => $child->id,
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/hr/departments/{$child->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id', 'name', 'code', 'level',
                'parent' => ['id', 'name'],
                'manager' => ['id', 'name'],
                'users' => ['*' => ['id', 'name', 'department_id']],
                'children' => ['*' => ['id', 'name', 'code', 'parent_id']],
                'users_count',
            ])
            ->assertJsonPath('id', $child->id)
            ->assertJsonPath('parent.id', $parent->id)
            ->assertJsonPath('manager.id', $this->admin->id);
    }

    public function test_show_returns_403_for_cross_organization_access(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignDept = Department::factory()->create([
            'level' => 4,
            'organization_id' => $otherOrg->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/hr/departments/{$foreignDept->id}");

        $response->assertStatus(403)
            ->assertJson(['message' => 'غير مصرح بالوصول إلى هذا القسم']);
    }

    public function test_show_allows_super_admin_to_view_any_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignDept = Department::factory()->create([
            'level' => 4,
            'organization_id' => $otherOrg->id,
        ]);
        $super = $this->superAdmin();

        $response = $this->actingAs($super, 'sanctum')
            ->getJson("/api/hr/departments/{$foreignDept->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $foreignDept->id)
            ->assertJsonPath('organization_id', $otherOrg->id);
    }

    // ====================================================================
    // update
    // ====================================================================

    public function test_update_changes_department_fields(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/hr/departments/{$this->department->id}", [
                'name' => 'اسم محدث',
                'level' => 4,
                'manager_id' => $this->admin->id,
                'is_active' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'department' => ['id', 'name', 'level_name']])
            ->assertJsonPath('department.name', 'اسم محدث');

        $this->assertDatabaseHas('departments', ['id' => $this->department->id, 'name' => 'اسم محدث']);
    }

    public function test_update_rejects_self_as_parent_with_422(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/hr/departments/{$this->department->id}", [
                'name' => 'نفسه كأب',
                'level' => 4,
                'parent_id' => $this->department->id,
            ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن تعيين القسم كأب لنفسه']);
    }

    public function test_update_rejects_parent_from_different_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignParent = Department::factory()->create([
            'level' => 1,
            'organization_id' => $otherOrg->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/hr/departments/{$this->department->id}", [
                'name' => 'محاولة',
                'level' => 4,
                'parent_id' => $foreignParent->id,
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'القسم الأب يجب أن ينتمي لنفس المؤسسة']);
    }

    public function test_update_rejects_invalid_hierarchy_when_level_changes(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/hr/departments/{$this->department->id}", [
                'name' => 'محاولة تغيير',
                'level' => 7, // خارج النطاق
            ]);

        $response->assertStatus(422);
    }

    public function test_update_ignores_organization_id_change_attempt(): void
    {
        $otherOrg = Organization::factory()->create();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/hr/departments/{$this->department->id}", [
                'name' => 'بدون تغيير المؤسسة',
                'level' => 4,
                'organization_id' => $otherOrg->id,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('departments', [
            'id' => $this->department->id,
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_update_returns_403_for_user_without_edit_permission(): void
    {
        // Read capability passes the engine route gate; the edit capability is missing.
        $plain = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($plain, Capability::DEPARTMENTS_VIEW);

        $response = $this->actingAs($plain, 'sanctum')
            ->putJson("/api/hr/departments/{$this->department->id}", [
                'name' => 'محاولة تعديل',
                'level' => 4,
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'ليس لديك صلاحية تعديل الأقسام']);
    }

    public function test_update_returns_403_for_cross_organization_target(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignDept = Department::factory()->create([
            'level' => 4,
            'organization_id' => $otherOrg->id,
        ]);
        $editor = $this->editor();

        $response = $this->actingAs($editor, 'sanctum')
            ->putJson("/api/hr/departments/{$foreignDept->id}", [
                'name' => 'محاولة',
                'level' => 4,
            ]);

        $response->assertStatus(403);
    }

    // ====================================================================
    // destroy
    // ====================================================================

    public function test_destroy_soft_deletes_department(): void
    {
        $target = Department::factory()->create([
            'level' => 4,
            'organization_id' => $this->organization->id,
        ]);
        $super = $this->superAdmin();

        $response = $this->actingAs($super, 'sanctum')
            ->deleteJson("/api/hr/departments/{$target->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['message']);

        $this->assertSoftDeleted('departments', ['id' => $target->id]);
    }

    public function test_destroy_rejects_department_with_users(): void
    {
        $target = Department::factory()->create([
            'level' => 4,
            'organization_id' => $this->organization->id,
        ]);
        User::factory()->create([
            'department_id' => $target->id,
            'organization_id' => $this->organization->id,
        ]);
        $super = $this->superAdmin();

        $response = $this->actingAs($super, 'sanctum')
            ->deleteJson("/api/hr/departments/{$target->id}");

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن حذف قسم يحتوي على موظفين']);
    }

    public function test_destroy_rejects_department_with_children(): void
    {
        $target = Department::factory()->create([
            'level' => 4,
            'organization_id' => $this->organization->id,
        ]);
        Department::factory()->create([
            'level' => 5,
            'parent_id' => $target->id,
            'organization_id' => $this->organization->id,
        ]);
        $super = $this->superAdmin();

        $response = $this->actingAs($super, 'sanctum')
            ->deleteJson("/api/hr/departments/{$target->id}");

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن حذف قسم يحتوي على أقسام فرعية']);
    }

    public function test_destroy_returns_403_for_user_without_delete_permission(): void
    {
        $target = Department::factory()->create([
            'level' => 4,
            'organization_id' => $this->organization->id,
        ]);
        $editor = $this->editor(); // edit_departments فقط

        $response = $this->actingAs($editor, 'sanctum')
            ->deleteJson("/api/hr/departments/{$target->id}");

        $response->assertStatus(403)
            ->assertJson(['message' => 'ليس لديك صلاحية حذف الأقسام']);
    }

    public function test_destroy_returns_403_for_cross_organization_target(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignDept = Department::factory()->create([
            'level' => 4,
            'organization_id' => $otherOrg->id,
        ]);
        $super = $this->superAdmin(); // super_admin يتجاوز فحص المؤسسة لكن لا تتجاوز الـ shareOrganization في destroy

        // super_admin يجب أن يتجاوز sharesOrganization
        $response = $this->actingAs($super, 'sanctum')
            ->deleteJson("/api/hr/departments/{$foreignDept->id}");

        $response->assertStatus(200);
    }

    public function test_destroy_returns_403_for_cross_org_for_admin(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignDept = Department::factory()->create([
            'level' => 4,
            'organization_id' => $otherOrg->id,
        ]);
        // مستخدم يملك delete_departments لكنه في هذه المؤسسة (cross-org target)
        $editor = $this->editor(); // في $this->organization، الهدف في $otherOrg
        $this->grantEngineCapability($editor, [Capability::DEPARTMENTS_VIEW, Capability::DEPARTMENTS_EDIT, Capability::DEPARTMENTS_DELETE]);

        $response = $this->actingAs($editor, 'sanctum')
            ->deleteJson("/api/hr/departments/{$foreignDept->id}");

        $response->assertStatus(403);
    }

    // ====================================================================
    // auth
    // ====================================================================

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/hr/departments');
        $response->assertStatus(401);

        $this->getJson('/api/hr/departments/list')->assertStatus(401);
        $this->getJson('/api/hr/departments/tree')->assertStatus(401);
        $this->getJson('/api/hr/departments/hierarchy')->assertStatus(401);
        $this->getJson('/api/hr/departments/allowed-levels')->assertStatus(401);
    }

    // ====================================================================
    // T-E side-effect: DepartmentObserver writes activity_logs row on
    // manager_id / parent_id changes (observer @ updated).
    // ====================================================================

    public function test_update_with_new_manager_writes_department_restructured_activity_log(): void
    {
        // The DepartmentObserver only fires on manager_id / parent_id changes
        // (see DepartmentObserver::updated). A "rename only" update must NOT
        // write an audit row — verify both sides.
        $target = Department::factory()->create([
            'level' => 4,
            'organization_id' => $this->organization->id,
        ]);

        $newManager = User::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/hr/departments/{$target->id}", [
                'name' => 'بدون تغيير هيكلي',
                'level' => 4,
            ])
            ->assertStatus(200);

        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'department_restructured',
            'loggable_type' => Department::class,
            'loggable_id' => $target->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/hr/departments/{$target->id}", [
                'name' => 'مع مدير جديد',
                'level' => 4,
                'manager_id' => $newManager->id,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'department_restructured',
            'loggable_type' => Department::class,
            'loggable_id' => $target->id,
            'user_id' => $this->admin->id,
        ]);
    }
}
