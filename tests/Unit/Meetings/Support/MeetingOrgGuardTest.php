<?php

namespace Tests\Unit\Meetings\Support;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingAgendaItem;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Support\MeetingOrgGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * MeetingOrgGuardTest - Phase 5.A: استخراج organization_id من كل كيان Meetings
 * وفحص Same-Organization بنفس القواعد الموحّدة.
 *
 * يطابق بنية EmployeeOrgGuardTest:
 *   - meetingOrgId / recommendationOrgId / agendaItemOrgId / attendeeUserOrgId.
 *   - sameOrganization: super_admin allowed، null-org denied، cross-org denied.
 *   - abortUnlessSameOrganization: throws AccessDeniedHttpException عند الرفض.
 */
class MeetingOrgGuardTest extends TestCase
{
    use RefreshDatabase;

    private MeetingOrgGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new MeetingOrgGuard;
    }

    // ===== Org id extraction =====

    public function test_meeting_org_id_returns_meeting_org(): void
    {
        $org = Organization::factory()->create();
        $organizer = User::factory()->create(['organization_id' => $org->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $organizer->id,
        ]);

        $this->assertSame($org->id, $this->guard->meetingOrgId($meeting));
    }

    public function test_meeting_org_id_null_when_meeting_null(): void
    {
        $this->assertNull($this->guard->meetingOrgId(null));
    }

    public function test_meeting_org_id_null_when_meeting_org_null(): void
    {
        $organizer = User::factory()->create();
        $meeting = Meeting::factory()->create([
            'organization_id' => null,
            'organizer_id' => $organizer->id,
        ]);

        $this->assertNull($this->guard->meetingOrgId($meeting));
    }

    public function test_recommendation_org_id_returns_direct_column(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);
        $meetingA = Meeting::factory()->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);
        $rec = Recommendation::factory()->create([
            'organization_id' => $orgB->id, // direct column takes priority
            'meeting_id' => $meetingA->id,
        ]);

        $this->assertSame($orgB->id, $this->guard->recommendationOrgId($rec));
    }

    public function test_recommendation_org_id_falls_back_to_meeting_org(): void
    {
        $orgA = Organization::factory()->create();
        $organizerA = User::factory()->create(['organization_id' => $orgA->id]);
        $meetingA = Meeting::factory()->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);
        $rec = Recommendation::factory()->create([
            'organization_id' => null,
            'meeting_id' => $meetingA->id,
        ]);

        $this->assertSame($orgA->id, $this->guard->recommendationOrgId($rec));
    }

    public function test_recommendation_org_id_null_when_both_null(): void
    {
        $organizer = User::factory()->create();
        $meeting = Meeting::factory()->create([
            'organization_id' => null,
            'organizer_id' => $organizer->id,
        ]);
        $rec = Recommendation::factory()->create([
            'organization_id' => null,
            'meeting_id' => $meeting->id,
        ]);

        $this->assertNull($this->guard->recommendationOrgId($rec));
    }

    public function test_recommendation_org_id_null_when_rec_null(): void
    {
        $this->assertNull($this->guard->recommendationOrgId(null));
    }

    public function test_agenda_item_org_id_walks_meeting_parent(): void
    {
        $org = Organization::factory()->create();
        $organizer = User::factory()->create(['organization_id' => $org->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $organizer->id,
        ]);
        $item = MeetingAgendaItem::factory()->create([
            'meeting_id' => $meeting->id,
            'organization_id' => null, // intentionally null — must walk the parent
        ]);

        $this->assertSame($org->id, $this->guard->agendaItemOrgId($item));
    }

    public function test_agenda_item_org_id_null_when_meeting_missing(): void
    {
        $item = new MeetingAgendaItem;

        $this->assertNull($this->guard->agendaItemOrgId($item));
    }

    public function test_attendee_user_org_id_returns_user_org(): void
    {
        $org = Organization::factory()->create();
        $organizer = User::factory()->create(['organization_id' => $org->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $organizer->id,
        ]);
        $attendee = User::factory()->create(['organization_id' => $org->id]);

        $this->assertSame($org->id, $this->guard->attendeeUserOrgId($meeting, $attendee->id));
    }

    public function test_attendee_user_org_id_null_when_user_missing(): void
    {
        $org = Organization::factory()->create();
        $organizer = User::factory()->create(['organization_id' => $org->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $organizer->id,
        ]);

        $this->assertNull($this->guard->attendeeUserOrgId($meeting, 99999));
    }

    public function test_attendee_user_org_id_null_when_user_org_null(): void
    {
        $org = Organization::factory()->create();
        $organizer = User::factory()->create(['organization_id' => $org->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'organizer_id' => $organizer->id,
        ]);
        $attendee = User::factory()->create(['organization_id' => null]);

        $this->assertNull($this->guard->attendeeUserOrgId($meeting, $attendee->id));
    }

    public function test_attendee_user_org_id_null_when_meeting_null(): void
    {
        $this->assertNull($this->guard->attendeeUserOrgId(null, 1));
    }

    // ===== sameOrganization =====

    public function test_same_organization_super_admin_always_allowed(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => null]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $org = Organization::factory()->create();
        $this->assertTrue($this->guard->sameOrganization($superAdmin, $org->id));
        $this->assertTrue($this->guard->sameOrganization($superAdmin, null));
        $this->assertTrue($this->guard->sameOrganization($superAdmin, 99999));
    }

    public function test_same_organization_same_org_allowed(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $this->assertTrue($this->guard->sameOrganization($actor, $org->id));
    }

    public function test_same_organization_cross_org_denied(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        $this->assertFalse($this->guard->sameOrganization($actor, $orgB->id));
    }

    public function test_same_organization_null_actor_org_denied(): void
    {
        $dept = Department::factory()->create();
        $actor = User::factory()->create([
            'organization_id' => null,
            'department_id' => $dept->id,
        ]);
        $org = Organization::factory()->create();

        $this->assertFalse($this->guard->sameOrganization($actor, $org->id));
    }

    public function test_same_organization_null_target_org_denied(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $this->assertFalse($this->guard->sameOrganization($actor, null));
    }

    // ===== sameOrganizationForMeeting / Recommendation / AgendaItem =====

    public function test_same_organization_for_meeting_walks_target(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerB = User::factory()->create(['organization_id' => $orgB->id]);
        $meetingB = Meeting::factory()->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);
        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        $this->assertFalse($this->guard->sameOrganizationForMeeting($actor, $meetingB));
        $this->assertFalse($this->guard->sameOrganizationForMeeting($actor, null));
    }

    public function test_same_organization_for_recommendation_walks_target(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerB = User::factory()->create(['organization_id' => $orgB->id]);
        $meetingB = Meeting::factory()->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);
        $recB = Recommendation::factory()->create([
            'organization_id' => $orgB->id,
            'meeting_id' => $meetingB->id,
        ]);
        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        $this->assertFalse($this->guard->sameOrganizationForRecommendation($actor, $recB));
        $this->assertFalse($this->guard->sameOrganizationForRecommendation($actor, null));
    }

    public function test_same_organization_for_agenda_item_walks_target(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $organizerB = User::factory()->create(['organization_id' => $orgB->id]);
        $meetingB = Meeting::factory()->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);
        $itemB = MeetingAgendaItem::factory()->create([
            'meeting_id' => $meetingB->id,
            'organization_id' => $orgB->id,
        ]);
        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        $this->assertFalse($this->guard->sameOrganizationForAgendaItem($actor, $itemB));
        $this->assertFalse($this->guard->sameOrganizationForAgendaItem($actor, null));
    }

    // ===== abortUnlessSameOrganization =====

    public function test_abort_unless_same_organization_throws_on_cross_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->guard->abortUnlessSameOrganization($actor, $orgB->id);
    }

    public function test_abort_unless_same_organization_passes_on_match(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $this->guard->abortUnlessSameOrganization($actor, $org->id);
        $this->assertTrue(true);
    }

    public function test_abort_unless_same_organization_passes_for_super_admin(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => null]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $this->guard->abortUnlessSameOrganization($superAdmin, 99999);
        $this->assertTrue(true);
    }

    public function test_abort_unless_same_organization_throws_for_null_actor_org(): void
    {
        $dept = Department::factory()->create();
        $actor = User::factory()->create([
            'organization_id' => null,
            'department_id' => $dept->id,
        ]);
        $org = Organization::factory()->create();

        $this->expectException(AccessDeniedHttpException::class);
        $this->guard->abortUnlessSameOrganization($actor, $org->id);
    }
}
