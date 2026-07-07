<?php

namespace Database\Factories;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectExpenseFactory extends Factory
{
    protected $model = ProjectExpense::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'created_by' => User::factory(),
            'title' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'amount' => $this->faker->randomFloat(2, 10, 5000),
            'category' => $this->faker->randomElement(array_keys(ProjectExpense::CATEGORIES)),
            'expense_date' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'reference_number' => $this->faker->numerify('REF-####'),
        ];
    }
}
