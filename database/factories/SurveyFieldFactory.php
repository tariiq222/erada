<?php

namespace Database\Factories;

use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use Illuminate\Database\Eloquent\Factories\Factory;

class SurveyFieldFactory extends Factory
{
    protected $model = SurveyField::class;

    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            'section_id' => null,
            'label' => $this->faker->sentence(3),
            'name' => $this->faker->unique()->slug(2),
            'type' => $this->faker->randomElement(['text', 'textarea', 'select', 'radio', 'checkbox', 'number', 'date']),
            'is_required' => $this->faker->boolean(70),
            'order' => $this->faker->numberBetween(1, 100),
            'config' => [],
        ];
    }

    public function required(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => true,
        ]);
    }

    public function optional(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => false,
        ]);
    }

    public function text(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'text',
        ]);
    }

    public function select(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'select',
            'config' => [
                'options' => [
                    ['label' => 'خيار 1', 'value' => 'option1'],
                    ['label' => 'خيار 2', 'value' => 'option2'],
                    ['label' => 'خيار 3', 'value' => 'option3'],
                ],
            ],
        ]);
    }
}
