<?php

namespace Tests\Unit\RiskManagement;

use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Enums\RiskStatus;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\RiskManagement\Models\RiskAssessment;
use App\Modules\RiskManagement\Notifications\RiskActionOverdueNotification;
use App\Modules\RiskManagement\Notifications\RiskReviewDueNotification;
use App\Modules\RiskManagement\Policies\RiskActionPolicy;
use App\Modules\RiskManagement\Policies\RiskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskNotificationsAndPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_overdue_action_notification_channels_mail_and_database_payload(): void
    {
        $action = RiskAction::factory()->overdue()->create([
            'title' => 'Close overdue mitigation',
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $notifiable = User::factory()->create(['name' => 'Risk Owner']);
        $notification = new RiskActionOverdueNotification($action->fresh('risk'));

        $this->assertSame(['mail', 'database'], $notification->via($notifiable));
        $mail = $notification->toMail($notifiable);
        $payload = $notification->toArray($notifiable);

        $this->assertStringContainsString('إجراء متأخر على الخطر', $mail->subject);
        $this->assertSame('risk_action_overdue', $payload['type']);
        $this->assertSame($action->risk_id, $payload['risk_id']);
        $this->assertSame($action->id, $payload['risk_action_id']);
        $this->assertStringContainsString('Close overdue mitigation', $payload['message']);
    }

    public function test_review_due_notification_channels_mail_and_database_payload(): void
    {
        $assessment = RiskAssessment::factory()->dueForReview()->create([
            'next_review_at' => now()->subDay()->toDateString(),
        ]);
        $notifiable = User::factory()->create(['name' => 'Risk Reviewer']);
        $notification = new RiskReviewDueNotification($assessment->fresh('risk'));

        $this->assertSame(['mail', 'database'], $notification->via($notifiable));
        $mail = $notification->toMail($notifiable);
        $payload = $notification->toArray($notifiable);

        $this->assertStringContainsString('موعد إعادة تقييم الخطر', $mail->subject);
        $this->assertSame('risk_review_due', $payload['type']);
        $this->assertSame($assessment->risk_id, $payload['risk_id']);
        $this->assertSame($assessment->id, $payload['risk_assessment_id']);
    }

    /**
     * Engine-only path: RiskPolicy reads ONLY engine capabilities
     * (AccessDecision::can). A user with no engine grant is denied. Legacy
     * Spatie flat permissions have been removed from the catalog (Wave 3 task 8)
     * and are ignored by the engine even if present.
     *
     * Cross-org isolation and ScopedRole-based grants are verified in:
     *   tests/Unit/RiskManagement/Policies/RiskPolicyTest
     *   tests/Feature/Authorization/RiskPolicyParityTest
     */
    public function test_risk_policy_denies_user_without_engine_grant(): void
    {
        // Engine-only path: a user with NO engine grant is denied by RiskPolicy.
        // The legacy Spatie flat permissions have been removed from the
        // catalog (Wave 3 task 8); even when seeded as Spatie rows for
        // compatibility, the engine ignores them — proving the engine path is
        // the only authz source.
        $risk = Risk::factory()->create();
        $user = User::factory()->create(['organization_id' => $risk->organization_id]);

        $policy = new RiskPolicy;

        $this->assertFalse($policy->viewAny($user));
        $this->assertFalse($policy->view($user, $risk));
        $this->assertFalse($policy->update($user, $risk));
        $this->assertFalse($policy->delete($user, $risk));
    }

    /**
     * Engine-only path: RiskActionPolicy passes the RiskAction (ScopeAware) to
     * the engine. A user with no engine grant is denied. Legacy Spatie flat
     * permissions have been removed from the catalog (Wave 3 task 8); the
     * engine ignores them. (GAP-3 closed — flat mechanism removed.)
     */
    public function test_risk_action_policy_denies_user_without_engine_grant(): void
    {
        // Engine-only path: a user with NO engine grant is denied by
        // RiskActionPolicy. The legacy Spatie flat permissions have been
        // removed from the catalog (Wave 3 task 8); the engine ignores them.
        $action = RiskAction::factory()->create();
        $user = User::factory()->create(['organization_id' => $action->organization_id]);

        $policy = new RiskActionPolicy;

        $this->assertFalse($policy->viewAny($user));
        $this->assertFalse($policy->view($user, $action));
        $this->assertFalse($policy->update($user, $action));
        $this->assertFalse($policy->delete($user, $action));
    }

    public function test_risk_status_and_action_status_transition_helpers_are_behavioral(): void
    {
        $this->assertSame('مفتوح', RiskStatus::Open->label());
        $this->assertSame('danger', RiskStatus::Open->color());
        $this->assertFalse(RiskStatus::Open->isTerminal());
        $this->assertTrue(RiskStatus::Open->canTransitionTo(RiskStatus::Treating));
        $this->assertFalse(RiskStatus::Closed->canTransitionTo(RiskStatus::Treating));

        $this->assertSame('قيد التنفيذ', RiskActionStatus::InProgress->label());
        $this->assertSame('primary', RiskActionStatus::InProgress->color());
        $this->assertFalse(RiskActionStatus::InProgress->isTerminal());
        $this->assertTrue(RiskActionStatus::Completed->isTerminal());
    }
}
