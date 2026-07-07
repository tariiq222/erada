<?php

namespace Database\Factories;

use App\Modules\Core\Models\Organization;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('now', '+1 month');
        $endDate = $this->faker->dateTimeBetween($startDate, '+6 months');

        // ponytail: cascade-coherent org — share ONE Organization factory
        // between organization_id and the dept's organization_id. Without this,
        // each factory call independently creates its own Organization, so
        // project.org_id and dept.org_id diverge — which ProjectObserver::saving
        // would then auto-correct (logging a warning every test). By binding both
        // to the same factory instance, they resolve to the same org row.
        $organization = Organization::factory();

        return [
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'status' => $this->faker->randomElement(['draft', 'planning', 'in_progress', 'on_hold', 'completed']),
            'priority' => $this->faker->randomElement(['low', 'medium', 'high', 'critical']),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'budget' => $this->faker->randomFloat(2, 10000, 1000000),
            'progress' => $this->faker->numberBetween(0, 100),
            'organization_id' => $organization,
            'department_id' => Department::factory()->state([
                'organization_id' => $organization,
            ]),
        ];
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
}
