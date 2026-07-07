<?php

namespace Database\Factories;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'type' => 'project', // نوع المهمة - project افتراضياً
            // source_type defaults to NULL — tasks are linked to their parent
            // (project / department) via dedicated FK columns. The
            // polymorphic source_type is reserved for engine-aware parents
            // (Recommendation, MeetingResolution, Risk, etc.) which is what
            // Task::scopeVisibleTo() branches against. Defaulting to 'Project'
            // here broke the legacy branch (2) of the visibility scope.
            'source_type' => null,
            'source_id' => null,
            'status' => $this->faker->randomElement(['todo', 'in_progress', 'in_review', 'completed']),
            'priority' => $this->faker->randomElement(['low', 'medium', 'high', 'critical']),
            'due_date' => $this->faker->dateTimeBetween('now', '+3 months'),
            'estimated_hours' => $this->faker->numberBetween(1, 40),
            'actual_hours' => $this->faker->numberBetween(0, 40),
            'progress' => $this->faker->numberBetween(0, 100),
            'project_id' => Project::factory(),
            'assigned_to' => User::factory(),
        ];
    }

    public function todo(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'todo',
            'progress' => 0,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'progress' => 100,
        ]);
    }
}
