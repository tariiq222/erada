<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        // إنشاء الأدمن مع مؤسسة
        $organization = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'level' => 4,
            'organization_id' => $organization->id,
        ]);
        $this->admin = User::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->admin, 'admin');
    }

    /**
     * اختبار عرض قائمة الأقسام
     */
    public function test_can_list_departments(): void
    {
        Department::factory()->count(3)->create([
            'level' => 4,
            'organization_id' => $this->admin->organization_id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
            ]);
    }

    /**
     * اختبار عرض قسم واحد
     */
    public function test_can_view_single_department(): void
    {
        $department = Department::factory()->create([
            'level' => 4,
            'organization_id' => $this->admin->organization_id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/hr/departments/{$department->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'name',
                'description',
                'manager_id',
                'parent_id',
                'is_active',
            ]);
    }

    /**
     * اختبار إنشاء قسم جديد
     */
    public function test_can_create_department(): void
    {
        // إنشاء إدارة عليا (بدون أب) - المستوى 1 فقط مسموح بدون parent
        $departmentData = [
            'name' => 'الإدارة العليا للتطوير',
            'description' => 'إدارة عليا',
            'level' => 1,
            'is_active' => true,
        ];

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/hr/departments', $departmentData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('departments', [
            'name' => 'الإدارة العليا للتطوير',
        ]);
    }

    /**
     * اختبار تحديث قسم
     */
    public function test_can_update_department(): void
    {
        $department = Department::factory()->create([
            'name' => 'اسم قديم',
            'level' => 4,
            'organization_id' => $this->admin->organization_id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/hr/departments/{$department->id}", [
                'name' => 'اسم جديد',
                'description' => 'وصف محدث',
                'manager_id' => $this->admin->id,
                'level' => 4,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'name' => 'اسم جديد',
        ]);
    }

    /**
     * اختبار حذف قسم
     */
    public function test_can_delete_department(): void
    {
        $department = Department::factory()->create([
            'level' => 4,
            'organization_id' => $this->admin->organization_id,
        ]);

        // admin is organization-wide: it CAN delete a department in its own
        // organization (engine grants every capability via is_admin_role).
        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/hr/departments/{$department->id}");

        $response->assertStatus(200);

        // Department uses SoftDeletes: the row remains with deleted_at set.
        $this->assertSoftDeleted('departments', [
            'id' => $department->id,
        ]);

        // Denial path: an admin from a DIFFERENT organization cannot delete a
        // department it does not own (organization isolation, fail-closed).
        $otherOrg = Organization::factory()->create();
        $foreignAdmin = User::factory()->create([
            'organization_id' => $otherOrg->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($foreignAdmin, 'admin');

        $other = Department::factory()->create([
            'level' => 4,
            'organization_id' => $this->admin->organization_id,
        ]);
        $this->actingAs($foreignAdmin, 'sanctum')
            ->deleteJson("/api/hr/departments/{$other->id}")
            ->assertStatus(403);
    }

    /**
     * اختبار التحقق من صحة البيانات عند الإنشاء
     */
    public function test_create_department_validation(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/hr/departments', [
                'name' => '', // فارغ
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'level']);
    }

    /**
     * اختبار رفض الوصول بدون مصادقة
     */
    public function test_unauthenticated_cannot_access_departments(): void
    {
        $response = $this->getJson('/api/hr/departments');

        $response->assertStatus(401);
    }

    /**
     * اختبار البحث في الأقسام
     */
    public function test_can_search_departments(): void
    {
        // إنشاء أقسام جديدة بكود فريد للبحث
        Department::factory()->create([
            'name' => 'قسم البرمجة',
            'code' => 'SEARCHTEST123',
            'level' => 4,
            'is_active' => true,
            'organization_id' => $this->admin->organization_id,
        ]);
        Department::factory()->create([
            'name' => 'قسم المبيعات',
            'code' => 'SAL-001',
            'level' => 4,
            'is_active' => true,
            'organization_id' => $this->admin->organization_id,
        ]);

        // البحث بالكود (أكثر دقة)
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments?search=SEARCHTEST123');

        $response->assertStatus(200);

        // التحقق من أن البحث يعيد نتائج
        $data = $response->json('data');
        $this->assertNotEmpty($data, 'يجب أن يعيد البحث نتائج');
    }

    /**
     * اختبار الأقسام النشطة فقط
     */
    public function test_can_get_active_departments_only(): void
    {
        Department::factory()->count(3)->create([
            'is_active' => true,
            'level' => 4,
            'organization_id' => $this->admin->organization_id,
        ]);
        Department::factory()->count(2)->create([
            'is_active' => false,
            'level' => 4,
            'organization_id' => $this->admin->organization_id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments?active=true');

        $response->assertStatus(200);

        $data = $response->json('data');
        foreach ($data as $department) {
            $this->assertTrue($department['is_active']);
        }
    }

    /**
     * اختبار إنشاء قسم فرعي
     */
    public function test_can_create_subdepartment(): void
    {
        // إنشاء قسم أب (مستوى 3 - إدارة)
        $parentDepartment = Department::factory()->create([
            'level' => 3,
            'organization_id' => $this->admin->organization_id,
        ]);

        $departmentData = [
            'name' => 'قسم فرعي',
            'description' => 'قسم فرعي للقسم الرئيسي',
            'parent_id' => $parentDepartment->id,
            'level' => 4, // مستوى القسم (تابع للإدارة)
            'is_active' => true,
        ];

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/hr/departments', $departmentData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('departments', [
            'name' => 'قسم فرعي',
            'parent_id' => $parentDepartment->id,
        ]);
    }

    /**
     * اختبار عدم القدرة على حذف قسم به موظفين
     */
    public function test_cannot_delete_department_with_employees(): void
    {
        $department = Department::factory()->create([
            'level' => 4,
            'organization_id' => $this->admin->organization_id,
        ]);
        User::factory()->create(['department_id' => $department->id]);

        // super_admin يملك delete_departments فيصل للقاعدة التجارية (422) لا للـ authz (403)
        $superAdmin = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/hr/departments/{$department->id}");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'لا يمكن حذف قسم يحتوي على موظفين',
            ]);
    }

    /**
     * اختبار الحصول على شجرة الأقسام
     */
    public function test_can_get_departments_tree(): void
    {
        Department::factory()->create([
            'level' => 1,
            'parent_id' => null,
            'organization_id' => $this->admin->organization_id,
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
                ],
            ]);
    }

    /**
     * اختبار الحصول على قائمة الأقسام البسيطة
     */
    public function test_can_get_departments_list(): void
    {
        Department::factory()->count(3)->create([
            'level' => 4,
            'is_active' => true,
            'organization_id' => $this->admin->organization_id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments/list');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'name',
                    'code',
                    'parent_id',
                    'level',
                ],
            ]);
    }

    /**
     * اختبار التسلسل الهرمي للأقسام
     */
    public function test_can_get_departments_hierarchy(): void
    {
        Department::factory()->create([
            'level' => 1,
            'organization_id' => $this->admin->organization_id,
        ]);
        Department::factory()->create([
            'level' => 4,
            'organization_id' => $this->admin->organization_id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments/hierarchy');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'all',
                'departments',
                'sections',
                'units',
            ]);
    }

    /**
     * اختبار الحصول على المستويات المسموحة
     */
    public function test_can_get_allowed_levels(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/hr/departments/allowed-levels');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'levels',
                'all_levels',
            ]);
    }
}
