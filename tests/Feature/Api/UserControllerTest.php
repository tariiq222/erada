<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $user;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        // إنشاء المؤسسة والقسم
        $organization = Organization::factory()->create();
        $this->department = Department::factory()->create(['organization_id' => $organization->id]);

        // إنشاء الأدمن
        $this->admin = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->admin, 'admin');

        // إنشاء مستخدم عادي
        $this->user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->user, 'member');
    }

    /**
     * اختبار عرض قائمة المستخدمين
     */
    public function test_can_list_users(): void
    {
        User::factory()->count(3)->create([
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/users');

        $response->assertStatus(200);
        $this->assertArrayHasKey('data', $response->json());
    }

    /**
     * اختبار عرض مستخدم واحد
     */
    public function test_can_view_single_user(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/users/{$user->id}");

        $response->assertStatus(200);
        $this->assertArrayHasKey('id', $response->json());
    }

    /**
     * اختبار إنشاء مستخدم جديد
     */
    public function test_can_create_user(): void
    {
        $userData = [
            'name' => 'مستخدم جديد',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'department_id' => $this->department->id,
            'job_title' => 'مطور',
            'phone' => '01234567890',
            'is_active' => true,
        ];

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/users', $userData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'name' => 'مستخدم جديد',
            'email' => 'newuser@example.com',
        ]);
    }

    /**
     * اختبار تحديث مستخدم
     */
    public function test_can_update_user(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'department_id' => $this->department->id,
            'name' => 'اسم قديم',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/users/{$user->id}", [
                'name' => 'اسم جديد',
                'department_id' => $this->department->id,
                'job_title' => 'مدير مشروع',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'اسم جديد',
        ]);
    }

    /**
     * اختبار حذف مستخدم
     */
    public function test_can_delete_user(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/users/{$user->id}");

        $response->assertStatus(200);
    }

    /**
     * اختبار التحقق من صحة البيانات عند الإنشاء
     */
    public function test_create_user_validation(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/users', [
                'name' => '', // فارغ
                'email' => 'invalid-email', // بريد إلكتروني غير صحيح
                'password' => '123', // كلمة مرور قصيرة جداً
                'department_id' => 99999, // غير موجود
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'department_id']);
    }

    /**
     * اختبار المستخدم بدون صلاحية لا يمكنه الوصول للمستخدمين
     */
    public function test_user_without_permission_cannot_access_users(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/users');

        // مستخدم بلا صلاحية view_users يُمنع من قائمة المستخدمين (تشديد أمني، يطابق اسم الاختبار)
        $response->assertStatus(403);
    }

    /**
     * اختبار رفض الوصول بدون مصادقة
     */
    public function test_unauthenticated_cannot_access_users(): void
    {
        $response = $this->getJson('/api/users');

        $response->assertStatus(401);
    }

    /**
     * اختبار فلترة المستخدمين حسب القسم
     */
    public function test_can_filter_users_by_department(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/users?department_id={$this->department->id}");

        $response->assertStatus(200);
    }

    /**
     * اختبار فلترة المستخدمين حسب الحالة
     */
    public function test_can_filter_users_by_status(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/users?is_active=true');

        $response->assertStatus(200);
    }

    /**
     * اختبار البحث في المستخدمين
     */
    public function test_can_search_users(): void
    {
        User::factory()->create([
            'name' => 'John Smith',
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/users?search=John');

        $response->assertStatus(200);
    }

    /**
     * اختبار تغيير كلمة المرور
     */
    public function test_can_change_password(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => 'password',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ]);

        $response->assertStatus(200);
    }

    /**
     * اختبار عرض بيانات المستخدم الحالي
     */
    public function test_can_view_current_user(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/user');

        $response->assertStatus(200);
        $this->assertArrayHasKey('user', $response->json());
    }

    /**
     * اختبار تحديث الملف الشخصي
     */
    public function test_can_update_own_profile(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'اسم محدث',
                'email' => $this->user->email,
                'phone' => '0987654321',
            ]);

        $response->assertStatus(200);

        $this->user->refresh();
        $this->assertEquals('اسم محدث', $this->user->name);
        $this->assertEquals('0987654321', $this->user->phone);
    }

    /**
     * اختبار إنشاء مستخدم جديد (للمسؤولين)
     */
    public function test_admin_can_create_user(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'مستخدم جديد',
                'email' => 'newuser@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'department_id' => $this->department->id,
                'roles' => ['viewer'],
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'name' => 'مستخدم جديد',
            'email' => 'newuser@example.com',
        ]);
    }

    /**
     * اختبار قائمة المستخدمين
     */
    public function test_can_get_users_list(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/users/list');

        $response->assertStatus(200);
        // التحقق من أن الاستجابة تحتوي على قائمة المستخدمين
        $this->assertIsArray($response->json());
    }

    /**
     * اختبار تكرار البريد الإلكتروني
     */
    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'مستخدم جديد',
                'email' => 'existing@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'department_id' => $this->department->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * اختبار عرض المستخدمين النشطين فقط
     */
    public function test_can_get_active_users_only(): void
    {
        User::factory()->count(3)->create(['is_active' => true]);
        User::factory()->count(2)->create(['is_active' => false]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/users?active_only=true');

        $response->assertStatus(200);

        $data = $response->json('data');
        foreach ($data as $user) {
            $this->assertTrue($user['is_active']);
        }
    }
}
