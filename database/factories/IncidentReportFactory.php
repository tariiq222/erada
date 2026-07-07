<?php

namespace Database\Factories;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Minimal factory for IncidentReport. The model uses UUIDs as PKs and
 * route-binds on `report_number` (not the id), so this factory sets
 * a stable identifier pattern that lets HTTP tests resolve URLs.
 *
 * The model also encrypts patient_name / patient_file_number
 * automatically via casts; no extra setup needed here.
 */
class IncidentReportFactory extends Factory
{
    protected $model = IncidentReport::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'reporter_id' => User::factory(),
            'reporter_name' => $this->faker->name(),
            'reporter_email' => $this->faker->safeEmail(),
            'incident_datetime' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'incident_type_id' => IncidentType::factory(),
            'incident_description' => $this->faker->sentence(),
            'severity_level' => $this->faker->randomElement(SeverityLevel::values()),
            'status' => ReportStatus::Submitted,
            'is_confidential' => false,
        ];
    }

    public function confidential(): static
    {
        return $this->state(fn () => ['is_confidential' => true]);
    }

    public function forOrganization(Organization $org): static
    {
        return $this->state(fn () => [
            'organization_id' => $org->id,
        ]);
    }
}
