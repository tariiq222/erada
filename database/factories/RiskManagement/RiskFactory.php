<?php

namespace Database\Factories\RiskManagement;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Enums\RiskResponseType;
use App\Modules\RiskManagement\Enums\RiskStatus;
use App\Modules\RiskManagement\Enums\RiskType;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Services\RiskScoreCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Risk>
 */
class RiskFactory extends Factory
{
    protected $model = Risk::class;

    public function definition(): array
    {
        $likelihood = $this->faker->numberBetween(1, 5);
        $impact = $this->faker->numberBetween(1, 5);
        $calc = app(RiskScoreCalculator::class)->calculate($likelihood, $impact);

        return [
            'organization_id' => Organization::factory(),
            'title' => $this->faker->sentence(4),
            'discovery_date' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'type' => $this->faker->randomElement(RiskType::cases())->value,
            'department_id' => Department::factory(),
            'description' => $this->faker->paragraph(),
            'initial_likelihood' => $likelihood,
            'initial_impact' => $impact,
            'current_likelihood' => $likelihood,
            'current_impact' => $impact,
            'current_score' => $calc['score'],
            'current_level' => $calc['level']->value,
            'status' => RiskStatus::Open->value,
            'owner_id' => User::factory(),
            'stakeholder_ids' => null,
            'preventive_measures' => $this->faker->optional()->paragraph(),
            'target_close_date' => $this->faker->optional()->dateTimeBetween('now', '+6 months'),
            'response_type' => $this->faker->randomElement(RiskResponseType::cases())->value,
            'riskable_type' => null,
            'riskable_id' => null,
            'created_by' => User::factory(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => RiskStatus::Closed->value]);
    }

    public function forOrganization(Organization $org): static
    {
        return $this->state(fn () => ['organization_id' => $org->id]);
    }
}
