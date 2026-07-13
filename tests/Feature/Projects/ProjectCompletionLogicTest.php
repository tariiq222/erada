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
 * ProjectCompletionLogicTest — منطق إتمام المشروع خارج فرض حقول الإغلاق:
 *  - ختم تاريخ الإنجاز الفعلي (actual_end_date) عند الإتمام وإلغاؤه عند إعادة الفتح (BUG-016 → BUG-017).
 *  - فرض خريطة انتقالات الحالة (state machine) في UpdateProjectRequest::STATUS_TRANSITIONS.
 *  - منع مستخدم من مؤسسة أخرى من إتمام المشروع (عزل المستأجرين).
 */
class ProjectCompletionLogicTest extends TestCase
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
        $this->grantCanonicalSuperAdmin($this->superAdmin);
    }

    protected function makeProject(string $status, string $type = 'development', array $extra = []): Project
    {
        return Project::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => $type,
            'status' => $status,
            'actual_end_date' => null,
        ], $extra));
    }

    /** الإتمام يختم actual_end_date تلقائياً (تعتمد عليه إحصائية متوسط زمن الإنجاز). */
    public function test_completing_project_stamps_actual_end_date(): void
    {
        $project = $this->makeProject('in_progress');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'status' => 'completed',
                'lessons_learned' => 'درس مستفاد',
                'achievement_status' => 'achieved',
            ])
            ->assertOk()
            ->assertJsonPath('project.status', 'completed');

        $fresh = $project->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->actual_end_date, 'actual_end_date should be stamped on completion');
        $this->assertSame(now()->toDateString(), $fresh->actual_end_date->toDateString());
    }

    /** الإتمام يضبط نسبة الإنجاز إلى 100% (اتساقاً مع المهام والمراحل). */
    public function test_completing_project_sets_progress_to_100(): void
    {
        $project = $this->makeProject('in_progress', 'development', ['progress' => 40]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'status' => 'completed',
                'lessons_learned' => 'درس',
                'achievement_status' => 'achieved',
            ])
            ->assertOk();

        $this->assertSame(100, (int) $project->fresh()->progress);
    }

    /**
     * النسبة 100% تبقى ثابتة بعد تعديل مهمة في مشروع مكتمل: Project::updateProgress()
     * يصبح no-op أثناء حالة completed، فلا يدوس TaskObserver النسبة المثبّتة.
     */
    public function test_completed_project_progress_stays_100_after_task_edit(): void
    {
        $project = $this->makeProject('in_progress', 'development', ['progress' => 40]);

        // كل المهام مغلقة كي يسمح الحارس بالإتمام.
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'status' => 'completed',
            'progress' => 100,
            'assigned_to' => $this->superAdmin->id,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'status' => 'completed',
                'lessons_learned' => 'درس',
                'achievement_status' => 'achieved',
            ])
            ->assertOk();

        $this->assertSame(100, (int) $project->fresh()->progress);

        // تعديل مهمة (يطلق TaskObserver::saved → Project::updateProgress) لا يخفّض النسبة.
        $task->update(['progress' => 30]);

        $this->assertSame(100, (int) $project->fresh()->progress, 'completed project progress must stay 100 after a task edit');
    }

    /** إعادة فتح مشروع مكتمل مسموحة وتُلغي actual_end_date. */
    public function test_reopening_completed_project_is_allowed_and_clears_actual_end_date(): void
    {
        $project = $this->makeProject('completed', 'development', [
            'actual_end_date' => now()->toDateString(),
            'lessons_learned' => 'درس',
            'achievement_status' => 'achieved',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('project.status', 'in_progress');

        $this->assertNull($project->fresh()->actual_end_date);
    }

    /** الانتقال completed → draft ممنوع (خريطة الحالات). */
    public function test_cannot_transition_completed_to_draft(): void
    {
        $project = $this->makeProject('completed', 'development', [
            'lessons_learned' => 'درس',
            'achievement_status' => 'achieved',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", ['status' => 'draft'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame('completed', $project->fresh()->status);
    }

    /** الانتقال cancelled → completed ممنوع (خريطة الحالات). */
    public function test_cannot_transition_cancelled_to_completed(): void
    {
        $project = $this->makeProject('cancelled');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'status' => 'completed',
                'lessons_learned' => 'درس',
                'achievement_status' => 'achieved',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame('cancelled', $project->fresh()->status);
    }

    /** مستخدم من مؤسسة أخرى لا يستطيع إتمام المشروع (عزل المستأجرين). */
    public function test_user_from_other_organization_cannot_complete_project(): void
    {
        $project = $this->makeProject('in_progress');

        $otherOrg = Organization::factory()->create();
        $otherDept = Department::factory()->create(['organization_id' => $otherOrg->id]);
        $outsider = User::factory()->create([
            'organization_id' => $otherOrg->id,
            'department_id' => $otherDept->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($outsider);

        $response = $this->actingAs($outsider, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'status' => 'completed',
                'lessons_learned' => 'درس',
                'achievement_status' => 'achieved',
            ]);

        $this->assertContains($response->status(), [403, 404], 'cross-org completion must be denied');
        $this->assertSame('in_progress', $project->fresh()->status);
        $this->assertNull($project->fresh()->actual_end_date);
    }
}
