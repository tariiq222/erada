<?php

namespace Database\Factories;

use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\ConflictPolicy;
use App\Modules\Surveys\Enums\InsertPolicy;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataMappingTemplate>
 */
class DataMappingTemplateFactory extends Factory
{
    protected $model = DataMappingTemplate::class;

    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            'name' => $this->faker->sentence(2),
            'description' => $this->faker->sentence(),
            'target_model' => 'departments',
            'mappings' => [
                'name' => ['column' => 'name', 'required' => true],
            ],
            'insert_policy' => InsertPolicy::Upsert,
            // RequireReview يجعل الحالة الأولية Pending في determineInitialStatus
            // (هذا ما يطلق إشعار DataImportPending — مطلوب لاختبارات SC3).
            'conflict_policy' => ConflictPolicy::RequireReview,
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => true]);
    }
}
