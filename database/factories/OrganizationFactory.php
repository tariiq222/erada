<?php

namespace Database\Factories;

use App\Modules\Core\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'code' => strtoupper($this->faker->unique()->lexify('ORG-????')),
            'description' => $this->faker->paragraph(),
            'email' => $this->faker->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
            'website' => $this->faker->url(),
            'is_active' => true,
            'type' => Organization::TYPE_ORGANIZATION,
            'parent_id' => null,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function cluster(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Organization::TYPE_CLUSTER,
        ]);
    }

    public function hospital(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Organization::TYPE_HOSPITAL,
        ]);
    }

    public function center(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Organization::TYPE_CENTER,
        ]);
    }

    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }

    public function childOf(Organization $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parent->getKey(),
        ]);
    }
}
