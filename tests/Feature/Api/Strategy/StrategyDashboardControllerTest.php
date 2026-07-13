<?php

namespace Tests\Feature\Api\Strategy;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);
    }

    // ========================================
    // اختبارات ملخص لوحة التحكم
    // ========================================

    public function test_can_get_dashboard_summary(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/dashboard/summary');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'portfolios' => ['total', 'active', 'avg_progress'],
                'programs' => ['total', 'active', 'avg_progress'],
                'projects' => ['unlinked'],
                'blockers' => ['open', 'critical', 'overdue'],
                'decisions' => ['pending'],
            ]);
    }

    public function test_dashboard_summary_returns_correct_portfolio_counts(): void
    {
        $owner = User::factory()->create();
        Portfolio::factory()->active()->count(3)->create();
        Portfolio::factory()->draft()->count(2)->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/dashboard/summary');

        $response->assertStatus(200);
        $this->assertEquals(5, $response->json('portfolios.total'));
        $this->assertEquals(3, $response->json('portfolios.active'));
    }

    public function test_dashboard_summary_returns_correct_program_counts(): void
    {
        $portfolio = Portfolio::factory()->create();

        Program::factory()->inProgress()->count(2)->create(['portfolio_id' => $portfolio->id]);
        Program::factory()->planning()->count(1)->create(['portfolio_id' => $portfolio->id]);
        Program::factory()->cancelled()->count(3)->create(['portfolio_id' => $portfolio->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/dashboard/summary');

        $response->assertStatus(200);
        $this->assertEquals(6, $response->json('programs.total'));
        $this->assertEquals(3, $response->json('programs.active')); // in_progress + planning
    }

    public function test_dashboard_summary_returns_unlinked_projects_count(): void
    {
        $portfolio = Portfolio::factory()->create();
        $program = Program::factory()->create(['portfolio_id' => $portfolio->id]);

        // مشاريع غير مرتبطة (نشطة)
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

        // مشاريع غير مرتبطة (مكتملة) - لا تُحسب
        Project::factory()->count(1)->create([
            'department_id' => $this->department->id,
            'program_id' => null,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/dashboard/summary');

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('projects.unlinked'));
    }

    public function test_dashboard_summary_calculates_average_progress(): void
    {
        $portfolio1 = Portfolio::factory()->create(['portfolio_progress' => 50]);
        $portfolio2 = Portfolio::factory()->active()->create(['portfolio_progress' => 100]);

        Program::factory()->inProgress()->create([
            'portfolio_id' => $portfolio1->id,
            'progress' => 40,
        ]);
        Program::factory()->inProgress()->create([
            'portfolio_id' => $portfolio2->id,
            'progress' => 80,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/dashboard/summary');

        $response->assertStatus(200);
        $this->assertEquals(60, $response->json('programs.avg_progress'));
    }

    // ========================================
    // اختبارات السلسلة الذهبية
    // ========================================

    public function test_can_get_golden_chain_for_portfolio(): void
    {
        $portfolio = Portfolio::factory()->create([
            'name' => 'التزام تنفيذي',
            'code' => 'PF-TEST-001',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/dashboard/golden-chain/portfolio/{$portfolio->id}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'portfolio' => [
                    'id' => $portfolio->id,
                    'code' => 'PF-TEST-001',
                    'name' => 'التزام تنفيذي',
                    'status' => $portfolio->status,
                ],
            ]);
    }

    public function test_can_get_golden_chain_for_program(): void
    {
        $portfolio = Portfolio::factory()->create(['name' => 'الالتزام']);
        $program = Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'name' => 'المبادرة',
            'code' => 'PRG-TEST-001',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/dashboard/golden-chain/program/{$program->id}");

        $response->assertStatus(200);

        $this->assertNotNull($response->json('portfolio'));
        $this->assertEquals($portfolio->id, $response->json('portfolio.id'));
        $this->assertEquals($program->id, $response->json('program.id'));
    }

    public function test_can_get_golden_chain_for_project(): void
    {
        $portfolio = Portfolio::factory()->create();
        $program = Program::factory()->create(['portfolio_id' => $portfolio->id]);
        $project = Project::factory()->create([
            'program_id' => $program->id,
            'department_id' => $this->department->id,
            'name' => 'المشروع',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/dashboard/golden-chain/project/{$project->id}");

        $response->assertStatus(200);

        $this->assertNotNull($response->json('portfolio'));
        $this->assertNotNull($response->json('program'));
        $this->assertNotNull($response->json('project'));
        $this->assertEquals($portfolio->id, $response->json('portfolio.id'));
        $this->assertEquals($program->id, $response->json('program.id'));
        $this->assertEquals($project->id, $response->json('project.id'));
    }

    public function test_golden_chain_for_unlinked_project(): void
    {
        $project = Project::factory()->create([
            'program_id' => null,
            'department_id' => $this->department->id,
            'name' => 'مشروع غير مرتبط',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/dashboard/golden-chain/project/{$project->id}");

        $response->assertStatus(200);

        $this->assertNull($response->json('portfolio'));
        $this->assertNull($response->json('program'));
        $this->assertNotNull($response->json('project'));
        $this->assertEquals($project->id, $response->json('project.id'));
    }

    public function test_golden_chain_for_nonexistent_entity(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/strategy/dashboard/golden-chain/portfolio/99999');

        $response->assertStatus(200);
        $this->assertNull($response->json('portfolio'));
    }

    // ========================================
    // اختبارات التوافق الخلفي للسلسلة الذهبية
    // ========================================

    public function test_golden_chain_direction_alias_works(): void
    {
        $portfolio = Portfolio::factory()->create(['name' => 'التزام']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/dashboard/golden-chain/direction/{$portfolio->id}");

        $response->assertStatus(200);
        $this->assertEquals($portfolio->id, $response->json('portfolio.id'));
    }

    public function test_golden_chain_initiative_alias_works(): void
    {
        $portfolio = Portfolio::factory()->create();
        $program = Program::factory()->create(['portfolio_id' => $portfolio->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/strategy/dashboard/golden-chain/initiative/{$program->id}");

        $response->assertStatus(200);
        $this->assertEquals($program->id, $response->json('program.id'));
    }

    // ========================================
    // اختبارات الأمان
    // ========================================

    public function test_unauthenticated_cannot_access_dashboard(): void
    {
        $response = $this->getJson('/api/strategy/dashboard/summary');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_access_golden_chain(): void
    {
        $portfolio = Portfolio::factory()->create();

        $response = $this->getJson("/api/strategy/dashboard/golden-chain/portfolio/{$portfolio->id}");
        $response->assertStatus(401);
    }
}
