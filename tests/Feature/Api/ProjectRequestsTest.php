<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\ProjectSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * اختبارات StoreProjectRequest و UpdateProjectRequest
 *
 * تتحقق من:
 * - التحقق من الحقول المطلوبة
 * - التحقق من صحة التواريخ
 * - التحقق من إعدادات المشرف
 * - رسائل الخطأ بالعربية
 */
class ProjectRequestsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();
        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');
    }

    /**
     * إنشاء مشروع بدون اسم يفشل
     */
    public function test_create_project_requires_name(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', [
                'priority' => 'medium',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * إنشاء مشروع بدون أولوية يفشل
     */
    public function test_create_project_requires_priority(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', [
                'name' => 'مشروع اختبار',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['priority']);
    }

    /**
     * تاريخ الانتهاء يجب أن يكون بعد تاريخ البداية
     */
    public function test_end_date_must_be_after_start_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', [
                'name' => 'مشروع اختبار',
                'priority' => 'medium',
                'start_date' => '2024-06-01',
                'end_date' => '2024-05-01', // قبل تاريخ البداية
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    /**
     * إنشاء مشروع ناجح مع بيانات صحيحة
     */
    public function test_create_project_with_valid_data(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', [
                'name' => 'مشروع اختبار جديد',
                'type' => 'development',
                'priority' => 'high',
                'department_id' => $this->department->id,
                'start_date' => '2024-01-01',
                'end_date' => '2024-12-31',
                'budget' => 100000,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('project.name', 'مشروع اختبار جديد');
    }

    /**
     * بعد توحيد أدوار المشاريع: حُذف حقل supervisor_id من المشروع (لم يعد عموداً
     * ولا قاعدة تحقق في StoreProjectRequest). لذا حتى مع تفعيل إعداد supervisor_required
     * لم يعد إنشاء المشروع بدون مشرف يفشل بالتحقق — الإنشاء ينجح (201).
     */
    public function test_supervisor_setting_no_longer_blocks_creation_after_role_unification(): void
    {
        ProjectSetting::updateOrCreate(
            ['key' => 'supervisor_required'],
            ['value' => true, 'type' => 'boolean']
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', [
                'name' => 'مشروع بدون مشرف',
                'type' => 'development',
                'priority' => 'medium',
            ]);

        // لم يعد هناك تحقق على supervisor_id → الإنشاء ينجح
        $response->assertStatus(201);
    }

    /**
     * إنشاء مشروع بدون مشرف ينجح عندما يكون الإعداد معطلاً (لم يتغير سلوكه).
     */
    public function test_supervisor_not_required_when_setting_disabled(): void
    {
        // تعطيل إعداد المشرف المطلوب
        ProjectSetting::updateOrCreate(
            ['key' => 'supervisor_required'],
            ['value' => false, 'type' => 'boolean']
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', [
                'name' => 'مشروع بدون مشرف',
                'type' => 'development',
                'priority' => 'medium',
            ]);

        $response->assertStatus(201);
    }

    /**
     * الميزانية يجب أن تكون رقم موجب
     */
    public function test_budget_must_be_positive(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', [
                'name' => 'مشروع اختبار',
                'priority' => 'medium',
                'budget' => -1000,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['budget']);
    }

    /**
     * الحالة يجب أن تكون من القيم المسموحة
     */
    public function test_status_must_be_valid(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', [
                'name' => 'مشروع اختبار',
                'priority' => 'medium',
                'status' => 'invalid_status',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    /**
     * تحديث مشروع ناجح
     */
    public function test_update_project_with_valid_data(): void
    {
        // إنشاء مشروع أولاً
        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', [
                'name' => 'مشروع أصلي',
                'type' => 'development',
                'priority' => 'low',
            ]);

        $projectId = $createResponse->json('project.id');

        // تحديث المشروع
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/projects/{$projectId}", [
                'name' => 'مشروع محدث',
                'status' => 'in_progress',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('project.name', 'مشروع محدث')
            ->assertJsonPath('project.status', 'in_progress');
    }

    /**
     * مستخدم بدون صلاحية لا يمكنه إنشاء مشروع
     */
    public function test_user_without_permission_cannot_create_project(): void
    {
        $normalUser = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $normalUser->assignRole('viewer'); // بدون صلاحية create_projects

        $response = $this->actingAs($normalUser, 'sanctum')
            ->postJson('/api/projects', [
                'name' => 'مشروع اختبار',
                'priority' => 'medium',
            ]);

        $response->assertStatus(403);
    }

    /**
     * إضافة مراحل مع المشروع
     */
    public function test_create_project_with_milestones(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', [
                'name' => 'مشروع مع مراحل',
                'type' => 'development',
                'priority' => 'high',
                'milestones' => [
                    [
                        'name' => 'المرحلة الأولى',
                        'start_date' => '2024-01-01',
                        'due_date' => '2024-03-31',
                    ],
                    [
                        'name' => 'المرحلة الثانية',
                        'start_date' => '2024-04-01',
                        'due_date' => '2024-06-30',
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertCount(2, $response->json('project.milestones'));
    }
}
