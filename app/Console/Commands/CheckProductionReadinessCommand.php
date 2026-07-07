<?php

namespace App\Console\Commands;

use App\Support\ProductionReadiness\ProductionReadinessChecklist;
use Illuminate\Console\Command;

class CheckProductionReadinessCommand extends Command
{
    protected $signature = 'production:check-readiness
        {--force : Enforce production readiness checks even outside APP_ENV=production}
        {--scheduler-confirmed : Assert that production scheduler deployment has been configured}';

    protected $description = 'Check whether production configuration is safe for deployment.';

    public function handle(ProductionReadinessChecklist $checklist): int
    {
        $snapshot = ProductionReadinessChecklist::snapshotFromLaravel(
            $this->option('scheduler-confirmed') ? true : null
        );

        $result = $checklist->evaluate($snapshot, (bool) $this->option('force'));

        if ($result['skipped']) {
            $this->info('Production readiness check skipped because APP_ENV is not production. Use --force to enforce locally.');

            return self::SUCCESS;
        }

        if (! $result['passed']) {
            $this->error('Production readiness check failed:');

            foreach ($result['failures'] as $failure) {
                $this->line('- '.$failure);
            }

            return self::FAILURE;
        }

        $this->info('Production readiness check passed.');

        foreach ($result['checks'] as $check) {
            $this->line('- '.$check);
        }

        return self::SUCCESS;
    }
}
