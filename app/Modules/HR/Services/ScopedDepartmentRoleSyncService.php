<?php

namespace App\Modules\HR\Services;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use Illuminate\Database\Eloquent\Collection;

/**
 * Assigns scoped department roles automatically by capacity:
 * - members (users with department_id = dept) receive the dept's 'member' role_keys
 * - the manager (departments.manager_id) receives the dept's 'manager' role_keys
 * All grants carry source='auto'; manual delegations are never touched.
 */
class ScopedDepartmentRoleSyncService
{
    /**
     * Whether the department's capacity policy still expects this role as an
     * auto grant for this user — member capacity if the user is a member of the
     * department, manager capacity if the user manages it. Drives the
     * downgrade-instead-of-delete decision on manual role removal.
     */
    public function isExpectedAutoRole(User $user, int $departmentId, string $role): bool
    {
        // member capacity applies if the user's department is this department
        if ((int) $user->department_id === $departmentId) {
            $member = DepartmentCapacityRole::where('department_id', $departmentId)
                ->where('capacity', DepartmentCapacityRole::CAPACITY_MEMBER)
                ->where('role_key', $role)->exists();
            if ($member) {
                return true;
            }
        }

        // manager capacity applies if the user manages this department
        $managesDept = Department::where('id', $departmentId)
            ->where('manager_id', $user->id)->exists();
        if ($managesDept) {
            return DepartmentCapacityRole::where('department_id', $departmentId)
                ->where('capacity', DepartmentCapacityRole::CAPACITY_MANAGER)
                ->where('role_key', $role)->exists();
        }

        return false;
    }

    public function syncUser(User $user): void
    {
        // Scopes this user currently SHOULD hold an auto role on.
        $expectedScopeIds = [];

        // Membership
        if ($user->department_id !== null) {
            $memberKeys = DepartmentCapacityRole::where('department_id', $user->department_id)
                ->where('capacity', DepartmentCapacityRole::CAPACITY_MEMBER)
                ->pluck('role_key')->all();

            $user->syncAutoScopedRolesForScope('department', (int) $user->department_id, $memberKeys);
            $expectedScopeIds[(int) $user->department_id] = true;
        }

        // Leadership (one or more departments where this user is the manager)
        $managedDeptIds = Department::where('manager_id', $user->id)->pluck('id');
        foreach ($managedDeptIds as $deptId) {
            $managerKeys = DepartmentCapacityRole::where('department_id', $deptId)
                ->where('capacity', DepartmentCapacityRole::CAPACITY_MANAGER)
                ->pluck('role_key')->all();

            // merge with member keys if they are also a member of this same dept
            $existing = $expectedScopeIds[(int) $deptId] ?? false;
            if ($existing) {
                $memberKeys = DepartmentCapacityRole::where('department_id', $deptId)
                    ->where('capacity', DepartmentCapacityRole::CAPACITY_MEMBER)
                    ->pluck('role_key')->all();
                $managerKeys = array_values(array_unique(array_merge($managerKeys, $memberKeys)));
            }

            $user->syncAutoScopedRolesForScope('department', (int) $deptId, $managerKeys);
            $expectedScopeIds[(int) $deptId] = true;
        }

        // Cleanup: remove auto department-scope roles on scopes no longer expected.
        $staleScopeIds = $user->scopedRoles()
            ->where('source', 'auto')
            ->where('scope_type', 'department')
            ->pluck('scope_id')
            ->unique()
            ->reject(fn ($id) => isset($expectedScopeIds[(int) $id]));

        foreach ($staleScopeIds as $scopeId) {
            $user->revokeAutoScopedRolesForScope('department', (int) $scopeId);
        }
    }

    public function syncDepartment(Department $department): void
    {
        $department->users()->chunkById(200, function (Collection $users) {
            foreach ($users as $user) {
                $this->syncUser($user);
            }
        });

        if ($department->manager_id !== null) {
            $manager = User::find($department->manager_id);
            if ($manager !== null) {
                $this->syncUser($manager);
            }
        }

        // Users holding an auto role on this department but no longer member/manager.
        $holderIds = ScopedRole::query()
            ->where('source', 'auto')
            ->where('scope_type', 'department')
            ->where('scope_id', $department->id)
            ->pluck('user_id')
            ->unique();

        User::whereIn('id', $holderIds)->chunkById(200, function (Collection $users) {
            foreach ($users as $user) {
                $this->syncUser($user);
            }
        });
    }
}
