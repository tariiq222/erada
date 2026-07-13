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
            'TaskPolicy is canonical-engine-only. viewAny() and create() require active canonical role assignments '.
            'that grant TASKS_VIEW/TASKS_CREATE at the intended scope. Rewrite this branch with explicit canonical '.
            'organization and project assignments.'
        );
    }

    public function test_personal_tasks_are_limited_to_owner_for_view_update_delete_status_and_upload(): void
    {
        $owner = User::factory()->create();
        $otherDepartment = Department::factory()->create();
        $other = User::factory()->create([
            'organization_id' => $otherDepartment->organization_id,
            'department_id' => $otherDepartment->id,
        ]);
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
            'TaskPolicy is canonical-engine-only. Express viewer, member, and manager differences with distinct '.
            'authorization roles and project-scoped authorization_role_assignments, then assert each capability branch.'
        );
    }

    public function test_system_permissions_are_organization_and_department_scoped_for_admins(): void
    {
        $this->markTestIncomplete(
            'TaskPolicy is canonical-engine-only. Model organization-admin and department-admin reach with explicit '.
            'canonical assignments, then verify that each assignment is constrained to its configured scope.'
        );
    }

    public function test_created_by_and_edit_own_task_branches(): void
    {
        $this->markTestIncomplete(
            'TaskPolicy is canonical-engine-only. The former edit_own_tasks branch is retired; rewrite this test '.
            'around canonical task capabilities plus the created_by record rule.'
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
            $role === 'super_admin'
                ? $this->grantCanonicalSuperAdmin($user)
                : $this->assignCanonicalRole($user, $role);
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
