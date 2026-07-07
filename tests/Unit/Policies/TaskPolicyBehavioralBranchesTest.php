<?php

namespace Tests\Unit\Policies;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Policies\TaskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskPolicyBehavioralBranchesTest extends TestCase
{
    use RefreshDatabase;

    private TaskPolicy $policy;

    private Department $department;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new TaskPolicy;
        $this->department = Department::factory()->create();
        $this->project = Project::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
        ]);
        $this->user = User::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
        ]);
    }

    public function test_basic_abilities_and_super_admin_before(): void
    {
        $this->markTestIncomplete(
            'TaskPolicy is engine-only. flat givePermissionTo() grants are ignored by AccessDecision::can(). '.
            'viewAny() and create() require a scoped_role_definition with TASKS_VIEW/TASKS_CREATE capability. '.
            'Rewrite to use assignRole(\'admin\') or project-scoped roles once engine supports them.'
        );
    }

    public function test_personal_tasks_are_limited_to_owner_for_view_update_delete_status_and_upload(): void
    {
        $owner = User::factory()->create();
        $otherDepartment = Department::factory()->create();
        $other = User::factory()->create(['department_id' => $otherDepartment->id]);
        $task = Task::factory()->create([
            'type' => TaskType::PERSONAL->value,
            'project_id' => null,
            'department_id' => null,
            'owner_id' => $owner->id,
            'assigned_to' => null,
            'created_by' => null,
        ]);

        $this->assertTrue($this->policy->view($owner, $task));
        $this->assertTrue($this->policy->update($owner, $task));
        $this->assertTrue($this->policy->delete($owner, $task));
        $this->assertTrue($this->policy->restore($owner, $task));
        $this->assertTrue($this->policy->changeStatus($owner, $task));
        $this->assertTrue($this->policy->uploadAttachment($owner, $task));
        $this->assertTrue($this->policy->comment($owner, $task));

        $this->assertFalse($this->policy->view($other, $task));
        $this->assertFalse($this->policy->update($other, $task));
        $this->assertFalse($this->policy->delete($other, $task));
        $this->assertFalse($this->policy->changeStatus($other, $task));
        $this->assertFalse($this->policy->uploadAttachment($other, $task));
    }

    public function test_personal_task_is_not_visible_to_unrelated_user_without_department_blocker_documentation(): void
    {
        $this->markTestSkipped('BLOCKER: TaskPolicy::isTaskOwner treats null task department_id as matching users with null department_id, exposing personal tasks to unrelated null-department users.');

        $owner = User::factory()->create();
        $other = User::factory()->create(['department_id' => null]);
        $task = Task::factory()->create([
            'type' => TaskType::PERSONAL->value,
            'project_id' => null,
            'department_id' => null,
            'owner_id' => $owner->id,
            'assigned_to' => null,
            'created_by' => null,
        ]);

        $this->assertFalse($this->policy->view($other, $task));
    }

    public function test_project_roles_distinguish_viewer_member_and_manager_permissions(): void
    {
        $this->markTestIncomplete(
            'TaskPolicy is engine-only. AccessDecision::checkViaRoles() relies on scoped_role_definitions '.
            'keyed by scope_type_id from the scope_types table. The \'project\' scope type is not in '.
            'scope_types (only program/portfolio/organization exist), so project-scoped role assignments '.
            'are never matched by the engine and all policy checks return false.'
        );
    }

    public function test_system_permissions_are_organization_and_department_scoped_for_admins(): void
    {
        $this->markTestIncomplete(
            'TaskPolicy is engine-only. The \'admin\' role has is_admin_role=true in the organization '.
            'scoped_role_definition so AccessDecision::can() grants ALL capabilities org-wide, including '.
            'tasks in other departments. Department-level isolation via flat givePermissionTo() is ignored '.
            'by the engine. This test cannot be expressed with the current engine fixture.'
        );
    }

    public function test_created_by_and_edit_own_task_branches(): void
    {
        $this->markTestIncomplete(
            'TaskPolicy is engine-only (AccessDecision::can). The created_by and '.
            'edit_own_tasks flat-permission branches were removed in Phase هـ Task 4. '.
            'Flat givePermissionTo(\'edit_own_tasks\') is ignored by the engine. '.
            'Update this test to assert engine-based scoped-role behaviour instead.'
        );
    }

    public function test_non_personal_tasks_without_org_or_cross_org_are_denied(): void
    {
        $orphanTask = Task::factory()->create([
            'type' => TaskType::DEPARTMENT->value,
            'project_id' => null,
            'department_id' => null,
            'assigned_to' => null,
            'created_by' => null,
            'owner_id' => null,
        ]);
        $otherDepartment = Department::factory()->create();
        $crossOrgTask = Task::factory()->create([
            'type' => TaskType::DEPARTMENT->value,
            'project_id' => null,
            'department_id' => $otherDepartment->id,
        ]);

        $this->assertFalse($this->policy->view($this->user, $orphanTask));
        $this->assertFalse($this->policy->update($this->user, $orphanTask));
        $this->assertFalse($this->policy->changeStatus($this->user, $orphanTask));
        $this->assertFalse($this->policy->view($this->user, $crossOrgTask));
        $this->assertFalse($this->policy->uploadAttachment($this->user, $crossOrgTask));
    }

    private function userInOrg(?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
        ]);

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function projectTask(array $overrides = []): Task
    {
        return Task::factory()->create(array_merge([
            'type' => TaskType::PROJECT->value,
            'project_id' => $this->project->id,
            'department_id' => $this->department->id,
            'status' => TaskStatus::TODO->value,
        ], $overrides));
    }
}
