<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Notifications\RecommendationAssignedNotification;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * RecommendationControllerTest
 *
 * Direction B (Phase R2): the unified `recommendations` table absorbs the
 * `decisions` table. Tests that previously constructed a Decision parent
 * now construct a Recommendation row with `kind=ruling` (and, where the
 * test exercises an action_item lifecycle, `kind=action_item`).
 *
 * The legacy `decision_id` FK on recommendations was dropped by migration
 * `2026_07_06_300001_drop_decision_id_from_recommendations.php`; tests no
 * longer pass `decision_id`.
 */
class RecommendationControllerTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected User $user;

    protected Department $dept;

    protected Project $project;

    protected Meeting $meeting;

    protected Recommendation $ruling;

    protected Recommendation $actionItem;

    protected Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->dept = Department::factory()->create();
        $this->user = User::factory()->create(['department_id' => $this->dept->id, 'is_active' => true]);
        $this->user->assignRole('super_admin');
        $this->project = Project::factory()->create(['department_id' => $this->dept->id]);

        // Direction B: a Meeting hosts recommendations directly (no separate
        // Decision parent). The first recommendation on the meeting is a
        // ruling — kept around because earlier test cases used it as the
        // shape of "the recommendation already exists for the meeting".
        $this->meeting = Meeting::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'organizer_id' => $this->user->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);

        $this->ruling = Recommendation::create([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $this->meeting->id,
            'decidable_type' => Project::class,
            'decidable_id' => $this->project->id,
            'title' => 'قرار',
            'type' => 'approval',
            'requested_by' => $this->user->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);

        // R4 expansion: an action_item companion + a task sourced from it.
        // Existing tests do not touch these (their assertions key off
        // $this->ruling / their own factory-create()'d rows), so adding
        // them here is additive only.
        $this->actionItem = Recommendation::create([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'meeting_id' => $this->meeting->id,
            'title' => 'إجراء متابعة',
            'assignee_id' => $this->user->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => Recommendation::STATUS_ACCEPTED,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);

        $this->task = Task::factory()->create([
            'source_type' => Recommendation::class,
            'source_id' => $this->actionItem->id,
            'organization_id' => $this->project->organization_id,
            'department_id' => $this->dept->id,
            'project_id' => $this->project->id,
            'status' => TaskStatus::IN_PROGRESS->value,
        ]);
    }

    public function test_can_list_recommendations(): void
    {
        Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
        ]);
        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/recommendations');
        $response->assertStatus(200)->assertJsonStructure([
            'data' => [[
                'allowed_actions' => ['update', 'delete'],
            ]],
        ]);
    }

    public function test_show_returns_record_aware_allowed_actions(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/recommendations/{$this->ruling->id}");

        $response->assertOk()
            ->assertJsonPath('allowed_actions.update', true)
            ->assertJsonPath('allowed_actions.delete', true)
            ->assertJsonPath('allowed_actions.approve', false)
            ->assertJsonPath('allowed_actions.accept', false);
    }

    public function test_can_create_a_recommendation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/recommendations', [
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $this->meeting->id,
            'title' => 'توصية اختبار',
            'type' => 'approval',
            'priority' => 'high',
        ]);
        $response->assertStatus(201)->assertJsonStructure(['message', 'recommendation' => ['id', 'reference_number', 'status']]);
        $this->assertDatabaseHas('recommendations', ['title' => 'توصية اختبار', 'priority' => 'high']);
    }

    public function test_can_accept_a_proposed_recommendation(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_PROPOSED,
        ]);
        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/recommendations/{$r->id}/accept");
        $response->assertStatus(200)->assertJsonPath('recommendation.status', Recommendation::STATUS_ACCEPTED);
    }

    public function test_can_complete_an_accepted_recommendation(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_ACCEPTED,
        ]);
        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/recommendations/{$r->id}/complete");
        $response->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_COMPLETED);
        $this->assertNotNull($response->json('recommendation.completed_at'));
    }

    public function test_cannot_complete_a_proposed_recommendation(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_PROPOSED,
        ]);
        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/recommendations/{$r->id}/complete");
        $response->assertStatus(409);
    }

    public function test_validation_rejects_invalid_priority(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/recommendations', [
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $this->meeting->id,
            'title' => 'x',
            'type' => 'approval',
            'priority' => 'urgent',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors(['priority']);
    }

    // ============================================================
    // A12 — update / destroy + RecommendationAssignedNotification T-E
    // ============================================================

    public function test_update_requires_authentication(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
        ]);

        $this->putJson("/api/recommendations/{$r->id}", [
            'title' => 'updated',
            'priority' => 'high',
        ])->assertStatus(401);
    }

    public function test_update_modifies_recommendation(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'title' => 'old', 'priority' => Recommendation::PRIORITY_LOW,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/recommendations/{$r->id}", [
                'title' => 'new',
                'priority' => Recommendation::PRIORITY_HIGH,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('recommendation.title', 'new')
            ->assertJsonPath('recommendation.priority', Recommendation::PRIORITY_HIGH);

        $r->refresh();
        $this->assertSame('new', $r->title);
        $this->assertSame(Recommendation::PRIORITY_HIGH, $r->priority);
    }

    public function test_update_with_new_assignee_dispatches_recommendation_assigned_notification(): void
    {
        Notification::fake();

        $newAssignee = User::factory()->create([
            'organization_id' => $this->user->organization_id,
            'is_active' => true,
        ]);

        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'assignee_id' => null,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/recommendations/{$r->id}", [
                'title' => $r->title,
                'priority' => $r->priority,
                'assignee_id' => $newAssignee->id,
            ])
            ->assertStatus(200);

        Notification::assertSentTo($newAssignee, RecommendationAssignedNotification::class);
    }

    public function test_update_with_unchanged_assignee_does_not_dispatch_notification(): void
    {
        Notification::fake();

        $assignee = User::factory()->create([
            'organization_id' => $this->user->organization_id,
            'is_active' => true,
        ]);

        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'assignee_id' => $assignee->id,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/recommendations/{$r->id}", [
                'title' => 'still mine',
                'priority' => $r->priority,
            ])
            ->assertStatus(200);

        Notification::assertNotSentTo($assignee, RecommendationAssignedNotification::class);
    }

    public function test_destroy_requires_authentication(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
        ]);

        $this->deleteJson("/api/recommendations/{$r->id}")
            ->assertStatus(401);
    }

    public function test_destroy_soft_deletes_recommendation(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/recommendations/{$r->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('recommendations', ['id' => $r->id]);
    }

    // ============================================================
    // F3 — reject / defer state machine + role 403
    // ============================================================
    // State transitions enforced by Recommendation::canTransitionTo().

    public function test_can_reject_a_proposed_recommendation(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_PROPOSED,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$r->id}/reject")
            ->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_REJECTED);
    }

    public function test_cannot_reject_an_accepted_recommendation(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_ACCEPTED,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$r->id}/reject")
            ->assertStatus(409);

        $this->assertSame(Recommendation::STATUS_ACCEPTED, $r->fresh()->status);
    }

    public function test_cannot_reject_a_completed_recommendation(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$r->id}/reject")
            ->assertStatus(409);
    }

    public function test_can_defer_a_proposed_recommendation(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_PROPOSED,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$r->id}/defer", [
                'defer_reason' => 'بانتظار توفر المعلومات',
                'deferred_until' => now()->addDays(7)->toDateString(),
            ])
            ->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_DEFERRED);
    }

    public function test_can_defer_an_accepted_recommendation(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_ACCEPTED,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$r->id}/defer", [
                'defer_reason' => 'بانتظار توفر المعلومات',
            ])
            ->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_DEFERRED);
    }

    public function test_cannot_defer_a_completed_recommendation(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$r->id}/defer", [
                'defer_reason' => 'محاولة تأجيل منجزة',
            ])
            ->assertStatus(409);
    }

    public function test_reject_requires_authentication(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_PROPOSED,
        ]);

        $this->postJson("/api/recommendations/{$r->id}/reject")
            ->assertStatus(401);
    }

    public function test_defer_requires_authentication(): void
    {
        $r = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'status' => Recommendation::STATUS_PROPOSED,
        ]);

        $this->postJson("/api/recommendations/{$r->id}/defer")
            ->assertStatus(401);
    }

    // ============================================================
    // Task 3.4 — GET /api/recommendations/list (happy + cross-org)
    // ============================================================

    public function test_list_endpoint_returns_recommendation_dropdown_data(): void
    {
        Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'title' => 'Recommendation Alpha',
            'priority' => Recommendation::PRIORITY_HIGH,
        ]);
        Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->user->organization_id,
            'title' => 'Recommendation Beta',
            'priority' => Recommendation::PRIORITY_LOW,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/recommendations/list');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['*' => ['id', 'title', 'reference_number', 'status', 'priority']]]);

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Recommendation Alpha', $titles);
        $this->assertContains('Recommendation Beta', $titles);
    }

    public function test_list_endpoint_excludes_other_organization_recommendations(): void
    {
        // The default $this->user is super_admin and bypasses org-scope. Use an
        // org-A actor that does NOT have cross-org visibility for the
        // cross-org leakage assertion.
        $orgA = $this->meeting->organization_id;

        $orgADept = Department::factory()->create(['organization_id' => $orgA]);
        $orgAActor = User::factory()->create([
            'organization_id' => $orgA,
            'department_id' => $orgADept->id,
            'is_active' => true,
        ]);
        $orgAActor->assignRole('admin');
        $this->grantEngineCapability($orgAActor, Capability::RECOMMENDATIONS_VIEW, 'organization', $orgA);

        // Same-org recommendation: must appear.
        $ownRec = Recommendation::factory()->create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $orgA,
            'title' => 'OwnOrgRec',
        ]);

        // Foreign org B with its own meeting.
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $projectB = Project::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
        ]);
        $foreignMeeting = Meeting::factory()->create([
            'department_id' => $deptB->id,
            'organization_id' => $orgB->id,
            'organizer_id' => $orgAActor->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
        $foreignRec = Recommendation::factory()->create([
            'meeting_id' => $foreignMeeting->id,
            'organization_id' => $orgB->id,
            'title' => 'ForeignOrgRec',
        ]);

        $response = $this->actingAs($orgAActor, 'sanctum')
            ->getJson('/api/recommendations/list');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($ownRec->id, $ids, 'org-A recommendation must appear in own-actor list');
        $this->assertNotContains($foreignRec->id, $ids, 'org-B recommendation must be scoped out');
    }

    // ============================================================
    // R4 — Direction B unified behavior coverage
    //   - ruling approve/reject/defer happy paths
    //   - self-approval block on ruling (action_item does not block)
    //   - action_item completion gate when linked task is incomplete
    //   - meeting-status gate (cancelled meeting -> 422 on store)
    //   - optimistic guard (concurrent approve against stale state)
    // ============================================================

    public function test_can_approve_a_pending_ruling_recommendation(): void
    {
        // $this->ruling is created in setUp() with kind=ruling, status=pending,
        // and requested_by=$this->user->id. The approve request must be made
        // by a DIFFERENT user (the requester cannot self-approve), so create
        // another super_admin in the same org.
        $otherUser = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $otherUser->assignRole('super_admin');

        $response = $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/recommendations/{$this->ruling->id}/approve", [
                'rationale' => 'موافقة نهائية',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_APPROVED);

        $this->assertSame(Recommendation::STATUS_APPROVED, $this->ruling->fresh()->status);
        $this->assertSame($otherUser->id, $this->ruling->fresh()->made_by);
        $this->assertNotNull($this->ruling->fresh()->decision_date);
    }

    public function test_can_reject_a_pending_ruling_recommendation(): void
    {
        $otherUser = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $otherUser->assignRole('super_admin');

        $response = $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/recommendations/{$this->ruling->id}/reject", [
                'rationale' => 'تحتاج مراجعة إضافية',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_REJECTED);

        $this->assertSame(Recommendation::STATUS_REJECTED, $this->ruling->fresh()->status);
        $this->assertSame('تحتاج مراجعة إضافية', $this->ruling->fresh()->rationale);
    }

    public function test_can_defer_a_pending_ruling_recommendation(): void
    {
        $otherUser = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $otherUser->assignRole('super_admin');

        $until = now()->addDays(14)->toDateString();
        $response = $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/recommendations/{$this->ruling->id}/defer", [
                'defer_reason' => 'بانتظار تقرير الدراسة',
                'deferred_until' => $until,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_DEFERRED);

        $fresh = $this->ruling->fresh();
        $this->assertSame(Recommendation::STATUS_DEFERRED, $fresh->status);
        $this->assertSame('بانتظار تقرير الدراسة', $fresh->defer_reason);
    }

    public function test_cannot_self_approve_a_ruling_recommendation(): void
    {
        // The ruling was created with requested_by=$this->user->id (the
        // super_admin actor). For a true self-approval assertion we need a
        // NON-super-admin actor that holds the ruling-side engine
        // capability — Gate::before() in AppServiceProvider would let
        // super_admin past every policy, masking the self-approval block.
        // Use a sibling user with exactly the same engine capability.
        $requester = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability(
            $requester,
            Capability::RECOMMENDATIONS_APPROVE,
            'organization',
            $this->project->organization_id
        );

        $ruling = Recommendation::create([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $this->meeting->id,
            'title' => 'قرار للاختبار الذاتي',
            'type' => 'approval',
            'requested_by' => $requester->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);

        $response = $this->actingAs($requester, 'sanctum')
            ->postJson("/api/recommendations/{$ruling->id}/approve");

        $response->assertStatus(403);
        // Status must remain pending — the blocked approve did not move state.
        $this->assertSame(Recommendation::STATUS_PENDING, $ruling->fresh()->status);
    }

    public function test_self_approval_does_not_apply_to_action_item_kind(): void
    {
        // Action items are assigned to the requester directly — there is no
        // "approver" role on action_item kind, so self-approval must not
        // apply. The RecommendationPolicy::approve() falls back to the
        // shared `update` capability for action_item, and accept() does not
        // apply a self-block. Here we verify the model state allows the
        // normal accept path even when requested_by == assignee.
        $rec = Recommendation::create([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'meeting_id' => $this->meeting->id,
            'title' => 'إجراء ذاتي',
            'requested_by' => $this->user->id,
            'assignee_id' => $this->user->id,
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => Recommendation::STATUS_PROPOSED,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/accept");

        // Action_item accept() does not apply the self-approval block, so
        // the actor with the `update` capability on the recommendation
        // succeeds. The endpoint transitions proposed -> accepted.
        $response->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_ACCEPTED);
    }

    public function test_cannot_complete_an_action_item_with_pending_task_attached(): void
    {
        // $this->task (created in setUp) is sourced from $this->actionItem
        // with status=in_progress — a non-terminal status. complete() must
        // gate on has_pending_tasks and return 422 with pending_task_ids.
        $this->assertSame(
            TaskStatus::IN_PROGRESS,
            $this->task->fresh()->status
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$this->actionItem->id}/complete");

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'pending_task_ids']);

        $this->assertSame(Recommendation::STATUS_ACCEPTED, $this->actionItem->fresh()->status);
    }

    public function test_cannot_store_recommendation_on_a_cancelled_meeting(): void
    {
        // Move the meeting to STATUS_CANCELLED — store() must refuse any new
        // recommendation on it with HTTP 422.
        $this->meeting->update(['status' => Meeting::STATUS_CANCELLED]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/recommendations', [
                'kind' => Recommendation::KIND_RULING,
                'meeting_id' => $this->meeting->id,
                'title' => 'قرار بعد الإلغاء',
                'type' => 'approval',
                'priority' => Recommendation::PRIORITY_MEDIUM,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن إضافة توصيات لاجتماع ملغى');

        $this->assertDatabaseMissing('recommendations', [
            'title' => 'قرار بعد الإلغاء',
        ]);
    }

    public function test_optimistic_guard_blocks_concurrent_approve_against_stale_state(): void
    {
        // Simulate a concurrent approve that already moved the recommendation
        // out of {pending, deferred}. The HTTP approve call must observe 0
        // rows updated by the optimistic-guard UPDATE and respond with 409,
        // matching the spec (`لا يمكن اعتماد التوصية في الحالة الحالية`).
        $this->ruling->update([
            'status' => Recommendation::STATUS_APPROVED,
            'made_by' => $this->user->id,
            'decision_date' => now(),
        ]);

        $otherUser = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $otherUser->assignRole('super_admin');

        $response = $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/recommendations/{$this->ruling->id}/approve");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن اعتماد التوصية في الحالة الحالية');

        // State was not changed by the blocked approve (the original made_by
        // value is still $this->user->id, not $otherUser->id).
        $fresh = $this->ruling->fresh();
        $this->assertSame(Recommendation::STATUS_APPROVED, $fresh->status);
        $this->assertSame($this->user->id, $fresh->made_by);
    }

    // ============================================================
    // R4 — action_item happy paths that were not previously pinned
    // ============================================================

    public function test_can_defer_an_accepted_action_item(): void
    {
        $rec = Recommendation::create([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'meeting_id' => $this->meeting->id,
            'title' => 'إجراء مقبول سيتم تأجيله',
            'assignee_id' => $this->user->id,
            'due_date' => now()->addDays(3)->toDateString(),
            'status' => Recommendation::STATUS_ACCEPTED,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/defer", [
                'defer_reason' => 'تأجيل قصير لتوفير الموارد',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_DEFERRED);

        $fresh = $rec->fresh();
        $this->assertSame('تأجيل قصير لتوفير الموارد', $fresh->defer_reason);
        $this->assertNotNull($fresh->deferred_at);
        $this->assertSame($this->user->id, $fresh->deferred_by);
    }

    public function test_can_complete_an_action_item_with_no_pending_tasks(): void
    {
        // Build a recommendation whose only task is already terminal; the
        // completion gate must allow the transition.
        $rec = Recommendation::create([
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'meeting_id' => $this->meeting->id,
            'title' => 'إجراء جاهز للإنجاز',
            'assignee_id' => $this->user->id,
            'due_date' => now()->addDays(2)->toDateString(),
            'status' => Recommendation::STATUS_ACCEPTED,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->project->organization_id,
        ]);

        Task::factory()->create([
            'source_type' => Recommendation::class,
            'source_id' => $rec->id,
            'organization_id' => $this->project->organization_id,
            'department_id' => $this->dept->id,
            'status' => TaskStatus::COMPLETED->value,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/recommendations/{$rec->id}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('recommendation.status', Recommendation::STATUS_COMPLETED);
        $this->assertNotNull($rec->fresh()->completed_at);
    }
}
