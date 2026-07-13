<?php

namespace Tests\Unit\Projects\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Policies\ProjectPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeProjectsPolicyTest — Phase CFA-04.
 *
 * Cluster tree widening for the Projects module:
 *   - view() widens via PROJECTS_VIEW + CLUSTER_TREE_VIEW on actor.org
 *   - updateStatus() widens via PROJECTS_EDIT + CLUSTER_TREE_MANAGE on actor.org
 *     (governance writes for status / PDCA transitions ONLY)
 *   - update / delete / create / assignProjectRoles stay strict same-org
 *
 * Sibling test to ClusterTreeStrategyPolicyTest (CFA-03) and
 * ClusterTreeKpiPolicyTest (Phase 9-D-D1a).
 */
class ClusterTreeProjectsPolicyTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);
        $sibling = Organization::factory()->create(['name' => 'sibling of '.$hospitalName]);

        return [$cluster, $hospital, $sibling];
    }

    private function makeProjectInOrg(int $orgId): Project
    {
        return Project::factory()->create([
            'organization_id' => $orgId,
            'department_id' => Department::factory()->create(['organization_id' => $orgId])->id,
        ]);
    }

    // ============================================================
    // view() — cluster widening via CLUSTER_TREE_VIEW
    // ============================================================

    public function test_cluster_user_with_read_pair_can_view_child_org_project(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::PROJECTS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childProject = $this->makeProjectInOrg($hospital->id);

        $this->assertTrue((new ProjectPolicy)->view($user, $childProject));
    }

    public function test_cluster_user_without_cluster_tree_view_cannot_view_child_org_project(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::PROJECTS_VIEW);

        $childProject = $this->makeProjectInOrg($hospital->id);

        $this->assertFalse((new ProjectPolicy)->view($user, $childProject));
    }

    public function test_cluster_user_without_projects_view_cannot_view_child_org_project(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $childProject = $this->makeProjectInOrg($hospital->id);

        $this->assertFalse((new ProjectPolicy)->view($user, $childProject));
    }

    public function test_missing_either_grant_blocks_view(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $childProject = $this->makeProjectInOrg($hospital->id);

        $userNoCluster = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoCluster, Capability::PROJECTS_VIEW);
        $this->assertFalse((new ProjectPolicy)->view($userNoCluster, $childProject));

        $userNoModule = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($userNoModule, Capability::CLUSTER_TREE_VIEW);
        $this->assertFalse((new ProjectPolicy)->view($userNoModule, $childProject));
    }

    public function test_sibling_cluster_isolated_for_view(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterA->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::PROJECTS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childProject = $this->makeProjectInOrg($hospitalA->id);

        // Switch actor to cluster B (sibling, not ancestor of cluster A).
        $user->update(['organization_id' => $clusterB->id]);
        $this->assertFalse((new ProjectPolicy)->view($user, $childProject));
    }

    public function test_child_user_cannot_view_parent_cluster_project_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $this->grantEngineCapability($childUser, [
            Capability::PROJECTS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $parentProject = $this->makeProjectInOrg($cluster->id);

        $this->assertFalse((new ProjectPolicy)->view($childUser, $parentProject));
    }

    public function test_null_org_user_with_grants_cannot_view_child_org_project(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $nullOrgUser = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantEngineCapability($nullOrgUser, [
            Capability::PROJECTS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childProject = $this->makeProjectInOrg($hospital->id);

        $this->assertFalse((new ProjectPolicy)->view($nullOrgUser, $childProject));
    }

    public function test_super_admin_can_view_child_org_project(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $childProject = $this->makeProjectInOrg($hospital->id);

        $this->assertTrue((new ProjectPolicy)->view($superAdmin, $childProject));
    }

    public function test_view_does_not_fire_for_same_org_target(): void
    {
        // For same-org targets, rescue is short-circuited; strict-equality gate wins.
        $org = Organization::factory()->create();
        $project = $this->makeProjectInOrg($org->id);

        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::PROJECTS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        // Same-org path: AccessDecision::can(PROJECTS_VIEW, project) returns true.
        // The two-path helper also accepts this — true on Path 1.
        $this->assertTrue((new ProjectPolicy)->view($user, $project));
    }

    // ============================================================
    // updateStatus() — governance write via CLUSTER_TREE_MANAGE
    // ============================================================

    public function test_cluster_user_with_manage_pair_can_update_child_project_status(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::PROJECTS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childProject = $this->makeProjectInOrg($hospital->id);

        $this->assertTrue((new ProjectPolicy)->updateStatus($user, $childProject));
    }

    public function test_cluster_user_without_cluster_tree_manage_cannot_update_child_project_status(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::PROJECTS_EDIT);

        $childProject = $this->makeProjectInOrg($hospital->id);

        $this->assertFalse((new ProjectPolicy)->updateStatus($user, $childProject));
    }

    public function test_cluster_user_without_projects_edit_cannot_update_child_project_status(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_MANAGE);

        $childProject = $this->makeProjectInOrg($hospital->id);

        $this->assertFalse((new ProjectPolicy)->updateStatus($user, $childProject));
    }

    public function test_sibling_cluster_isolated_for_update_status(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB] = $this->makeClusterTree('cluster B', 'hospital B');

        $user = User::factory()->create(['organization_id' => $clusterA->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::PROJECTS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childProject = $this->makeProjectInOrg($hospitalA->id);

        $user->update(['organization_id' => $clusterB->id]);
        $this->assertFalse((new ProjectPolicy)->updateStatus($user, $childProject));
    }

    public function test_child_user_cannot_update_parent_project_status_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create(['organization_id' => $hospital->id, 'is_active' => true]);
        $this->grantEngineCapability($childUser, [
            Capability::PROJECTS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $parentProject = $this->makeProjectInOrg($cluster->id);

        $this->assertFalse((new ProjectPolicy)->updateStatus($childUser, $parentProject));
    }

    // ============================================================
    // update() / delete() / assignProjectRoles() — strict same-org invariants
    // ============================================================

    public function test_update_stays_strict_same_org_no_widening(): void
    {
        // CRUD stays strict same-org per CFA-00 owner decision. Only updateStatus()
        // (status / PDCA transitions) widens via cluster_tree.manage. update()
        // itself remains strict same-org for arbitrary field updates.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::PROJECTS_EDIT,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childProject = $this->makeProjectInOrg($hospital->id);

        $this->assertFalse((new ProjectPolicy)->update($user, $childProject));
    }

    public function test_delete_stays_strict_same_org_no_widening(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::PROJECTS_DELETE,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childProject = $this->makeProjectInOrg($hospital->id);

        $this->assertFalse((new ProjectPolicy)->delete($user, $childProject));
    }

    public function test_assign_project_roles_stays_strict_same_org_no_widening(): void
    {
        // Per CFA-00 owner decision: NO project role/member assignment widening.
        // Cluster PMOs monitor projects via view + can change status / PDCA,
        // but do NOT assign team members cross-org.
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create(['organization_id' => $cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [
            Capability::PROJECTS_ASSIGN_ROLES,
            Capability::CLUSTER_TREE_MANAGE,
        ]);

        $childProject = $this->makeProjectInOrg($hospital->id);

        $this->assertFalse((new ProjectPolicy)->assignProjectRoles($user, $childProject));
    }
}
