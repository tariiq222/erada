<?php

namespace Database\Factories;

use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\SurveyStatus;
use App\Modules\Surveys\Enums\SurveyType;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Database\Eloquent\Factories\Factory;

class SurveyFactory extends Factory
{
    protected $model = Survey::class;

    public function definition(): array
    {
        return [
            'code' => 'SRV-'.date('Y').'-'.str_pad($this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'organization_id' => null,
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'type' => SurveyType::Initial,
            'status' => SurveyStatus::Draft,
            'is_public' => false,
            'requires_auth' => true,
            'accepting_responses' => false,
            'allow_multiple_responses' => false,
            'allow_edit_response' => true,
            'consent_required' => false,
            'created_by' => User::factory(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SurveyStatus::Draft,
            'accepting_responses' => false,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SurveyStatus::Published,
            'accepting_responses' => true,
            'published_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SurveyStatus::Closed,
            'accepting_responses' => false,
            'published_at' => now()->subDays(30),
            'closed_at' => now(),
        ]);
    }

    public function public(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => true,
            'requires_auth' => false,
        ]);
    }

    public function initial(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => SurveyType::Initial,
        ]);
    }

    public function periodic(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => SurveyType::Periodic,
        ]);
    }

    public function withDateRange(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subDays(7),
            'ends_at' => now()->addDays(30),
        ]);
    }
}
