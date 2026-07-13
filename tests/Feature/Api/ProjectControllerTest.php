<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProjectControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);

        // تشغيل seeder الصلاحيات
        $this->seed(RolesAndPermissionsSeeder::class);

        // إنشاء القسم والمستخدم
        $this->department = Department::factory()->create();

        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        // إعطاء المستخدم صلاحيات super_admin للاختبار
        $this->grantCanonicalSuperAdmin($this->user);
    }

    /**
     * اختبار عرض قائمة المشاريع
     */
    public function test_can_list_projects(): void
    {
        Project::factory()->count(3)->create([
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/projects');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
            ]);
    }

    /**
     * اختبار عرض مشروع واحد
     */
    public function test_can_view_single_project(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/projects/{$project->id}");

        $response->assertStatus(200);
    }

    /**
     * اختبار إنشاء مشروع جديد
     */
    public function test_can_create_project(): void
    {
        $projectData = [
            'name' => 'مشروع اختباري',
            'type' => 'development',
            'description' => 'وصف المشروع الاختباري',
            'status' => 'planning',
            'priority' => 'high',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
            'department_id' => $this->department->id,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', $projectData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('projects', [
            'name' => 'مشروع اختباري',
        ]);
    }

    /**
     * اختبار تحديث مشروع
     */
    public function test_can_update_project(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
            'name' => 'اسم قديم',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'name' => 'اسم جديد',
                'status' => 'in_progress',
                'priority' => 'high',
                'start_date' => $project->start_date->format('Y-m-d'),
                'end_date' => $project->end_date->format('Y-m-d'),
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'اسم جديد',
        ]);
    }

    /**
     * اختبار حذف مشروع
     */
    public function test_can_delete_project(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/projects/{$project->id}");

        $response->assertStatus(200);

        // التحقق من الحذف الناعم
        $this->assertSoftDeleted('projects', [
            'id' => $project->id,
        ]);
    }

    /**
     * اختبار التحقق من صحة البيانات عند الإنشاء
     */
    public function test_create_project_validation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/projects', [
                'name' => '', // فارغ
                'status' => 'invalid_status',
                'priority' => 'invalid_priority', // priority مطلوب ويجب أن يكون صالحاً
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'status', 'priority']);
    }

    /**
     * اختبار رفض الوصول بدون مصادقة
     */
    public function test_unauthenticated_cannot_access_projects(): void
    {
        $response = $this->getJson('/api/projects');

        $response->assertStatus(401);
    }
}
