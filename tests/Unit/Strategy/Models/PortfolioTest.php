<?php

namespace Tests\Unit\Strategy\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->creator = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_portfolio_code_is_auto_generated_when_blank(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'code' => null,
        ]);

        $this->assertNotNull($portfolio->code);
        $this->assertMatchesRegularExpression('/^PF-\d{4}-\d{3}$/', $portfolio->code);
    }

    public function test_portfolio_keeps_explicit_code_when_provided(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'code' => 'PF-2026-CUSTOM',
        ]);

        $this->assertSame('PF-2026-CUSTOM', $portfolio->fresh()->code);
    }

    public function test_generate_code_uses_year_prefix_and_increments(): void
    {
        $year = date('Y');

        Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'code' => "PF-{$year}-007",
        ]);

        $next = Portfolio::generateCode();

        $this->assertSame("PF-{$year}-008", $next);
    }

    public function test_generate_code_starts_at_001_when_no_prior_codes(): void
    {
        $this->assertSame('PF-'.date('Y').'-001', Portfolio::generateCode());
    }

    public function test_belongs_to_creator(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $this->creator->id,
        ]);

        $this->assertInstanceOf(User::class, $portfolio->creator);
        $this->assertSame($this->creator->id, $portfolio->creator->id);
    }

    public function test_belongs_to_organization(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->assertInstanceOf(Organization::class, $portfolio->organization);
        $this->assertSame($this->org->id, $portfolio->organization->id);
    }

    public function test_has_many_programs_returns_child_programs_ordered(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $programB = Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $this->org->id,
            'order' => 2,
        ]);
        $programA = Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $this->org->id,
            'order' => 1,
        ]);

        $programs = $portfolio->programs;

        $this->assertCount(2, $programs);
        $this->assertSame($programA->id, $programs->first()->id);
        $this->assertSame($programB->id, $programs->last()->id);
    }

    public function test_has_many_through_projects_returns_nested_projects(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $program = Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $this->org->id,
        ]);

        $project = Project::factory()->create([
            'program_id' => $program->id,
            'organization_id' => $this->org->id,
        ]);

        $projects = $portfolio->projects;

        $this->assertCount(1, $projects);
        $this->assertSame($project->id, $projects->first()->id);
    }

    public function test_calculate_progress_returns_zero_when_no_programs(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->assertSame(0.0, $portfolio->calculateProgress());
    }

    public function test_calculate_progress_excludes_cancelled_programs(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $this->org->id,
            'progress' => 50,
            'weight' => 1,
            'status' => 'in_progress',
        ]);
        Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $this->org->id,
            'progress' => 0,
            'weight' => 1,
            'status' => 'cancelled',
        ]);

        // Only the non-cancelled program counts; weighted progress = 50/1 = 50.
        $this->assertSame(50.0, $portfolio->fresh()->calculateProgress());
    }

    public function test_calculate_progress_is_weighted_average(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $this->org->id,
            'progress' => 100,
            'weight' => 1,
            'status' => 'in_progress',
        ]);
        Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $this->org->id,
            'progress' => 0,
            'weight' => 1,
            'status' => 'in_progress',
        ]);

        $this->assertSame(50.0, $portfolio->fresh()->calculateProgress());
    }

    public function test_update_progress_persists_calculated_value(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'portfolio_progress' => 0,
        ]);
        Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $this->org->id,
            'progress' => 80,
            'weight' => 1,
            'status' => 'in_progress',
        ]);

        $portfolio->updateProgress();

        $this->assertSame('80.00', (string) $portfolio->fresh()->portfolio_progress);
    }

    public function test_can_be_closed_strategically_true_when_no_active_programs(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'completed',
        ]);

        $this->assertTrue($portfolio->fresh()->canBeClosedStrategically());
    }

    public function test_can_be_closed_strategically_false_when_planning_program_exists(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'planning',
        ]);

        $this->assertFalse($portfolio->fresh()->canBeClosedStrategically());
    }

    public function test_can_be_closed_strategically_false_when_in_progress_program_exists(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $this->org->id,
            'status' => 'in_progress',
        ]);

        $this->assertFalse($portfolio->fresh()->canBeClosedStrategically());
    }

    public function test_status_label_attribute_returns_arabic_label(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'active',
        ]);

        $this->assertSame('نشط', $portfolio->status_label);
    }

    public function test_status_label_attribute_returns_default_when_unknown(): void
    {
        // The DB CHECK constraint forbids unknown statuses, so build an
        // in-memory instance to exercise the accessor's fallback path only.
        $portfolio = new Portfolio(['status' => 'not_a_real_status']);

        $this->assertSame('غير محدد', $portfolio->status_label);
    }

    public function test_portfolio_status_label_attribute_returns_arabic_label(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'portfolio_status' => 'frozen',
        ]);

        $this->assertSame('مجمدة', $portfolio->portfolio_status_label);
    }

    public function test_portfolio_status_label_attribute_returns_default_when_unknown(): void
    {
        // portfolio_status is a free-text column with no DB CHECK constraint
        // but we still build in-memory to assert the accessor fallback.
        $portfolio = new Portfolio(['portfolio_status' => 'unknown']);

        $this->assertSame('غير محدد', $portfolio->portfolio_status_label);
    }

    public function test_directive_source_label_uses_arabic_map_for_known_source(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'directive_source' => 'moh',
        ]);

        $this->assertSame('وزارة الصحة', $portfolio->directive_source_label);
    }

    public function test_directive_source_label_prefers_free_text_when_source_is_other(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'directive_source' => 'other',
            'directive_source_other' => 'جهة خاصة مخصصة',
        ]);

        $this->assertSame('جهة خاصة مخصصة', $portfolio->directive_source_label);
    }

    public function test_directive_source_label_falls_back_to_map_when_other_has_no_text(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'directive_source' => 'other',
            'directive_source_other' => null,
        ]);

        $this->assertSame('جهة أخرى', $portfolio->directive_source_label);
    }

    public function test_directive_source_label_returns_empty_string_when_null(): void
    {
        $portfolio = new Portfolio(['directive_source' => null]);

        $this->assertSame('', $portfolio->directive_source_label);
    }

    public function test_scope_active_filters_to_operational_active(): void
    {
        $active = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'active',
        ]);
        Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'draft',
        ]);

        $ids = Portfolio::active()->pluck('id')->all();

        $this->assertSame([$active->id], $ids);
    }

    public function test_scope_strategically_active_filters_by_portfolio_status(): void
    {
        $active = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'portfolio_status' => 'active',
        ]);
        Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'portfolio_status' => 'frozen',
        ]);

        $ids = Portfolio::strategicallyActive()->pluck('id')->all();

        $this->assertSame([$active->id], $ids);
    }

    public function test_scope_draft_filters_to_drafts(): void
    {
        $draft = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'draft',
        ]);
        Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'active',
        ]);

        $ids = Portfolio::draft()->pluck('id')->all();

        $this->assertSame([$draft->id], $ids);
    }

    public function test_scope_ordered_sorts_by_priority_rank_desc_then_order(): void
    {
        $lowPriority = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'priority_rank' => 1,
            'order' => 5,
        ]);
        $highPriority = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'priority_rank' => 10,
            'order' => 99,
        ]);

        $ordered = Portfolio::ordered()->pluck('id')->all();

        // High priority_rank comes first regardless of order column.
        $this->assertSame([$highPriority->id, $lowPriority->id], $ordered);
    }

    public function test_scope_by_weight_orders_by_weight_descending(): void
    {
        $heavy = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'weight' => 90,
        ]);
        $light = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'weight' => 10,
        ]);

        $ordered = Portfolio::byWeight()->pluck('id')->all();

        $this->assertSame([$heavy->id, $light->id], $ordered);
    }

    public function test_scope_aware_parent_is_null_for_portfolio(): void
    {
        // Portfolio sits at the top of the PMI golden chain; it has no parent.
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->assertNull($portfolio->scopeParent());
    }

    public function test_scope_aware_type_key_is_portfolio(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->assertSame('portfolio', $portfolio->scopeTypeKey());
    }

    public function test_scope_aware_organization_id_returns_own_org(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->assertSame($this->org->id, $portfolio->scopeOrganizationId());
    }

    public function test_scope_aware_organization_id_is_null_when_no_org_set(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => null,
        ]);

        $this->assertNull($portfolio->scopeOrganizationId());
    }

    public function test_dates_are_cast_to_carbon_instances(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'start_date' => '2026-01-15',
            'end_date' => '2026-12-31',
        ]);

        $this->assertSame('2026-01-15', $portfolio->start_date->toDateString());
        $this->assertSame('2026-12-31', $portfolio->end_date->toDateString());
    }

    public function test_decimal_casts_round_to_two_places(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
            'weight' => 12.3456,
            'portfolio_progress' => 33.3333,
        ]);

        $fresh = $portfolio->fresh();
        // decimal:2 cast formats to two places as a string.
        $this->assertSame('12.35', (string) $fresh->weight);
        $this->assertSame('33.33', (string) $fresh->portfolio_progress);
    }

    public function test_soft_deletes_hide_portfolio_from_default_queries(): void
    {
        $portfolio = Portfolio::factory()->create([
            'organization_id' => $this->org->id,
        ]);
        $portfolio->delete();

        $this->assertNull(Portfolio::find($portfolio->id));
        $this->assertNotNull(Portfolio::withTrashed()->find($portfolio->id));
    }
}
