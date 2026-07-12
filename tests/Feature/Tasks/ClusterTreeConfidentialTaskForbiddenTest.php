<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Policies\TaskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeConfidentialTaskForbiddenTest — Phase CFA-08 (CRITICAL INVARIANT).
 *
 * Pins the source_sensitivity='confidential' floor for Tasks:
 *   - A task stamped with source_sensitivity='confidential' is NEVER
 *     visible to a cluster actor via the cluster rescue branch.
 *   - Even with BOTH TASKS_VIEW + CLUSTER_TREE_VIEW on actor.org.
 *   - This is the per-row stamp independent of the OVR IncidentReport
 *     source — it must hold even if source_id / source_type are null.
 *
 * stop conditions:
 *   - source_sensitivity='confidential' task visible to cluster actor → STOP
 */
class ClusterTreeConfidentialTaskForbiddenTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_task_with_source_sensitivity_confidential_is_hidden_from_cluster_actor(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        $clusterUser = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($clusterUser, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $hospitalDept = Department::factory()->create(['organization_id' => $hospital->id]);
        $hospitalProject = Project::factory()->create([
            'organization_id' => $hospital->id,
            'department_id' => $hospitalDept->id,
        ]);

        // Task with the per-row source_sensitivity stamp — no source row,
        // no IncidentReport. Just the per-row marker.
        $confidentialTask = Task::factory()->create([
            'project_id' => $hospitalProject->id,
            'type' => 'project',
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => 'confidential',
        ]);

        // Control: normal task in the same child org IS cluster-visible.
        $normalTask = Task::factory()->create([
            'project_id' => $hospitalProject->id,
            'type' => 'project',
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => 'normal',
        ]);

        // Per-record view: cluster user CANNOT see confidential task.
        $this->assertFalse(
            (new TaskPolicy)->view($clusterUser, $confidentialTask->fresh()),
            'source_sensitivity=confidential task must NOT be cluster-visible to a cluster actor'
        );

        // Per-record view: cluster user CAN see normal task.
        $this->assertTrue(
            (new TaskPolicy)->view($clusterUser, $normalTask->fresh()),
            'normal task in the same child org must remain cluster-visible'
        );

        // List path: cluster user cannot see confidential task in the query.
        $query = Task::query()->visibleTo($clusterUser);
        $this->assertSame(0, (clone $query)->where('id', $confidentialTask->id)->count(),
            'source_sensitivity=confidential task must be excluded from cluster actor list');
        $this->assertSame(1, (clone $query)->where('id', $normalTask->id)->count());
    }

    public function test_task_with_source_sensitivity_confidential_is_hidden_from_cluster_actor_with_manage_pair(): void
    {
        // The cluster rescue branch on the CHANGE STATUS path is gated by
        // the same SensitivelyScoped + isSensitive=true pre-flight. A
        // confidential task must NOT be status-transition-writable by a
        // cluster actor even with TASKS_EDIT + CLUSTER_TREE_MANAGE.
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        $clusterUser = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($clusterUser, [
            Capability::TASKS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $hospitalDept = Department::factory()->create(['organization_id' => $hospital->id]);
        $hospitalProject = Project::factory()->create([
            'organization_id' => $hospital->id,
            'department_id' => $hospitalDept->id,
        ]);

        $task = Task::factory()->create([
            'project_id' => $hospitalProject->id,
            'type' => 'project',
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => 'confidential',
        ]);

        $this->assertFalse(
            (new TaskPolicy)->changeStatus($clusterUser, $task->fresh()),
            'cluster actor must NOT be able to change status on a confidential task'
        );
    }

    public function test_super_admin_can_view_confidential_task(): void
    {
        // super_admin bypasses the sensitivity gate (the engine before()
        // short-circuit). They MUST see the confidential task through
        // the per-record path. This is intentional — only non-super
        // admins are blocked.
        $org = Organization::factory()->create();
        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $task = Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => 'confidential',
        ]);

        $this->assertTrue(
            (new TaskPolicy)->view($superAdmin, $task->fresh()),
            'super_admin must retain access to confidential tasks'
        );
    }
}
