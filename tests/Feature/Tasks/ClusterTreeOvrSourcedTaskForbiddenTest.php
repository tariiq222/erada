<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Policies\TaskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeOvrSourcedTaskForbiddenTest — Phase CFA-08 (CRITICAL INVARIANT).
 *
 * Pins the OVR confidential floor for Tasks:
 *   - A task stamped as sourced from a confidential OVR IncidentReport
 *     (source_type='IncidentReport' AND source_sensitivity='confidential')
 *     is NEVER visible to a cluster actor via the cluster rescue branch.
 *   - Even with BOTH TASKS_VIEW + CLUSTER_TREE_VIEW on actor.org.
 *   - The engine's clusterTreeRescueApplies short-circuits on the
 *     SensitivelyScoped + isSensitive()=true gate. The copied task stamp is
 *     authoritative because tasks.source_id cannot store OVR UUID IDs.
 *   - The Task::scopeVisibleTo + the cluster floor NEVER include these
 *     tasks in the query result set.
 *
 * stop conditions:
 *   - OVR-sourced confidential task visible to cluster actor → STOP
 *   - cluster widening bypasses OVR confidential floor → STOP
 */
class ClusterTreeOvrSourcedTaskForbiddenTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_ovr_sourced_confidential_task_is_hidden_from_cluster_actor(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        // Cluster user with BOTH grants — the cluster rescue branch.
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

        // Schema workaround: IncidentReport.id is UUID but tasks.source_id
        // is bigint — direct FK is not expressible. The synthetic source_id
        // stands in for the (UUID, does not exist in tasks.source_id) pair;
        // the per-row source_sensitivity='confidential' stamp is the
        // authoritative signal here. The SQL filter and the per-row stamp are
        // the gates that block the cluster actor on list and show paths.
        $ovrConfidentialTask = Task::factory()->create([
            'project_id' => $hospitalProject->id,
            'type' => 'project',
            'source_type' => 'IncidentReport',
            'source_id' => 0,
            'source_sensitivity' => 'confidential',
        ]);

        // Control: a normal task in the child org IS cluster-visible.
        $normalTask = Task::factory()->create([
            'project_id' => $hospitalProject->id,
            'type' => 'project',
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
        ]);

        // The cluster user MUST see the normal task.
        $this->assertTrue((new TaskPolicy)->view($clusterUser, $normalTask->fresh()));

        // The cluster user MUST NOT see the OVR-confidential task — even
        // with both cluster grants. The top-level source_sensitivity filter
        // in Task::scopeVisibleTo + the per-record isSensitive contract
        // force the policy engine deny.
        $this->assertFalse((new TaskPolicy)->view($clusterUser, $ovrConfidentialTask->fresh()));

        // Per-record path blocked. Now check the LIST path.
        $query = Task::query()->visibleTo($clusterUser);

        $this->assertSame(0, (clone $query)->where('id', $ovrConfidentialTask->id)->count(),
            'OVR-sourced confidential task must be excluded from cluster actor list');
        $this->assertSame(1, (clone $query)->where('id', $normalTask->id)->count(),
            'Normal task in the same child org must remain cluster-visible');
    }

    public function test_ovr_sourced_task_with_confidential_stamp_only_is_hidden_from_cluster_actor(): void
    {
        // Belt-and-braces: a task that has the OVR-sourced source_type +
        // explicit source_sensitivity='confidential' stamp must be
        // cluster-excluded even if the source row's is_confidential flag
        // would be different (or the source row is unresolvable).
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

        $task = Task::factory()->create([
            'project_id' => $hospitalProject->id,
            'type' => 'project',
            'source_type' => 'IncidentReport',
            'source_id' => 0,
            'source_sensitivity' => 'confidential',
        ]);

        // Per-record view blocked.
        $this->assertFalse((new TaskPolicy)->view($clusterUser, $task->fresh()));

        // List path blocked.
        $query = Task::query()->visibleTo($clusterUser);
        $this->assertSame(0, (clone $query)->where('id', $task->id)->count(),
            'OVR-sourced task with source_sensitivity=confidential must be cluster-excluded');
    }

    public function test_ovr_sourced_confidential_task_is_hidden_from_same_org_user_without_ovr_confidential(): void
    {
        // Even WITHOUT the cluster grants (same-org floor) the OVR
        // confidential floor holds when the user happens to be in the
        // same org without OVR_CONFIDENTIAL. Path 1 same-org engine
        // check runs the existing source-sensitivity gate.
        $org = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::TASKS_VIEW);

        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $task = Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'source_type' => 'IncidentReport',
            'source_id' => 0,
            'source_sensitivity' => 'confidential',
        ]);

        // Same-org user without OVR_CONFIDENTIAL cannot view via the
        // existing source-sensitivity gate (top-level confidential filter).
        $this->assertFalse((new TaskPolicy)->view($user, $task->fresh()));
    }
}
