<?php

namespace Tests\Unit\Strategy\Models;

use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Models\Review;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->program = Program::factory()->create();
    }

    public function test_morph_to_resolves_linked_program(): void
    {
        $review = Review::create([
            'title' => 'مراجعة ربع سنوية للبرنامج',
            'reviewable_type' => Program::class,
            'reviewable_id' => $this->program->id,
            'type' => 'quarterly',
            'pdca_phase' => 'check',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonths(3)->toDateString(),
            'period_end' => now()->toDateString(),
            'conducted_by' => $this->user->id,
        ]);

        $this->assertInstanceOf(Program::class, $review->reviewable);
        $this->assertSame($this->program->id, $review->reviewable->id);
    }

    public function test_belongs_to_conductor_returns_user(): void
    {
        $review = Review::create([
            'title' => 'مراجعة شهرية',
            'reviewable_type' => Program::class,
            'reviewable_id' => $this->program->id,
            'type' => 'monthly',
            'pdca_phase' => 'check',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'conducted_by' => $this->user->id,
        ]);

        $this->assertInstanceOf(User::class, $review->conductor);
        $this->assertSame($this->user->id, $review->conductor->id);
    }

    public function test_attendees_are_cast_from_json_to_array(): void
    {
        $review = Review::create([
            'title' => 'مراجعة طارئة',
            'reviewable_type' => Program::class,
            'reviewable_id' => $this->program->id,
            'type' => 'adhoc',
            'pdca_phase' => 'act',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'attendees' => [
                ['name' => 'محمد', 'role' => 'مدير البرنامج'],
                ['name' => 'فاطمة', 'role' => 'مسؤول المخاطر'],
            ],
            'conducted_by' => $this->user->id,
        ]);

        $this->assertSame(
            ['name' => 'محمد', 'role' => 'مدير البرنامج'],
            $review->attendees[0]
        );
        $this->assertCount(2, $review->attendees);
    }

    public function test_dates_are_cast_to_carbon_instances(): void
    {
        $review = Review::create([
            'title' => 'مراجعة',
            'reviewable_type' => Program::class,
            'reviewable_id' => $this->program->id,
            'type' => 'annual',
            'pdca_phase' => 'plan',
            'review_date' => '2026-06-15',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-15',
            'conducted_by' => $this->user->id,
        ]);

        $this->assertSame('2026-06-15', $review->review_date->toDateString());
        $this->assertSame('2026-01-01', $review->period_start->toDateString());
        $this->assertSame('2026-06-15', $review->period_end->toDateString());
    }

    public function test_progress_snapshot_is_cast_to_two_decimal_string(): void
    {
        $review = Review::create([
            'title' => 'مراجعة',
            'reviewable_type' => Program::class,
            'reviewable_id' => $this->program->id,
            'type' => 'quarterly',
            'pdca_phase' => 'check',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonths(3)->toDateString(),
            'period_end' => now()->toDateString(),
            'progress_snapshot' => 42.567,
            'conducted_by' => $this->user->id,
        ]);

        $this->assertSame('42.57', (string) $review->fresh()->progress_snapshot);
    }

    public function test_type_label_attribute_returns_arabic_label_per_type(): void
    {
        $cases = [
            'monthly' => 'شهري',
            'quarterly' => 'ربع سنوي',
            'annual' => 'سنوي',
            'adhoc' => 'طارئ',
        ];

        foreach ($cases as $value => $label) {
            $review = new Review(['type' => $value]);
            $this->assertSame($label, $review->type_label, "type={$value}");
        }
    }

    public function test_type_label_attribute_returns_default_when_unknown(): void
    {
        $review = new Review(['type' => 'not_a_real_type']);

        $this->assertSame('غير محدد', $review->type_label);
    }

    public function test_pdca_phase_label_attribute_returns_arabic_label_per_phase(): void
    {
        $cases = [
            'plan' => 'التخطيط (Plan)',
            'do' => 'التنفيذ (Do)',
            'check' => 'المراجعة (Check)',
            'act' => 'التحسين (Act)',
        ];

        foreach ($cases as $value => $label) {
            $review = new Review(['pdca_phase' => $value]);
            $this->assertSame($label, $review->pdca_phase_label, "phase={$value}");
        }
    }

    public function test_pdca_phase_label_attribute_returns_default_when_unknown(): void
    {
        $review = new Review(['pdca_phase' => 'not_a_real_phase']);

        $this->assertSame('غير محدد', $review->pdca_phase_label);
    }

    public function test_overall_status_label_attribute_returns_arabic_label_per_status(): void
    {
        $cases = [
            'on_track' => 'على المسار',
            'at_risk' => 'في خطر',
            'off_track' => 'متأخر',
            'completed' => 'مكتمل',
        ];

        foreach ($cases as $value => $label) {
            $review = new Review(['overall_status' => $value]);
            $this->assertSame($label, $review->overall_status_label, "status={$value}");
        }
    }

    public function test_overall_status_label_attribute_returns_default_when_unknown(): void
    {
        $review = new Review(['overall_status' => 'unknown']);

        $this->assertSame('غير محدد', $review->overall_status_label);
    }

    public function test_scope_of_type_filters_by_review_type(): void
    {
        $quarterly = Review::create([
            'title' => 'q',
            'reviewable_type' => Program::class,
            'reviewable_id' => $this->program->id,
            'type' => 'quarterly',
            'pdca_phase' => 'check',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonths(3)->toDateString(),
            'period_end' => now()->toDateString(),
            'conducted_by' => $this->user->id,
        ]);
        Review::create([
            'title' => 'm',
            'reviewable_type' => Program::class,
            'reviewable_id' => $this->program->id,
            'type' => 'monthly',
            'pdca_phase' => 'check',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'conducted_by' => $this->user->id,
        ]);

        $ids = Review::ofType('quarterly')->pluck('id')->all();

        $this->assertSame([$quarterly->id], $ids);
    }

    public function test_scope_phase_filters_by_pdca_phase(): void
    {
        $plan = Review::create([
            'title' => 'p',
            'reviewable_type' => Program::class,
            'reviewable_id' => $this->program->id,
            'type' => 'annual',
            'pdca_phase' => 'plan',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->toDateString(),
            'conducted_by' => $this->user->id,
        ]);
        Review::create([
            'title' => 'c',
            'reviewable_type' => Program::class,
            'reviewable_id' => $this->program->id,
            'type' => 'quarterly',
            'pdca_phase' => 'check',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonths(3)->toDateString(),
            'period_end' => now()->toDateString(),
            'conducted_by' => $this->user->id,
        ]);

        $ids = Review::phase('plan')->pluck('id')->all();

        $this->assertSame([$plan->id], $ids);
    }

    public function test_scope_recent_orders_by_review_date_desc_and_limits(): void
    {
        $oldest = Review::create([
            'title' => 'old',
            'reviewable_type' => Program::class,
            'reviewable_id' => $this->program->id,
            'type' => 'annual',
            'pdca_phase' => 'check',
            'review_date' => '2024-01-01',
            'period_start' => '2023-01-01',
            'period_end' => '2024-01-01',
            'conducted_by' => $this->user->id,
        ]);
        $middle = Review::create([
            'title' => 'mid',
            'reviewable_type' => Program::class,
            'reviewable_id' => $this->program->id,
            'type' => 'quarterly',
            'pdca_phase' => 'check',
            'review_date' => '2025-01-01',
            'period_start' => '2024-10-01',
            'period_end' => '2025-01-01',
            'conducted_by' => $this->user->id,
        ]);
        $newest = Review::create([
            'title' => 'new',
            'reviewable_type' => Program::class,
            'reviewable_id' => $this->program->id,
            'type' => 'quarterly',
            'pdca_phase' => 'check',
            'review_date' => '2026-06-01',
            'period_start' => '2026-04-01',
            'period_end' => '2026-06-01',
            'conducted_by' => $this->user->id,
        ]);

        // recent(2) returns the two newest reviews by review_date desc;
        // the 2024 review is excluded.
        $ids = Review::recent(2)->pluck('id')->all();

        $this->assertSame([$newest->id, $middle->id], $ids);
        $this->assertNotContains($oldest->id, $ids);
    }

    public function test_soft_deletes_hide_review_from_default_queries(): void
    {
        $review = Review::create([
            'title' => 'r',
            'reviewable_type' => Program::class,
            'reviewable_id' => $this->program->id,
            'type' => 'quarterly',
            'pdca_phase' => 'check',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonths(3)->toDateString(),
            'period_end' => now()->toDateString(),
            'conducted_by' => $this->user->id,
        ]);
        $review->delete();

        $this->assertNull(Review::find($review->id));
        $this->assertNotNull(Review::withTrashed()->find($review->id));
    }
}
