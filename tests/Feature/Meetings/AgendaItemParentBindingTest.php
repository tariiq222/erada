<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingAgendaItem;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AgendaItemParentBindingTest
 *
 * P0 IDOR defense — `{agendaItem}` MUST belong to route `{meeting}` before any
 * controller body runs. The four mutation actions (update / destroy / approve
 * / reject) all flow through the nested {meeting}/agenda-items/{agendaItem}
 * family; without scoped implicit binding, a user can pass their own meeting id
 * + someone else's agenda item id and the controller mutates the wrong row
 * (cross-parent IDOR).
 *
 * Expected behavior with the fix in place:
 *   - All four actions return HTTP 404 when the agenda item's meeting_id
 *     does NOT match the route's {meeting} parameter.
 *   - The child row is left exactly as it was: title / status / review_note /
 *     deleted_at are unchanged.
 *   - Same-parent (valid) update still gets through and mutates the row, so
 *     the regression proof also covers the happy path.
 */
class AgendaItemParentBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $organizer;

    private Organization $org;

    private Department $dept;

    private Project $project;

    private Meeting $meetingA;

    private Meeting $meetingB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::factory()->create();
        $this->project = Project::factory()->create(['department_id' => $this->dept->id]);
        $this->org = Organization::find($this->project->organization_id);

        // super_admin so any further capability checks short-circuit; we want
        // to test the route-level cross-parent binding, not authority checks.
        $this->organizer = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $this->organizer->assignRole('super_admin');

        $this->meetingA = Meeting::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'organizer_id' => $this->organizer->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);

        $this->meetingB = Meeting::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'organizer_id' => $this->organizer->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
    }

    public function test_mismatched_parent_update_returns_404_and_unchanged(): void
    {
        $item = MeetingAgendaItem::factory()->create([
            'meeting_id' => $this->meetingB->id,
            'organization_id' => $this->project->organization_id,
            'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'title' => 'نقطة الاجتماع ب',
        ]);

        $response = $this->actingAs($this->organizer, 'sanctum')
            ->putJson("/api/meetings/{$this->meetingA->id}/agenda-items/{$item->id}", [
                'title' => 'محاولة تعديل عبر IDOR',
            ]);

        $response->assertStatus(404);

        $item->refresh();
        $this->assertSame('نقطة الاجتماع ب', $item->title);
        $this->assertSame(MeetingAgendaItem::STATUS_APPROVED, $item->status);
        $this->assertSame($this->meetingB->id, (int) $item->meeting_id);
    }

    public function test_mismatched_parent_destroy_returns_404_and_unchanged(): void
    {
        $item = MeetingAgendaItem::factory()->create([
            'meeting_id' => $this->meetingB->id,
            'organization_id' => $this->project->organization_id,
            'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'title' => 'نقطة محمية من الحذف',
        ]);

        $response = $this->actingAs($this->organizer, 'sanctum')
            ->deleteJson("/api/meetings/{$this->meetingA->id}/agenda-items/{$item->id}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('meeting_agenda_items', ['id' => $item->id]);
        $this->assertNull($item->fresh()->deleted_at);
    }

    public function test_mismatched_parent_approve_returns_404_and_unchanged(): void
    {
        $item = MeetingAgendaItem::factory()->create([
            'meeting_id' => $this->meetingB->id,
            'organization_id' => $this->project->organization_id,
            'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->organizer, 'sanctum')
            ->postJson("/api/meetings/{$this->meetingA->id}/agenda-items/{$item->id}/approve");

        $response->assertStatus(404);

        $item->refresh();
        $this->assertSame(MeetingAgendaItem::STATUS_PENDING, $item->status);
        $this->assertSame($this->meetingB->id, (int) $item->meeting_id);
    }

    public function test_mismatched_parent_reject_returns_404_and_unchanged(): void
    {
        $item = MeetingAgendaItem::factory()->create([
            'meeting_id' => $this->meetingB->id,
            'organization_id' => $this->project->organization_id,
            'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->organizer, 'sanctum')
            ->postJson("/api/meetings/{$this->meetingA->id}/agenda-items/{$item->id}/reject", [
                'review_note' => 'محاولة رفض عبر IDOR',
            ]);

        $response->assertStatus(404);

        $item->refresh();
        $this->assertSame(MeetingAgendaItem::STATUS_PENDING, $item->status);
        $this->assertNull($item->review_note);
    }

    public function test_same_parent_update_still_works(): void
    {
        // Sanity: same-parent (valid) update must still get through — we
        // only want to add a guard, not break the happy path.
        $item = MeetingAgendaItem::factory()->create([
            'meeting_id' => $this->meetingA->id,
            'organization_id' => $this->project->organization_id,
            'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'title' => 'قبل',
        ]);

        $response = $this->actingAs($this->organizer, 'sanctum')
            ->putJson("/api/meetings/{$this->meetingA->id}/agenda-items/{$item->id}", [
                'title' => 'بعد',
            ]);

        $response->assertStatus(200);
        $this->assertSame('بعد', $item->fresh()->title);
    }
}
