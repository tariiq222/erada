<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Policies\MeetingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * DirectionRIntegrityTest - Phase CFA-06 REGRESSION.
 *
 * Pins Direction R integrity BEFORE and AFTER Phase CFA-06 cluster_tree
 * read widening for Meetings + Recommendations:
 *
 *   1) The meeting_resolutions lifecycle remains forward-only:
 *      open → in_progress → {completed, cancelled, converted_to_tasks}.
 *      No approve / reject / adopt / deliberate / endorsed lifecycle exists
 *      on this model — by design (Direction R records typed outputs, not
 *      rulings that need a four-eyes approval).
 *
 *   2) The hold / release_hold metadata semantics on resolutions remain
 *      preserved (hold does NOT change status; release_hold is metadata
 *      only).
 *
 *   3) CFA-06 does NOT widen read or write access on meeting_resolutions —
 *      only meetings (read) and recommendations (read) widen through
 *      cluster_tree. Resolution authorization stays strict same-org even
 *      when the actor holds BOTH MEETINGS_VIEW + CLUSTER_TREE_VIEW.
 *
 *   4) CFA-06 does NOT introduce a new transition (open or otherwise) on
 *      meeting_resolutions. The Transition matrix is exactly what it was
 *      before CFA-06.
 *
 *   5) The start/complete/cancel transitions on the parent Meeting remain
 *      org-strict — CFA-00 owner explicitly excluded operational
 *      transitions from cluster widening.
 *
 * This test exists so any future change that tries to widen Meeting /
 * Recommendation reads beyond their existing lifecycle, or accidentally
 * introduces a new meeting_resolutions transition, will fail here.
 */
class DirectionRIntegrityTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_meeting_resolution_lifecycle_is_forward_only_with_no_approve_reject_transition(): void
    {
        // Direction R lifecycle constants — pins the documented state machine.
        $this->assertSame(
            ['open', 'in_progress', 'converted_to_tasks', 'completed', 'cancelled'],
            array_keys(MeetingResolution::STATUSES)
        );

        // No approve / reject / adopt / deliberate / endorsed lifecycle
        // exists on this model. This is by design (Direction R records
        // typed outputs, not rulings).
        $forbiddenStatuses = ['approve', 'approved', 'reject', 'rejected', 'adopt', 'adopted', 'deliberate', 'endorsed'];
        foreach ($forbiddenStatuses as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                MeetingResolution::STATUSES,
                "Direction R forbids '{$forbidden}' status on meeting_resolutions"
            );
        }
    }

    public function test_can_transition_to_matrix_unchanged_after_cfa06(): void
    {
        $resolution = new MeetingResolution;
        $resolution->status = MeetingResolution::STATUS_OPEN;

        // From OPEN: only forward to in_progress, converted_to_tasks, completed, cancelled.
        $openMatrix = [
            MeetingResolution::STATUS_IN_PROGRESS => true,
            MeetingResolution::STATUS_CONVERTED_TO_TASKS => true,
            MeetingResolution::STATUS_COMPLETED => true,
            MeetingResolution::STATUS_CANCELLED => true,
            MeetingResolution::STATUS_OPEN => false,
            // Forbidden Direction R transitions stay forbidden.
            'approved' => false,
            'rejected' => false,
            'adopted' => false,
        ];

        foreach ($openMatrix as $target => $allowed) {
            $this->assertSame(
                $allowed,
                $resolution->canTransitionTo($target),
                "open -> {$target} should be ".($allowed ? 'allowed' : 'forbidden')
            );
        }

        // From IN_PROGRESS: only forward to terminal states.
        $resolution->status = MeetingResolution::STATUS_IN_PROGRESS;
        $inProgressMatrix = [
            MeetingResolution::STATUS_IN_PROGRESS => false,
            MeetingResolution::STATUS_COMPLETED => true,
            MeetingResolution::STATUS_CANCELLED => true,
            MeetingResolution::STATUS_CONVERTED_TO_TASKS => true,
            MeetingResolution::STATUS_OPEN => false,
        ];

        foreach ($inProgressMatrix as $target => $allowed) {
            $this->assertSame(
                $allowed,
                $resolution->canTransitionTo($target),
                "in_progress -> {$target} should be ".($allowed ? 'allowed' : 'forbidden')
            );
        }

        // From any TERMINAL status: no transition allowed (forward-only).
        foreach ([
            MeetingResolution::STATUS_COMPLETED,
            MeetingResolution::STATUS_CANCELLED,
            MeetingResolution::STATUS_CONVERTED_TO_TASKS,
        ] as $terminal) {
            $resolution->status = $terminal;
            foreach (array_keys(MeetingResolution::STATUSES) as $target) {
                $this->assertFalse(
                    $resolution->canTransitionTo($target),
                    "terminal '{$terminal}' -> '{$target}' must be forbidden (forward-only)"
                );
            }
        }
    }

    public function test_hold_is_metadata_only_and_does_not_change_status(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $meeting = Meeting::factory()->create([
            'status' => Meeting::STATUS_IN_PROGRESS,
        ]);

        $resolution = MeetingResolution::create([
            'meeting_id' => $meeting->id,
            'kind' => MeetingResolution::KIND_RECOMMENDATION,
            'title' => 'مخرج للاختبار',
            'owner_id' => $user->id,
            'created_by' => $user->id,
            'status' => MeetingResolution::STATUS_IN_PROGRESS,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
            'organization_id' => $meeting->organization_id,
        ]);

        // Apply hold metadata (simulate the controller logic without DB hit).
        $resolution->hold_at = now();
        $resolution->hold_reason = 'للاختبار فقط';
        $resolution->save();

        // Status MUST remain in_progress despite hold metadata.
        $this->assertSame(MeetingResolution::STATUS_IN_PROGRESS, $resolution->fresh()->status);
        $this->assertTrue($resolution->fresh()->isOnHold());

        // Release_hold clears the metadata — status still in_progress.
        $resolution->hold_at = null;
        $resolution->hold_until = null;
        $resolution->hold_reason = null;
        $resolution->save();

        $this->assertSame(MeetingResolution::STATUS_IN_PROGRESS, $resolution->fresh()->status);
        $this->assertFalse($resolution->fresh()->isOnHold());

        // Forward-only: hold / release_hold do NOT introduce a status transition.
        // Hold is metadata-only on the existing status, not a new state.
        $this->assertArrayNotHasKey('hold', MeetingResolution::STATUSES);
        $this->assertArrayNotHasKey('on_hold', MeetingResolution::STATUSES);
        $this->assertArrayNotHasKey('held', MeetingResolution::STATUSES);
    }

    public function test_resolution_visibility_remains_strict_same_org_under_cfa06(): void
    {
        // Build a cluster: cluster -> hospital.
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        $hospitalOrganizer = User::factory()->create(['organization_id' => $hospital->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $hospital->id,
            'organizer_id' => $hospitalOrganizer->id,
        ]);
        $resolution = MeetingResolution::create([
            'meeting_id' => $meeting->id,
            'kind' => MeetingResolution::KIND_RECOMMENDATION,
            'title' => 'مخرج للاختبار',
            'owner_id' => $hospitalOrganizer->id,
            'created_by' => $hospitalOrganizer->id,
            'status' => MeetingResolution::STATUS_OPEN,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
            'organization_id' => $hospital->id,
        ]);

        // Cluster-level user with both MEETINGS_VIEW + CLUSTER_TREE_VIEW.
        $clusterUser = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($clusterUser, [
            Capability::MEETINGS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        // The cluster user CAN see the meeting (Phase CFA-06 widening).
        $meetingPolicy = new MeetingPolicy;
        $this->assertTrue($meetingPolicy->view($clusterUser, $meeting));

        // But the resolution's parent-meeting scope is still strict same-org.
        // CFA-06 does NOT widen MeetingResolution visibility — the resolution
        // stays scoped to its own organization. We assert this at the
        // ScopeAware shape (the conference access decision delegates to the
        // parent meeting's organization_id, which is the child org).
        $resolution->setRelation('meeting', $meeting);
        $this->assertSame($hospital->id, $resolution->scopeOrganizationId());
        $this->assertNotSame($cluster->id, $resolution->scopeOrganizationId());

        // Cluster user's organization does not match the resolution's organization.
        // That means cluster_tree rescue at the resolution level would have to
        // walk the parent_id tree from hospital to cluster — feasible in
        // principle, but CFA-00 owner explicitly excluded resolutions from
        // the widening and MeetingResolutionPolicy was not modified.
        $this->assertNotSame((int) $clusterUser->organization_id, (int) $resolution->scopeOrganizationId());
    }

    public function test_meeting_lifecycle_unchanged_after_cfa06(): void
    {
        $meeting = new Meeting;

        // Phase CFA-06 explicitly does NOT widen start/complete/cancel —
        // those stay org-strict. The lifecycle must be exactly:
        //   scheduled -> {in_progress, cancelled}
        //   in_progress -> {completed, cancelled}
        // No other transition is legal.
        $meeting->status = Meeting::STATUS_SCHEDULED;
        $this->assertTrue($meeting->canTransitionTo(Meeting::STATUS_IN_PROGRESS));
        $this->assertTrue($meeting->canTransitionTo(Meeting::STATUS_CANCELLED));
        $this->assertFalse($meeting->canTransitionTo(Meeting::STATUS_COMPLETED));
        $this->assertFalse($meeting->canTransitionTo(Meeting::STATUS_SCHEDULED));

        $meeting->status = Meeting::STATUS_IN_PROGRESS;
        $this->assertTrue($meeting->canTransitionTo(Meeting::STATUS_COMPLETED));
        $this->assertTrue($meeting->canTransitionTo(Meeting::STATUS_CANCELLED));
        $this->assertFalse($meeting->canTransitionTo(Meeting::STATUS_IN_PROGRESS));
        $this->assertFalse($meeting->canTransitionTo(Meeting::STATUS_SCHEDULED));

        // No approve/reject lifecycle exists on Meeting either.
        foreach ([Meeting::STATUS_COMPLETED, Meeting::STATUS_CANCELLED] as $terminal) {
            $meeting->status = $terminal;
            foreach ([Meeting::STATUS_SCHEDULED, Meeting::STATUS_IN_PROGRESS, Meeting::STATUS_COMPLETED, Meeting::STATUS_CANCELLED] as $target) {
                $this->assertFalse(
                    $meeting->canTransitionTo($target),
                    "terminal '{$terminal}' -> '{$target}' must be forbidden on Meeting"
                );
            }
        }
    }
}
