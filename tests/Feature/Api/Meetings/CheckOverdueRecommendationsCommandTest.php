<?php

namespace Tests\Feature\Api\Meetings;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Notifications\RecommendationOverdueNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * CheckOverdueRecommendationsCommandTest
 *
 * Pins the `recommendations:check-overdue` artisan command. After Direction B
 * the command scans the unified `recommendations` table for accepted action
 * items whose due_date has lapsed — the legacy Decision model no longer
 * exists, so the earlier fixture `Decision::factory()->create([...]) +
 * 'decision_id' => $decision->id` is replaced by a direct Recommendation
 * factory call.
 */
class CheckOverdueRecommendationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_overdue_notification_to_assignee(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $assignee = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $rec = Recommendation::factory()->create([
            'organization_id' => $org->id,
            'assignee_id' => $assignee->id,
            'status' => Recommendation::STATUS_ACCEPTED,
            'due_date' => now()->subDays(5)->toDateString(),
            'overdue_notified_at' => null,
        ]);

        $this->artisan('recommendations:check-overdue')->assertSuccessful();

        Notification::assertSentTo($assignee, RecommendationOverdueNotification::class);
        $this->assertNotNull($rec->fresh()->overdue_notified_at);
    }

    public function test_skips_completed_recommendations(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $assignee = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        Recommendation::factory()->create([
            'organization_id' => $org->id,
            'assignee_id' => $assignee->id,
            'status' => Recommendation::STATUS_COMPLETED,
            'due_date' => now()->subDays(10)->toDateString(),
            'overdue_notified_at' => null,
        ]);

        $this->artisan('recommendations:check-overdue')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_is_idempotent_on_re_run(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $assignee = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        Recommendation::factory()->create([
            'organization_id' => $org->id,
            'assignee_id' => $assignee->id,
            'status' => Recommendation::STATUS_ACCEPTED,
            'due_date' => now()->subDays(5)->toDateString(),
            'overdue_notified_at' => null,
        ]);

        $this->artisan('recommendations:check-overdue')->assertSuccessful();
        $this->artisan('recommendations:check-overdue')->assertSuccessful();

        Notification::assertSentToTimes($assignee, RecommendationOverdueNotification::class, 1);
    }

    public function test_respects_grace_days_config(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        config()->set('meetings.recommendation_overdue_grace_days', 7);

        $org = Organization::factory()->create();
        $assignee = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        // Only 3 days overdue, but grace is 7 days → not yet overdue
        Recommendation::factory()->create([
            'organization_id' => $org->id,
            'assignee_id' => $assignee->id,
            'status' => Recommendation::STATUS_ACCEPTED,
            'due_date' => now()->subDays(3)->toDateString(),
            'overdue_notified_at' => null,
        ]);

        $this->artisan('recommendations:check-overdue')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
