<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminE2ETestSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin-e2e@example.test';

    public const TWO_FACTOR_EMAIL = 'admin-2fa-e2e@example.test';

    public const REGULAR_EMAIL = 'regular-e2e@example.test';

    public const ISOLATED_REGULAR_EMAIL = 'regular-isolated-e2e@example.test';

    public const PASSWORD = 'AdminE2E!Password123';

    public function run(): void
    {
        if (! app()->environment('testing') || ! str_ends_with((string) DB::connection()->getDatabaseName(), '_test')) {
            throw new RuntimeException('AdminE2ETestSeeder is restricted to a testing database ending in _test.');
        }

        $primary = Organization::query()->create([
            'name' => 'Admin E2E Primary',
            'code' => 'ADMIN-E2E-PRIMARY',
            'type' => Organization::TYPE_ORGANIZATION,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $isolated = Organization::query()->create([
            'name' => 'Admin E2E Isolated',
            'code' => 'ADMIN-E2E-ISOLATED',
            'type' => Organization::TYPE_ORGANIZATION,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $department = Department::query()->create([
            'name' => 'Admin E2E Governance',
            'code' => 'ADMIN-E2E-GOV',
            'level' => Department::LEVEL_TOP_MANAGEMENT,
            'organization_id' => $primary->id,
            'is_active' => true,
        ]);
        Department::query()->create([
            'name' => 'Admin E2E Isolated Department',
            'code' => 'ADMIN-E2E-ISO',
            'level' => Department::LEVEL_TOP_MANAGEMENT,
            'organization_id' => $isolated->id,
            'is_active' => true,
        ]);

        $admin = $this->user(self::ADMIN_EMAIL, 'Admin E2E Super', $primary->id, $department->id, 'super_admin');
        $this->user(self::REGULAR_EMAIL, 'Admin E2E Regular', $primary->id, $department->id, 'viewer');
        $isolatedDepartment = Department::query()->where('code', 'ADMIN-E2E-ISO')->firstOrFail();
        $this->user(self::ISOLATED_REGULAR_EMAIL, 'Admin E2E Isolated Admin', $isolated->id, $isolatedDepartment->id, 'admin');
        $twoFactor = $this->user(self::TWO_FACTOR_EMAIL, 'Admin E2E Two Factor', $primary->id, $department->id, 'super_admin');
        $twoFactor->forceFill([
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ])->saveQuietly();

        for ($index = 1; $index <= 55; $index++) {
            ActivityLog::query()->create([
                'user_id' => $admin->id,
                'organization_id' => $primary->id,
                'action' => 'admin_e2e_fixture',
                'description' => sprintf('Admin E2E audit row %02d', $index),
                'loggable_type' => User::class,
                'loggable_id' => (string) $admin->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'AdminE2ETestSeeder',
                'created_at' => now()->subSeconds(56 - $index),
                'updated_at' => now()->subSeconds(56 - $index),
            ]);
        }
    }

    private function user(string $email, string $name, int $organizationId, int $departmentId, string $role): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'organization_id' => $organizationId,
            'department_id' => $departmentId,
            'is_active' => true,
            'registration_status' => 'approved',
        ]);
        $user->assignRole($role);

        return $user;
    }
}
