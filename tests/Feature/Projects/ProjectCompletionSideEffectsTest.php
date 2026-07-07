<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProjectCompletionSideEffectsTest — يوثّق الآثار الجانبية لإتمام المشروع.
 *
 * إتمام المشروع وله مهام مفتوحة (غير completed/cancelled) ممنوع على الخادم:
 * يُرفض التحديث بـ 422 مع خطأ على الحقل status. إذا كانت كل المهام مغلقة
 * (مكتملة أو ملغاة) يُسمح بالإتمام (200).
 */
class ProjectCompletionSideEffectsTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected Department $dept;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');
    }

    /** الحارس: لا يمكن إتمام المشروع وبه مهمة مفتوحة (422 على status). */
    public function test_project_cannot_be_completed_with_incomplete_tasks(): void
    {
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);

        $openTask = Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'status' => 'in_progress',
            'assigned_to' => $this->superAdmin->id,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'status' => 'completed',
                'lessons_learned' => 'درس',
                'achievement_status' => 'achieved',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // المشروع لم يكتمل والمهمة بقيت كما هي
        $this->assertSame('in_progress', $project->fresh()->status);
        $this->assertNotSame('completed', $openTask->fresh()->status->value);
    }

    /** مع إغلاق كل المهام (مكتملة/ملغاة) يُسمح بإتمام المشروع. */
    public function test_project_can_be_completed_when_all_tasks_closed(): void
    {
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);

        Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'status' => 'completed',
            'assigned_to' => $this->superAdmin->id,
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'status' => 'cancelled',
            'assigned_to' => $this->superAdmin->id,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'status' => 'completed',
                'lessons_learned' => 'درس',
                'achievement_status' => 'achieved',
            ])
            ->assertOk();

        $this->assertSame('completed', $project->fresh()->status);
    }
}
