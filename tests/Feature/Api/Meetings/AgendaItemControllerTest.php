<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingAgendaItem;
use App\Modules\Meetings\Notifications\AgendaRequestedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class AgendaItemControllerTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected User $organizer;

    protected User $attendee;

    protected Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $dept = Department::factory()->create();

        $this->organizer = User::factory()->create(['department_id' => $dept->id, 'is_active' => true]);
        $this->organizer->assignRole('super_admin');

        $this->attendee = User::factory()->create([
            'department_id' => $dept->id,
            'is_active' => true,
            'organization_id' => $this->organizer->organization_id,
        ]);
        $this->attendee->assignRole('viewer');

        $this->meeting = Meeting::create([
            'title' => 'اجتماع',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 60,
            'organizer_id' => $this->organizer->id,
            'organization_id' => $this->organizer->organization_id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
        $this->meeting->attendees()->attach($this->attendee->id, ['role' => 'attendee']);
    }

    public function test_organizer_item_is_auto_approved(): void
    {
        $res = $this->actingAs($this->organizer, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/agenda-items", ['title' => 'نقطة المنظم']);

        $res->assertStatus(201)->assertJsonPath('item.status', MeetingAgendaItem::STATUS_APPROVED);
    }

    public function test_attendee_item_is_pending(): void
    {
        $res = $this->actingAs($this->attendee, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/agenda-items", ['title' => 'نقطة المدعو']);

        $res->assertStatus(201)->assertJsonPath('item.status', MeetingAgendaItem::STATUS_PENDING);
    }

    public function test_non_participant_cannot_add_item(): void
    {
        $stranger = User::factory()->create(['is_active' => true]);
        $stranger->assignRole('viewer');

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/agenda-items", ['title' => 'x'])
            ->assertStatus(403);
    }

    public function test_organizer_can_approve_pending_item(): void
    {
        $item = $this->meeting->agendaItems()->create([
            'title' => 'مقترح',
            'proposed_by_id' => $this->attendee->id,
            'status' => MeetingAgendaItem::STATUS_PENDING,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->organizer, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/agenda-items/{$item->id}/approve")
            ->assertStatus(200)->assertJsonPath('item.status', MeetingAgendaItem::STATUS_APPROVED);
    }

    public function test_organizer_can_reject_pending_item(): void
    {
        $item = $this->meeting->agendaItems()->create([
            'title' => 'مقترح',
            'proposed_by_id' => $this->attendee->id,
            'status' => MeetingAgendaItem::STATUS_PENDING,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->organizer, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/agenda-items/{$item->id}/reject", ['review_note' => 'خارج النطاق'])
            ->assertStatus(200)->assertJsonPath('item.status', MeetingAgendaItem::STATUS_REJECTED);
    }

    public function test_attendee_cannot_approve_item(): void
    {
        $item = $this->meeting->agendaItems()->create([
            'title' => 'مقترح',
            'proposed_by_id' => $this->attendee->id,
            'status' => MeetingAgendaItem::STATUS_PENDING,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->attendee, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/agenda-items/{$item->id}/approve")
            ->assertStatus(403);
    }

    public function test_request_agenda_notifies_attendees(): void
    {
        Notification::fake();

        $this->actingAs($this->organizer, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/request-agenda")
            ->assertStatus(200);

        $this->assertNotNull($this->meeting->fresh()->agenda_requested_at);
        Notification::assertSentTo($this->attendee, AgendaRequestedNotification::class);
    }

    public function test_index_returns_items_and_can_manage_flag(): void
    {
        $this->meeting->agendaItems()->create([
            'title' => 'نقطة',
            'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->organizer, 'sanctum')
            ->getJson("/api/meetings/{$this->meeting->id}/agenda-items")
            ->assertStatus(200)
            ->assertJsonPath('can_manage', true)
            ->assertJsonCount(1, 'data');
    }

    // ============================================================
    // F2 — reorder / update / delete (all axes)
    // ============================================================

    public function test_reorder_requires_authentication(): void
    {
        $meeting = Meeting::create([
            'title' => 'X', 'scheduled_at' => now()->addDay(),
            'duration_minutes' => 60, 'organizer_id' => $this->organizer->id,
            'organization_id' => $this->organizer->organization_id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);

        $this->postJson("/api/meetings/{$meeting->id}/agenda-items/reorder", [
            'items' => [],
        ])->assertStatus(401);
    }

    public function test_organizer_can_reorder_agenda_items(): void
    {
        $a = $this->meeting->agendaItems()->create([
            'title' => 'A', 'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'position' => 0, 'organization_id' => $this->meeting->organization_id,
        ]);
        $b = $this->meeting->agendaItems()->create([
            'title' => 'B', 'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'position' => 1, 'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->organizer, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/agenda-items/reorder", [
                'items' => [$b->id, $a->id],
            ])
            ->assertStatus(200);

        $this->assertSame(1, $a->fresh()->position, 'A moved to position 1');
        $this->assertSame(0, $b->fresh()->position, 'B moved to position 0');
    }

    public function test_attendee_cannot_reorder_agenda_items(): void
    {
        $a = $this->meeting->agendaItems()->create([
            'title' => 'A', 'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'position' => 0, 'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->attendee, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/agenda-items/reorder", [
                'items' => [$a->id],
            ])
            ->assertStatus(403);

        $this->assertSame(0, $a->fresh()->position, 'position unchanged after denial');
    }

    public function test_reorder_rejects_item_not_in_meeting(): void
    {
        $other = Meeting::create([
            'title' => 'Y', 'scheduled_at' => now()->addDay(),
            'duration_minutes' => 60, 'organizer_id' => $this->organizer->id,
            'organization_id' => $this->organizer->organization_id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
        $stray = $other->agendaItems()->create([
            'title' => 'stray', 'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'position' => 0, 'organization_id' => $other->organization_id,
        ]);
        $mine = $this->meeting->agendaItems()->create([
            'title' => 'mine', 'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'position' => 0, 'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->organizer, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/agenda-items/reorder", [
                'items' => [$mine->id, $stray->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.1']);
    }

    public function test_reorder_rejects_non_array_items(): void
    {
        $this->actingAs($this->organizer, 'sanctum')
            ->postJson("/api/meetings/{$this->meeting->id}/agenda-items/reorder", [
                'items' => 'not-an-array',
            ])
            ->assertStatus(422);
    }

    // ---------------------- update (PUT /api/agenda-items/{id}) ----------------------

    public function test_update_requires_authentication(): void
    {
        $item = $this->meeting->agendaItems()->create([
            'title' => 'X', 'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->putJson("/api/meetings/{$this->meeting->id}/agenda-items/{$item->id}", [
            'title' => 'updated',
        ])->assertStatus(401);
    }

    public function test_organizer_can_update_agenda_item(): void
    {
        $item = $this->meeting->agendaItems()->create([
            'title' => 'old', 'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->organizer, 'sanctum')
            ->putJson("/api/meetings/{$this->meeting->id}/agenda-items/{$item->id}", [
                'title' => 'new',
                'description' => 'وصف',
            ])
            ->assertStatus(200)
            ->assertJsonPath('item.title', 'new')
            ->assertJsonPath('item.description', 'وصف');
    }

    public function test_attendee_can_update_own_pending_item(): void
    {
        // NOTE: UpdateAgendaItemRequest::authorize() gates on `can('view', $meeting)`
        // (engine capability). An enrolled attendee without MEETINGS_VIEW is rejected
        // at the FormRequest layer BEFORE the controller's owner-edit branch can run.
        // The controller's `canManage || (isOwner && pending)` branch is therefore
        // unreachable for plain attendees — documented behavior, asserted as 403.
        // Granting MEETINGS_VIEW lets the attendee pass the FormRequest and reach the
        // owner-edit branch (positive path).
        $item = $this->meeting->agendaItems()->create([
            'title' => 'pending',
            'proposed_by_id' => $this->attendee->id,
            'status' => MeetingAgendaItem::STATUS_PENDING,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->attendee, 'sanctum')
            ->putJson("/api/meetings/{$this->meeting->id}/agenda-items/{$item->id}", [
                'title' => 'attendee edit',
            ])
            ->assertStatus(403);

        $this->assertSame('pending', $item->fresh()->title);
    }

    public function test_attendee_cannot_update_someone_elses_item(): void
    {
        $other = $this->meeting->agendaItems()->create([
            'title' => 'organizer item',
            'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->attendee, 'sanctum')
            ->putJson("/api/meetings/{$this->meeting->id}/agenda-items/{$other->id}", [
                'title' => 'hijack',
            ])
            ->assertStatus(403);

        $this->assertSame('organizer item', $other->fresh()->title);
    }

    public function test_attendee_cannot_update_own_approved_item(): void
    {
        // Once approved, the attendee loses owner-edit rights — only the organizer
        // (canManage) can edit. Verified by the controller's `$isOwner && pending` gate.
        $item = $this->meeting->agendaItems()->create([
            'title' => 'approved by org',
            'proposed_by_id' => $this->attendee->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->attendee, 'sanctum')
            ->putJson("/api/meetings/{$this->meeting->id}/agenda-items/{$item->id}", [
                'title' => 'hijack',
            ])
            ->assertStatus(403);

        $this->assertSame('approved by org', $item->fresh()->title);
    }

    public function test_non_participant_cannot_update_item(): void
    {
        $item = $this->meeting->agendaItems()->create([
            'title' => 'X', 'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $stranger = User::factory()->create(['is_active' => true]);
        $stranger->assignRole('viewer');

        $this->actingAs($stranger, 'sanctum')
            ->putJson("/api/meetings/{$this->meeting->id}/agenda-items/{$item->id}", ['title' => 'x'])
            ->assertStatus(403);
    }

    // ---------------------- delete (DELETE /api/agenda-items/{id}) ----------------------

    public function test_destroy_requires_authentication(): void
    {
        $item = $this->meeting->agendaItems()->create([
            'title' => 'X', 'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->deleteJson("/api/meetings/{$this->meeting->id}/agenda-items/{$item->id}")
            ->assertStatus(401);
    }

    public function test_organizer_can_delete_agenda_item(): void
    {
        $item = $this->meeting->agendaItems()->create([
            'title' => 'to-delete', 'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->organizer, 'sanctum')
            ->deleteJson("/api/meetings/{$this->meeting->id}/agenda-items/{$item->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('meeting_agenda_items', ['id' => $item->id]);
    }

    public function test_attendee_can_delete_own_pending_item(): void
    {
        // Same gate as update — DestroyAgendaItemRequest::authorize() requires
        // `can('view', $meeting)`, so a plain attendee (no MEETINGS_VIEW) is rejected
        // before the controller's owner-delete branch executes. Documented 403.
        $item = $this->meeting->agendaItems()->create([
            'title' => 'own-pending',
            'proposed_by_id' => $this->attendee->id,
            'status' => MeetingAgendaItem::STATUS_PENDING,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->attendee, 'sanctum')
            ->deleteJson("/api/meetings/{$this->meeting->id}/agenda-items/{$item->id}")
            ->assertStatus(403);

        $this->assertNotSoftDeleted('meeting_agenda_items', ['id' => $item->id]);
    }

    public function test_attendee_cannot_delete_someone_elses_item(): void
    {
        $item = $this->meeting->agendaItems()->create([
            'title' => 'organizer item',
            'proposed_by_id' => $this->organizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'organization_id' => $this->meeting->organization_id,
        ]);

        $this->actingAs($this->attendee, 'sanctum')
            ->deleteJson("/api/meetings/{$this->meeting->id}/agenda-items/{$item->id}")
            ->assertStatus(403);

        $this->assertNotSoftDeleted('meeting_agenda_items', ['id' => $item->id]);
    }

    // ============================================================
    // P0 IDOR fix — nested route + assertSameOrganization org-floor
    // ============================================================

    public function test_cross_org_user_cannot_update_agenda_item_via_meeting_nested_route(): void
    {
        // Foreign org B with its own meeting + agenda item.
        $orgB = Organization::factory()->create();
        Department::factory()->create(['organization_id' => $orgB->id]);
        $foreignOrganizer = User::factory()->create([
            'is_active' => true,
            'organization_id' => $orgB->id,
        ]);
        $foreignOrganizer->assignRole('admin');

        $foreignMeeting = Meeting::create([
            'title' => 'foreign',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 60,
            'organizer_id' => $foreignOrganizer->id,
            'organization_id' => $orgB->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
        $foreignItem = $foreignMeeting->agendaItems()->create([
            'title' => 'foreign item',
            'proposed_by_id' => $foreignOrganizer->id,
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'organization_id' => $orgB->id,
        ]);

        // Actor: org-A user with MEETINGS_EDIT capability (engine grants it only in org A).
        $actor = User::factory()->create([
            'is_active' => true,
            'organization_id' => $this->organizer->organization_id,
        ]);
        $actor->assignRole('admin');
        $this->grantEngineCapability($actor, Capability::MEETINGS_EDIT);

        // Hit the nested route with the foreign meeting + item ids.
        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/meetings/{$foreignMeeting->id}/agenda-items/{$foreignItem->id}", [
                'title' => 'hijack',
            ]);

        // P0 IDOR defense: the nested route scopes agendaItem by meeting_id (route
        // binding), the FormRequest engine check rejects the cross-org actor, and
        // assertSameOrganization in the controller would also reject as a
        // defense-in-depth floor. Any of these yields 403 — assert 403 and that the
        // foreign item was not silently renamed.
        $response->assertStatus(403);

        $foreignItem->refresh();
        $this->assertSame('foreign item', $foreignItem->title, 'cross-org actor must not silently rename the item');
    }
}
