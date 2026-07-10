<?php

namespace Tests\Unit\Tasks\Policies;

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
 * ClusterTreeTaskPolicyCrudStrictTest — Phase CFA-08.
 *
 * CFA-00 owner decision (2026-07-09): Tasks CRUD (create / update / delete)
 * stays strict same-org. NO cluster widening for arbitrary CRUD — only the
 * read surface (view) and the PDCA status transition (changeStatus) widen.
 *
 * This file pins that contract so a future widening attempt cannot silently
 * bypass the strict same-org gate.
 */
class ClusterTreeTaskPolicyCrudStrictTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);

        return [$cluster, $hospital];
    }

    private function makeTaskInOrg(int $orgId): Task
    {
        $project = Project::factory()->create([
            'organization_id' => $orgId,
            'department_id' => Department::factory()->create(['organization_id' => $orgId])->id,
        ]);

        return Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
        ]);
    }

    // ============================================================
    // update() — stays strict same-org (NO cluster widening)
    // ============================================================

    public function test_update_stays_strict_same_org_no_widening_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childTask = $this->makeTaskInOrg($hospital->id);

        // update() must NOT widen — only changeStatus (PDCA governance
        // write) widens via cluster rescue. A field update on a child-org
        // task by a cluster actor is the silent-bypass case.
        $this->assertFalse((new TaskPolicy)->update($user, $childTask));
    }

    // ============================================================
    // delete() — stays strict same-org (NO cluster widening)
    // ============================================================

    public function test_delete_stays_strict_same_org_no_widening_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_DELETE,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childTask = $this->makeTaskInOrg($hospital->id);

        $this->assertFalse((new TaskPolicy)->delete($user, $childTask));
    }

    // ============================================================
    // create() — public / target-free gate, no cluster tightening here
    // ============================================================

    public function test_create_uses_engine_grant_not_cluster_widening(): void
    {
        // create() is a target-free gate; the cluster widening applies only
        // to target-bound capabilities. A user with no TASKS_CREATE cannot
        // create regardless of cluster grants.
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::CLUSTER_TREE_VIEW,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $this->assertFalse((new TaskPolicy)->create($user));
    }

    // ============================================================
    // completeTask() — leadership-only capability, stays strict same-org
    // ============================================================

    public function test_complete_task_stays_strict_same_org_no_widening_via_cluster_tree(): void
    {
        // completeTask requires TASKS_COMPLETE (leadership gate) and stays
        // strict same-org — no cluster rescue. A cluster PMO can change
        // status via changeStatus() but cannot force complete on a child-org
        // task.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_COMPLETE,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childTask = $this->makeTaskInOrg($hospital->id);

        $this->assertFalse((new TaskPolicy)->completeTask($user, $childTask));
    }

    // ============================================================
    // assign() — stays strict same-org (NO cluster widening)
    // ============================================================

    public function test_assign_stays_strict_same_org_no_widening_via_cluster_tree(): void
    {
        // assign() is a separate TASKS_ASSIGN capability and stays strict
        // same-org. CFA-00 owner decision: cluster PMOs monitor tasks via
        // view + status transitions; they do not re-assign work cross-org.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_ASSIGN,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childTask = $this->makeTaskInOrg($hospital->id);

        $this->assertFalse((new TaskPolicy)->assign($user, $childTask));
    }

    // ============================================================
    // uploadAttachment() — stays strict same-org (NO cluster widening)
    // ============================================================

    public function test_upload_attachment_stays_strict_same_org_no_widening_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::TASKS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childTask = $this->makeTaskInOrg($hospital->id);

        $this->assertFalse((new TaskPolicy)->uploadAttachment($user, $childTask));
    }
}
