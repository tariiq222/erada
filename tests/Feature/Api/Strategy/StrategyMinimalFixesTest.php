<?php

namespace Tests\Feature\Api\Strategy;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Blocker;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 7C-B — Strategy minimal production fixes.
 *
 * Covers:
 *   - F3  StoreReviewRequest rejects 'initiative' (legacy token). Accepts 'program'.
 *   - F6  Cannot create a Strategy record (Portfolio / Program / Blocker / Review)
 *        whose organization_id resolves to null. Validation-style 422.
 *   - F12 ProgramController::unlinkProject target-bound on STRATEGY_EDIT.
 *   - F13 EscalateBlockerRequest target-bound on STRATEGY_EDIT.
 *
 * The existing suite (ProgramControllerTest, BlockerControllerTest,
 * ReviewControllerTest, StrategyOrganizationScopeTest,
 * StrategyPermissionEnforcementTest, ProgramShowDoesNotReferenceLegacyDecisionsTest)
 * covers the regression surface.
 */
class StrategyMinimalFixesTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ============================================================
    // F3 — reviewable_type allowlist (program accepted, initiative rejected)
    // ============================================================

    public function test_review_accepts_program_reviewable_type(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        $portfolio = Portfolio::factory()->create(['organization_id' => $org->id]);
        $program = Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $org->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/strategy/reviews', [
                'title' => 'مراجعة على مبادرة',
                'reviewable_type' => 'program',
                'reviewable_id' => $program->id,
                'type' => 'monthly',
                'pdca_phase' => 'check',
                'review_date' => now()->toDateString(),
                'period_start' => now()->subMonth()->toDateString(),
                'period_end' => now()->toDateString(),
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('reviews', [
            'title' => 'مراجعة على مبادرة',
            'reviewable_type' => Program::class,
            'reviewable_id' => $program->id,
        ]);
    }

    public function test_review_rejects_legacy_initiative_reviewable_type_with_422(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        $portfolio = Portfolio::factory()->create(['organization_id' => $org->id]);
        $program = Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $org->id,
        ]);

        // Old pre-2026_01_16 token. The prior rule accepted it (then the
        // controller threw InvalidArgumentException → 500). New rule rejects
        // it at the validation layer with 422.
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/strategy/reviews', [
                'title' => 'مراجعة',
                'reviewable_type' => 'initiative',
                'reviewable_id' => $program->id,
                'type' => 'monthly',
                'pdca_phase' => 'check',
                'review_date' => now()->toDateString(),
                'period_start' => now()->subMonth()->toDateString(),
                'period_end' => now()->toDateString(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reviewable_type']);
        $this->assertDatabaseMissing('reviews', ['title' => 'مراجعة']);
    }

    // ============================================================
    // F6 — Orphan organization_id rejection (Portfolio / Program / Blocker / Review)
    // ============================================================

    public function test_superadmin_without_org_cannot_create_orphan_portfolio(): void
    {
        // A super_admin without an organization_id who also omits the
        // organization_id from the payload would otherwise land on
        // forceFill(['organization_id' => null]) and produce an invisible
        // row. The new guard rejects that with 422.
        $orphanSuperAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $orphanSuperAdmin->assignRole('super_admin');

        $response = $this->actingAs($orphanSuperAdmin, 'sanctum')
            ->postJson('/api/strategy/portfolios', [
                'name' => 'محفظة يتيمة',
                // intentionally no organization_id
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('portfolios', ['name' => 'محفظة يتيمة']);
    }

    public function test_program_cannot_be_created_against_orphan_portfolio(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        // An orphan portfolio — organization_id is null. The new guard
        // refuses to create a program that would inherit null.
        $orphanPortfolio = Portfolio::factory()->create([
            'organization_id' => null,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/strategy/programs', [
                'name' => 'مبادرة يتيمة',
                'portfolio_id' => $orphanPortfolio->id,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('programs', ['name' => 'مبادرة يتيمة']);
    }

    public function test_blocker_cannot_be_created_against_orphan_parent(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        // ProjectObserver::saving auto-corrects project.organization_id from
        // its department's organization_id; bypass the correction by leaving
        // department_id null so the orphan record stays null.
        $orphanProject = Project::factory()->create([
            'organization_id' => null,
            'department_id' => null,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/strategy/blockers', [
                'title' => 'تعثر يتيم',
                'blockable_type' => 'project',
                'blockable_id' => $orphanProject->id,
                'severity' => 'medium',
                'identified_date' => now()->toDateString(),
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('blockers', ['title' => 'تعثر يتيم']);
    }

    public function test_review_cannot_be_created_against_orphan_parent(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        $orphanProject = Project::factory()->create([
            'organization_id' => null,
            'department_id' => null,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/strategy/reviews', [
                'title' => 'مراجعة يتيمة',
                'reviewable_type' => 'project',
                'reviewable_id' => $orphanProject->id,
                'type' => 'monthly',
                'pdca_phase' => 'check',
                'review_date' => now()->toDateString(),
                'period_start' => now()->subMonth()->toDateString(),
                'period_end' => now()->toDateString(),
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('reviews', ['title' => 'مراجعة يتيمة']);
    }

    // ============================================================
    // F12 — unlinkProject target-bound (STRATEGY_EDIT on the program)
    // ============================================================

    public function test_unlink_project_denies_user_without_strategy_edit_capability(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $portfolio = Portfolio::factory()->create(['organization_id' => $org->id]);
        $program = Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $org->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'program_id' => $program->id,
        ]);

        // Plain user, no engine grant. Must be denied because the new
        // target-bound check evaluates STRATEGY_EDIT on the program and
        // the user has nothing.
        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/strategy/programs/{$program->id}/unlink-project/{$project->id}");

        // 403 because the actor is authenticated but the engine rejects
        // the target-bound check.
        $this->assertContains($response->status(), [403], 'unauthorized actor must be denied');
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'program_id' => $program->id,
        ]);
    }

    public function test_unlink_project_denies_cross_org_pair(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $portfolioA = Portfolio::factory()->create(['organization_id' => $orgA->id]);
        $programA = Program::factory()->create([
            'portfolio_id' => $portfolioA->id,
            'organization_id' => $orgA->id,
        ]);
        // Project in orgB with its own dept in orgB so
        // ProjectObserver::saving leaves organization_id alone in orgB.
        $projectB = Project::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'program_id' => null,
        ]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'is_active' => true,
        ]);
        // Engine grant at the org-A scope; the target project is in org-B.
        $this->grantEngineCapability($actor, Capability::STRATEGY_EDIT, 'organization', $orgA->id);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/strategy/programs/{$programA->id}/unlink-project/{$projectB->id}");

        // The org check fails first → 403 (or 404 via ModelNotFoundException
        // path; both deny). The pair must NOT mutate.
        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseHas('projects', [
            'id' => $projectB->id,
            'program_id' => null,
        ]);
    }

    public function test_superadmin_can_unlink_project_within_own_org(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $portfolio = Portfolio::factory()->create(['organization_id' => $org->id]);
        $program = Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $org->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'program_id' => $program->id,
        ]);

        $superAdmin = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/strategy/programs/{$program->id}/unlink-project/{$project->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'program_id' => null,
        ]);
    }

    // ============================================================
    // F13 — escalate target-bound (STRATEGY_EDIT on the blocker)
    // ============================================================

    private function makeBlockerFor(User $reporter, Project $project, ?int $orgId): Blocker
    {
        return Blocker::create([
            'title' => 'تعثر اختبار',
            'blockable_type' => Project::class,
            'blockable_id' => $project->id,
            'organization_id' => $orgId,
            'severity' => 'medium',
            'status' => 'open',
            'reported_by' => $reporter->id,
            'identified_date' => now()->toDateString(),
        ]);
    }

    public function test_escalate_blocker_requires_strategy_edit_on_blocker(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $reporter = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $reporter->assignRole('super_admin');

        $blocker = $this->makeBlockerFor($reporter, $project, $org->id);

        // Same-org actor with NO engine grant. EscalateBlockerRequest's
        // authorize() must reject them.
        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/strategy/blockers/{$blocker->id}/escalate");

        $response->assertStatus(403);
    }

    public function test_escalate_blocker_allows_same_org_strategy_edit_grant(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $reporter = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $reporter->assignRole('super_admin');

        $blocker = $this->makeBlockerFor($reporter, $project, $org->id);

        $actor = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::STRATEGY_EDIT, 'organization', $org->id);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/strategy/blockers/{$blocker->id}/escalate");

        $response->assertStatus(200);
        $this->assertDatabaseHas('blockers', [
            'id' => $blocker->id,
            'status' => 'escalated',
        ]);
    }

    public function test_escalate_blocker_denies_null_org_actor(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $reporter = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $reporter->assignRole('super_admin');

        $blocker = $this->makeBlockerFor($reporter, $project, $org->id);

        // Actor with no organization_id at all. Even if they hold the
        // capability at the global scope, they cannot satisfy
        // AccessDecision::can() against an org-bound blocker. Authz must 403.
        $nullOrgActor = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($nullOrgActor, 'sanctum')
            ->postJson("/api/strategy/blockers/{$blocker->id}/escalate");

        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseHas('blockers', [
            'id' => $blocker->id,
            'status' => 'open',
        ]);
    }

    public function test_superadmin_can_escalate_blocker(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $superAdmin = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $blocker = $this->makeBlockerFor($superAdmin, $project, $org->id);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/strategy/blockers/{$blocker->id}/escalate");

        $response->assertStatus(200);
        $this->assertDatabaseHas('blockers', [
            'id' => $blocker->id,
            'status' => 'escalated',
        ]);
    }
}
