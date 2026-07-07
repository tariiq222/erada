<?php

namespace Database\Seeders;

use Database\Seeders\Scenarios\AuthzTestFixturesScenario;
use Database\Seeders\Scenarios\GenericCompanyScenario;
use Database\Seeders\Scenarios\HospitalScenario;
use Illuminate\Database\Seeder;

/**
 * Demo data entry point.
 *
 * Usage:
 *   php artisan db:seed --class=DemoDataSeeder                # generic company (default)
 *   php artisan db:seed --class=DemoDataSeeder -- hospital    # hospital scenario
 *   php artisan db:seed --class=DemoDataSeeder -- authz       # 8 authz fixtures
 *   DEMO_SCENARIO=hospital php artisan db:seed --class=DemoDataSeeder
 *   DEMO_SCENARIO=authz    php artisan db:seed --class=DemoDataSeeder
 *
 * Scenarios:
 *   generic   - multi-department company with strategy, projects, tasks, surveys
 *   hospital  - Al-Noor hospital with 4-level org hierarchy, OVR incident types
 *   authz     - eight deliberately diverse org fixtures for exercising the
 *               unified AuthZ engine (flat / deep / wide / hybrid / multi-tenant /
 *               orphan / path-collision / cycle-attempt)
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $scenario = $this->resolveScenario();

        $this->command->info('');
        $this->command->info("Running demo scenario: [{$scenario}]");
        $this->command->info('');

        match ($scenario) {
            'hospital' => (new HospitalScenario($this->command))->run(),
            'authz' => (new AuthzTestFixturesScenario($this->command))->run(),
            default => (new GenericCompanyScenario($this->command))->run(),
        };

        $this->command->info('');
        $this->command->info('Login: admin@admin.com / password');
    }

    private function resolveScenario(): string
    {
        // CLI argument: php artisan db:seed --class=DemoDataSeeder -- hospital
        $argv = $_SERVER['argv'] ?? [];
        foreach ($argv as $arg) {
            if (in_array($arg, ['hospital', 'generic', 'authz'], true)) {
                return $arg;
            }
        }

        // Environment variable
        return strtolower(env('DEMO_SCENARIO', 'generic'));
    }
}
