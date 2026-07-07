<?php

namespace Database\Factories;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProgramFactory extends Factory
{
    protected $model = Program::class;

    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('-1 month', '+1 month');
        $endDate = $this->faker->dateTimeBetween($startDate, '+12 months');

        return [
            'code' => 'PRG-'.date('Y').'-'.str_pad($this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'portfolio_id' => Portfolio::factory(),
            'department_id' => Department::factory(),
            'budget' => $this->faker->randomFloat(2, 50000, 5000000),
            'spent_amount' => $this->faker->randomFloat(2, 0, 1000000),
            'total_program_budget' => $this->faker->randomFloat(2, 100000, 10000000),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'progress' => $this->faker->randomFloat(2, 0, 100),
            'weight' => $this->faker->randomFloat(2, 1, 100),
            'status' => $this->faker->randomElement(['draft', 'planning', 'in_progress', 'on_hold', 'completed', 'cancelled']),
            'priority' => $this->faker->randomElement(['low', 'medium', 'high', 'critical']),
            'progress_calculation_method' => $this->faker->randomElement(['weighted', 'average', 'manual']),
            'created_by' => User::factory(),
            'order' => $this->faker->numberBetween(1, 100),
            'organization_id' => Organization::factory(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    public function planning(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'planning',
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'progress' => 100,
        ]);
    }

    public function onHold(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'on_hold',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 'high',
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 'critical',
        ]);
    }

    public function forPortfolio(Portfolio $portfolio): static
    {
        return $this->state(fn (array $attributes) => [
            'portfolio_id' => $portfolio->id,
        ]);
    }
}
