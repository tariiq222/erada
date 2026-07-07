<?php

namespace App\Modules\Core\Traits;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\HR\Models\Department;
use Illuminate\Support\Collection;

/**
 * HasDepartmentRoles Trait
 *
 * يوفر إدارة الأدوار على مستوى الأقسام مع دعم الوراثة الهرمية
 */
trait HasDepartmentRoles
{
    // ========== استعلامات أدوار الأقسام ==========

    /**
     * الحصول على دور المستخدم في قسم معين
     */
    public function roleInDepartment(int|Department $department): ?string
    {
        $departmentId = $department instanceof Department ? $department->id : $department;

        // 1. تحقق من الدور المباشر
        $directRole = $this->activeScopedRoles()
            ->inScope(ScopedRole::SCOPE_DEPARTMENT, $departmentId)
            ->first();

        if ($directRole) {
            return $directRole->role;
        }

        // 2. تحقق من الوراثة من الأقسام الأب
        $dept = $department instanceof Department ? $department : Department::find($departmentId);
        if (! $dept) {
            return null;
        }

        $ancestorIds = array_map('intval', $dept->getAncestors()->pluck('id')->toArray());

        if (empty($ancestorIds)) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($ancestorIds), '?'));

        $inheritedRole = $this->activeScopedRoles()
            ->ofType(ScopedRole::SCOPE_DEPARTMENT)
            ->whereIn('scope_id', $ancestorIds)
            ->where('inherit_to_children', true)
            ->orderByRaw("array_position(ARRAY[$placeholders]::bigint[], scope_id)", $ancestorIds)
            ->first();

        return $inheritedRole?->role;
    }

    /**
     * هل لديه دور في القسم (مع الوراثة)؟
     */
    public function hasRoleInDepartment(int|Department $department, string|array|null $roles = null): bool
    {
        $userRole = $this->roleInDepartment($department);

        if (! $userRole) {
            return false;
        }

        if ($roles === null) {
            return true;
        }

        $roles = is_array($roles) ? $roles : [$roles];

        return in_array($userRole, $roles);
    }

    /**
     * هل هو مدير القسم (مباشرة أو بالوراثة)؟
     */
    public function isDepartmentManager(int|Department $department): bool
    {
        return $this->hasRoleInDepartment($department, ScopedRole::DEPARTMENT_MANAGER);
    }

    /**
     * هل هو مدير/مشرف في القسم؟
     */
    public function isDepartmentAdmin(int|Department $department): bool
    {
        $role = $this->roleInDepartment($department);

        return $role && ScopedRole::isDepartmentAdminRole($role);
    }

    /**
     * الحصول على جميع الأقسام التي يديرها (مع الفروع)
     */
    public function getManagedDepartments(): Collection
    {
        // الأقسام التي لديه دور إداري فيها مباشرة
        $directDeptIds = $this->activeScopedRoles()
            ->ofType(ScopedRole::SCOPE_DEPARTMENT)
            ->whereIn('role', [ScopedRole::DEPARTMENT_MANAGER, ScopedRole::DEPARTMENT_SUPERVISOR])
            ->pluck('scope_id');

        $allDeptIds = collect();

        foreach ($directDeptIds as $deptId) {
            $dept = Department::find($deptId);
            if ($dept) {
                $allDeptIds = $allDeptIds->merge($dept->getAllChildrenIds());
            }
        }

        return Department::whereIn('id', $allDeptIds->unique())->get();
    }

    /**
     * الحصول على IDs الأقسام التي يديرها
     */
    public function getManagedDepartmentIds(): array
    {
        return $this->getManagedDepartments()->pluck('id')->toArray();
    }

    // ========== تعيين وإزالة أدوار الأقسام ==========

    /**
     * تعيين دور في قسم
     */
    public function assignDepartmentRole(
        int|Department $department,
        string $role,
        ?int $grantedBy = null,
        bool $inheritToChildren = true,
        ?\DateTimeInterface $expiresAt = null
    ): ScopedRole {
        $departmentId = $department instanceof Department ? $department->id : $department;

        return $this->assignScopedRole(
            $role,
            ScopedRole::SCOPE_DEPARTMENT,
            $departmentId,
            $grantedBy,
            $inheritToChildren,
            $expiresAt
        );
    }

    /**
     * إزالة دور من قسم
     */
    public function revokeDepartmentRole(int|Department $department): bool
    {
        $departmentId = $department instanceof Department ? $department->id : $department;

        return $this->revokeScopedRole(ScopedRole::SCOPE_DEPARTMENT, $departmentId);
    }

    // ========== التحقق من الوصول للأقسام ==========

    /**
     * هل يمكنه الوصول للقسم؟
     */
    public function canAccessDepartment(int|Department $department): bool
    {
        // 1. Super Admin يصل لكل شيء
        if ($this->isSuperAdmin()) {
            return true;
        }

        // 2. تحديد معرف القسم وتحميل النموذج
        $deptId = $department instanceof Department ? $department->id : $department;
        $dept = $department instanceof Department ? $department : Department::find($deptId);

        if (! $dept) {
            return false;
        }

        // 3. عزل المؤسسة (D-02/D-04): لا يمكن الوصول لقسم من مؤسسة أخرى
        if ($this->organization_id === null
            || $dept->organization_id === null
            || $this->organization_id !== $dept->organization_id) {
            return false;
        }

        // 4. هو في هذا القسم
        if ($this->department_id === $dept->id) {
            return true;
        }

        // 5. لديه دور إداري في القسم أو قسم أب
        if ($this->hasRoleInDepartment($dept)) {
            return true;
        }

        return false;
    }
}
