<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class UnifiedTaskControllerTopUpTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private Department $department;

    private Project $project;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();
        $this->project = Project::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
        ]);
        $this->admin = User::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
        ]);
        $this->admin->assignRole('admin');
    }

    /**
     * Attach a user to a project as a project member via the project_members
     * pivot (which is `model_has_scoped_roles` with scope_type='project').
     * Mirrors what TeamService::addMember does in production.
     */
    private function addProjectMember(User $user, Project $project, string $role = 'member'): void
    {
        ScopedRole::create([
            'user_id' => $user->id,
            'role' => $role,
            'scope_type' => ScopedRole::SCOPE_PROJECT,
            'scope_id' => $project->id,
            'inherit_to_children' => true,
            'granted_by' => null,
        ]);
    }

    public function test_my_tasks_stats_activity_log_assign_and_destroy_endpoints(): void
    {
        $assignee = User::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
        ]);
        $task = Task::factory()->create([
            'type' => TaskType::PROJECT->value,
            'project_id' => $this->project->id,
            'department_id' => $this->department->id,
            'assigned_to' => $this->admin->id,
            'created_by' => $this->admin->id,
            'status' => TaskStatus::TODO->value,
        ]);
        ActivityLog::create([
            'loggable_type' => Task::class,
            'loggable_id' => $task->id,
            'user_id' => $this->admin->id,
            'action' => 'created',
            'description' => 'Task created for controller top-up',
            'old_values' => [],
            'new_values' => ['title' => $task->title],
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/unified-tasks/my?per_page=10')
            ->assertOk()
            ->assertJsonStructure(['data' => ['*' => ['id', 'title', 'status', 'assignee']]])
            ->assertJsonPath('data.0.id', $task->id);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/unified-tasks/stats?my_tasks=1&type=project')
            ->assertOk()
            ->assertJsonStructure(['total', 'by_status', 'by_priority', 'overdue', 'upcoming_7_days', 'by_type'])
            ->assertJsonPath('by_status.todo', 1);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/unified-tasks/{$task->id}/activity-log")
            ->assertOk()
            ->assertJsonStructure(['*' => ['id', 'action', 'description', 'user']])
            ->assertJsonPath('0.action', 'created');

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/assign", ['assigned_to' => $assignee->id])
            ->assertOk()
            ->assertJsonPath('message', 'تم تعيين المهمة بنجاح')
            ->assertJsonPath('task.assignee.id', $assignee->id);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/unified-tasks/{$task->id}")
            ->assertOk()
            ->assertJson(['message' => 'تم حذف المهمة بنجاح']);

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_assign_rejects_target_outside_project_organization(): void
    {
        $outsideAssignee = User::factory()->create();
        $task = Task::factory()->create([
            'type' => TaskType::PROJECT->value,
            'project_id' => $this->project->id,
            'department_id' => $this->department->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/assign", ['assigned_to' => $outsideAssignee->id])
            ->assertForbidden();
    }

    public function test_update_status_reopens_completed_task_and_clears_completed_date(): void
    {
        $task = Task::factory()->completed()->create([
            'type' => TaskType::PROJECT->value,
            'project_id' => $this->project->id,
            'department_id' => $this->department->id,
            'completed_date' => now()->subDay(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/status", ['status' => TaskStatus::IN_PROGRESS->value])
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث حالة المهمة بنجاح')
            ->assertJsonPath('task.status', TaskStatus::IN_PROGRESS->value);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => TaskStatus::IN_PROGRESS->value,
            'completed_date' => null,
        ]);
    }

    // ============================================================
    // Task 3.6 — Tasks remaining edges (store cross-org, personal-task
    // open-create, assign() denial, update() subtask-incomplete guard)
    // ============================================================

    public function test_store_rejects_cross_org_project_id_in_body(): void
    {
        // Foreign project owned by orgB (NOT $this->project's org).
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $foreignProject = Project::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
        ]);

        $status = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/unified-tasks', [
                'type' => TaskType::PROJECT->value,
                'title' => 'Cross-org task',
                'project_id' => $foreignProject->id,
            ])
            ->status();

        $this->assertContains(
            $status,
            [403, 422],
            'store must reject a cross-org project_id in the body'
        );

        // No task row was created against the foreign project.
        $this->assertDatabaseMissing('tasks', [
            'title' => 'Cross-org task',
            'project_id' => $foreignProject->id,
        ]);
    }

    public function test_store_rejects_cross_org_parent_id_in_body(): void
    {
        // Foreign parent task owned by orgB.
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $foreignProject = Project::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
        ]);
        $foreignParent = Task::factory()->create([
            'type' => TaskType::PROJECT->value,
            'project_id' => $foreignProject->id,
            'department_id' => $deptB->id,
        ]);

        $status = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/unified-tasks', [
                'type' => TaskType::PROJECT->value,
                'title' => 'Child with foreign parent',
                'project_id' => $this->project->id,
                'parent_id' => $foreignParent->id,
            ])
            ->status();

        $this->assertContains(
            $status,
            [403, 422],
            'store must reject a cross-org parent_id in the body'
        );

        // No child task row was created referencing the foreign parent.
        $this->assertDatabaseMissing('tasks', [
            'title' => 'Child with foreign parent',
            'parent_id' => $foreignParent->id,
        ]);
    }

    public function test_viewer_can_create_personal_task_but_is_denied_project_task(): void
    {
        // A plain viewer (no TASKS_CREATE capability). Personal tasks bypass
        // the engine (StoreTaskRequest::authorize), so a viewer MAY POST a
        // type=personal task (201). Project tasks must be denied (403).
        $viewer = User::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $viewer->assignRole('viewer');

        // Personal task: 201 (open-create).
        $personalResponse = $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/unified-tasks', [
                'type' => TaskType::PERSONAL->value,
                'title' => 'Viewer personal task',
            ]);

        $personalResponse->assertStatus(201);
        $this->assertDatabaseHas('tasks', [
            'title' => 'Viewer personal task',
            'type' => TaskType::PERSONAL->value,
            'created_by' => $viewer->id,
        ]);

        // Project task: 403 (engine denial).
        $projectResponse = $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/unified-tasks', [
                'type' => TaskType::PROJECT->value,
                'title' => 'Viewer project task',
                'project_id' => $this->project->id,
            ]);

        $projectResponse->assertStatus(403);
        $this->assertDatabaseMissing('tasks', ['title' => 'Viewer project task']);
    }

    public function test_assign_denies_project_member_assignor(): void
    {
        // The assign() path requires update permission on the task (TaskPolicy
        // → TASKS_EDIT). A project member who is NOT a manager / owner must be
        // denied. Use a member-level ScopedRole definition so the engine
        // rejects TASKS_EDIT on this scope.
        $member = User::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->addProjectMember($member, $this->project, 'member');

        $assignee = User::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $task = Task::factory()->create([
            'type' => TaskType::PROJECT->value,
            'project_id' => $this->project->id,
            'department_id' => $this->department->id,
            'assigned_to' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($member, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/assign", ['assigned_to' => $assignee->id])
            ->assertForbidden();

        // Side-effect guard: assignee was not set.
        $task->refresh();
        $this->assertNotSame($assignee->id, (int) $task->assigned_to, 'assign must not have mutated assigned_to');
    }

    public function test_update_rejects_complete_status_when_subtasks_pending(): void
    {
        // The PUT update() path also enforces the subtask-incomplete guard
        // (only PATCH /status was covered before). Create a parent with one
        // pending subtask and assert PUT with status=completed returns 422.
        $parent = Task::factory()->create([
            'type' => TaskType::PROJECT->value,
            'project_id' => $this->project->id,
            'department_id' => $this->department->id,
            'status' => TaskStatus::IN_PROGRESS->value,
        ]);

        Task::factory()->create([
            'type' => TaskType::PROJECT->value,
            'project_id' => $this->project->id,
            'department_id' => $this->department->id,
            'parent_id' => $parent->id,
            'status' => TaskStatus::TODO->value,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/unified-tasks/{$parent->id}", [
                'title' => $parent->title,
                'status' => TaskStatus::COMPLETED->value,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن إكمال المهمة وبها مهام فرعية غير مكتملة');

        // Side-effect guard: the parent must remain non-completed.
        $parent->refresh();
        $this->assertNotSame(TaskStatus::COMPLETED->value, $parent->status?->value);
    }
}
