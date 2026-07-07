<?php

namespace Tests\Unit\Strategy\Enums;

use App\Modules\Strategy\Enums\ProgramStatus;
use PHPUnit\Framework\TestCase;

class ProgramStatusTest extends TestCase
{
    public function test_enum_has_all_six_cases(): void
    {
        $this->assertCount(6, ProgramStatus::cases());
    }

    public function test_enum_values_match_database_check_constraint(): void
    {
        // These values are referenced by the programs_status_check PostgreSQL
        // CHECK constraint; any drift here would break inserts at runtime.
        $this->assertSame('draft', ProgramStatus::DRAFT->value);
        $this->assertSame('planning', ProgramStatus::PLANNING->value);
        $this->assertSame('in_progress', ProgramStatus::IN_PROGRESS->value);
        $this->assertSame('on_hold', ProgramStatus::ON_HOLD->value);
        $this->assertSame('completed', ProgramStatus::COMPLETED->value);
        $this->assertSame('cancelled', ProgramStatus::CANCELLED->value);
    }

    public function test_label_returns_arabic_label_per_case(): void
    {
        $this->assertSame('مسودة', ProgramStatus::DRAFT->label());
        $this->assertSame('تخطيط', ProgramStatus::PLANNING->label());
        $this->assertSame('قيد التنفيذ', ProgramStatus::IN_PROGRESS->label());
        $this->assertSame('معلق', ProgramStatus::ON_HOLD->label());
        $this->assertSame('مكتمل', ProgramStatus::COMPLETED->label());
        $this->assertSame('ملغي', ProgramStatus::CANCELLED->label());
    }

    public function test_color_returns_distinct_value_per_case(): void
    {
        $colors = array_map(fn ($case) => $case->color(), ProgramStatus::cases());

        // Each status must have a non-empty color and they must be distinct so
        // StatusBadge renders different swatches.
        $this->assertCount(6, $colors);
        $this->assertSame(count($colors), count(array_unique($colors)));
    }

    public function test_is_active_only_true_for_planning_and_in_progress(): void
    {
        $this->assertFalse(ProgramStatus::DRAFT->isActive());
        $this->assertTrue(ProgramStatus::PLANNING->isActive());
        $this->assertTrue(ProgramStatus::IN_PROGRESS->isActive());
        $this->assertFalse(ProgramStatus::ON_HOLD->isActive());
        $this->assertFalse(ProgramStatus::COMPLETED->isActive());
        $this->assertFalse(ProgramStatus::CANCELLED->isActive());
    }

    public function test_is_closed_only_true_for_completed_and_cancelled(): void
    {
        $this->assertFalse(ProgramStatus::DRAFT->isClosed());
        $this->assertFalse(ProgramStatus::PLANNING->isClosed());
        $this->assertFalse(ProgramStatus::IN_PROGRESS->isClosed());
        $this->assertFalse(ProgramStatus::ON_HOLD->isClosed());
        $this->assertTrue(ProgramStatus::COMPLETED->isClosed());
        $this->assertTrue(ProgramStatus::CANCELLED->isClosed());
    }

    public function test_options_returns_value_to_label_map(): void
    {
        $options = ProgramStatus::options();

        $this->assertSame([
            'draft' => 'مسودة',
            'planning' => 'تخطيط',
            'in_progress' => 'قيد التنفيذ',
            'on_hold' => 'معلق',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
        ], $options);
    }

    public function test_from_value_round_trips_for_every_case(): void
    {
        foreach (ProgramStatus::cases() as $case) {
            $this->assertSame($case, ProgramStatus::from($case->value));
        }
    }
}
