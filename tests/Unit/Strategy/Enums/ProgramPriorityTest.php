<?php

namespace Tests\Unit\Strategy\Enums;

use App\Modules\Strategy\Enums\ProgramPriority;
use PHPUnit\Framework\TestCase;

class ProgramPriorityTest extends TestCase
{
    public function test_enum_has_four_cases(): void
    {
        $this->assertCount(4, ProgramPriority::cases());
    }

    public function test_enum_values_match_database_check_constraint(): void
    {
        // These values are referenced by the programs_priority_check PostgreSQL
        // CHECK constraint; any drift here would break inserts at runtime.
        $this->assertSame('low', ProgramPriority::LOW->value);
        $this->assertSame('medium', ProgramPriority::MEDIUM->value);
        $this->assertSame('high', ProgramPriority::HIGH->value);
        $this->assertSame('critical', ProgramPriority::CRITICAL->value);
    }

    public function test_label_returns_arabic_label_per_case(): void
    {
        $this->assertSame('منخفض', ProgramPriority::LOW->label());
        $this->assertSame('متوسط', ProgramPriority::MEDIUM->label());
        $this->assertSame('عالي', ProgramPriority::HIGH->label());
        $this->assertSame('حرج', ProgramPriority::CRITICAL->label());
    }

    public function test_color_returns_distinct_value_per_case(): void
    {
        $colors = array_map(fn ($case) => $case->color(), ProgramPriority::cases());

        $this->assertCount(4, $colors);
        $this->assertSame(count($colors), count(array_unique($colors)));
    }

    public function test_weight_is_strictly_increasing_low_to_critical(): void
    {
        // The weight is used for sorting; low < medium < high < critical.
        $this->assertSame(1, ProgramPriority::LOW->weight());
        $this->assertSame(2, ProgramPriority::MEDIUM->weight());
        $this->assertSame(3, ProgramPriority::HIGH->weight());
        $this->assertSame(4, ProgramPriority::CRITICAL->weight());
    }

    public function test_options_returns_value_to_label_map(): void
    {
        $options = ProgramPriority::options();

        $this->assertSame([
            'low' => 'منخفض',
            'medium' => 'متوسط',
            'high' => 'عالي',
            'critical' => 'حرج',
        ], $options);
    }

    public function test_from_value_round_trips_for_every_case(): void
    {
        foreach (ProgramPriority::cases() as $case) {
            $this->assertSame($case, ProgramPriority::from($case->value));
        }
    }
}
