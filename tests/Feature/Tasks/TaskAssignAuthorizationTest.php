<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * TaskAssignAuthorizationTest — Phase 2 of the master AuthZ unification plan.
 *
 * Pins the corrected authorization contract for
 * PATCH /api/unified-tasks/{task}/assign:
 *
 *   - actor with Capability::TASKS_ASSIGN (and no TASKS_EDIT) → 200
 *   - actor with Capability::TASKS_EDIT  (and no TASKS_ASSIGN) → 403
 *
 * Before Phase 2 the controller gated assignment through Capability::TASKS_EDIT
 * (AssignTaskRequest::authorize() → TaskPolicy::update), so a user carrying
 * only `tasks.assign` failed the call even though the capability was exported
 * in TaskResource.abilities. This test pins the corrected contract: the
 * authorize path uses Capability::TASKS_ASSIGN end-to-end.
 */
class TaskAssignAuthorizationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $orgA;

    private Department $deptA;

    private Project $projectA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->projectA = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
    }

    private function makeProjectTask(): Task
    {
        return Task::factory()->create([
            'type' => TaskType::PROJECT->value,
            'project_id' => $this->projectA->id,
            'department_id' => $this->deptA->id,
            'assigned_to' => null,
            'created_by' => null,
            'status' => TaskStatus::TODO->value,
        ]);
    }

    public function test_actor_with_only_tasks_assign_capability_succeeds(): void
    {
        $task = $this->makeProjectTask();
        $actor = $this->makeUser();
        $this->grantEngineCapability($actor, Capability::TASKS_ASSIGN);
        $assignee = $this->makeUser();

        $response = $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/assign", [
                'assigned_to' => $assignee->id,
            ])
            ->assertOk();

        $response->assertJsonPath('task.assignee.id', $assignee->id);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'assigned_to' => $assignee->id,
        ]);
    }

    public function test_actor_with_only_tasks_edit_capability_is_forbidden(): void
    {
        $task = $this->makeProjectTask();
        $actor = $this->makeUser();
        $this->grantEngineCapability($actor, Capability::TASKS_EDIT);
        $assignee = $this->makeUser();

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/assign", [
                'assigned_to' => $assignee->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('tasks', [
            'id' => $task->id,
            'assigned_to' => $assignee->id,
        ]);
    }
}
