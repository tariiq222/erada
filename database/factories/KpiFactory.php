<?php

namespace Database\Factories;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use Illuminate\Database\Eloquent\Factories\Factory;

class KpiFactory extends Factory
{
    protected $model = Kpi::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->sentence(),
            'measurement_method' => $this->faker->sentence(),
            'category' => 'project',
            'baseline' => 0,
            'target' => 100,
            'current_value' => 0,
            'unit' => null,
            'frequency' => 'monthly',
            'direction' => 'increase',
            'status' => 'active',
            'owner_id' => User::factory(),
            'created_by' => User::factory(),
            'order' => 0,
        ];
    }
}
