<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TaskCompletionAuthAndGuardTest — منطق إتمام المهمة على مستوى الـ API
 * (PATCH /api/unified-tasks/{task}/status):
 *  - حارس المهام الفرعية غير المكتملة (يمنع إتمام الأب).
 *  - عزل المستأجرين (مستخدم من مؤسسة أخرى لا يكمل المهمة).
 *  - الإتمام المباشر يضبط completed_date + progress=100 (لا توجد خريطة انتقال للمهام).
 */
class TaskCompletionAuthAndGuardTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected Department $dept;

    protected User $superAdmin;

    protected Project $project;

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

        $this->project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);
    }

    protected function makeTask(string $status, ?int $parentId = null): Task
    {
        return Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'status' => $status,
            'parent_id' => $parentId,
            'assigned_to' => $this->superAdmin->id,
        ]);
    }

    /** لا يمكن إكمال مهمة بها مهام فرعية غير مكتملة (422). */
    public function test_cannot_complete_task_with_incomplete_subtasks(): void
    {
        $parent = $this->makeTask('in_progress');
        $this->makeTask('in_progress', $parent->id);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/unified-tasks/{$parent->id}/status", ['status' => 'completed'])
            ->assertStatus(422);

        $this->assertNotSame('completed', $parent->fresh()->status->value);
    }

    /** يمكن إكمال الأب بعد إكمال جميع المهام الفرعية. */
    public function test_can_complete_parent_after_subtasks_completed(): void
    {
        $parent = $this->makeTask('in_progress');
        $this->makeTask('completed', $parent->id);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/unified-tasks/{$parent->id}/status", ['status' => 'completed'])
            ->assertOk();

        $this->assertSame('completed', $parent->fresh()->status->value);
    }

    /** مستخدم من مؤسسة أخرى لا يستطيع إكمال المهمة. */
    public function test_user_from_other_organization_cannot_complete_task(): void
    {
        $task = $this->makeTask('in_progress');

        $otherOrg = Organization::factory()->create();
        $otherDept = Department::factory()->create(['organization_id' => $otherOrg->id]);
        $outsider = User::factory()->create([
            'organization_id' => $otherOrg->id,
            'department_id' => $otherDept->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($outsider);

        $response = $this->actingAs($outsider, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/status", ['status' => 'completed']);

        $this->assertContains($response->status(), [403, 404], 'cross-org task completion must be denied');
        $this->assertNotSame('completed', $task->fresh()->status->value);
    }

    /** الإتمام المباشر (todo → completed) يضبط completed_date + progress=100. */
    public function test_direct_completion_sets_completed_date_and_progress(): void
    {
        $task = $this->makeTask('todo');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/status", ['status' => 'completed'])
            ->assertOk();

        $fresh = $task->fresh();
        $this->assertSame('completed', $fresh->status->value);
        $this->assertNotNull($fresh->completed_date);
        $this->assertSame(100, (int) $fresh->progress);
    }

    /**
     * اعتماد الإكمال صلاحية قيادية (completeTask / tasks.complete): عضو المشروع
     * لا يستطيع اعتماد إكمال المهمة.
     */
    public function test_project_member_cannot_complete_task(): void
    {
        $member = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($member, 'project_member', 'project', $this->project->id);

        $task = $this->makeTask('in_progress');

        $this->actingAs($member, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/status", ['status' => 'completed'])
            ->assertForbidden();

        $this->assertNotSame('completed', $task->fresh()->status->value);
    }

    /**
     * السلوك الحالي (موثّق — BUG-018 مرشّح): تغيير حالة المهمة مقصور على القيادة
     * (tasks.edit عبر PROJECT_MANAGER/admin). حتى المُسنَد إليه المهمة، إن كان عضو
     * مشروع فقط (PROJECT_MEMBER)، لا يستطيع إرسال مهمته للمراجعة (403) — وهذا يناقض
     * تدفّق الخدمة الذاتية الذي توحي به الواجهة ("بعد الإرسال للمراجعة يتولّى المدير").
     */
    public function test_member_assignee_currently_cannot_change_own_task_status(): void
    {
        $assignee = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($assignee, 'project_member', 'project', $this->project->id);

        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'status' => 'in_progress',
            'assigned_to' => $assignee->id,
        ]);

        $this->actingAs($assignee, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/status", ['status' => 'in_review'])
            ->assertForbidden();

        $this->assertSame('in_progress', $task->fresh()->status->value);
    }

    /** مدير المشروع (PROJECT_MANAGER) يستطيع اعتماد إكمال المهمة. */
    public function test_project_manager_can_complete_task(): void
    {
        $manager = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($manager, 'project_manager', 'project', $this->project->id);

        $task = $this->makeTask('in_review');

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/status", ['status' => 'completed'])
            ->assertOk();

        $this->assertSame('completed', $task->fresh()->status->value);
    }
}
