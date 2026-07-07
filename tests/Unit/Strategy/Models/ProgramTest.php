<?php

namespace Tests\Unit\Strategy\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Blocker;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Models\Review;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected User $creator;

    protected Portfolio $portfolio;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->creator = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);
        $this->portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);
        $this->department = Department::factory()->create([
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_program_code_is_auto_generated_when_blank(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'department_id' => $this->department->id,
            'organization_id' => $this->org->id,
            'code' => null,
        ]);

        $this->assertNotNull($program->code);
        $this->assertMatchesRegularExpression('/^PRG-\d{4}-\d{3}$/', $program->code);
    }

    public function test_program_keeps_explicit_code_when_provided(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'code' => 'PRG-2026-CUSTOM',
        ]);

        $this->assertSame('PRG-2026-CUSTOM', $program->fresh()->code);
    }

    public function test_generate_code_uses_year_prefix_and_increments(): void
    {
        $year = date('Y');

        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'code' => "PRG-{$year}-009",
        ]);

        $this->assertSame("PRG-{$year}-010", Program::generateCode());
    }

    public function test_generate_code_starts_at_001_when_no_prior_codes(): void
    {
        $this->assertSame('PRG-'.date('Y').'-001', Program::generateCode());
    }

    public function test_belongs_to_portfolio(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
        ]);

        $this->assertInstanceOf(Portfolio::class, $program->portfolio);
        $this->assertSame($this->portfolio->id, $program->portfolio->id);
    }

    public function test_belongs_to_department(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'department_id' => $this->department->id,
            'organization_id' => $this->org->id,
        ]);

        $this->assertInstanceOf(Department::class, $program->department);
        $this->assertSame($this->department->id, $program->department->id);
    }

    public function test_belongs_to_creator(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'created_by' => $this->creator->id,
        ]);

        $this->assertInstanceOf(User::class, $program->creator);
        $this->assertSame($this->creator->id, $program->creator->id);
    }

    public function test_has_many_projects_returns_linked_projects(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
        ]);

        $project = Project::factory()->create([
            'program_id' => $program->id,
            'organization_id' => $this->org->id,
        ]);

        $this->assertCount(1, $program->projects);
        $this->assertSame($project->id, $program->projects->first()->id);
    }

    public function test_morph_many_blockers_returns_linked_blockers(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
        ]);

        $blocker = Blocker::create([
            'title' => 'تأخر في التوريد',
            'blockable_type' => Program::class,
            'blockable_id' => $program->id,
            'severity' => 'high',
            'status' => 'open',
            'identified_date' => now()->toDateString(),
        ]);

        $this->assertCount(1, $program->blockers);
        $this->assertSame($blocker->id, $program->blockers->first()->id);
    }

    public function test_open_blockers_returns_only_non_resolved(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
        ]);

        Blocker::create([
            'title' => 'open blocker',
            'blockable_type' => Program::class,
            'blockable_id' => $program->id,
            'severity' => 'high',
            'status' => 'open',
            'identified_date' => now()->toDateString(),
        ]);
        Blocker::create([
            'title' => 'in_progress blocker',
            'blockable_type' => Program::class,
            'blockable_id' => $program->id,
            'severity' => 'medium',
            'status' => 'in_progress',
            'identified_date' => now()->toDateString(),
        ]);
        Blocker::create([
            'title' => 'escalated blocker',
            'blockable_type' => Program::class,
            'blockable_id' => $program->id,
            'severity' => 'critical',
            'status' => 'escalated',
            'identified_date' => now()->toDateString(),
        ]);
        Blocker::create([
            'title' => 'resolved blocker',
            'blockable_type' => Program::class,
            'blockable_id' => $program->id,
            'severity' => 'low',
            'status' => 'resolved',
            'identified_date' => now()->toDateString(),
        ]);

        $statuses = $program->openBlockers->pluck('status')->all();

        sort($statuses);
        $this->assertSame(['escalated', 'in_progress', 'open'], $statuses);
    }

    public function test_morph_many_recommendations_returns_linked_recommendations(): void
    {
        // Direction B (commit f98adef5): rulings previously loaded onto the
        // standalone `decisions` table through Program->decisions() now live
        // on Recommendation (kind=ruling) via Program->recommendations().
        // Pin the polymorphic shape so a regression in the morph declaration
        // surfaces here.
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
        ]);

        $meeting = Meeting::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->department->id,
        ]);

        $recommendation = Recommendation::create([
            'kind' => Recommendation::KIND_RULING,
            'meeting_id' => $meeting->id,
            'decidable_type' => Program::class,
            'decidable_id' => $program->id,
            'type' => 'approval',
            'title' => 'اعتماد خطة البرنامج',
            'status' => Recommendation::STATUS_PENDING,
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'organization_id' => $this->org->id,
        ]);

        $this->assertCount(1, $program->recommendations);
        $this->assertSame($recommendation->id, $program->recommendations->first()->id);
    }

    public function test_morph_many_reviews_returns_linked_reviews(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
        ]);

        $review = Review::create([
            'title' => 'مراجعة ربع سنوية',
            'reviewable_type' => Program::class,
            'reviewable_id' => $program->id,
            'type' => 'quarterly',
            'pdca_phase' => 'check',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonths(3)->toDateString(),
            'period_end' => now()->toDateString(),
            'conducted_by' => $this->creator->id,
        ]);

        $this->assertCount(1, $program->reviews);
        $this->assertSame($review->id, $program->reviews->first()->id);
    }

    public function test_calculate_progress_returns_stored_progress_when_method_is_manual(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'progress' => 42.5,
            'progress_calculation_method' => 'manual',
        ]);

        $this->assertSame(42.5, $program->calculateProgress());
    }

    public function test_calculate_progress_weighted_uses_project_budget_as_weight(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'progress_calculation_method' => 'weighted',
        ]);

        // big-budget project at 100% and small-budget project at 0% ⇒ ~80%
        Project::factory()->create([
            'program_id' => $program->id,
            'organization_id' => $this->org->id,
            'progress' => 100,
            'budget' => 800000,
            'status' => 'in_progress',
        ]);
        Project::factory()->create([
            'program_id' => $program->id,
            'organization_id' => $this->org->id,
            'progress' => 0,
            'budget' => 200000,
            'status' => 'in_progress',
        ]);

        $this->assertSame(80.0, $program->fresh()->calculateProgress());
    }

    public function test_calculate_progress_average_returns_simple_mean(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'progress_calculation_method' => 'average',
        ]);

        Project::factory()->create([
            'program_id' => $program->id,
            'organization_id' => $this->org->id,
            'progress' => 60,
            'status' => 'in_progress',
        ]);
        Project::factory()->create([
            'program_id' => $program->id,
            'organization_id' => $this->org->id,
            'progress' => 100,
            'status' => 'in_progress',
        ]);

        $this->assertSame(80.0, $program->fresh()->calculateProgress());
    }

    public function test_calculate_progress_excludes_cancelled_projects(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'progress_calculation_method' => 'average',
        ]);

        Project::factory()->create([
            'program_id' => $program->id,
            'organization_id' => $this->org->id,
            'progress' => 100,
            'status' => 'in_progress',
        ]);
        Project::factory()->create([
            'program_id' => $program->id,
            'organization_id' => $this->org->id,
            'progress' => 0,
            'status' => 'cancelled',
        ]);

        $this->assertSame(100.0, $program->fresh()->calculateProgress());
    }

    public function test_calculate_progress_falls_back_to_stored_when_no_projects(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'progress' => 33.0,
            'progress_calculation_method' => 'weighted',
        ]);

        $this->assertSame(33.0, $program->calculateProgress());
    }

    public function test_update_progress_persists_calculated_value(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'progress_calculation_method' => 'manual',
            'progress' => 0,
        ]);

        $program->progress = 67.89;
        $program->updateProgress();

        $fresh = $program->fresh();
        $this->assertSame('67.89', (string) $fresh->progress);
    }

    public function test_budget_utilization_uses_total_program_budget_when_set(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'budget' => 100000,
            'total_program_budget' => 250000,
            'spent_amount' => 50000,
        ]);

        // 50000 / 250000 = 20%
        $this->assertSame(20.0, $program->budget_utilization);
    }

    public function test_budget_utilization_falls_back_to_budget_when_total_unset(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'budget' => 100000,
            'total_program_budget' => null,
            'spent_amount' => 25000,
        ]);

        $this->assertSame(25.0, $program->budget_utilization);
    }

    public function test_budget_utilization_is_zero_when_budget_is_zero(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'budget' => 0,
            'total_program_budget' => 0,
            'spent_amount' => 0,
        ]);

        $this->assertSame(0.0, $program->budget_utilization);
    }

    public function test_status_label_attribute_returns_arabic_label(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'in_progress',
        ]);

        $this->assertSame('قيد التنفيذ', $program->status_label);
    }

    public function test_status_label_attribute_returns_default_when_unknown(): void
    {
        // status is constrained by CHECK; build in-memory to test the fallback.
        $program = new Program(['status' => 'not_a_real_status']);

        $this->assertSame('غير محدد', $program->status_label);
    }

    public function test_priority_label_attribute_returns_arabic_label(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'priority' => 'critical',
        ]);

        $this->assertSame('حرج', $program->priority_label);
    }

    public function test_priority_label_attribute_returns_default_when_unknown(): void
    {
        $program = new Program(['priority' => 'not_a_real_priority']);

        $this->assertSame('غير محدد', $program->priority_label);
    }

    public function test_progress_method_label_attribute_returns_arabic_label(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'progress_calculation_method' => 'manual',
        ]);

        $this->assertSame('يدوي', $program->progress_method_label);
    }

    public function test_progress_method_label_attribute_returns_default_when_unknown(): void
    {
        $program = new Program(['progress_calculation_method' => 'mystery_method']);

        $this->assertSame('غير محدد', $program->progress_method_label);
    }

    public function test_scope_active_returns_only_planning_or_in_progress_programs(): void
    {
        $planning = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'planning',
        ]);
        $inProgress = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'in_progress',
        ]);
        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'completed',
        ]);
        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'cancelled',
        ]);
        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'on_hold',
        ]);

        $ids = Program::active()->pluck('id')->all();
        sort($ids);

        $expected = [$planning->id, $inProgress->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_scope_ordered_sorts_by_order_then_created_at(): void
    {
        $early = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'order' => 1,
            'created_at' => now()->subDay(),
        ]);
        $middle = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'order' => 2,
            'created_at' => now(),
        ]);

        $this->assertSame(
            [$early->id, $middle->id],
            Program::ordered()->pluck('id')->all()
        );
    }

    public function test_scope_aware_parent_returns_linked_portfolio(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
        ]);

        $parent = $program->scopeParent();

        $this->assertInstanceOf(Portfolio::class, $parent);
        $this->assertSame($this->portfolio->id, $parent->id);
    }

    public function test_scope_aware_parent_is_null_when_no_portfolio(): void
    {
        $program = new Program;
        $program->portfolio_id = null;

        $this->assertNull($program->scopeParent());
    }

    public function test_scope_aware_type_key_is_program(): void
    {
        $program = new Program;

        $this->assertSame('program', $program->scopeTypeKey());
    }

    public function test_scope_aware_organization_id_prefers_own_org_column(): void
    {
        $otherOrg = Organization::factory()->create();
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $otherOrg->id,
        ]);

        $this->assertSame($otherOrg->id, $program->scopeOrganizationId());
    }

    public function test_scope_aware_organization_id_falls_back_to_portfolio_org(): void
    {
        // Program has no direct org_id but its portfolio does.
        $program = new Program(['portfolio_id' => $this->portfolio->id]);

        $this->assertSame($this->org->id, $program->scopeOrganizationId());
    }

    public function test_scope_aware_organization_id_is_null_when_nothing_set(): void
    {
        $program = new Program;

        $this->assertNull($program->scopeOrganizationId());
    }

    public function test_dates_are_cast_to_carbon_instances(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
        ]);

        $this->assertSame('2026-03-01', $program->start_date->toDateString());
        $this->assertSame('2026-12-31', $program->end_date->toDateString());
    }

    public function test_decimal_casts_round_to_two_places(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
            'budget' => 1234.567,
            'weight' => 7.891,
            'progress' => 12.345,
        ]);

        $fresh = $program->fresh();
        $this->assertSame('1234.57', (string) $fresh->budget);
        $this->assertSame('7.89', (string) $fresh->weight);
        $this->assertSame('12.35', (string) $fresh->progress);
    }

    public function test_soft_deletes_hide_program_from_default_queries(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
        ]);
        $program->delete();

        $this->assertNull(Program::find($program->id));
        $this->assertNotNull(Program::withTrashed()->find($program->id));
    }

    public function test_kpis_morph_to_many_returns_linked_kpis(): void
    {
        $program = Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'organization_id' => $this->org->id,
        ]);

        $kpi = Kpi::factory()->create();
        \DB::table('kpi_links')->insert([
            'organization_id' => $this->org->id,
            'linkable_type' => Program::class,
            'linkable_id' => $program->id,
            'kpi_id' => $kpi->id,
            'relationship_type' => 'contributes_to',
            'weight' => 1.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(1, $program->kpis);
        $this->assertSame($kpi->id, $program->kpis->first()->id);
    }
}
