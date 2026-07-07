<?php

namespace App\Modules\RiskManagement\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\RiskSetting;

/**
 * Department-scoped + governing-department authorization for risks (mirrors
 * ProjectAuthorizationService). A single governing department applies to all
 * risk types.
 *
 * Creation paths (on top of an org-level functional grant):
 *   (1) Own-department subtree — a department manager/member may create risks for
 *       their own department or any descendant of it.
 *   (2) Governing department — a member of (the subtree of) the risks governing
 *       department may create a risk for ANY department in the organization, and
 *       sees every risk org-wide.
 */
class RiskAuthorizationService
{
    public function canCreate(User $user, ?int $departmentId = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (AccessDecision::can($user, Capability::RISKS_CREATE)) {
            return true;
        }

        $createDeptIds = $this->createScopeDepartmentIds($user);
        if ($createDeptIds === []) {
            return false;
        }

        if ($this->governs($user)) {
            return true;
        }

        $target = $departmentId ?? $user->department_id;

        return $target !== null && in_array((int) $target, $createDeptIds, true);
    }

    public function canCreateAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Engine-only (matches the pre-existing policy contract: a flat Spatie
        // create_risks permission alone never granted creation — the policy
        // required an engine grant). Department-scoped create roles qualify.
        if (AccessDecision::can($user, Capability::RISKS_CREATE)) {
            return true;
        }

        return $this->createScopeDepartmentIds($user) !== [];
    }

    /**
     * @return list<int>
     */
    public function createScopeDepartmentIds(User $user): array
    {
        $scopes = AccessDecision::grantingScopes($user, Capability::RISKS_CREATE);

        return AccessDecision::subtreeDepartmentIds($scopes['department'] ?? []);
    }

    /**
     * Departments the user may target when creating a risk. null => unrestricted
     * (super_admin, org-level creator, or governing-department member). [] => none.
     *
     * @return list<int>|null
     */
    public function creatableDepartmentIds(User $user): ?array
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        if (AccessDecision::can($user, Capability::RISKS_CREATE)) {
            return null;
        }

        $createDeptIds = $this->createScopeDepartmentIds($user);
        if ($createDeptIds === []) {
            return [];
        }

        if ($this->governs($user)) {
            return null;
        }

        return $createDeptIds;
    }

    /**
     * Is the user a member of (the subtree of) the risks governing department
     * with a risks.view grant — i.e. an org-wide risk overseer?
     */
    public function governs(User $user): bool
    {
        $governingId = RiskSetting::getGoverningDepartmentId();
        if ($governingId === null) {
            return false;
        }

        $viewDeptIds = AccessDecision::subtreeDepartmentIds(
            AccessDecision::grantingScopes($user, Capability::RISKS_VIEW)['department'] ?? []
        );
        if ($viewDeptIds === []) {
            return false;
        }

        $governingSubtree = AccessDecision::subtreeDepartmentIds([$governingId]);

        return array_intersect($viewDeptIds, $governingSubtree) !== [];
    }

    /**
     * Can the user see any risk at all (for coarse list/route gating)?
     */
    public function canViewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (AccessDecision::can($user, Capability::RISKS_VIEW)
            || AccessDecision::grantsAtOrganization($user, Capability::RISKS_VIEW)) {
            return true;
        }

        if ($this->governs($user)) {
            return true;
        }

        $scopes = AccessDecision::grantingScopes($user, Capability::RISKS_VIEW);

        return ($scopes['department'] ?? []) !== [];
    }
}
