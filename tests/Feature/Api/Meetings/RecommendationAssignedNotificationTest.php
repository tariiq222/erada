<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Notifications\RecommendationAssignedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * RecommendationAssignedNotificationTest
 *
 * Pins RecommendationAssignedNotification: the notifiable is the new
 * assignee (not the creator), and the database-channel payload carries the
 * priority + due_date + reference_number the UI needs to render the bell.
 *
 * Direction B rewrites the fixture: there is no longer a parent Decision
 * row to bind to, so the Recommendation is created directly with kind=
 * action_item (the default factory state) and no `decision_id`.
 */
class RecommendationAssignedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommendation_assigned_notification_sent_to_new_assignee(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $creator = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $newAssignee = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $rec = Recommendation::factory()->create([
            'organization_id' => $org->id,
            'requested_by' => $creator->id,
            'assignee_id' => $newAssignee->id,
        ]);

        $newAssignee->notify(new RecommendationAssignedNotification($rec));

        Notification::assertSentTo($newAssignee, RecommendationAssignedNotification::class);
        Notification::assertNotSentTo($creator, RecommendationAssignedNotification::class);
    }

    public function test_database_payload_includes_priority_and_due_date(): void
    {
        $org = Organization::factory()->create();
        $assignee = User::factory()->create(['organization_id' => $org->id]);

        $rec = Recommendation::factory()->create([
            'organization_id' => $org->id,
            'assignee_id' => $assignee->id,
            'priority' => Recommendation::PRIORITY_HIGH,
            'title' => 'إعادة جدولة المرحلة 2',
            'reference_number' => 'REC-2026-0011',
            'due_date' => now()->addWeeks(2)->toDateString(),
        ]);

        $payload = (new RecommendationAssignedNotification($rec))->toArray($assignee);

        $this->assertSame('recommendation_assigned', $payload['type']);
        $this->assertSame('high', $payload['priority']);
        $this->assertNotNull($payload['due_date']);
        $this->assertSame('إعادة جدولة المرحلة 2', $payload['title']);
        $this->assertSame('REC-2026-0011', $payload['reference_number']);
    }
}
