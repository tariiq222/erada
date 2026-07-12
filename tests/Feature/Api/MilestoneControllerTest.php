<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestoneControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected Project $project;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'level' => 4,
        ]);
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->user, 'project_manager');

        // إنشاء مشروع بتواريخ محددة للاختبارات
        $this->project = Project::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'start_date' => now(),
            'end_date' => now()->addMonths(6),
        ]);
        // المدير يُمثَّل كدور سياقي (scoped role) لا كعمود manager_id
        $this->assignCanonicalRole($this->user, 'project_manager', 'project', $this->project->id);
    }

    /**
     * اختبار عرض قائمة مراحل مشروع
     */
    public function test_can_list_milestones(): void
    {
        Milestone::factory()->count(3)->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/milestones?project_id={$this->project->id}");

        $response->assertStatus(200)
            ->assertJsonCount(3);
    }

    /**
     * اختبار أن project_id مطلوب عند عرض المراحل
     */
    public function test_list_milestones_requires_project_id(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/milestones');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);
    }

    /**
     * اختبار عرض مرحلة واحدة
     */
    public function test_can_view_single_milestone(): void
    {
        $milestone = Milestone::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/milestones/{$milestone->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'name',
                'description',
                'project_id',
                'status',
                'start_date',
                'due_date',
                'progress',
                'order',
            ]);
    }

    /**
     * اختبار إنشاء مرحلة جديدة
     */
    public function test_can_create_milestone(): void
    {
        $milestoneData = [
            'name' => 'مرحلة اختبارية',
            'description' => 'وصف المرحلة الاختبارية',
            'project_id' => $this->project->id,
            'duration_value' => 2,
            'duration_unit' => 'week',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/milestones', $milestoneData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('milestones', [
            'name' => 'مرحلة اختبارية',
            'project_id' => $this->project->id,
        ]);
    }

    /**
     * اختبار تحديث مرحلة
     */
    public function test_can_update_milestone(): void
    {
        $milestone = Milestone::factory()->create([
            'project_id' => $this->project->id,
            'name' => 'اسم قديم',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", [
                'name' => 'اسم جديد',
                'status' => 'in_progress',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'name' => 'اسم جديد',
        ]);
    }

    /**
     * اختبار حذف مرحلة
     */
    public function test_can_delete_milestone(): void
    {
        $milestone = Milestone::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertStatus(200);

        // Milestone يستخدم SoftDeletes: الصف يبقى مع deleted_at، لا يُحذف فعلياً
        $this->assertSoftDeleted('milestones', [
            'id' => $milestone->id,
        ]);
    }

    /**
     * اختبار عدم إمكانية حذف مرحلة بها مهام
     */
    public function test_cannot_delete_milestone_with_tasks(): void
    {
        $milestone = Milestone::factory()->create([
            'project_id' => $this->project->id,
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'milestone_id' => $milestone->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'لا يمكن حذف مرحلة بها مهام. قم بنقل أو حذف المهام أولاً.',
            ]);
    }

    /**
     * اختبار التحقق من صحة البيانات عند الإنشاء
     */
    public function test_create_milestone_validation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/milestones', [
                'name' => '', // فارغ
                'project_id' => 99999, // غير موجود
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'project_id', 'duration_value', 'duration_unit']);
    }

    /**
     * اختبار رفض الوصول بدون مصادقة
     */
    public function test_unauthenticated_cannot_access_milestones(): void
    {
        $response = $this->getJson("/api/milestones?project_id={$this->project->id}");

        $response->assertStatus(401);
    }

    /**
     * اختبار تحديث تقدم المرحلة
     */
    public function test_can_update_milestone_progress(): void
    {
        $milestone = Milestone::factory()->create([
            'project_id' => $this->project->id,
            'progress' => 25,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", [
                'progress' => 75,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'progress' => 75,
        ]);
    }

    /**
     * اختبار إكمال المرحلة يضيف تاريخ الإكمال تلقائياً
     */
    public function test_completing_milestone_sets_completed_date(): void
    {
        $milestone = Milestone::factory()->create([
            'project_id' => $this->project->id,
            'status' => 'in_progress',
            'completed_date' => null,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", [
                'status' => 'completed',
            ]);

        $response->assertStatus(200);

        $milestone->refresh();
        $this->assertEquals('completed', $milestone->status);
        $this->assertEquals(100, $milestone->progress);
        $this->assertNotNull($milestone->completed_date);
    }

    /**
     * اختبار ترتيب المراحل تلقائياً
     */
    public function test_milestone_order_is_auto_incremented(): void
    {
        $milestone1Data = [
            'name' => 'المرحلة الأولى',
            'project_id' => $this->project->id,
            'duration_value' => 1,
            'duration_unit' => 'week',
        ];

        $response1 = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/milestones', $milestone1Data);

        $milestone2Data = [
            'name' => 'المرحلة الثانية',
            'project_id' => $this->project->id,
            'duration_value' => 1,
            'duration_unit' => 'week',
        ];

        $response2 = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/milestones', $milestone2Data);

        $response1->assertStatus(201);
        $response2->assertStatus(201);

        $milestone1 = Milestone::where('name', 'المرحلة الأولى')->first();
        $milestone2 = Milestone::where('name', 'المرحلة الثانية')->first();

        $this->assertEquals(1, $milestone1->order);
        $this->assertEquals(2, $milestone2->order);
    }

    /**
     * اختبار حساب تاريخ البداية تلقائياً
     */
    public function test_milestone_start_date_is_calculated(): void
    {
        $milestone1Data = [
            'name' => 'المرحلة الأولى',
            'project_id' => $this->project->id,
            'duration_value' => 7,
            'duration_unit' => 'day',
        ];

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/milestones', $milestone1Data);

        $milestone1 = Milestone::where('name', 'المرحلة الأولى')->first();

        // المرحلة الأولى يجب أن تبدأ من تاريخ بداية المشروع
        $this->assertEquals(
            $this->project->start_date->format('Y-m-d'),
            $milestone1->start_date->format('Y-m-d')
        );

        $milestone2Data = [
            'name' => 'المرحلة الثانية',
            'project_id' => $this->project->id,
            'duration_value' => 7,
            'duration_unit' => 'day',
        ];

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/milestones', $milestone2Data);

        $milestone2 = Milestone::where('name', 'المرحلة الثانية')->first();

        // المرحلة الثانية يجب أن تبدأ بعد انتهاء الأولى
        $expectedStart = $milestone1->due_date->copy()->addDay();
        $this->assertEquals(
            $expectedStart->format('Y-m-d'),
            $milestone2->start_date->format('Y-m-d')
        );
    }

    /**
     * اختبار تحديث حالة المرحلة
     */
    public function test_can_update_milestone_status(): void
    {
        $milestone = Milestone::factory()->pending()->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", [
                'status' => 'in_progress',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'in_progress',
        ]);
    }

    /**
     * اختبار الحالات المسموح بها
     */
    public function test_valid_milestone_statuses(): void
    {
        $validStatuses = ['pending', 'in_progress', 'completed', 'overdue'];

        foreach ($validStatuses as $status) {
            $milestone = Milestone::factory()->create([
                'project_id' => $this->project->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->user, 'sanctum')
                ->putJson("/api/milestones/{$milestone->id}", [
                    'status' => $status,
                ]);

            $response->assertStatus(200);
        }
    }

    /**
     * اختبار رفض حالة غير صالحة
     */
    public function test_invalid_status_is_rejected(): void
    {
        $milestone = Milestone::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", [
                'status' => 'invalid_status',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }
}
