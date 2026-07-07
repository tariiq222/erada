<?php

namespace Tests\Unit\Strategy\Models;

use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Models\Review;
use App\Modules\Strategy\Models\StrategicObjective;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The strategic_objectives table was archived and dropped in migration
 * 2026_01_16_200003_archive_and_drop_strategic_objectives.php. The model
 * itself is retained only for polymorphic metadata references; this test
 * recreates the table on-demand so the model's DB-touching methods can
 * still be exercised.
 */
class StrategicObjectiveTest extends TestCase
{
    use RefreshDatabase;

    protected Portfolio $portfolio;

    protected User $user;

    protected function recreateStrategicObjectivesTable(): void
    {
        if (Schema::hasTable('strategic_objectives')) {
            return;
        }

        Schema::create('strategic_objectives', function ($table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('portfolio_id')->nullable();
            $table->string('bsc_perspective', 50)->nullable();
            $table->decimal('target_value', 15, 2)->nullable();
            $table->string('measurement_unit', 50)->nullable();
            $table->decimal('current_value', 15, 2)->default(0);
            $table->decimal('baseline_value', 15, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('weight', 5, 2)->default(1);
            $table->string('status', 20)->default('draft');
            $table->unsignedTinyInteger('order')->default(0);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->recreateStrategicObjectivesTable();

        $this->user = User::factory()->create();
        $this->portfolio = Portfolio::factory()->create();
    }

    public function test_bsc_perspective_constants_are_stable(): void
    {
        $this->assertSame('financial', StrategicObjective::BSC_FINANCIAL);
        $this->assertSame('customer', StrategicObjective::BSC_CUSTOMER);
        $this->assertSame('internal_process', StrategicObjective::BSC_INTERNAL_PROCESS);
        $this->assertSame('learning_growth', StrategicObjective::BSC_LEARNING_GROWTH);
    }

    public function test_bsc_perspectives_map_has_arabic_labels_for_all_four(): void
    {
        $map = StrategicObjective::BSC_PERSPECTIVES;

        $this->assertCount(4, $map);
        $this->assertSame('المالي', $map[StrategicObjective::BSC_FINANCIAL]);
        $this->assertSame('العملاء', $map[StrategicObjective::BSC_CUSTOMER]);
        $this->assertSame('العمليات الداخلية', $map[StrategicObjective::BSC_INTERNAL_PROCESS]);
        $this->assertSame('التعلم والنمو', $map[StrategicObjective::BSC_LEARNING_GROWTH]);
    }

    public function test_status_constants_and_labels_are_stable(): void
    {
        $this->assertSame('draft', StrategicObjective::STATUS_DRAFT);
        $this->assertSame('active', StrategicObjective::STATUS_ACTIVE);
        $this->assertSame('completed', StrategicObjective::STATUS_COMPLETED);
        $this->assertSame('cancelled', StrategicObjective::STATUS_CANCELLED);

        $this->assertSame([
            StrategicObjective::STATUS_DRAFT => 'مسودة',
            StrategicObjective::STATUS_ACTIVE => 'نشط',
            StrategicObjective::STATUS_COMPLETED => 'مكتمل',
            StrategicObjective::STATUS_CANCELLED => 'ملغي',
        ], StrategicObjective::STATUSES);
    }

    public function test_code_is_auto_generated_when_blank(): void
    {
        $objective = new StrategicObjective;
        $objective->name = 'objective';
        $objective->portfolio_id = $this->portfolio->id;
        $objective->code = null;
        $objective->save();

        $this->assertNotNull($objective->code);
        $this->assertMatchesRegularExpression('/^SO-\d{4}-\d{3}$/', $objective->code);
    }

    public function test_generate_code_increments_against_existing_codes(): void
    {
        $year = date('Y');

        StrategicObjective::create([
            'name' => 'objective',
            'portfolio_id' => $this->portfolio->id,
            'code' => "SO-{$year}-004",
        ]);

        $this->assertSame("SO-{$year}-005", StrategicObjective::generateCode());
    }

    public function test_generate_code_starts_at_001_when_table_is_empty(): void
    {
        $this->assertSame('SO-'.date('Y').'-001', StrategicObjective::generateCode());
    }

    public function test_direction_is_alias_for_portfolio(): void
    {
        $objective = new StrategicObjective(['portfolio_id' => $this->portfolio->id]);

        // direction() returns the same Portfolio relation as portfolio() — the
        // alias exists only for backward compatibility.
        $this->assertInstanceOf(Portfolio::class, $objective->direction()->getRelated());
        $this->assertSame(
            $objective->portfolio()->getQualifiedForeignKeyName(),
            $objective->direction()->getQualifiedForeignKeyName()
        );
        $this->assertSame(
            $objective->portfolio()->getQualifiedOwnerKeyName(),
            $objective->direction()->getQualifiedOwnerKeyName()
        );
    }

    public function test_belongs_to_portfolio_resolves_linked_portfolio(): void
    {
        $objective = new StrategicObjective(['portfolio_id' => $this->portfolio->id]);

        $this->assertSame($this->portfolio->id, $objective->portfolio->id);
    }

    public function test_belongs_to_owner_resolves_user(): void
    {
        $objective = new StrategicObjective(['owner_id' => $this->user->id]);

        $this->assertSame($this->user->id, $objective->owner->id);
    }

    public function test_belongs_to_creator_resolves_user(): void
    {
        $objective = new StrategicObjective(['created_by' => $this->user->id]);

        $this->assertSame($this->user->id, $objective->creator->id);
    }

    public function test_morph_many_reviews_returns_linked_reviews(): void
    {
        $objective = StrategicObjective::create([
            'name' => 'objective',
            'portfolio_id' => $this->portfolio->id,
        ]);

        $review = Review::create([
            'title' => 'r',
            'reviewable_type' => StrategicObjective::class,
            'reviewable_id' => $objective->id,
            'type' => 'annual',
            'pdca_phase' => 'check',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->toDateString(),
            'conducted_by' => $this->user->id,
        ]);

        $this->assertCount(1, $objective->reviews);
        $this->assertSame($review->id, $objective->reviews->first()->id);
    }

    public function test_calculate_progress_uses_target_and_current_when_present(): void
    {
        $objective = new StrategicObjective([
            'target_value' => 200,
            'current_value' => 50,
        ]);

        // 50 / 200 = 25%
        $this->assertSame(25.0, $objective->calculateProgress());
    }

    public function test_calculate_progress_caps_at_100_percent(): void
    {
        $objective = new StrategicObjective([
            'target_value' => 50,
            'current_value' => 200,
        ]);

        $this->assertSame(100.0, $objective->calculateProgress());
    }

    public function test_calculate_progress_falls_back_to_programs_when_no_target(): void
    {
        $objective = StrategicObjective::create([
            'name' => 'objective',
            'portfolio_id' => $this->portfolio->id,
        ]);

        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'progress' => 80,
            'weight' => 1,
            'status' => 'in_progress',
        ]);
        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'progress' => 60,
            'weight' => 1,
            'status' => 'in_progress',
        ]);

