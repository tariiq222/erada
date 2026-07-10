<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class ClusterTreeTaskStatusHttpTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cluster_manager_can_apply_non_completion_pdca_transition_to_child_task(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $actor = $this->makeActor($cluster, [
            Capability::TASKS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);
        $task = $this->makeTask($hospital);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/status", ['status' => 'in_review'])
            ->assertOk();

        $this->assertSame('in_review', $task->fresh()->status->value);
    }

    public function test_cluster_manager_cannot_complete_child_task_without_same_org_leadership_grant(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $actor = $this->makeActor($cluster, [
            Capability::TASKS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);
        $task = $this->makeTask($hospital);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/status", ['status' => 'completed'])
            ->assertForbidden();

        $this->assertNotSame('completed', $task->fresh()->status->value);
    }

    public function test_cluster_manager_cannot_change_status_on_confidential_child_task(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $actor = $this->makeActor($cluster, [
            Capability::TASKS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);
        $task = $this->makeTask($hospital, ['source_sensitivity' => 'confidential']);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/status", ['status' => 'in_review'])
            ->assertForbidden();

        $this->assertSame('todo', $task->fresh()->status->value);
    }

    /**
     * @return array{0: Organization, 1: Organization}
     */
    private function makeClusterTree(): array
    {
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        return [$cluster, $hospital];
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function makeActor(Organization $organization, array $capabilities): User
    {
        $actor = User::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, $capabilities);

        return $actor;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTask(Organization $organization, array $overrides = []): Task
    {
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
        ]);

        return Task::factory()->create(array_merge([
            'project_id' => $project->id,
            'type' => 'project',
            'status' => 'todo',
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
        ], $overrides));
    }
}
