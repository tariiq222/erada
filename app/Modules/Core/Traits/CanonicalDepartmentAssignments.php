<?php

namespace App\Modules\Core\Traits;

use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\HR\Models\Department;
use Illuminate\Support\Collection;

/**
 * Canonical department-assignment helpers backed exclusively by the unified
 * authorization role assignments table.
 */
trait CanonicalDepartmentAssignments
{
    private const DEPARTMENT_MANAGER_ROLE = 'dept_manager';

    public function roleInDepartment(int|Department $department): ?string
    {
        $departmentId = $department instanceof Department ? $department->id : $department;

        $directRole = $this->activeCanonicalRoleAssignments()
            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_DEPARTMENT)
            ->where('scope_id', $departmentId)
            ->with('role:id,name,is_active')
            ->first();

        if ($directRole) {
            return $directRole->role?->name;
        }

        $departmentModel = $department instanceof Department ? $department : Department::find($departmentId);
        if (! $departmentModel) {
            return null;
        }

        $ancestorIds = array_map('intval', $departmentModel->getAncestors()->pluck('id')->toArray());
        if ($ancestorIds === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($ancestorIds), '?'));
        $inheritedRole = $this->activeCanonicalRoleAssignments()
            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_DEPARTMENT)
            ->whereIn('scope_id', $ancestorIds)
            ->where('inherit_to_children', true)
            ->with('role:id,name,is_active')
            ->orderByRaw("array_position(ARRAY[$placeholders]::bigint[], scope_id)", $ancestorIds)
            ->first();

        return $inheritedRole?->role?->name;
    }

    public function hasRoleInDepartment(int|Department $department, string|array|null $roles = null): bool
    {
        $userRole = $this->roleInDepartment($department);
        if (! $userRole) {
            return false;
        }

        if ($roles === null) {
            return true;
        }

        return in_array($userRole, is_array($roles) ? $roles : [$roles], true);
    }

    public function isDepartmentManager(int|Department $department): bool
    {
        return $this->hasRoleInDepartment($department, self::DEPARTMENT_MANAGER_ROLE);
    }

    public function isDepartmentAdmin(int|Department $department): bool
    {
        return $this->roleInDepartment($department) === self::DEPARTMENT_MANAGER_ROLE;
    }

    public function getManagedDepartments(): Collection
    {
        $directDepartmentIds = $this->activeCanonicalRoleAssignments()
            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_DEPARTMENT)
            ->whereHas('role', fn ($query) => $query->where('name', self::DEPARTMENT_MANAGER_ROLE))
            ->pluck('scope_id');

        $managedDepartmentIds = collect();
        foreach ($directDepartmentIds as $departmentId) {
            $department = Department::find($departmentId);
            if ($department) {
                $managedDepartmentIds = $managedDepartmentIds->merge($department->getAllChildrenIds());
            }
        }

        return Department::whereIn('id', $managedDepartmentIds->unique())->get();
    }

    /** @return list<int> */
    public function getManagedDepartmentIds(): array
    {
        return $this->getManagedDepartments()->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function canAccessDepartment(int|Department $department): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $departmentModel = $department instanceof Department ? $department : Department::find($department);
        if (! $departmentModel) {
            return false;
        }

        if ($this->organization_id === null
            || $departmentModel->organization_id === null
            || (int) $this->organization_id !== (int) $departmentModel->organization_id) {
            return false;
        }

        return (int) $this->department_id === (int) $departmentModel->id
            || $this->hasRoleInDepartment($departmentModel);
    }
}
