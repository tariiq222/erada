<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Blocker;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Models\Review;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 04-04 — vertical Strategy permission enforcement regressions.
 *
 * يثبت أن member بلا *_strategy لا يمر لأي action كان مكشوفاً سابقاً في:
 * Blocker, Decision, Review, StrategyDashboard.
 */
class StrategyPermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
    }

    private function makeUser(?string $role): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function forceOrg(Model $model): Model
    {
        $model->forceFill(['organization_id' => $this->org->id])->save();

        return $model->refresh();
    }

    private function makeProject(): Project
    {
        // ProjectObserver::saving corrects organization_id to match the
        // department's org. Create the department inside $this->org so the
        // observer leaves the org alone instead of overriding forceOrg().
        $dept = Department::factory()->create(['organization_id' => $this->org->id]);

        /** @var Project $project */
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
        ]);

        return $project;
    }

    private function makePortfolio(array $overrides = []): Portfolio
    {
        /** @var Portfolio $portfolio */
        $portfolio = Portfolio::factory()->create(array_merge([
            'created_by' => $this->makeUser(null)->id,
            'status' => 'active',
            'portfolio_status' => 'active',
        ], $overrides));

        return $this->forceOrg($portfolio);
    }

    private function makeProgram(?Portfolio $portfolio = null, array $overrides = []): Program
    {
        $portfolio ??= $this->makePortfolio();

        /** @var Program $program */
        $program = Program::factory()->create(array_merge([
            'portfolio_id' => $portfolio->id,
            'created_by' => $this->makeUser(null)->id,
            'status' => 'in_progress',
        ], $overrides));

        return $this->forceOrg($program);
    }

    private function makeBlocker(array $overrides = []): Blocker
    {
        $project = $this->makeProject();

        $blocker = Blocker::create(array_merge([
            'title' => 'تعثر اختباري',
            'blockable_type' => Project::class,
            'blockable_id' => $project->id,
            'reported_by' => $this->makeUser(null)->id,
            'status' => 'open',
            'severity' => 'medium',
            'identified_date' => now()->toDateString(),
        ], $overrides));

        return $this->forceOrg($blocker);
    }

    private function makeDecision(array $overrides = []): Recommendation
    {
        // Direction B (commit f98adef5): rulings live on the unified
        // `recommendations` table with `kind=ruling`. The factory call
        // mirrors what RecommendationController::store() accepts.
        $project = $this->makeProject();
        $meeting = Meeting::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $project->department_id,
        ]);

        $recommendation = Recommendation::create(array_merge([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $meeting->id,
            'decidable_type' => Project::class,
            'decidable_id' => $project->id,
            'type' => 'approval',
            'title' => 'قرار اختباري',
            'requested_by' => $this->makeUser(null)->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->org->id,
        ], $overrides));

        return $this->forceOrg($recommendation);
    }

    private function makeReview(array $overrides = []): Review
    {
        $project = $this->makeProject();

        $review = Review::create(array_merge([
            'title' => 'مراجعة اختبارية',
            'reviewable_type' => Project::class,
            'reviewable_id' => $project->id,
            'type' => 'monthly',
            'pdca_phase' => 'check',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'overall_status' => 'on_track',
            'conducted_by' => $this->makeUser(null)->id,
        ], $overrides));

        return $this->forceOrg($review);
    }

    public function test_member_without_strategy_permission_cannot_use_blocker_methods(): void
    {
        $member = $this->makeUser('viewer');
        $blocker = $this->makeBlocker();
        $project = $this->makeProject();

        // viewer has strategy.view → reads are allowed (200), writes stay 403.
        $this->actingAs($member, 'sanctum')->getJson('/api/strategy/blockers')->assertStatus(200);
        $this->actingAs($member, 'sanctum')->postJson('/api/strategy/blockers', [
            'title' => 'تعثر جديد',
            'blockable_type' => 'project',
            'blockable_id' => $project->id,
            'severity' => 'high',
            'identified_date' => now()->toDateString(),
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->getJson("/api/strategy/blockers/{$blocker->id}")->assertStatus(200);
        $this->actingAs($member, 'sanctum')->putJson("/api/strategy/blockers/{$blocker->id}", [
            'title' => 'تعثر محدث',
            'severity' => 'critical',
            'status' => 'in_progress',
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->deleteJson("/api/strategy/blockers/{$blocker->id}")->assertStatus(403);
        $this->actingAs($member, 'sanctum')->postJson("/api/strategy/blockers/{$blocker->id}/resolve", [
            'resolution' => 'حل غير مصرح',
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->postJson("/api/strategy/blockers/{$blocker->id}/escalate")->assertStatus(403);
    }

    public function test_member_without_strategy_permission_cannot_use_recommendation_methods(): void
    {
        $member = $this->makeUser('viewer');
        $recommendation = $this->makeDecision();
        $project = $this->makeProject();

        // Direction B (commit f98adef5): the legacy /api/decisions/* CRUD
        // group is gone. Both ruling and action_item lifecycle actions live
        // under /api/recommendations/*, gated by the unified
        // RecommendationPolicy on engine capability. viewer holds no
        // recommendations.* capability, so every method stays 403 for them.
        $this->actingAs($member, 'sanctum')->getJson('/api/recommendations')->assertStatus(403);
        $this->actingAs($member, 'sanctum')->postJson('/api/recommendations', [
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $recommendation->meeting_id,
            'title' => 'قرار جديد',
            'decidable_type' => Project::class,
            'decidable_id' => $project->id,
            'type' => 'approval',
            'priority' => Recommendation::PRIORITY_MEDIUM,
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->getJson("/api/recommendations/{$recommendation->id}")->assertStatus(403);
        $this->actingAs($member, 'sanctum')->putJson("/api/recommendations/{$recommendation->id}", [
            'title' => 'قرار محدث',
            'type' => 'change_request',
            'priority' => Recommendation::PRIORITY_MEDIUM,
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->deleteJson("/api/recommendations/{$recommendation->id}")->assertStatus(403);
        $this->actingAs($member, 'sanctum')->postJson("/api/recommendations/{$recommendation->id}/approve")->assertStatus(403);
        $this->actingAs($member, 'sanctum')->postJson("/api/recommendations/{$recommendation->id}/reject", [
            'rationale' => 'رفض غير مصرح',
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->postJson("/api/recommendations/{$recommendation->id}/defer", [
            'defer_reason' => 'تأجيل غير مصرح',
        ])->assertStatus(403);
    }

    public function test_member_without_strategy_permission_cannot_use_review_methods(): void
    {
        $member = $this->makeUser('viewer');
        $review = $this->makeReview();
        $project = $this->makeProject();

        // viewer has strategy.view → reads are allowed (200), writes stay 403.
        $this->actingAs($member, 'sanctum')->getJson('/api/strategy/reviews')->assertStatus(200);
        $this->actingAs($member, 'sanctum')->postJson('/api/strategy/reviews', [
            'title' => 'مراجعة جديدة',
            'reviewable_type' => 'project',
            'reviewable_id' => $project->id,
            'type' => 'monthly',
            'pdca_phase' => 'check',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'overall_status' => 'on_track',
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->getJson("/api/strategy/reviews/{$review->id}")->assertStatus(200);
        $this->actingAs($member, 'sanctum')->putJson("/api/strategy/reviews/{$review->id}", [
            'title' => 'مراجعة محدثة',
            'type' => 'quarterly',
            'pdca_phase' => 'act',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'overall_status' => 'at_risk',
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->deleteJson("/api/strategy/reviews/{$review->id}")->assertStatus(403);
    }

    public function test_member_without_strategy_permission_cannot_use_dashboard_methods(): void
    {
        $member = $this->makeUser('viewer');
        $portfolio = $this->makePortfolio();

        // viewer has strategy.view → dashboard reads are allowed (200).
        $this->actingAs($member, 'sanctum')
            ->getJson('/api/strategy/dashboard/summary')
            ->assertStatus(200);

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/strategy/dashboard/golden-chain/portfolio/{$portfolio->id}")
            ->assertStatus(200);
    }

    public function test_member_without_strategy_permission_cannot_use_portfolio_methods(): void
    {
        $member = $this->makeUser('viewer');
        $portfolio = $this->makePortfolio();

        // viewer has strategy.view → reads are allowed (200), writes stay 403.
        $this->actingAs($member, 'sanctum')->getJson('/api/strategy/portfolios')->assertStatus(200);
        $this->actingAs($member, 'sanctum')->postJson('/api/strategy/portfolios', [
            'name' => 'التزام غير مصرح',
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->getJson("/api/strategy/portfolios/{$portfolio->id}")->assertStatus(200);
        $this->actingAs($member, 'sanctum')->putJson("/api/strategy/portfolios/{$portfolio->id}", [
            'name' => 'التزام محدث غير مصرح',
            'status' => 'active',
            'portfolio_status' => 'active',
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->deleteJson("/api/strategy/portfolios/{$portfolio->id}")->assertStatus(403);
        $this->actingAs($member, 'sanctum')->getJson('/api/strategy/portfolios/list')->assertStatus(200);
        $this->actingAs($member, 'sanctum')->getJson('/api/strategy/portfolios/summary')->assertStatus(200);
        $this->actingAs($member, 'sanctum')->putJson("/api/strategy/portfolios/{$portfolio->id}/priority", [
            'priority_rank' => 1,
            'weight' => 10,
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->putJson("/api/strategy/portfolios/{$portfolio->id}/strategic-status", [
            'portfolio_status' => 'frozen',
        ])->assertStatus(403);
    }

    public function test_member_without_strategy_permission_cannot_use_program_methods(): void
    {
        $member = $this->makeUser('viewer');
        $portfolio = $this->makePortfolio();
        $program = $this->makeProgram($portfolio);
        $project = $this->makeProject();

        // viewer has strategy.view → reads are allowed (200), writes stay 403.
        $this->actingAs($member, 'sanctum')->getJson('/api/strategy/programs')->assertStatus(200);
        $this->actingAs($member, 'sanctum')->postJson('/api/strategy/programs', [
            'name' => 'مبادرة غير مصرح بها',
            'portfolio_id' => $portfolio->id,
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->getJson("/api/strategy/programs/{$program->id}")->assertStatus(200);
        $this->actingAs($member, 'sanctum')->putJson("/api/strategy/programs/{$program->id}", [
            'name' => 'مبادرة محدثة غير مصرح بها',
            'portfolio_id' => $portfolio->id,
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->deleteJson("/api/strategy/programs/{$program->id}")->assertStatus(403);
        $this->actingAs($member, 'sanctum')->getJson('/api/strategy/programs/list')->assertStatus(200);
        $this->actingAs($member, 'sanctum')->postJson("/api/strategy/programs/{$program->id}/link-project", [
            'project_id' => $project->id,
        ])->assertStatus(403);
        $this->actingAs($member, 'sanctum')->deleteJson("/api/strategy/programs/{$program->id}/unlink-project/{$project->id}")->assertStatus(403);
        $this->actingAs($member, 'sanctum')->getJson('/api/strategy/programs/unlinked-projects')->assertStatus(200);
    }

    public function test_admin_with_strategy_permissions_is_not_forbidden_for_read_create_and_update_controls(): void
    {
        $admin = $this->makeUser('admin');
        $blocker = $this->makeBlocker();
        $project = $this->makeProject();

        $readResponse = $this->actingAs($admin, 'sanctum')->getJson('/api/strategy/dashboard/summary');
        $this->assertNotSame(403, $readResponse->status(), 'admin with view_strategy should not be authorization-blocked on dashboard summary');

        $createResponse = $this->actingAs($admin, 'sanctum')->postJson('/api/strategy/blockers', [
            'title' => 'تعثر مصرح',
            'blockable_type' => 'project',
            'blockable_id' => $project->id,
            'severity' => 'high',
            'identified_date' => now()->toDateString(),
        ]);
        $this->assertNotSame(403, $createResponse->status(), 'admin with create_strategy should not be authorization-blocked on blocker create');

        $updateResponse = $this->actingAs($admin, 'sanctum')->putJson("/api/strategy/blockers/{$blocker->id}", [
            'title' => 'تعثر مصرح محدث',
            'severity' => 'critical',
            'status' => 'in_progress',
        ]);
        $this->assertNotSame(403, $updateResponse->status(), 'admin with edit_strategy should not be authorization-blocked on blocker update');
    }

    public function test_admin_with_strategy_permissions_is_not_forbidden_for_portfolio_and_program_controls(): void
    {
        $admin = $this->makeUser('admin');
        $portfolio = $this->makePortfolio();
        $program = $this->makeProgram($portfolio);

        $portfolioListResponse = $this->actingAs($admin, 'sanctum')->getJson('/api/strategy/portfolios/list');
        $this->assertNotSame(403, $portfolioListResponse->status(), 'admin with view_strategy should not be authorization-blocked on portfolio list');

        $portfolioCreateResponse = $this->actingAs($admin, 'sanctum')->postJson('/api/strategy/portfolios', [
            'name' => 'التزام مصرح',
        ]);
        $this->assertNotSame(403, $portfolioCreateResponse->status(), 'admin with create_strategy should not be authorization-blocked on portfolio create');

        $programCreateResponse = $this->actingAs($admin, 'sanctum')->postJson('/api/strategy/programs', [
            'name' => 'مبادرة مصرح بها',
            'portfolio_id' => $portfolio->id,
        ]);
        $this->assertNotSame(403, $programCreateResponse->status(), 'admin with create_strategy should not be authorization-blocked on program create');

        $programUpdateResponse = $this->actingAs($admin, 'sanctum')->putJson("/api/strategy/programs/{$program->id}", [
            'name' => 'مبادرة مصرح بتعديلها',
            'portfolio_id' => $portfolio->id,
        ]);
        $this->assertNotSame(403, $programUpdateResponse->status(), 'admin with edit_strategy should not be authorization-blocked on program update');
    }
}
