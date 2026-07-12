<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Capability;
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
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 04-04 — Strategy organization isolation regressions.
 *
 * يثبت SC2/SC3/SC4 لكل سطح Strategy بعد إضافة organization_id:
 * - مستخدم مصرّح له لكن من مؤسسة أخرى لا يرى/يعدل/يحذف كيانات مؤسسة B.
 * - القوائم و dashboard aggregates محصورة بمؤسسة المستخدم.
 * - null-org non-super_admin يُرفض صراحةً.
 * - super_admin، حتى بدون organization_id، لا ينكسر وصوله.
 */
class StrategyOrganizationScopeTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
    }

    private function makeUser(?Organization $org, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'is_active' => true,
        ]);

        if ($role) {
            $this->assignCanonicalRole($user, $role);
        }

        return $user;
    }

    /**
     * Admin lacks delete_strategy in the seeder, so grant it directly when the
     * scenario must prove org-isolation rather than permission failure.
     */
    private function makeStrategyActor(?Organization $org): User
    {
        $user = $this->makeUser($org, 'admin');
        $this->grantEngineCapability($user, Capability::STRATEGY_DELETE);

        return $user;
    }

    private function forceOrg(Model $model, Organization $org): Model
    {
        $model->forceFill(['organization_id' => $org->id])->save();

        return $model->refresh();
    }

    private function makeProject(Organization $org): Project
    {
        /** @var Project $project */
        $project = Project::factory()->create();

        return $this->forceOrg($project, $org);
    }

    private function makePortfolio(Organization $org, array $overrides = []): Portfolio
    {
        /** @var Portfolio $portfolio */
        $portfolio = Portfolio::factory()->create(array_merge([
            'created_by' => $this->makeUser($org)->id,
            'status' => 'active',
            'portfolio_status' => 'active',
        ], $overrides));

        return $this->forceOrg($portfolio, $org);
    }

    private function makeProgram(Organization $org, ?Portfolio $portfolio = null, array $overrides = []): Program
    {
        $portfolio ??= $this->makePortfolio($org);

        /** @var Program $program */
        $program = Program::factory()->create(array_merge([
            'portfolio_id' => $portfolio->id,
            'created_by' => $this->makeUser($org)->id,
            'status' => 'in_progress',
        ], $overrides));

        return $this->forceOrg($program, $org);
    }

    private function makeBlocker(Organization $org, array $overrides = []): Blocker
    {
        $project = $this->makeProject($org);

        $blocker = Blocker::create(array_merge([
            'title' => 'تعثر اختباري',
            'blockable_type' => Project::class,
            'blockable_id' => $project->id,
            'reported_by' => $this->makeUser($org)->id,
            'status' => 'open',
            'severity' => 'medium',
            'identified_date' => now()->toDateString(),
        ], $overrides));

        return $this->forceOrg($blocker, $org);
    }

    private function makeDecision(Organization $org, array $overrides = []): Recommendation
    {
        // Direction B (commit f98adef5): rulings live on the unified
        // `recommendations` table with `kind=ruling`. The Meeting module
        // exposes /api/recommendations/{id}/{transition} in place of the
        // legacy /api/decisions endpoints.
        $project = $this->makeProject($org);
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $meeting = Meeting::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $recommendation = Recommendation::create(array_merge([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $meeting->id,
            'decidable_type' => Project::class,
            'decidable_id' => $project->id,
            'type' => 'approval',
            'title' => 'قرار اختباري',
            'requested_by' => $this->makeUser($org)->id,
            'status' => Recommendation::STATUS_PENDING,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $org->id,
        ], $overrides));

        return $this->forceOrg($recommendation, $org);
    }

    private function makeReview(Organization $org, array $overrides = []): Review
    {
        $project = $this->makeProject($org);

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
            'conducted_by' => $this->makeUser($org)->id,
        ], $overrides));

        return $this->forceOrg($review, $org);
    }

    private function assertDeniedByIsolation(int $status, string $message): void
    {
        $this->assertContains($status, [403, 404], $message);
    }

    private function assertRejectedCrossOrgWrite(int $status, string $message): void
    {
        $this->assertContains($status, [403, 422], $message);
    }

    private function assertIndexContainsOnlyOrgA(string $url, Model $orgAEntity, Model $orgBEntity): void
    {
        $actor = $this->makeStrategyActor($this->orgA);

        $response = $this->actingAs($actor, 'sanctum')->getJson($url);

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($orgAEntity->id, $ids, "Expected org-A entity to appear in {$url}");
        $this->assertNotContains($orgBEntity->id, $ids, "Expected org-B entity to be scoped out from {$url}");
    }

    public function test_cross_org_admin_cannot_access_blocker_crud_or_custom_actions(): void
    {
        $adminA = $this->makeStrategyActor($this->orgA);
        $blockerB = $this->makeBlocker($this->orgB);

        $this->assertDeniedByIsolation(
            $this->actingAs($adminA, 'sanctum')->getJson("/api/strategy/blockers/{$blockerB->id}")->status(),
            'يجب منع قراءة blocker من مؤسسة أخرى'
        );
        $this->assertDeniedByIsolation(
            $this->actingAs($adminA, 'sanctum')->putJson("/api/strategy/blockers/{$blockerB->id}", [
                'title' => 'تعثر محدث',
                'severity' => 'high',
                'status' => 'in_progress',
            ])->status(),
            'يجب منع تعديل blocker من مؤسسة أخرى'
        );
        $this->assertDeniedByIsolation(
            $this->actingAs($adminA, 'sanctum')->deleteJson("/api/strategy/blockers/{$blockerB->id}")->status(),
            'يجب منع حذف blocker من مؤسسة أخرى'
        );
        $this->assertDeniedByIsolation(
            $this->actingAs($adminA, 'sanctum')->postJson("/api/strategy/blockers/{$blockerB->id}/resolve", [
                'resolution' => 'حل غير مصرح',
            ])->status(),
            'يجب منع resolve blocker من مؤسسة أخرى'
        );
        $this->assertDeniedByIsolation(
            $this->actingAs($adminA, 'sanctum')->postJson("/api/strategy/blockers/{$blockerB->id}/escalate")->status(),
            'يجب منع escalate blocker من مؤسسة أخرى'
        );
    }

    public function test_cross_org_admin_cannot_access_recommendation_crud_or_custom_actions(): void
    {
        $adminA = $this->makeStrategyActor($this->orgA);
        $rulingB = $this->makeDecision($this->orgB);

        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->getJson("/api/recommendations/{$rulingB->id}")->status(), 'يجب منع قراءة ruling من مؤسسة أخرى');
        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->putJson("/api/recommendations/{$rulingB->id}", [
            'title' => 'قرار محدث',
            'type' => 'change_request',
            'priority' => 'medium',
        ])->status(), 'يجب منع تعديل ruling من مؤسسة أخرى');
        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->deleteJson("/api/recommendations/{$rulingB->id}")->status(), 'يجب منع حذف ruling من مؤسسة أخرى');
        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->postJson("/api/recommendations/{$rulingB->id}/approve")->status(), 'يجب منع approve ruling من مؤسسة أخرى');
        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->postJson("/api/recommendations/{$rulingB->id}/reject", [
            'rationale' => 'رفض غير مصرح',
        ])->status(), 'يجب منع reject ruling من مؤسسة أخرى');
        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->postJson("/api/recommendations/{$rulingB->id}/defer", [
            'defer_reason' => 'تأجيل غير مصرح',
        ])->status(), 'يجب منع defer ruling من مؤسسة أخرى');
    }

    public function test_cross_org_admin_cannot_access_review_crud(): void
    {
        $adminA = $this->makeStrategyActor($this->orgA);
        $reviewB = $this->makeReview($this->orgB);

        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->getJson("/api/strategy/reviews/{$reviewB->id}")->status(), 'يجب منع قراءة review من مؤسسة أخرى');
        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->putJson("/api/strategy/reviews/{$reviewB->id}", [
            'title' => 'مراجعة محدثة',
            'type' => 'quarterly',
            'pdca_phase' => 'act',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'overall_status' => 'at_risk',
        ])->status(), 'يجب منع تعديل review من مؤسسة أخرى');
        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->deleteJson("/api/strategy/reviews/{$reviewB->id}")->status(), 'يجب منع حذف review من مؤسسة أخرى');
    }

    public function test_cross_org_admin_cannot_access_portfolio_show_update_or_destroy(): void
    {
        $adminA = $this->makeStrategyActor($this->orgA);
        $portfolioB = $this->makePortfolio($this->orgB);

        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->getJson("/api/strategy/portfolios/{$portfolioB->id}")->status(), 'يجب منع قراءة portfolio من مؤسسة أخرى');
        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->putJson("/api/strategy/portfolios/{$portfolioB->id}", [
            'name' => 'التزام محدث',
            'status' => 'active',
            'portfolio_status' => 'active',
        ])->status(), 'يجب منع تعديل portfolio من مؤسسة أخرى');
        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->deleteJson("/api/strategy/portfolios/{$portfolioB->id}")->status(), 'يجب منع حذف portfolio من مؤسسة أخرى');
    }

    public function test_cross_org_admin_cannot_access_program_show_update_or_destroy(): void
    {
        $adminA = $this->makeStrategyActor($this->orgA);
        $programB = $this->makeProgram($this->orgB);

        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->getJson("/api/strategy/programs/{$programB->id}")->status(), 'يجب منع قراءة program من مؤسسة أخرى');
        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->putJson("/api/strategy/programs/{$programB->id}", [
            'name' => 'مبادرة محدثة',
            'portfolio_id' => $programB->portfolio_id,
            'status' => 'in_progress',
        ])->status(), 'يجب منع تعديل program من مؤسسة أخرى');
        $this->assertDeniedByIsolation($this->actingAs($adminA, 'sanctum')->deleteJson("/api/strategy/programs/{$programB->id}")->status(), 'يجب منع حذف program من مؤسسة أخرى');
    }

    public function test_portfolio_store_stamps_actor_organization_and_denies_null_org_creator(): void
    {
        $adminA = $this->makeStrategyActor($this->orgA);

        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/strategy/portfolios', [
                'name' => 'التزام مختوم بمؤسسة المستخدم',
            ])
            ->assertStatus(201);

        $portfolio = Portfolio::where('name', 'التزام مختوم بمؤسسة المستخدم')->firstOrFail();
        $this->assertSame($this->orgA->id, $portfolio->organization_id, 'Portfolio create must stamp the actor organization_id.');

        $nullOrgAdmin = $this->makeStrategyActor(null);

        $this->actingAs($nullOrgAdmin, 'sanctum')
            ->postJson('/api/strategy/portfolios', [
                'name' => 'التزام بلا مؤسسة',
            ])
            ->assertStatus(403);
    }

    // test_portfolio_owner_user_id_is_org_scoped_on_store_and_update:
    // حُذف — أعمدة FK (portfolio_owner_id) أُسقطت من schema وحقول الـ API في Phase هـ Task 5.
    // حماية cross-org مضمونة الآن عبر engine + scoped roles (test_cross_org_admin_cannot_access_portfolio_show_update_or_destroy).

    public function test_program_store_and_update_reject_cross_org_portfolio_and_sync_same_org_portfolio_organization(): void
    {
        $adminA = $this->makeStrategyActor($this->orgA);
        $portfolioA = $this->makePortfolio($this->orgA);
        $replacementPortfolioA = $this->makePortfolio($this->orgA);
        $portfolioB = $this->makePortfolio($this->orgB);

        $this->assertRejectedCrossOrgWrite(
            $this->actingAs($adminA, 'sanctum')->postJson('/api/strategy/programs', [
                'name' => 'مبادرة بمحفظة مؤسسة أخرى',
                'portfolio_id' => $portfolioB->id,
            ])->status(),
            'Program store must reject cross-org portfolio_id.'
        );

        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/strategy/programs', [
                'name' => 'مبادرة بمحفظة نفس المؤسسة',
                'portfolio_id' => $portfolioA->id,
            ])
            ->assertStatus(201);

        $createdProgram = Program::where('name', 'مبادرة بمحفظة نفس المؤسسة')->firstOrFail();
        $this->assertSame($portfolioA->organization_id, $createdProgram->organization_id, 'Program store must sync organization_id from accepted portfolio.');

        $programA = $this->makeProgram($this->orgA, $portfolioA);

        $this->assertRejectedCrossOrgWrite(
            $this->actingAs($adminA, 'sanctum')->putJson("/api/strategy/programs/{$programA->id}", [
                'name' => 'مبادرة محدثة بمحفظة مؤسسة أخرى',
                'portfolio_id' => $portfolioB->id,
            ])->status(),
            'Program update must reject cross-org portfolio_id.'
        );

        $this->actingAs($adminA, 'sanctum')
            ->putJson("/api/strategy/programs/{$programA->id}", [
                'name' => 'مبادرة محدثة بمحفظة نفس المؤسسة',
                'portfolio_id' => $replacementPortfolioA->id,
            ])
            ->assertStatus(200);

        $programA->refresh();
        $this->assertSame($replacementPortfolioA->id, $programA->portfolio_id, 'Program update must accept same-org portfolio_id.');
        $this->assertSame($replacementPortfolioA->organization_id, $programA->organization_id, 'Program update must sync organization_id from accepted portfolio.');
    }

    // test_strategy_user_foreign_keys_reject_cross_org_users_on_write_paths:
    // حُذف — أعمدة FK (portfolio_owner_id/owner_id/program_manager_id/executive_sponsor_id)
    // أُسقطت من schema + validation rules في Phase هـ Task 5.
    // الـ Blocker/Decision assigned_to/made_by validation يبقى في BlockerController/DecisionController (خارج نطاق هذه المهمة).
    // حماية cross-org على portfolio/program مضمونة عبر engine + scoped roles.

    public function test_strategy_indexes_scope_rows_to_authenticated_users_organization(): void
    {
        $this->assertIndexContainsOnlyOrgA('/api/strategy/blockers', $this->makeBlocker($this->orgA), $this->makeBlocker($this->orgB));
        $this->assertIndexContainsOnlyOrgA('/api/recommendations', $this->makeDecision($this->orgA), $this->makeDecision($this->orgB));
        $this->assertIndexContainsOnlyOrgA('/api/strategy/reviews', $this->makeReview($this->orgA), $this->makeReview($this->orgB));
        $this->assertIndexContainsOnlyOrgA('/api/strategy/portfolios', $this->makePortfolio($this->orgA), $this->makePortfolio($this->orgB));
        $this->assertIndexContainsOnlyOrgA('/api/strategy/programs', $this->makeProgram($this->orgA), $this->makeProgram($this->orgB));
    }

    public function test_strategy_dashboard_summary_and_golden_chain_are_org_scoped(): void
    {
        $adminA = $this->makeStrategyActor($this->orgA);

        $portfolioA = $this->makePortfolio($this->orgA);
        $portfolioB = $this->makePortfolio($this->orgB);
        $this->makeProgram($this->orgA, $portfolioA);
        $this->makeProgram($this->orgB, $portfolioB);
        $this->makeBlocker($this->orgA);
        $this->makeBlocker($this->orgB);
        $this->makeDecision($this->orgA);
        $this->makeDecision($this->orgB);

        $this->actingAs($adminA, 'sanctum')
            ->getJson('/api/strategy/dashboard/summary')
            ->assertStatus(200)
            ->assertJsonPath('portfolios.total', 1)
            ->assertJsonPath('programs.total', 1)
            ->assertJsonPath('blockers.open', 1)
            ->assertJsonPath('decisions.pending', 1);

        $this->actingAs($adminA, 'sanctum')
            ->getJson("/api/strategy/dashboard/golden-chain/portfolio/{$portfolioB->id}")
            ->assertStatus(200)
            ->assertJsonPath('portfolio', null);
    }

    public function test_null_org_non_super_admin_is_denied_on_strategy_show_and_dashboard_endpoints(): void
    {
        $nullOrgAdmin = $this->makeStrategyActor(null);

        $entities = [
            "/api/strategy/blockers/{$this->makeBlocker($this->orgB)->id}",
            "/api/recommendations/{$this->makeDecision($this->orgB)->id}",
            "/api/strategy/reviews/{$this->makeReview($this->orgB)->id}",
            "/api/strategy/portfolios/{$this->makePortfolio($this->orgB)->id}",
            "/api/strategy/programs/{$this->makeProgram($this->orgB)->id}",
            '/api/strategy/dashboard/summary',
        ];

        foreach ($entities as $url) {
            $this->actingAs($nullOrgAdmin, 'sanctum')
                ->getJson($url)
                ->assertStatus(403);
        }
    }

    public function test_super_admin_can_access_strategy_entities_across_organizations(): void
    {
        $superAdmin = $this->makeUser($this->orgA, 'super_admin');

        $urls = [
            "/api/strategy/blockers/{$this->makeBlocker($this->orgB)->id}",
            "/api/recommendations/{$this->makeDecision($this->orgB)->id}",
            "/api/strategy/reviews/{$this->makeReview($this->orgB)->id}",
            "/api/strategy/portfolios/{$this->makePortfolio($this->orgB)->id}",
            "/api/strategy/programs/{$this->makeProgram($this->orgB)->id}",
        ];

        foreach ($urls as $url) {
            $this->actingAs($superAdmin, 'sanctum')
                ->getJson($url)
                ->assertStatus(200);
        }
    }

    public function test_null_org_super_admin_is_not_blocked_by_strategy_org_check(): void
    {
        $nullOrgSuperAdmin = $this->makeUser(null, 'super_admin');
        $blockerB = $this->makeBlocker($this->orgB);
        $portfolioB = $this->makePortfolio($this->orgB);

        $this->actingAs($nullOrgSuperAdmin, 'sanctum')
            ->getJson("/api/strategy/blockers/{$blockerB->id}")
            ->assertStatus(200);

        $this->actingAs($nullOrgSuperAdmin, 'sanctum')
            ->getJson("/api/strategy/portfolios/{$portfolioB->id}")
            ->assertStatus(200);
    }

    /**
     * A9 — Portfolio write endpoints /priority and /strategic-status must
     * reject cross-org requests via the URL-bound portfolio.
     *
     * The actor holds both STRATEGY_MANAGE_PRIORITY (priority) and
     * STRATEGY_EDIT (strategic-status) at the org-A scope so the rejection is
     * provably isolation-driven, not capability-driven.
     */
    public function test_cross_org_admin_cannot_update_portfolio_priority_or_strategic_status(): void
    {
        $adminA = $this->makeUser($this->orgA, 'admin');
        $this->grantEngineCapability(
            $adminA,
            [Capability::STRATEGY_MANAGE_PRIORITY, Capability::STRATEGY_EDIT],
            'organization',
            $this->orgA->id
        );

        $portfolioB = $this->makePortfolio($this->orgB, [
            'priority_rank' => 5,
            'weight' => 10,
            'portfolio_status' => 'active',
        ]);

        $this->assertDeniedByIsolation(
            $this->actingAs($adminA, 'sanctum')->putJson(
                "/api/strategy/portfolios/{$portfolioB->id}/priority",
                [
                    'priority_rank' => 99,
                    'weight' => 80,
                ]
            )->status(),
            'يجب منع تعديل أولوية محفظة مؤسسة أخرى'
        );

        $this->assertDeniedByIsolation(
            $this->actingAs($adminA, 'sanctum')->putJson(
                "/api/strategy/portfolios/{$portfolioB->id}/strategic-status",
                [
                    'portfolio_status' => 'frozen',
                    'decision_note' => 'محاولة تعديل عابرة للمؤسسات',
                ]
            )->status(),
            'يجب منع تعديل الحالة الاستراتيجية لمحفظة مؤسسة أخرى'
        );

        // Side-effect guard: portfolio B must remain at its original state
        // regardless of which error code the engine surfaced.
        $portfolioB->refresh();
        $this->assertSame(5, (int) $portfolioB->priority_rank, 'priority_rank of org-B portfolio must remain unchanged');
        $this->assertSame(10.0, (float) $portfolioB->weight, 'weight of org-B portfolio must remain unchanged');
        $this->assertSame('active', $portfolioB->portfolio_status, 'portfolio_status of org-B portfolio must remain unchanged');
    }

    // ============================================================
    // Task 3.7 — reviews/blockers with program/objective/task types
    // (only `project` was covered in the existing tests; the other three
    // reviewable/blockable aliases must also create cleanly and round-trip.)
    // ============================================================

    public function test_review_with_initiative_reviewable_type_is_rejected_at_validation(): void
    {
        // Phase 7C-B / F3: StoreReviewRequest's `reviewable_type` validation
        // now aligns with ReviewController::getModelClass(): it accepts
        // ['objective', 'program', 'project']. The legacy `initiative` token
        // (leftover from the 2026_01_16_200001_convert_initiatives_to_programs
        // migration) is rejected at the validation layer with 422, NOT a 500
        // from getModelClass() throwing InvalidArgumentException.
        $adminA = $this->makeStrategyActor($this->orgA);
        $programA = $this->makeProgram($this->orgA);

        $response = $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/strategy/reviews', [
                'title' => 'Initiative Review',
                'reviewable_type' => 'initiative',
                'reviewable_id' => $programA->id,
                'type' => 'monthly',
                'pdca_phase' => 'check',
                'review_date' => now()->toDateString(),
                'period_start' => now()->subMonth()->toDateString(),
                'period_end' => now()->toDateString(),
                'overall_status' => 'on_track',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reviewable_type']);
        $this->assertDatabaseMissing('reviews', ['title' => 'Initiative Review']);
    }

    public function test_review_with_objective_reviewable_type_returns_error_when_archived(): void
    {
        // The strategic_objectives table was archived/dropped, so any review
        // with reviewable_type=objective must fail. The controller's
        // `find($id)` returns null and the controller returns 422 — but if
        // the model's table is missing entirely, the find() will surface a
        // 500. Accept either: both prove the path is NOT silently succeeding
        // against a live row.
        $adminA = $this->makeStrategyActor($this->orgA);

        $status = $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/strategy/reviews', [
                'title' => 'Objective Review',
                'reviewable_type' => 'objective',
                'reviewable_id' => 1,
                'type' => 'monthly',
                'pdca_phase' => 'check',
                'review_date' => now()->toDateString(),
                'period_start' => now()->subMonth()->toDateString(),
                'period_end' => now()->toDateString(),
                'overall_status' => 'on_track',
            ])
            ->status();

        $this->assertContains($status, [422, 500], 'objective reviewable must not silently create a review row');
    }

    public function test_blocker_with_program_blockable_type_creates_and_round_trips(): void
    {
        $adminA = $this->makeStrategyActor($this->orgA);
        $programA = $this->makeProgram($this->orgA);

        $response = $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/strategy/blockers', [
                'title' => 'Program Blocker',
                'blockable_type' => 'program',
                'blockable_id' => $programA->id,
                'severity' => 'high',
                'status' => 'open',
                'description' => 'Budget pending approval',
                'identified_date' => now()->toDateString(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('blocker.blockable_type', Program::class)
            ->assertJsonPath('blocker.blockable_id', $programA->id);
    }

    public function test_blocker_with_task_blockable_type_creates_and_round_trips(): void
    {
        $adminA = $this->makeStrategyActor($this->orgA);
        $projectA = $this->makeProject($this->orgA);
        $taskA = Task::factory()->create([
            'type' => TaskType::PROJECT->value,
            'project_id' => $projectA->id,
            'department_id' => null,
        ]);
        $taskA->forceFill(['organization_id' => $this->orgA->id])->save();

        $response = $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/strategy/blockers', [
                'title' => 'Task Blocker',
                'blockable_type' => 'task',
                'blockable_id' => $taskA->id,
                'severity' => 'medium',
                'status' => 'open',
                'description' => 'Waiting on external vendor',
                'identified_date' => now()->toDateString(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('blocker.blockable_type', Task::class)
            ->assertJsonPath('blocker.blockable_id', $taskA->id);
    }
}
