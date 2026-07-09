<?php

namespace Tests\Unit\Core\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeUserPolicyDirectoryTest - Phase CFA-07: cluster_tree read widening
 * at the Policy layer for the limited directory endpoint (HIGH PII).
 *
 * Proves the strict CFA-07 contract:
 *   1) cluster user with USERS_VIEW + CLUSTER_TREE_VIEW => viewDirectory() on
 *      child-org user = true.
 *   2) cluster user without CLUSTER_TREE_VIEW => viewDirectory() = false.
 *   3) cluster user without USERS_VIEW => viewDirectory() = false.
 *   4) cluster user with both grants => sibling org user = false (sibling isolation).
 *   5) child user => cannot viewDirectory parent cluster user via cluster_tree
 *      (one-directional walk).
 *   6) null-org user with both grants => viewDirectory() = false (fail-closed).
 *   7) super_admin: viewDirectory() always true (sanitized via resource, not
 *      bypassed by widening).
 *   8) existing view() behavior is UNCHANGED (still same-org only) - this
 *      proves CFA-07 did NOT silently widen view().
 *
 * Uses GrantsEngineCapability::grantEngineCapability() to grant engine
 * capabilities via ScopedRole on user.organization_id (not Spatie givePermissionTo).
 */
class ClusterTreeUserPolicyDirectoryTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private UserPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new UserPolicy;
    }

    public function test_cluster_user_with_both_grants_can_view_directory_of_child_org_user(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::USERS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);

        $this->assertTrue($this->policy->viewDirectory($user, $childUser));
    }

    public function test_cluster_user_without_cluster_tree_view_cannot_view_directory_of_child_org_user(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::USERS_VIEW);

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->viewDirectory($user, $childUser));
    }

    public function test_cluster_user_without_users_view_cannot_view_directory_of_child_org_user(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->viewDirectory($user, $childUser));
    }

    public function test_sibling_cluster_cannot_view_directory_of_each_others_child_org_users(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $userA = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($userA, [
            Capability::USERS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $userInClusterB = User::factory()->create([
            'organization_id' => $clusterB->id,
            'is_active' => true,
        ]);
        $userInHospitalB = User::factory()->create([
            'organization_id' => $hospitalB->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->viewDirectory($userA, $userInClusterB));
        $this->assertFalse($this->policy->viewDirectory($userA, $userInHospitalB));
    }

    public function test_child_user_cannot_view_directory_of_parent_cluster_user_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($childUser, [
            Capability::USERS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $parentUser = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $ownUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);

        // The child cannot viewDirectory the parent (one-directional walk).
        $this->assertFalse($this->policy->viewDirectory($childUser, $parentUser));
        // Same-org reads are NOT what viewDirectory() opens (handled by view()).
        $this->assertFalse($this->policy->viewDirectory($childUser, $ownUser));
    }

    public function test_null_org_user_with_cluster_grants_cannot_view_directory_fail_closed(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($orphan, [
            Capability::USERS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $target = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);

        // null-org actor => fail-closed even with both capabilities.
        $this->assertFalse($this->policy->viewDirectory($orphan, $target));
    }

    public function test_super_admin_can_view_directory_of_any_user(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $super = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $super->assignRole('super_admin');

        $target = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);

        // super_admin bypass is local to the method for call sites that skip before().
        $this->assertTrue($this->policy->viewDirectory($super, $target));
    }

    public function test_view_directory_still_rejects_same_org_targets(): void
    {
        [$cluster] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::USERS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $sameOrgUser = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);

        // viewDirectory() returns false for same-org - that path goes through view()
        // and UserResource (NOT the directory shape).
        $this->assertFalse($this->policy->viewDirectory($user, $sameOrgUser));
    }

    public function test_existing_view_method_is_unchanged_by_cfa07(): void
    {
        // The critical CFA-07 invariant: the existing `view` keeps its same-org
        // semantics. CFA-07 adds viewDirectory(), it does NOT widen view().
        [$cluster, $hospital] = $this->makeClusterTree();

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::USERS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);

        // viewDirectory() admits the cluster widening
        $this->assertTrue($this->policy->viewDirectory($user, $childUser));

        // view() still rejects (same-org only)
        $this->assertFalse($this->policy->view($user, $childUser));
    }

    /**
     * @return array{0: Organization, 1: Organization}
     */
    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);

        return [$cluster, $hospital];
    }
}
