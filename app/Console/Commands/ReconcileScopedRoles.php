<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\User;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use Illuminate\Console\Command;

class ReconcileScopedRoles extends Command
{
    protected $signature = 'roles:reconcile';

    protected $description = 'Recompute all auto scoped roles from HR facts (idempotent)';

    public function handle(ScopedDepartmentRoleSyncService $service): int
    {
        $count = 0;

        User::query()->chunkById(200, function ($users) use ($service, &$count) {
            foreach ($users as $user) {
                $service->syncUser($user);
                $count++;
            }
        });

        $this->info("Reconciled scoped roles for {$count} users.");

        return self::SUCCESS;
    }
}
