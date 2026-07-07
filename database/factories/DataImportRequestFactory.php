<?php

namespace Database\Factories;

use App\Modules\Surveys\Enums\ImportStatus;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\SurveyResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataImportRequest>
 *
 * ملاحظة: لا يوجد عمود organization_id على DataImportRequest — تُشتقّ المؤسسة
 * فقط عبر response.survey.organization_id. الـ Survey::factory المتداخل يضبط
 * organization_id إلى null افتراضياً، لذا يجب على الاختبارات تمرير سلسلة
 * استبيان/إجابة مرتبطة بمؤسسة صراحةً لربط الطلب بمؤسسة محددة.
 */
class DataImportRequestFactory extends Factory
{
    protected $model = DataImportRequest::class;

    public function definition(): array
    {
        return [
            'response_id' => SurveyResponse::factory(),
            'template_id' => DataMappingTemplate::factory(),
            'target_table' => 'departments',
            'operation' => 'create',
            'payload' => ['name' => $this->faker->company()],
            'status' => ImportStatus::Pending,
            'priority' => 0,
            'requested_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ImportStatus::Pending]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ImportStatus::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ImportStatus::Rejected,
            'rejection_reason' => $this->faker->sentence(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ImportStatus::Failed,
            'error_message' => $this->faker->sentence(),
        ]);
    }
}
