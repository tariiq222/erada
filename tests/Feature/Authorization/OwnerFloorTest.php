<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OwnerFloorTest — Phase 0, Task 1.
 *
 * Verifies the engine owner floor inside AccessDecision::can():
 *  - a user always sees a record they own (created_by / owner_id), with no role;
 *  - ownership never grants delete/manage/assign/close;
 *  - owner edit is lifecycle-gated via the OwnerEditable contract;
 *  - the owner floor never crosses the organization boundary.
 */
class OwnerFloorTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_own_record_without_any_role(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'created_by' => $owner->id,
            'status' => 'planning',
        ]);

        $this->assertTrue(AccessDecision::can($owner, Capability::PROJECTS_VIEW, $project));
        // delete is NOT granted by ownership
        $this->assertFalse(AccessDecision::can($owner, Capability::PROJECTS_DELETE, $project));
        // manage_members / assign_roles / change_status are NOT granted by ownership
        $this->assertFalse(AccessDecision::can($owner, Capability::PROJECTS_MANAGE_MEMBERS, $project));
        $this->assertFalse(AccessDecision::can($owner, Capability::PROJECTS_ASSIGN_ROLES, $project));
        $this->assertFalse(AccessDecision::can($owner, Capability::PROJECTS_CLOSE, $project));
    }

    public function test_owner_edit_is_lifecycle_gated(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create(['organization_id' => $org->id]);

        $editable = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'created_by' => $owner->id,
            'status' => 'planning',
        ]);
        $locked = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'created_by' => $owner->id,
            'status' => 'completed',
        ]);

        $this->assertTrue(AccessDecision::can($owner, Capability::PROJECTS_EDIT, $editable));
        $this->assertFalse(AccessDecision::can($owner, Capability::PROJECTS_EDIT, $locked));
    }

    public function test_owner_floor_never_crosses_org(): void
    {
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $b->id]);
        $ownerA = User::factory()->create(['organization_id' => $a->id]);

        // A project in org B that (pathologically) records ownerA as creator.
        $projectB = Project::factory()->create([
            'organization_id' => $b->id,
            'department_id' => $deptB->id,
            'created_by' => $ownerA->id,
            'status' => 'planning',
        ]);

        $this->assertFalse(AccessDecision::can($ownerA, Capability::PROJECTS_VIEW, $projectB));
        $this->assertFalse(AccessDecision::can($ownerA, Capability::PROJECTS_EDIT, $projectB));
    }

    public function test_task_owner_floor_view_and_lifecycle_edit(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create(['organization_id' => $org->id]);

        $editableProject = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'status' => 'planning',
        ]);

        $editableTask = Task::factory()->create([
            'project_id' => $editableProject->id,
            'owner_id' => $owner->id,
            'status' => 'in_progress',
        ]);
        $completedTask = Task::factory()->create([
            'project_id' => $editableProject->id,
            'owner_id' => $owner->id,
            'status' => 'completed',
        ]);

        // View is unconditional for the owner.
        $this->assertTrue(AccessDecision::can($owner, Capability::TASKS_VIEW, $editableTask));
        $this->assertTrue(AccessDecision::can($owner, Capability::TASKS_VIEW, $completedTask));

        // Edit is lifecycle-gated: blocked once completed.
        $this->assertTrue(AccessDecision::can($owner, Capability::TASKS_EDIT, $editableTask));
        $this->assertFalse(AccessDecision::can($owner, Capability::TASKS_EDIT, $completedTask));

        // Ownership never grants delete.
        $this->assertFalse(AccessDecision::can($owner, Capability::TASKS_DELETE, $editableTask));
    }
}
