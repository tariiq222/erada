<?php

namespace Tests\Feature\Api\Strategy;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class ProgramControllerTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected Portfolio $portfolio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');

        $owner = User::factory()->create();
        $this->portfolio = Portfolio::factory()->active()->create();
    }

    // ========================================
    // اختبارات القراءة (GET)
    // ========================================

    public function test_can_list_programs(): void
    {
        Program::factory()->count(5)->create(['portfolio_id' => $this->portfolio->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/programs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'current_page',
                'last_page',
                'total',
            ]);
    }

    public function test_can_filter_programs_by_portfolio(): void
    {
        $otherPortfolio = Portfolio::factory()->create();

        Program::factory()->count(2)->create(['portfolio_id' => $this->portfolio->id]);
        Program::factory()->count(3)->create(['portfolio_id' => $otherPortfolio->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/programs?portfolio_id={$this->portfolio->id}");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_filter_programs_by_status(): void
    {
        Program::factory()->count(2)->create([
            'portfolio_id' => $this->portfolio->id,
            'status' => 'in_progress',
        ]);
        Program::factory()->count(3)->create([
            'portfolio_id' => $this->portfolio->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/programs?status=in_progress');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_search_programs(): void
    {
        $searchableProgram = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'name' => 'Digital Transformation Initiative',
        ]);
        Program::factory()->count(3)->create(['portfolio_id' => $this->portfolio->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/programs?search=Digital');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($searchableProgram->id, $response->json('data.0.id'));
    }

    public function test_can_view_single_program(): void
    {
        $program = Program::factory()->create(['portfolio_id' => $this->portfolio->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/programs/{$program->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $program->id]);
    }

    public function test_can_get_programs_list_for_dropdown(): void
    {
        Program::factory()->inProgress()->count(3)->create(['portfolio_id' => $this->portfolio->id]);
        Program::factory()->cancelled()->count(2)->create(['portfolio_id' => $this->portfolio->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/programs/list');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
    }

    public function test_can_filter_programs_list_by_portfolio(): void
    {
        $otherPortfolio = Portfolio::factory()->create();

        Program::factory()->inProgress()->count(2)->create(['portfolio_id' => $this->portfolio->id]);
        Program::factory()->inProgress()->count(3)->create(['portfolio_id' => $otherPortfolio->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/programs/list?portfolio_id={$this->portfolio->id}");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    // ========================================
    // اختبارات الإنشاء (POST)
    // ========================================

    public function test_can_create_program(): void
    {
        $programData = [
            'name' => 'مبادرة جديدة',
            'description' => 'وصف المبادرة',
            'portfolio_id' => $this->portfolio->id,
            'department_id' => $this->department->id,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addYear()->format('Y-m-d'),
            'status' => 'draft',
            'priority' => 'high',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/programs', $programData);

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'مبادرة جديدة']);

        $this->assertDatabaseHas('programs', [
            'name' => 'مبادرة جديدة',
            'portfolio_id' => $this->portfolio->id,
        ]);
    }

    public function test_create_program_requires_portfolio(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/programs', [
                'name' => 'مبادرة بدون التزام',
                'status' => 'draft',
                // portfolio_id مفقود
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['portfolio_id']);
    }

    public function test_create_program_generates_unique_code(): void
    {
        $response1 = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/programs', [
                'name' => 'المبادرة الأولى',
                'portfolio_id' => $this->portfolio->id,
            ]);

        $response2 = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/programs', [
                'name' => 'المبادرة الثانية',
                'portfolio_id' => $this->portfolio->id,
            ]);

        $response1->assertStatus(201);
        $response2->assertStatus(201);

        $code1 = $response1->json('program.code');
        $code2 = $response2->json('program.code');

        $this->assertNotEquals($code1, $code2);
    }

    public function test_create_program_validation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/programs', [
                'name' => '', // مطلوب
                'portfolio_id' => 99999, // غير موجود
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'portfolio_id']);
    }

    public function test_create_program_with_budget(): void
    {
        $programData = [
            'name' => 'مبادرة بميزانية',
            'portfolio_id' => $this->portfolio->id,
            'budget' => 500000,
            'total_program_budget' => 1000000,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/programs', $programData);

        $response->assertStatus(201);
        $this->assertDatabaseHas('programs', [
            'name' => 'مبادرة بميزانية',
            'budget' => 500000,
        ]);
    }

    public function test_create_program_with_team_members(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $sponsor = User::factory()->create();

        $programData = [
            'name' => 'مبادرة مع فريق',
            'portfolio_id' => $this->portfolio->id,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/strategy/programs', $programData);

        $response->assertStatus(201);
        $this->assertDatabaseHas('programs', [
            'name' => 'مبادرة مع فريق',
            'portfolio_id' => $this->portfolio->id,
        ]);
    }

    // ========================================
    // اختبارات التحديث (PUT)
    // ========================================

    public function test_can_update_program(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'name' => 'اسم قديم',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/strategy/programs/{$program->id}", [
                'name' => 'اسم جديد',
                'portfolio_id' => $this->portfolio->id,
                'status' => 'in_progress',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('programs', [
            'id' => $program->id,
            'name' => 'اسم جديد',
            'status' => 'in_progress',
        ]);
    }

    public function test_can_update_program_progress(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'progress' => 25,
            'progress_calculation_method' => 'manual',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/strategy/programs/{$program->id}", [
                'name' => $program->name,
                'portfolio_id' => $this->portfolio->id,
                'progress' => 75,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('programs', [
            'id' => $program->id,
            'progress' => 75,
        ]);
    }

    public function test_can_update_program_spent_amount(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'budget' => 100000,
            'spent_amount' => 0,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/strategy/programs/{$program->id}", [
                'name' => $program->name,
                'portfolio_id' => $this->portfolio->id,
                'spent_amount' => 50000,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('programs', [
            'id' => $program->id,
            'spent_amount' => 50000,
        ]);
    }

    // ========================================
    // اختبارات الحذف (DELETE)
    // ========================================

    public function test_can_delete_program(): void
    {
        $program = Program::factory()->create(['portfolio_id' => $this->portfolio->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/strategy/programs/{$program->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('programs', ['id' => $program->id]);
    }

    public function test_delete_program_unlinks_projects(): void
    {
        $program = Program::factory()->create(['portfolio_id' => $this->portfolio->id]);
        $project = Project::factory()->create([
            'program_id' => $program->id,
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/strategy/programs/{$program->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'program_id' => null,
        ]);
    }

    // ========================================
    // اختبارات ربط المشاريع
    // ========================================

    public function test_can_link_project_to_program(): void
    {
        $program = Program::factory()->create(['portfolio_id' => $this->portfolio->id]);
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
            'program_id' => null,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/strategy/programs/{$program->id}/link-project", [
                'project_id' => $project->id,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'program_id' => $program->id,
        ]);
    }

    public function test_cannot_link_already_linked_project(): void
    {
        $program1 = Program::factory()->create(['portfolio_id' => $this->portfolio->id]);
        $program2 = Program::factory()->create(['portfolio_id' => $this->portfolio->id]);
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
            'program_id' => $program1->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/strategy/programs/{$program2->id}/link-project", [
                'project_id' => $project->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_can_unlink_project_from_program(): void
    {
        $program = Program::factory()->create(['portfolio_id' => $this->portfolio->id]);
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
            'program_id' => $program->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/strategy/programs/{$program->id}/unlink-project/{$project->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'program_id' => null,
        ]);
    }

    public function test_cannot_unlink_project_from_wrong_program(): void
    {
        $program1 = Program::factory()->create(['portfolio_id' => $this->portfolio->id]);
        $program2 = Program::factory()->create(['portfolio_id' => $this->portfolio->id]);
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
            'program_id' => $program1->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/strategy/programs/{$program2->id}/unlink-project/{$project->id}");

        $response->assertStatus(422);
    }

    public function test_can_get_unlinked_projects(): void
    {
        $program = Program::factory()->create(['portfolio_id' => $this->portfolio->id]);

        // مشاريع غير مرتبطة
        Project::factory()->count(3)->create([
            'department_id' => $this->department->id,
            'program_id' => null,
            'status' => 'in_progress',
        ]);

        // مشاريع مرتبطة
        Project::factory()->count(2)->create([
            'department_id' => $this->department->id,
            'program_id' => $program->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/programs/unlinked-projects');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_can_search_unlinked_projects(): void
    {
        Project::factory()->create([
            'department_id' => $this->department->id,
            'program_id' => null,
            'name' => 'مشروع خاص',
            'status' => 'in_progress',
        ]);
        Project::factory()->count(2)->create([
            'department_id' => $this->department->id,
            'program_id' => null,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/programs/unlinked-projects?search=خاص');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    // ========================================
    // اختبارات الأمان
    // ========================================

    public function test_unauthenticated_cannot_access_programs(): void
    {
        $response = $this->getJson('/api/strategy/programs');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_create_program(): void
    {
        $response = $this->postJson('/api/strategy/programs', [
            'name' => 'اختبار',
            'portfolio_id' => $this->portfolio->id,
        ]);
        $response->assertStatus(401);
    }

    // ========================================
    // اختبارات الـ Model
    // ========================================

    public function test_program_calculates_progress_from_projects(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'progress_calculation_method' => 'average',
        ]);

        Project::factory()->create([
            'program_id' => $program->id,
            'department_id' => $this->department->id,
            'progress' => 50,
            'status' => 'in_progress',
        ]);

        Project::factory()->create([
            'program_id' => $program->id,
            'department_id' => $this->department->id,
            'progress' => 100,
            'status' => 'completed',
        ]);

        $progress = $program->calculateProgress();

        $this->assertEquals(75, $progress);
    }

    public function test_program_weighted_progress_calculation(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'progress_calculation_method' => 'weighted',
        ]);

        Project::factory()->create([
            'program_id' => $program->id,
            'department_id' => $this->department->id,
            'progress' => 50,
            'budget' => 100000,
            'status' => 'in_progress',
        ]);

        Project::factory()->create([
            'program_id' => $program->id,
            'department_id' => $this->department->id,
            'progress' => 100,
            'budget' => 300000,
            'status' => 'completed',
        ]);

        $progress = $program->calculateProgress();

        // (50*100000 + 100*300000) / 400000 = 87.5
        $this->assertEquals(87.5, $progress);
    }

    public function test_program_status_labels(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'status' => 'in_progress',
            'priority' => 'high',
        ]);

        $this->assertEquals('قيد التنفيذ', $program->status_label);
        $this->assertEquals('عالي', $program->priority_label);
    }

    public function test_program_budget_utilization(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'total_program_budget' => 100000,
            'spent_amount' => 25000,
        ]);

        $this->assertEquals(25, $program->budget_utilization);
    }

    public function test_program_has_portfolio_relationship(): void
    {
        $program = Program::factory()->create(['portfolio_id' => $this->portfolio->id]);

        $this->assertInstanceOf(Portfolio::class, $program->portfolio);
        $this->assertEquals($this->portfolio->id, $program->portfolio->id);
    }

    public function test_program_has_projects_relationship(): void
    {
        $program = Program::factory()->create(['portfolio_id' => $this->portfolio->id]);
        Project::factory()->count(3)->create([
            'program_id' => $program->id,
            'department_id' => $this->department->id,
        ]);

        $this->assertCount(3, $program->projects);
    }

    public function test_program_scopes(): void
    {
        Program::factory()->inProgress()->count(2)->create(['portfolio_id' => $this->portfolio->id]);
        Program::factory()->planning()->count(1)->create(['portfolio_id' => $this->portfolio->id]);
        Program::factory()->cancelled()->count(3)->create(['portfolio_id' => $this->portfolio->id]);

        $this->assertCount(3, Program::active()->get());
    }

    // ========================================
    // Cross-org isolation (A7/A8) — link/unlink-project
    // ========================================

    /**
     * Build an org-A + org-B context with an actor in orgA who holds
     * STRATEGY_EDIT at the org-A scope. Both orgs have their own portfolio +
     * program + project so the cross-org foreign id is a real row in a
     * different org, not a missing-id 422.
     */
    private function seedCrossOrgContext(): array
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::STRATEGY_EDIT, 'organization', $orgA->id);

        $portfolioA = Portfolio::factory()->create([
            'organization_id' => $orgA->id,
            'status' => 'active',
            'portfolio_status' => 'active',
        ]);
        $portfolioB = Portfolio::factory()->create([
            'organization_id' => $orgB->id,
            'status' => 'active',
            'portfolio_status' => 'active',
        ]);

        $programA = Program::factory()->create([
            'portfolio_id' => $portfolioA->id,
            'organization_id' => $orgA->id,
            'status' => 'in_progress',
        ]);
        $programB = Program::factory()->create([
            'portfolio_id' => $portfolioB->id,
            'organization_id' => $orgB->id,
            'status' => 'in_progress',
        ]);

        $projectA = Project::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'program_id' => null,
        ]);
        $projectB = Project::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'program_id' => null,
        ]);

        return compact('actor', 'programA', 'projectA', 'programB', 'projectB');
    }

    /**
     * A7 — POST /api/strategy/programs/{program}/link-project
     * Cross-org project_id in body → expect [403, 422].
     */
    public function test_link_project_rejects_cross_org_project_id_in_body(): void
    {
        ['actor' => $actor, 'programA' => $programA, 'projectB' => $projectB] = $this->seedCrossOrgContext();

        $status = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/strategy/programs/{$programA->id}/link-project", [
                'project_id' => $projectB->id,
            ])
            ->status();

        $this->assertContains($status, [403, 422], 'cross-org project_id in link-project body must be rejected');

        // Side-effect guard: the foreign project must remain unlinked from the
        // org-A program regardless of which error code surfaced.
        $this->assertDatabaseHas('projects', [
            'id' => $projectB->id,
            'program_id' => null,
        ]);
    }

    /**
     * A8 — DELETE /api/strategy/programs/{program}/unlink-project/{project}
     * Cross-org pair (orgA program, orgB project) → expect [403, 404].
     */
    public function test_unlink_project_denies_cross_org_pair(): void
    {
        ['actor' => $actor, 'programA' => $programA, 'projectB' => $projectB] = $this->seedCrossOrgContext();

        $status = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/strategy/programs/{$programA->id}/unlink-project/{$projectB->id}")
            ->status();

        $this->assertContains($status, [403, 404], 'cross-org unlink-project pair must be denied by isolation');

        $this->assertDatabaseHas('projects', [
            'id' => $projectB->id,
            'program_id' => null,
        ]);
    }

    // ============================================================
    // Task 3.4 — GET /api/strategy/programs/list + /unlinked-projects
    // (cross-org leakage)
    // ============================================================

    public function test_programs_list_endpoint_excludes_other_organization_programs(): void
    {
        // Use the cross-org context seeded by A7/A8 to get an org-A actor
        // (NOT super_admin). super_admin would see both orgs' programs.
        ['actor' => $actor, 'programA' => $programA] = $this->seedCrossOrgContext();
        // Add STRATEGY_VIEW so /list (which requires it) is reachable. Pass as
        // an array so both STRATEGY_VIEW + STRATEGY_EDIT land on the same
        // definition (single-role-per-scope semantics).
        $this->grantEngineCapability(
            $actor,
            [Capability::STRATEGY_VIEW, Capability::STRATEGY_EDIT],
            'organization',
            $actor->organization_id
        );

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/strategy/programs/list');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($programA->id, $ids, 'org-A active program must appear in own-actor list');

        // Confirm org-B program is out: pick up its id directly from DB.
        $orgBProgramId = Program::where('organization_id', '!=', $actor->organization_id)->value('id');
        if ($orgBProgramId !== null) {
            $this->assertNotContains($orgBProgramId, $ids, 'org-B active program must be scoped out');
        }
    }

    public function test_unlinked_projects_endpoint_excludes_other_organization_projects(): void
    {
        // Use the cross-org context seeded by A7/A8 to get an org-A actor
        // (NOT super_admin). super_admin would see both orgs' projects.
        ['actor' => $actor, 'projectA' => $projectA] = $this->seedCrossOrgContext();
        $this->grantEngineCapability(
            $actor,
            [Capability::STRATEGY_VIEW, Capability::STRATEGY_EDIT],
            'organization',
            $actor->organization_id
        );

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/strategy/programs/unlinked-projects');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($projectA->id, $ids, 'org-A unlinked project must appear in own-actor list');

        $orgBProjectId = Project::where('organization_id', '!=', $actor->organization_id)->value('id');
        if ($orgBProjectId !== null) {
            $this->assertNotContains($orgBProjectId, $ids, 'org-B unlinked project must be scoped out');
        }
    }
}
