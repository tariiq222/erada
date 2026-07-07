<?php

namespace Database\Factories\RiskManagement;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAssessment;
use App\Modules\RiskManagement\Services\RiskScoreCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiskAssessment>
 */
class RiskAssessmentFactory extends Factory
{
    protected $model = RiskAssessment::class;

    public function definition(): array
    {
        $likelihood = $this->faker->numberBetween(1, 5);
        $impact = $this->faker->numberBetween(1, 5);
        $calc = app(RiskScoreCalculator::class)->calculate($likelihood, $impact);

        return [
            'risk_id' => Risk::factory(),
            'organization_id' => Organization::factory(),
            'likelihood' => $likelihood,
            'impact' => $impact,
            'score' => $calc['score'],
            'level' => $calc['level']->value,
            'residual_likelihood' => null,
            'residual_impact' => null,
            'residual_score' => null,
            'residual_level' => null,
            'assessor_id' => User::factory(),
            'notes' => $this->faker->optional()->paragraph(),
            'next_review_at' => $this->faker->optional()->dateTimeBetween('now', '+3 months'),
            'review_due_notified_at' => null,
        ];
    }

    public function dueForReview(): static
    {
        return $this->state(fn () => [
            'next_review_at' => now()->subDays(2)->toDateString(),
            'review_due_notified_at' => null,
        ]);
    }
}
