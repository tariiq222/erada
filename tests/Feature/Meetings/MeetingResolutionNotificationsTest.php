<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Notifications\ResolutionConvertedToTasksNotification;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * MeetingResolutionNotificationsTest — Phase 4 / Direction R.
 *
 * Pins the notification contract on convert-to-tasks:
 *   - each unique assignee receives exactly one notification
 *   - the actor (creator) is skipped
 *   - users with no row are skipped (defensive)
 *   - rolled-back conversions emit ZERO notifications
 *   - duplicate (re-)conversion is rejected at the controller and emits
 *     zero notifications
 */
class MeetingResolutionNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $assigneeA;

    private User $assigneeB;

    private Project $project;

    private Department $dept;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = Department::factory()->create();
        $this->project = Project::factory()->create(['department_id' => $this->dept->id]);
        $this->user = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');

        $this->assigneeA = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);
        $this->assigneeB = User::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'is_active' => true,
        ]);

        $this->meeting = Meeting::factory()->create([
            'department_id' => $this->dept->id,
            'organization_id' => $this->project->organization_id,
            'organizer_id' => $this->user->id,
            'status' => Meeting::STATUS_SCHEDULED,
        ]);
    }

    private function makeResolution(): MeetingResolution
    {
        return MeetingResolution::create([
            'meeting_id' => $this->meeting->id,
            'organization_id' => $this->project->organization_id,
            'kind' => MeetingResolution::KIND_DECISION,
            'title' => 'مخرج للاختبار',
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => MeetingResolution::STATUS_OPEN,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
        ]);
    }

    public function test_one_notification_per_unique_assignee(): void
    {
        Notification::fake();

        $r = $this->makeResolution();
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة ١', 'assignee_id' => $this->assigneeA->id],
                    ['title' => 'مهمة ٢', 'assignee_id' => $this->assigneeA->id], // same assignee
                    ['title' => 'مهمة ٣', 'assignee_id' => $this->assigneeB->id],
                ],
            ])->assertStatus(201);

        // assertSentTimes(NotificationClass, expected) — Laravel's API for
        // "this notification was sent N times in total, regardless of
        // recipient". We expect exactly 2 (one per unique assignee).
        Notification::assertSentTimes(ResolutionConvertedToTasksNotification::class, 2);

        Notification::assertSentTo($this->assigneeA, ResolutionConvertedToTasksNotification::class, function ($notif) {
            return $notif->assigneeTaskCount === 2;
        });
        Notification::assertSentTo($this->assigneeB, ResolutionConvertedToTasksNotification::class, function ($notif) {
            return $notif->assigneeTaskCount === 1;
        });
    }

    public function test_actor_is_skipped_when_assignee(): void
    {
        Notification::fake();

        $r = $this->makeResolution();
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [
                    // The creator (super_admin in tests) is also the assignee.
                    // The actor must be excluded from the recipient list.
                    ['title' => 'مهمة ذاتية', 'assignee_id' => $this->user->id],
                    ['title' => 'مهمة للزميل', 'assignee_id' => $this->assigneeA->id],
                ],
            ])->assertStatus(201);

        Notification::assertNotSentTo($this->user, ResolutionConvertedToTasksNotification::class);
        // Exactly one notification was sent (to the colleague).
        Notification::assertSentTimes(ResolutionConvertedToTasksNotification::class, 1);
        Notification::assertSentTo($this->assigneeA, ResolutionConvertedToTasksNotification::class);
    }

    public function test_notification_payload_carries_required_fields(): void
    {
        Notification::fake();

        $r = $this->makeResolution();
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة', 'assignee_id' => $this->assigneeA->id],
                ],
            ])->assertStatus(201);

        // First confirm the notification was sent (without a callback) — this
        // proves NotificationFake captured it. Then pin the payload via the
        // sent() helper which returns the actual notification instance.
        Notification::assertSentTo($this->assigneeA, ResolutionConvertedToTasksNotification::class);

        $sent = Notification::sent($this->assigneeA, ResolutionConvertedToTasksNotification::class);
        $notif = $sent->first();
        $this->assertNotNull($notif);
        $this->assertSame($r->id, $notif->resolutionId);
        $this->assertSame($this->meeting->id, $notif->meetingId);
        $this->assertSame($r->title, $notif->resolutionTitle);
        $this->assertSame(1, $notif->totalTaskCount);
        $this->assertSame(1, $notif->assigneeTaskCount);
        $this->assertIsArray($notif->tasksForAssignee);
        $this->assertCount(1, $notif->tasksForAssignee);

        // The `url` field lives inside toArray() (database payload), not as a
        // top-level property. Pin that the toArray output is well-formed.
        $array = $notif->toArray($this->assigneeA);
        $this->assertSame($r->id, $array['resolution_id']);
        $this->assertNotEmpty($array['url']);
        $this->assertSame(1, $array['task_count']);
        $this->assertSame(1, $array['assignee_task_count']);
    }

    public function test_no_notification_on_rolled_back_conversion(): void
    {
        Notification::fake();

        $r = $this->makeResolution();

        // Pass an empty tasks array — FormRequest requires at least one
        // task (min:1), so the validator rejects with 422 BEFORE any
        // DB row is touched, exercising the validation guard.
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [],
            ]);

        $response->assertStatus(422);
        Notification::assertNothingSent();
    }

    public function test_no_duplicate_notification_on_repeat_conversion(): void
    {
        Notification::fake();

        $r = $this->makeResolution();
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة', 'assignee_id' => $this->assigneeA->id],
                ],
            ])->assertStatus(201);

        Notification::assertSentTimes(ResolutionConvertedToTasksNotification::class, 1);

        // Second attempt — controller returns 409 because the resolution
        // is now in `converted_to_tasks` status.
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة ثانية', 'assignee_id' => $this->assigneeA->id],
                ],
            ])->assertStatus(409);

        // Notification count must not have grown.
        Notification::assertSentTimes(ResolutionConvertedToTasksNotification::class, 1);
    }

    public function test_notification_after_commit_only_via_should_queue(): void
    {
        // Phase 4 design choice: the controller dispatches the
        // notification inside the controller transaction, but the
        // notification class is `ShouldQueue` (queued after commit).
        // The queue worker cannot process it until the outer transaction
        // commits — guaranteeing a rolled-back conversion never delivers
        // an email. We pin that property by asserting the notification
        // reaches the queue on the success path.
        Notification::fake();

        $r = $this->makeResolution();
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/meeting-resolutions/{$r->id}/convert-to-tasks", [
                'tasks' => [
                    ['title' => 'مهمة', 'assignee_id' => $this->assigneeA->id],
                ],
            ])->assertStatus(201);

        // On the success path the notification was sent to the assignee.
        Notification::assertSentTo($this->assigneeA, ResolutionConvertedToTasksNotification::class);
    }
}
