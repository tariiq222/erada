<?php

namespace Database\Factories;

use App\Modules\HR\Models\EmployeeCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Minimal factory for EmployeeCertificate, mirroring the project's
 * ProjectExpenseFactory shape — fillable columns from EmployeeCertificate plus
 * a FK to EmployeeProfile (caller-supplied via `forProfile()` because
 * EmployeeProfile has no factory). Used for cross-org deletion coverage;
 * happy-path tests still build the model explicitly to assert on file_path
 * semantics.
 */
class CertificateFactory extends Factory
{
    protected $model = EmployeeCertificate::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(EmployeeCertificate::TYPES);

        return [
            'employee_profile_id' => null,
            'type' => $type,
            'title' => Str::title($type).' Certificate',
            'file_path' => 'hr/employees/'.$this->faker->numberBetween(1, 1000).'/'.$type.'/'.Str::uuid().'.pdf',
            'file_name' => $this->faker->slug(2).'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => $this->faker->numberBetween(10, 5000),
            'issued_at' => $this->faker->dateTimeBetween('-2 years', '-1 year')->format('Y-m-d'),
            'expires_at' => $this->faker->dateTimeBetween('-1 month', '+1 year')->format('Y-m-d'),
            'notes' => null,
        ];
    }

    public function forProfile(int $employeeProfileId): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_profile_id' => $employeeProfileId,
        ]);
    }
}
