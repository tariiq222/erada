<?php

namespace Database\Factories;

use App\Modules\OVR\Models\IncidentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Minimal factory for IncidentType. Used by IncidentReportFactory::definition()
 * to satisfy the `incident_type_id` foreign key when tests build incident
 * reports via the factory. Existing tests that use IncidentType::create([...])
 * directly are unaffected — both paths coexist.
 */
class IncidentTypeFactory extends Factory
{
    protected $model = IncidentType::class;

    public function definition(): array
    {
        return [
            'organization_id' => null,
            'name' => $this->faker->words(2, true),
            'name_ar' => 'نوع حادثة',
            'is_active' => true,
            'requires_reportable_type' => false,
        ];
    }
}
