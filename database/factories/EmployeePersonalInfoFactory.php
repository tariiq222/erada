<?php

namespace Database\Factories;

use App\Modules\HR\Models\EmployeePersonalInfo;
use App\Modules\HR\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Minimal factory for EmployeePersonalInfo (HR test coverage only).
 *
 * FK to EmployeeProfile (caller-supplied via forProfile). National_id /
 * iqama_number are kept null in the base shape — caller is responsible for
 * filling the right one based on nationality to satisfy any future unique
 * constraints.
 */
class EmployeePersonalInfoFactory extends Factory
{
    protected $model = EmployeePersonalInfo::class;

    public function definition(): array
    {
        return [
            'employee_profile_id' => null,
            'full_name_english' => $this->faker->name(),
            'full_name_arabic' => 'اسم تجريبي',
            'nationality' => 'SA',
            'gender' => 'male',
            'birth_date' => $this->faker->date('Y-m-d', '-30 years'),
            'address' => $this->faker->address(),
            'emergency_contact' => $this->faker->name(),
            'emergency_phone' => $this->faker->numerify('05########'),
            'emergency_contact_relation' => 'brother',
            'national_id' => null,
            'national_id_issue_date' => null,
            'national_id_issue_place' => null,
            'national_id_expiry_date' => null,
            'national_id_document_path' => null,
            'iqama_number' => null,
            'iqama_issue_date' => null,
            'iqama_issue_place' => null,
            'iqama_expiry_date' => null,
            'iqama_document_path' => null,
            'profession' => null,
            'religion' => null,
            'sponsor' => null,
        ];
    }

    public function forProfile(int $employeeProfileId): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_profile_id' => $employeeProfileId,
        ]);
    }
}
