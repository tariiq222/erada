<?php

namespace Tests\Unit\Meetings\Scopes;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Scopes\UserMeetingScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeUserMeetingScopeTest - Phase CFA-06.
 *
 * Pins the four canonical behaviors of the cluster_tree widening added to
 * UserMeetingScope:
 *   1) cluster user (MEETINGS_VIEW + CLUSTER_TREE_VIEW) sees own + child org
 *      meetings via applyToMeetings().
 *   2) same-org user without CLUSTER_TREE_VIEW ⇒ strict same-org only.
 *   3) child user cannot widen upward through the cluster scope.
 *   4) null-org user ⇒ zero rows (fail-closed).
 *   5) super_admin bypasses (sees everything).
 *   6) without MEETINGS_VIEW ⇒ no widening even with CLUSTER_TREE_VIEW.
 */
class ClusterTreeUserMeetingScopeTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private UserMeetingScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserMeetingScope;
    }

    public function test_cluster_user_sees_own_and_child_org_meetings(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $clusterOrganizer = User::factory()->create(['organization_id' => $cluster->id]);
        $hospitalOrganizer = User::factory()->create(['organization_id' => $hospital->id]);

        // 2 meetings on cluster, 3 on child hospital.
        Meeting::factory()->count(2)->create([
            'organization_id' => $cluster->id,
            'organizer_id' => $clusterOrganizer->id,
        ]);
        Meeting::factory()->count(3)->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $hospitalOrganizer->id,
        ]);

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $query = Meeting::query();
        $this->scope->applyToMeetings($query, $user);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_same_org_user_without_cluster_tree_view_sees_only_same_org_meetings(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $clusterOrganizer = User::factory()->create(['organization_id' => $cluster->id]);
        $hospitalOrganizer = User::factory()->create(['organization_id' => $hospital->id]);

        Meeting::factory()->count(2)->create([
            'organization_id' => $cluster->id,
            'organizer_id' => $clusterOrganizer->id,
        ]);
        Meeting::factory()->count(3)->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $hospitalOrganizer->id,
        ]);

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        // Only MEETINGS_VIEW — no CLUSTER_TREE_VIEW.
        $this->grantEngineCapability($user, Capability::MEETINGS_VIEW);

        $query = Meeting::query();
        $this->scope->applyToMeetings($query, $user);

        // Pre-CFA-06 same-org behavior is preserved exactly.
        $this->assertSame(2, (clone $query)->count());
    }

    public function test_child_user_with_cluster_grants_does_not_see_parent_org_meetings(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $clusterOrganizer = User::factory()->create(['organization_id' => $cluster->id]);
        $hospitalOrganizer = User::factory()->create(['organization_id' => $hospital->id]);

        Meeting::factory()->count(2)->create([
            'organization_id' => $cluster->id,
            'organizer_id' => $clusterOrganizer->id,
        ]);
        Meeting::factory()->count(3)->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $hospitalOrganizer->id,
        ]);

        $user = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $query = Meeting::query();
        $this->scope->applyToMeetings($query, $user);

        // The child sees the 3 hospital meetings, NOT the 2 cluster meetings.
        // The cluster_tree rescue is one-directional (ancestor → descendants),
        // so a child-user cannot widen upward.
        $this->assertSame(3, (clone $query)->count());
    }

    public function test_null_org_user_sees_no_meetings_even_with_cluster_grants(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        Meeting::factory()->count(3)->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($orphan, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $query = Meeting::query();
        $this->scope->applyToMeetings($query, $orphan);

        $this->assertSame(0, (clone $query)->count());
    }

    public function test_super_admin_bypasses_cluster_or_strict_filters(): void
    {
        [, $hospital] = $this->makeClusterTree();

        $organizer = User::factory()->create(['organization_id' => $hospital->id]);
        Meeting::factory()->count(3)->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $organizer->id,
        ]);

        $super = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $super->assignRole('super_admin');

        $query = Meeting::query();
        $this->scope->applyToMeetings($query, $super);

        $this->assertSame(3, (clone $query)->count());
    }

    public function test_user_without_meetings_view_does_not_widen_even_with_cluster_grants(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();

        $clusterOrganizer = User::factory()->create(['organization_id' => $cluster->id]);
        $hospitalOrganizer = User::factory()->create(['organization_id' => $hospital->id]);

        Meeting::factory()->count(2)->create([
            'organization_id' => $cluster->id,
            'organizer_id' => $clusterOrganizer->id,
        ]);
        Meeting::factory()->count(3)->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $hospitalOrganizer->id,
        ]);

        $user = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        // Only CLUSTER_TREE_VIEW — no MEETINGS_VIEW.
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $query = Meeting::query();
        $this->scope->applyToMeetings($query, $user);

        // Without MEETINGS_VIEW the cluster_tree widening cannot grant more
        // than the strict same-org floor (preserves pre-CFA-06 behavior).
        $this->assertSame(2, (clone $query)->count());
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
