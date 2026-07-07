<?php

namespace App\Modules\RiskManagement\Services;

use App\Modules\RiskManagement\Enums\RiskLevel;
use InvalidArgumentException;

class RiskScoreCalculator
{
    /**
     * @return array{score:int, level:RiskLevel, color:string}
     */
    public function calculate(int $likelihood, int $impact): array
    {
        $this->assertScaleValue($likelihood, 'likelihood');
        $this->assertScaleValue($impact, 'impact');

        $score = $likelihood * $impact;
        $level = RiskLevel::fromScore($score);

        return [
            'score' => $score,
            'level' => $level,
            'color' => $level->color(),
        ];
    }

    private function assertScaleValue(int $value, string $field): void
    {
        if ($value < 1 || $value > 5) {
            throw new InvalidArgumentException("Risk {$field} must be between 1 and 5.");
        }
    }
}
