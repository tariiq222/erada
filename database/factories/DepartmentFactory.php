<?php

namespace Database\Factories;

use App\Modules\Core\Models\Organization;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' - '.$this->faker->randomElement(['قسم', 'إدارة', 'وحدة']),
            'code' => strtoupper($this->faker->unique()->lexify('???-???')),
            'level' => $this->faker->numberBetween(1, 6),
            'description' => $this->faker->sentence(),
            'is_active' => true,
            'organization_id' => Organization::factory(),
        ];
    }

    public function level(int $level): static
    {
        return $this->state(fn (array $attributes) => [
            'level' => $level,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Bind this department's organization_id to a specific Organization id
     * (used by ProjectFactory to keep project.org == dept.org).
     */
    public function withOrganization(int $organizationId): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organizationId,
        ]);
    }
}
