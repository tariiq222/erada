<?php

namespace Database\Factories;

use App\Modules\Core\Models\Organization;
use App\Modules\Meetings\Models\MeetingCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingCategory>
 */
class MeetingCategoryFactory extends Factory
{
    protected $model = MeetingCategory::class;

    public function definition(): array
    {
        return [
            'name' => 'تصنيف '.$this->faker->word(),
            'is_active' => true,
            'sort_order' => 0,
            'organization_id' => Organization::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
