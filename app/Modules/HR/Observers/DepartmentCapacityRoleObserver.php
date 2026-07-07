<?php

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;

class DepartmentCapacityRoleObserver
{
    public function created(DepartmentCapacityRole $policy): void
    {
        $this->resync($policy);
    }

    public function updated(DepartmentCapacityRole $policy): void
    {
        $this->resync($policy);
    }

    public function deleted(DepartmentCapacityRole $policy): void
    {
        $this->resync($policy);
    }

    protected function resync(DepartmentCapacityRole $policy): void
    {
        $department = $policy->department;
        if ($department !== null) {
            app(ScopedDepartmentRoleSyncService::class)->syncDepartment($department);
        }
    }
}
