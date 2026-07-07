<?php

namespace Database\Factories;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Portfolio;
use Illuminate\Database\Eloquent\Factories\Factory;

class PortfolioFactory extends Factory
{
    protected $model = Portfolio::class;

    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('-3 months', 'now');
        $endDate = $this->faker->dateTimeBetween($startDate, '+12 months');

        return [
            'code' => 'PF-'.date('Y').'-'.str_pad($this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'rationale' => $this->faker->optional()->paragraph(),
            'strategic_plan_link' => $this->faker->optional()->url(),
            'directive_source' => $this->faker->randomElement(['cluster_3', 'moh', 'holding', null]),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $this->faker->randomElement(['draft', 'active', 'completed', 'cancelled']),
            'portfolio_status' => $this->faker->randomElement(['active', 'rebalancing', 'frozen', 'closed_strategically']),
            'portfolio_progress' => $this->faker->randomFloat(2, 0, 100),
            'order' => $this->faker->numberBetween(1, 100),
            'priority_rank' => $this->faker->numberBetween(1, 10),
            'weight' => $this->faker->randomFloat(2, 1, 100),
            'created_by' => User::factory(),
            'organization_id' => Organization::factory(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'portfolio_status' => 'active',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'portfolio_progress' => 100,
        ]);
    }

    public function strategicallyActive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'portfolio_status' => 'active',
        ]);
    }

    public function frozen(): static
    {
        return $this->state(fn (array $attributes) => [
            'portfolio_status' => 'frozen',
        ]);
    }
}
