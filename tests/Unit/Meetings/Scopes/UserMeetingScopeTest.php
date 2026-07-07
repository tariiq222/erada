<?php

namespace Tests\Unit\Meetings\Scopes;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingAgendaItem;
use App\Modules\Meetings\Models\MeetingAttendee;
use App\Modules\Meetings\Models\MeetingCategory;
use App\Modules\Meetings\Models\MeetingSettings;
use App\Modules\Meetings\Scopes\UserMeetingScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UserMeetingScopeTest - Phase 5.A: verify the single source of truth for the
 * Meetings/Recommendations org-isolation floor across all five query variants.
 *
 * Pins the four canonical isolation cases for every method:
 *   - super_admin ⇒ no filter (sees everything).
 *   - normal user ⇒ strict organization_id match on the row or its parent.
 *   - null-org user ⇒ whereRaw('1 = 0') (zero rows).
 *
 * For AgendaItems / Attendees / Categories the assertion looks at the
 * generated SQL fragment so we don't have to depend on chained Eloquent
 * hydration order.
 */
class UserMeetingScopeTest extends TestCase
{
    use RefreshDatabase;

    private UserMeetingScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserMeetingScope;
    }

    private function makeUser(?Organization $org, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'is_active' => true,
        ]);

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    // ========== applyToMeetings ==========

    public function test_super_admin_sees_all_meetings(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $organizerA = $this->makeUser($orgA);
        $organizerB = $this->makeUser($orgB);
        Meeting::factory()->count(2)->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);
        Meeting::factory()->count(3)->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);

        $super = $this->makeUser(null, 'super_admin');

        $query = Meeting::query();
        $this->scope->applyToMeetings($query, $super);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_org_a_user_sees_only_org_a_meetings(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $organizerA = $this->makeUser($orgA);
        $organizerB = $this->makeUser($orgB);
        Meeting::factory()->count(2)->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);
        Meeting::factory()->count(3)->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);

        $user = $this->makeUser($orgA);

        $query = Meeting::query();
        $this->scope->applyToMeetings($query, $user);

        $this->assertSame(2, (clone $query)->count());
    }

    public function test_null_org_user_sees_no_meetings(): void
    {
        $orgA = Organization::factory()->create();
        $organizerA = $this->makeUser($orgA);
        Meeting::factory()->count(2)->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);

        $orphan = $this->makeUser(null);

        $query = Meeting::query();
        $this->scope->applyToMeetings($query, $orphan);

        $this->assertSame(0, (clone $query)->count());
    }

    // ========== applyToAgendaItems — walks meeting parent ==========

    public function test_super_admin_sees_all_agenda_items(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $organizerA = $this->makeUser($orgA);
        $organizerB = $this->makeUser($orgB);
        $meetingA = Meeting::factory()->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);
        $meetingB = Meeting::factory()->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);
        MeetingAgendaItem::factory()->count(2)->create(['meeting_id' => $meetingA->id]);
        MeetingAgendaItem::factory()->count(3)->create(['meeting_id' => $meetingB->id]);

        $super = $this->makeUser(null, 'super_admin');

        $query = MeetingAgendaItem::query();
        $this->scope->applyToAgendaItems($query, $super);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_org_a_user_sees_only_org_a_agenda_items(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $organizerA = $this->makeUser($orgA);
        $organizerB = $this->makeUser($orgB);
        $meetingA = Meeting::factory()->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);
        $meetingB = Meeting::factory()->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);
        MeetingAgendaItem::factory()->count(2)->create(['meeting_id' => $meetingA->id]);
        MeetingAgendaItem::factory()->count(3)->create(['meeting_id' => $meetingB->id]);

        $user = $this->makeUser($orgA);

        $query = MeetingAgendaItem::query();
        $this->scope->applyToAgendaItems($query, $user);

        $this->assertSame(2, (clone $query)->count());
    }

    public function test_null_org_user_sees_no_agenda_items(): void
    {
        $orgA = Organization::factory()->create();
        $organizerA = $this->makeUser($orgA);
        $meetingA = Meeting::factory()->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);
        MeetingAgendaItem::factory()->count(2)->create(['meeting_id' => $meetingA->id]);

        $orphan = $this->makeUser(null);

        $query = MeetingAgendaItem::query();
        $this->scope->applyToAgendaItems($query, $orphan);

        $this->assertSame(0, (clone $query)->count());
    }

    // ========== applyToAttendees — walks meeting parent ==========

    public function test_super_admin_sees_all_attendees(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $organizerA = $this->makeUser($orgA);
        $organizerB = $this->makeUser($orgB);
        $meetingA = Meeting::factory()->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);
        $meetingB = Meeting::factory()->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);
        // 2 attendees on the org A meeting, 3 on the org B meeting.
        $orgAUsers = User::factory()->count(2)->create(['organization_id' => $orgA->id]);
        $orgBUsers = User::factory()->count(3)->create(['organization_id' => $orgB->id]);
        $meetingA->attendees()->attach($orgAUsers->pluck('id')->all());
        $meetingB->attendees()->attach($orgBUsers->pluck('id')->all());

        $super = $this->makeUser(null, 'super_admin');

        $query = MeetingAttendee::query();
        $this->scope->applyToAttendees($query, $super);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_org_a_user_sees_only_org_a_attendees(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $organizerA = $this->makeUser($orgA);
        $organizerB = $this->makeUser($orgB);
        $meetingA = Meeting::factory()->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);
        $meetingB = Meeting::factory()->create([
            'organization_id' => $orgB->id,
            'organizer_id' => $organizerB->id,
        ]);
        $orgAUsers = User::factory()->count(2)->create(['organization_id' => $orgA->id]);
        $orgBUsers = User::factory()->count(3)->create(['organization_id' => $orgB->id]);
        $meetingA->attendees()->attach($orgAUsers->pluck('id')->all());
        $meetingB->attendees()->attach($orgBUsers->pluck('id')->all());

        $user = $this->makeUser($orgA);

        $query = MeetingAttendee::query();
        $this->scope->applyToAttendees($query, $user);

        $this->assertSame(2, (clone $query)->count());
    }

    public function test_null_org_user_sees_no_attendees(): void
    {
        $orgA = Organization::factory()->create();
        $organizerA = $this->makeUser($orgA);
        $meetingA = Meeting::factory()->create([
            'organization_id' => $orgA->id,
            'organizer_id' => $organizerA->id,
        ]);
        $orgAUsers = User::factory()->count(2)->create(['organization_id' => $orgA->id]);
        $meetingA->attendees()->attach($orgAUsers->pluck('id')->all());

        $orphan = $this->makeUser(null);

        $query = MeetingAttendee::query();
        $this->scope->applyToAttendees($query, $orphan);

        $this->assertSame(0, (clone $query)->count());
    }

    // ========== applyToCategories ==========

    public function test_super_admin_sees_all_categories(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        MeetingCategory::factory()->count(2)->create(['organization_id' => $orgA->id]);
        MeetingCategory::factory()->count(3)->create(['organization_id' => $orgB->id]);

        $super = $this->makeUser(null, 'super_admin');

        $query = MeetingCategory::query();
        $this->scope->applyToCategories($query, $super);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_org_a_user_sees_only_org_a_categories(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        MeetingCategory::factory()->count(2)->create(['organization_id' => $orgA->id]);
        MeetingCategory::factory()->count(3)->create(['organization_id' => $orgB->id]);

        $user = $this->makeUser($orgA);

        $query = MeetingCategory::query();
        $this->scope->applyToCategories($query, $user);

        $this->assertSame(2, (clone $query)->count());
    }

    public function test_null_org_user_sees_no_categories(): void
    {
        $orgA = Organization::factory()->create();
        MeetingCategory::factory()->count(2)->create(['organization_id' => $orgA->id]);

        $orphan = $this->makeUser(null);

        $query = MeetingCategory::query();
        $this->scope->applyToCategories($query, $orphan);

        $this->assertSame(0, (clone $query)->count());
    }

    // ========== applyToSettings ==========

    public function test_super_admin_sees_all_settings(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $this->makeSettings($orgA);
        $this->makeSettings($orgB);

        $super = $this->makeUser(null, 'super_admin');

        $query = MeetingSettings::query();
        $this->scope->applyToSettings($query, $super);

        $this->assertSame(2, (clone $query)->count());
    }

    public function test_org_a_user_sees_only_org_a_settings(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $this->makeSettings($orgA);
        $this->makeSettings($orgB);

        $user = $this->makeUser($orgA);

        $query = MeetingSettings::query();
        $this->scope->applyToSettings($query, $user);

        $this->assertSame(1, (clone $query)->count());
    }

    public function test_null_org_user_sees_no_settings(): void
    {
        $orgA = Organization::factory()->create();
        $this->makeSettings($orgA);

        $orphan = $this->makeUser(null);

        $query = MeetingSettings::query();
        $this->scope->applyToSettings($query, $orphan);

        $this->assertSame(0, (clone $query)->count());
    }

    private function makeSettings(Organization $org): MeetingSettings
    {
        $settings = new MeetingSettings;
        $settings->forceFill([
            'organization_id' => $org->id,
            'default_duration_minutes' => 60,
            'reminder_window_hours' => 24,
            'attendee_roles' => MeetingSettings::DEFAULT_ATTENDEE_ROLES,
            'agenda_request_enabled' => true,
            'agenda_request_lead_hours' => 48,
            'decision_pending_expiry_days' => 30,
            'recommendation_overdue_grace_days' => 0,
        ])->save();

        return $settings;
    }
}
