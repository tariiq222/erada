<?php

namespace Database\Factories;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Minimal factory for EmployeeProfile (HR test coverage only).
 *
 * Mirrors CertificateFactory's shape: keeps fillable columns + a FK to User.
 * The owning User is auto-created if not supplied so test fixtures don't have
 * to wire the User↔Profile relation by hand.
 */
class EmployeeProfileFactory extends Factory
{
    protected $model = EmployeeProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'employee_no' => 'EMP-'.Str::upper(Str::random(8)),
            'hire_date' => $this->faker->dateTimeBetween('-5 years', '-1 month')->format('Y-m-d'),
            'ministry_hire_date' => null,
            'employment_type' => 'full_time',
            'employment_status' => 'active',
            'contract_type' => null,
            'social_insurance_number' => null,
            'specialization' => null,
            'current_work_field' => null,
            'fingerprint_number' => null,
            'staff_category' => 'administrative',
            'notes' => null,
        ];
    }

    public function forUser(int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
        ]);
    }
}
