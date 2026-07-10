<?php

namespace Tests\Unit\Meetings\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Policies\RecommendationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeRecommendationPolicyViewTest - Phase CFA-06: cluster_tree read
 * widening at the RecommendationPolicy layer.
 *
 * Mirrors ClusterTreeMeetingPolicyViewTest but for the unified recommendations
 * table (Direction B). The critical integrity pin: view() is widened, but
 * approve / reject / defer / accept / complete stay strict same-org and the
 * self-approval block (requested_by != approver) is preserved for rulings.
 *
 * Proves:
 *   1) cluster user with RECOMMENDATIONS_VIEW + CLUSTER_TREE_VIEW ⇒ view() on child-org rec = true.
 *   2) cluster user without CLUSTER_TREE_VIEW ⇒ view() on child-org rec = false.
 *   3) cluster user without RECOMMENDATIONS_VIEW ⇒ view() on child-org rec = false.
 *   4) sibling cluster ⇒ view() = false.
 *   5) child user ⇒ cannot view parent cluster rec via cluster_tree.
 *   6) approve / reject / defer stay org-strict (Direction B integrity).
 *   7) super_admin bypasses everything.
 *   8) null-org user ⇒ view() = false (fail-closed).
 *   9) unrelated org outside the cluster ⇒ no widening.
 *  10) self-approval block preserved on cross-org narrowing (cannot be exploited
 *      through cluster widening — the user would still need RECOMMENDATIONS_VIEW
 *      on the target org, which cluster_tree rescue does not grant for writes).
 */
class ClusterTreeRecommendationPolicyViewTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private RecommendationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new RecommendationPolicy;
    }

    public function test_cluster_user_with_both_grants_can_view_child_org_recommendation(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::RECOMMENDATIONS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childRec = Recommendation::factory()->create([
            'organization_id' => $hospital->id,
            'meeting_id' => $meeting->id,
        ]);

        $this->assertTrue($this->policy->view($user, $childRec));
    }

    public function test_cluster_user_without_cluster_tree_view_cannot_view_child_org_recommendation(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::RECOMMENDATIONS_VIEW);

        $childRec = Recommendation::factory()->create([
            'organization_id' => $hospital->id,
            'meeting_id' => $meeting->id,
        ]);

        $this->assertFalse($this->policy->view($user, $childRec));
    }

    public function test_cluster_user_without_recommendations_view_cannot_view_child_org_recommendation(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $childRec = Recommendation::factory()->create([
            'organization_id' => $hospital->id,
            'meeting_id' => $meeting->id,
        ]);

        // CLUSTER_TREE_VIEW alone is not enough — RECOMMENDATIONS_VIEW is also required.
        $this->assertFalse($this->policy->view($user, $childRec));
    }

    public function test_sibling_cluster_cannot_view_each_others_child_org_recommendations(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $organizerB = User::factory()->create(['organization_id' => $hospitalB->id]);
        $meetingB = Meeting::factory()->create([
            'organization_id' => $hospitalB->id,
            'organizer_id' => $organizerB->id,
        ]);

        $userA = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($userA, [
            Capability::RECOMMENDATIONS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $recInClusterB = Recommendation::factory()->create([
            'organization_id' => $clusterB->id,
            'meeting_id' => $meetingB->id,
        ]);
        $recInHospitalB = Recommendation::factory()->create([
            'organization_id' => $hospitalB->id,
            'meeting_id' => $meetingB->id,
        ]);

        // A cannot see B's subtree even with both capabilities.
        $this->assertFalse($this->policy->view($userA, $recInClusterB));
        $this->assertFalse($this->policy->view($userA, $recInHospitalB));
    }

    public function test_child_user_cannot_view_parent_cluster_recommendation_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $parentOrganizer = User::factory()->create(['organization_id' => $cluster->id]);
        $parentMeeting = Meeting::factory()->create([
            'organization_id' => $cluster->id,
            'organizer_id' => $parentOrganizer->id,
        ]);
        $parentRec = Recommendation::factory()->create([
            'organization_id' => $cluster->id,
            'meeting_id' => $parentMeeting->id,
        ]);

        $childOrganizer = User::factory()->create(['organization_id' => $hospital->id]);
        $ownMeeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $childOrganizer->id,
        ]);
        $ownRec = Recommendation::factory()->create([
            'organization_id' => $hospital->id,
            'meeting_id' => $ownMeeting->id,
        ]);

        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($childUser, [
            Capability::RECOMMENDATIONS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        // The child cannot view the parent (one-directional cluster rescue).
        $this->assertFalse($this->policy->view($childUser, $parentRec));
        // But it does see a recommendation in its own organization (same-org).
        $this->assertTrue($this->policy->view($childUser, $ownRec));
    }

    /**
     * Direction B integrity — approve / reject / defer stay org-strict even
     * when cluster_tree widens view. No write reaches the recommendation
     * lifecycle through cluster rescue.
     */
    public function test_approve_reject_defer_remain_org_strict_with_cluster_grants(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::RECOMMENDATIONS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
            Capability::RECOMMENDATIONS_APPROVE,
            Capability::RECOMMENDATIONS_REJECT,
            Capability::RECOMMENDATIONS_DEFER,
        ]);

        $rulingRec = Recommendation::factory()->ruling()->create([
            'organization_id' => $hospital->id,
            'meeting_id' => $meeting->id,
        ]);

        // view is widened, but Direction B transitions stay org-strict.
        $this->assertTrue($this->policy->view($user, $rulingRec));
        $this->assertFalse($this->policy->approve($user, $rulingRec));
        $this->assertFalse($this->policy->reject($user, $rulingRec));
        $this->assertFalse($this->policy->defer($user, $rulingRec));
        $this->assertFalse($this->policy->update($user, $rulingRec));
    }

    public function test_accept_complete_remain_org_strict_with_cluster_grants(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::RECOMMENDATIONS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
            Capability::RECOMMENDATIONS_ACCEPT,
            Capability::RECOMMENDATIONS_COMPLETE,
        ]);

        $actionItem = Recommendation::factory()->actionItem()->create([
            'organization_id' => $hospital->id,
            'meeting_id' => $meeting->id,
        ]);

        // view is widened, but Direction B action_item transitions stay org-strict.
        $this->assertTrue($this->policy->view($user, $actionItem));
        // approve routes through update() (Direction B action-item accept).
        $this->assertFalse($this->policy->approve($user, $actionItem));
        $this->assertFalse($this->policy->update($user, $actionItem));
    }

    public function test_super_admin_can_view_any_recommendation_via_cluster_tree_path(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        $super = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $super->assignRole('super_admin');

        $childRec = Recommendation::factory()->create([
            'organization_id' => $hospital->id,
            'meeting_id' => $meeting->id,
        ]);

        // super_admin bypasses without going through the cluster_tree rescue.
        $this->assertTrue($this->policy->view($super, $childRec));
    }

    public function test_null_org_user_cannot_view_child_org_recommendation_even_with_grants(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($orphan, [
            Capability::RECOMMENDATIONS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childRec = Recommendation::factory()->create([
            'organization_id' => $hospital->id,
            'meeting_id' => $meeting->id,
        ]);

        // null-org user ⇒ cluster_tree rescue fail-closed.
        $this->assertFalse($this->policy->view($orphan, $childRec));
    }

    public function test_unrelated_org_outside_cluster_cannot_be_seen(): void
    {
        [$cluster, , $other] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $other->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $other->id,
            'organizer_id' => $organizer->id,
        ]);

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::RECOMMENDATIONS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $recInOther = Recommendation::factory()->create([
            'organization_id' => $other->id,
            'meeting_id' => $meeting->id,
        ]);

        $this->assertFalse($this->policy->view($user, $recInOther));
    }

    /**
     * @return array{0: Organization, 1: Organization, 2: Organization}
     */
    private function makeClusterTree(string $clusterName = 'cluster', string $hospitalName = 'hospital'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);
        $sibling = Organization::factory()->create(['name' => 'sibling of '.$hospitalName]);

        return [$cluster, $hospital, $sibling];
    }
}
