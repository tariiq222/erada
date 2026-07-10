<?php

namespace Tests\Unit\Meetings\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Policies\MeetingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeMeetingPolicyViewTest - Phase CFA-06: cluster_tree read widening
 * at the MeetingPolicy layer.
 *
 * Mirrors ClusterTreeKpiPolicyTest (Phase 9-D-D1a) — every test pins a
 * specific capability combination and verifies view() returns the expected
 * boolean. Direction R integrity is preserved across all assertions (the
 * meeting lifecycle is never widened by CFA-06).
 *
 * Proves:
 *   1) cluster user with MEETINGS_VIEW + CLUSTER_TREE_VIEW ⇒ view() on child-org meeting = true.
 *   2) cluster user without CLUSTER_TREE_VIEW ⇒ view() on child-org meeting = false.
 *   3) cluster user without MEETINGS_VIEW ⇒ view() on child-org meeting = false.
 *   4) sibling cluster ⇒ view() = false.
 *   5) child user ⇒ cannot view parent cluster meeting via cluster_tree.
 *   6) update / delete / start / complete / cancel stay org-strict (no widening).
 *   7) super_admin bypasses everything.
 *   8) null-org user ⇒ view() = false (fail-closed).
 *   9) unrelated org outside the cluster ⇒ no widening.
 */
class ClusterTreeMeetingPolicyViewTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private MeetingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new MeetingPolicy;
    }

    public function test_cluster_user_with_both_grants_can_view_child_org_meeting(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childMeeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        $this->assertTrue($this->policy->view($user, $childMeeting));
    }

    public function test_cluster_user_without_cluster_tree_view_cannot_view_child_org_meeting(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::MEETINGS_VIEW);

        $childMeeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        $this->assertFalse($this->policy->view($user, $childMeeting));
    }

    public function test_cluster_user_without_meetings_view_cannot_view_child_org_meeting(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $childMeeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        // CLUSTER_TREE_VIEW alone is not enough — MEETINGS_VIEW is also required.
        $this->assertFalse($this->policy->view($user, $childMeeting));
    }

    public function test_sibling_cluster_cannot_view_each_others_child_org_meetings(): void
    {
        [$clusterA, $hospitalA] = $this->makeClusterTree('cluster A', 'hospital A');
        [$clusterB, $hospitalB] = $this->makeClusterTree('cluster B', 'hospital B');

        $userA = User::factory()->create([
            'organization_id' => $clusterA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($userA, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $meetingInClusterB = Meeting::factory()->create(['organization_id' => $clusterB->id]);
        $meetingInHospitalB = Meeting::factory()->create(['organization_id' => $hospitalB->id]);

        // A cannot see B's subtree even with both capabilities.
        $this->assertFalse($this->policy->view($userA, $meetingInClusterB));
        $this->assertFalse($this->policy->view($userA, $meetingInHospitalB));
    }

    public function test_child_user_cannot_view_parent_cluster_meeting_via_cluster_tree(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $childOrganizer = User::factory()->create(['organization_id' => $cluster->id]);
        $childUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($childUser, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $parentMeeting = Meeting::factory()->create([
            'organization_id' => $cluster->id,
            'organizer_id' => $childOrganizer->id,
        ]);
        $ownMeeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $childUser->id,
        ]);

        // The child cannot view the parent (one-directional cluster rescue).
        $this->assertFalse($this->policy->view($childUser, $parentMeeting));
        // But it does see a meeting in its own organization (same-org).
        $this->assertTrue($this->policy->view($childUser, $ownMeeting));
    }

    public function test_update_remains_org_strict_with_cluster_grants(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
            Capability::MEETINGS_EDIT,
        ]);

        $childMeeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        // view is widened, but update stays org-strict (Direction R integrity).
        $this->assertTrue($this->policy->view($user, $childMeeting));
        $this->assertFalse($this->policy->update($user, $childMeeting));
    }

    public function test_delete_remains_org_strict_with_cluster_grants(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
            Capability::MEETINGS_DELETE,
        ]);

        $childMeeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        // view is widened, but delete stays org-strict (Direction R integrity).
        $this->assertTrue($this->policy->view($user, $childMeeting));
        $this->assertFalse($this->policy->delete($user, $childMeeting));
    }

    public function test_super_admin_can_view_any_meeting_via_cluster_tree_path(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $super = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $super->assignRole('super_admin');

        $childMeeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        // super_admin bypasses without going through the cluster_tree rescue.
        $this->assertTrue($this->policy->view($super, $childMeeting));
    }

    public function test_null_org_user_cannot_view_child_org_meeting_even_with_grants(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($orphan, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $childMeeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        // null-org user ⇒ cluster_tree rescue fail-closed.
        $this->assertFalse($this->policy->view($orphan, $childMeeting));
    }

    public function test_unrelated_org_outside_cluster_cannot_be_seen(): void
    {
        [$cluster, , $other] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $other->id]);
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $meetingInOther = Meeting::factory()->create([
            'organization_id' => $other->id,
            'organizer_id' => $organizer->id,
        ]);

        $this->assertFalse($this->policy->view($user, $meetingInOther));
    }

    /**
     * Direction R integrity — the meeting lifecycle includes
     * start/complete/cancel transitions handled via `update` in
     * MeetingController (and MeetingPolicy itself via before()).
     * CFA-06 must NOT widen these. This test pins start/complete/cancel
     * (model-only surface; the controller authorization flows through
     * update()) as still org-strict on a child-org meeting.
     */
    public function test_meeting_start_complete_cancel_remain_org_strict(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
            Capability::MEETINGS_EDIT,
        ]);

        $childMeeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);

        // Model-level: cross-org user cannot drive the lifecycle.
        $this->assertFalse($this->policy->update($user, $childMeeting));
        // view is widened.
        $this->assertTrue($this->policy->view($user, $childMeeting));
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
