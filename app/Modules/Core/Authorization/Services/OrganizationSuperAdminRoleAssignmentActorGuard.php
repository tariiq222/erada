<?php

namespace App\Modules\Core\Authorization\Services;

use App\Modules\Core\Authorization\Contracts\AuthorizationAssignmentActorGuard;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite).
 *
 * Narrow actor guard for the OrgSuper-specific role-assignment route. Replaces
 * CanonicalAuthorizationAssignmentActorGuard for /api/org-super/role-assignments.
 *
 * Contract — `allows()` returns true ONLY IF ALL conditions hold:
 *   - actor is organization_super_admin AND NOT super_admin
 *   - subject.organization_id === actor.organization_id (same-org fail-closed)
 *   - subject has NO active super_admin or organization_super_admin assignment
 *   - role.name NOT IN ['super_admin', 'organization_super_admin', 'admin']
 *   - role.is_admin_role === false
 *   - role.is_system === false
 *   - role.is_active === true
 *   - scope.type === 'organization'
 *   - scope.id === actor.organization_id (server-derived)
 *   - scope.inheritToChildren === false
 */
final class OrganizationSuperAdminRoleAssignmentActorGuard implements AuthorizationAssignmentActorGuard
{
    /** @var list<string> */
    private const FORBIDDEN_ROLE_NAMES = [
        'super_admin',
        'organization_super_admin',
        'admin',
    ];

    /** @var list<string> */
    private const PROTECTED_TARGET_ROLE_NAMES = [
        'super_admin',
        'organization_super_admin',
    ];

    public function allows(User $actor, User $subject, AuthorizationRole $role, AssignmentScope $scope): bool
    {
        // 1. Actor must be OrgSuper, not super_admin.
        if (! $actor->isOrganizationSuperAdmin() || $actor->isSuperAdmin()) {
            return false;
        }

        // 2. Subject must be in actor's organization.
        if ($actor->organization_id === null
            || $subject->organization_id === null
            || (int) $actor->organization_id !== (int) $subject->organization_id) {
            return false;
        }

        // 3. Subject must NOT hold a protected role.
        $hasProtectedAssignment = AuthorizationRoleAssignment::query()
            ->where('user_id', $subject->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('role', fn ($roleQuery) => $roleQuery
                ->whereIn('name', self::PROTECTED_TARGET_ROLE_NAMES)
                ->where('is_active', true))
            ->exists();
        if ($hasProtectedAssignment) {
            return false;
        }

        // 4. Role must be active, not is_admin_role, not is_system, not in forbidden names.
        if (! (bool) $role->is_active) {
            return false;
        }
        if ((bool) $role->is_admin_role || (bool) $role->is_system) {
            return false;
        }
        if (in_array($role->name, self::FORBIDDEN_ROLE_NAMES, true)) {
            return false;
        }

        // 5. Scope is server-derived: organization + actor's org id + no children.
        if ($scope->type !== AssignmentScope::ORGANIZATION) {
            return false;
        }
        if ($scope->id === null || (int) $scope->id !== (int) $actor->organization_id) {
            return false;
        }
        if ($scope->inheritToChildren) {
            return false;
        }

        return true;
    }
}
