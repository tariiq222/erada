<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Notifications\RiskActionOverdueNotification;
use App\Modules\RiskManagement\Notifications\RiskReviewDueNotification;
use Database\Factories\RiskManagement\RiskActionFactory;
use Database\Factories\RiskManagement\RiskAssessmentFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RiskCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_due_evaluations_command_notifies_and_marks_idempotent(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $assessment = RiskAssessmentFactory::new()->dueForReview()->create([
            'organization_id' => $org->id,
            'assessor_id' => $owner->id,
        ]);
        $assessment->risk->forceFill(['owner_id' => $owner->id])->save();

        $this->artisan('risks:check-due-evaluations')->assertSuccessful();

        Notification::assertSentTo($owner, RiskReviewDueNotification::class);
        $this->assertNotNull($assessment->fresh()->review_due_notified_at);

        // Re-run is a no-op
        $this->artisan('risks:check-due-evaluations')->assertSuccessful();
        Notification::assertSentToTimes($owner, RiskReviewDueNotification::class, 1);
    }

    public function test_notify_overdue_actions_command_skips_completed_and_idempotently_marks(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $action = RiskActionFactory::new()->overdue()->create([
            'organization_id' => $org->id,
            'owner_id' => $owner->id,
        ]);

        $this->artisan('risks:notify-overdue-actions')->assertSuccessful();

        Notification::assertSentTo($owner, RiskActionOverdueNotification::class);
        $this->assertNotNull($action->fresh()->overdue_notified_at);

        // Re-run is a no-op
        $this->artisan('risks:notify-overdue-actions')->assertSuccessful();
        Notification::assertSentToTimes($owner, RiskActionOverdueNotification::class, 1);
    }
}
