<?php

namespace Database\Factories;

use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    protected $model = Milestone::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('now', '+1 month');
        $dueDate = $this->faker->dateTimeBetween($startDate, '+3 months');

        return [
            'project_id' => Project::factory(),
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'start_date' => $startDate,
            'due_date' => $dueDate,
            'completed_date' => null,
            'status' => $this->faker->randomElement(['pending', 'in_progress', 'completed', 'overdue']),
            'progress' => $this->faker->randomFloat(2, 0, 100),
            'order' => $this->faker->numberBetween(0, 10),
        ];
    }

    /**
     * مرحلة معلقة
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'progress' => 0,
            'completed_date' => null,
        ]);
    }

    /**
     * مرحلة قيد التنفيذ
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
            'progress' => $this->faker->randomFloat(2, 10, 90),
            'completed_date' => null,
        ]);
    }

    /**
     * مرحلة مكتملة
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'progress' => 100,
            'completed_date' => now(),
        ]);
    }

    /**
     * مرحلة متأخرة
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'overdue',
            'due_date' => now()->subDays(5),
            'completed_date' => null,
        ]);
    }
}
