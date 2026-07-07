<?php

namespace App\Modules\Core\Observers;

use App\Modules\Core\Models\User;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;

class UserObserver
{
    /**
     * On user creation, sync the capacity-aware scoped department roles.
     */
    public function created(User $user): void
    {
        app(ScopedDepartmentRoleSyncService::class)->syncUser($user);
    }

    /**
     * On user update, resync only when the department membership changed.
     */
    public function updated(User $user): void
    {
        if ($user->wasChanged('department_id')) {
            app(ScopedDepartmentRoleSyncService::class)->syncUser($user);
        }
    }
}