        // (80*1 + 60*1) / (1+1) = 70
        $this->assertSame(70.0, $objective->calculateProgress());
    }

    public function test_calculate_progress_ignores_cancelled_programs(): void
    {
        $objective = StrategicObjective::create([
            'name' => 'objective',
            'portfolio_id' => $this->portfolio->id,
        ]);

        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'progress' => 100,
            'weight' => 1,
            'status' => 'in_progress',
        ]);
        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'progress' => 0,
            'weight' => 1,
            'status' => 'cancelled',
        ]);

        $this->assertSame(100.0, $objective->calculateProgress());
    }

    public function test_calculate_progress_returns_zero_when_no_programs_and_no_target(): void
    {
        $objective = StrategicObjective::create([
            'name' => 'objective',
            'portfolio_id' => $this->portfolio->id,
        ]);

        $this->assertSame(0.0, $objective->calculateProgress());
    }

    public function test_calculate_progress_handles_zero_total_weight(): void
    {
        $objective = StrategicObjective::create([
            'name' => 'objective',
            'portfolio_id' => $this->portfolio->id,
        ]);

        // All programs have weight 0 → division by zero must not throw.
        Program::factory()->create([
            'portfolio_id' => $this->portfolio->id,
            'progress' => 50,
            'weight' => 0,
            'status' => 'in_progress',
        ]);

        $this->assertSame(0.0, $objective->calculateProgress());
    }

    public function test_bsc_perspective_label_attribute_returns_arabic_label(): void
    {
        $objective = new StrategicObjective(['bsc_perspective' => 'financial']);

        $this->assertSame('المالي', $objective->bsc_perspective_label);
    }

    public function test_bsc_perspective_label_attribute_returns_default_when_unknown(): void
    {
        $objective = new StrategicObjective(['bsc_perspective' => 'mystery_perspective']);

        $this->assertSame('غير محدد', $objective->bsc_perspective_label);
    }

    public function test_status_label_attribute_returns_arabic_label(): void
    {
        $objective = new StrategicObjective(['status' => 'active']);

        $this->assertSame('نشط', $objective->status_label);
    }

    public function test_status_label_attribute_returns_default_when_unknown(): void
    {
        $objective = new StrategicObjective(['status' => 'unknown_state']);

        $this->assertSame('غير محدد', $objective->status_label);
    }

    public function test_scope_active_filters_to_active_status(): void
    {
        $active = StrategicObjective::create([
            'name' => 'active',
            'portfolio_id' => $this->portfolio->id,
            'status' => 'active',
        ]);
        StrategicObjective::create([
            'name' => 'draft',
            'portfolio_id' => $this->portfolio->id,
            'status' => 'draft',
        ]);

        $ids = StrategicObjective::active()->pluck('id')->all();

        $this->assertSame([$active->id], $ids);
    }

    public function test_scope_perspective_filters_by_bsc_perspective(): void
    {
        $financial = StrategicObjective::create([
            'name' => 'fin',
            'portfolio_id' => $this->portfolio->id,
            'bsc_perspective' => 'financial',
        ]);
        StrategicObjective::create([
            'name' => 'cust',
            'portfolio_id' => $this->portfolio->id,
            'bsc_perspective' => 'customer',
        ]);

        $ids = StrategicObjective::perspective('financial')->pluck('id')->all();

        $this->assertSame([$financial->id], $ids);
    }

    public function test_scope_ordered_sorts_by_order_then_created_at(): void
    {
        $second = StrategicObjective::create([
            'name' => 'second',
            'portfolio_id' => $this->portfolio->id,
            'order' => 2,
            'created_at' => now()->subDay(),
        ]);
        $first = StrategicObjective::create([
            'name' => 'first',
            'portfolio_id' => $this->portfolio->id,
            'order' => 1,
            'created_at' => now(),
        ]);

        $this->assertSame(
            [$first->id, $second->id],
            StrategicObjective::ordered()->pluck('id')->all()
        );
    }

    public function test_dates_are_cast_to_carbon_instances(): void
    {
        $objective = new StrategicObjective([
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $this->assertSame('2026-01-01', $objective->start_date->toDateString());
        $this->assertSame('2026-12-31', $objective->end_date->toDateString());
    }

    public function test_decimal_casts_round_to_two_places(): void
    {
        $objective = new StrategicObjective([
            'target_value' => 100.567,
            'current_value' => 33.333,
            'baseline_value' => 5.001,
            'weight' => 1.499,
        ]);

        $this->assertSame('100.57', (string) $objective->target_value);
        $this->assertSame('33.33', (string) $objective->current_value);
        $this->assertSame('5.00', (string) $objective->baseline_value);
        $this->assertSame('1.50', (string) $objective->weight);
    }

    public function test_soft_deletes_hide_objective_from_default_queries(): void
    {
        $objective = StrategicObjective::create([
            'name' => 'objective',
            'portfolio_id' => $this->portfolio->id,
        ]);
        $objective->delete();

        $this->assertNull(StrategicObjective::find($objective->id));
        $this->assertNotNull(StrategicObjective::withTrashed()->find($objective->id));
    }
}
