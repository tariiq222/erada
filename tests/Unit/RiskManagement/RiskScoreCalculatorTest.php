<?php

namespace Tests\Unit\RiskManagement;

use App\Modules\RiskManagement\Enums\RiskLevel;
use App\Modules\RiskManagement\Services\RiskScoreCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RiskScoreCalculatorTest extends TestCase
{
    private RiskScoreCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new RiskScoreCalculator;
    }

    #[Test]
    #[DataProvider('validMatrixCases')]
    public function it_maps_likelihood_impact_to_score_and_level(int $l, int $i, int $expectedScore, RiskLevel $expectedLevel): void
    {
        $result = $this->calc->calculate($l, $i);

        $this->assertSame($expectedScore, $result['score']);
        $this->assertSame($expectedLevel, $result['level']);
        $this->assertSame($expectedLevel->color(), $result['color']);
    }

    public static function validMatrixCases(): array
    {
        return [
            'lowest_low' => [1, 1, 1, RiskLevel::Low],
            'low_boundary' => [1, 3, 3, RiskLevel::Low],
            'medium_floor' => [2, 2, 4, RiskLevel::Medium],
            'medium_boundary' => [2, 3, 6, RiskLevel::Medium],
            'high_floor' => [2, 4, 8, RiskLevel::High],
            'high_boundary' => [3, 4, 12, RiskLevel::High],
            'critical_floor' => [3, 5, 15, RiskLevel::Critical],
            'critical_corner' => [5, 5, 25, RiskLevel::Critical],
        ];
    }

    #[Test]
    public function it_rejects_values_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->calculate(0, 2);
    }

    #[Test]
    public function it_rejects_values_above_five(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->calculate(2, 6);
    }
}
