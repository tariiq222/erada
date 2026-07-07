<?php

namespace App\Modules\Projects\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\ProjectSetting;

/**
 * Project create-scope decisions (department-scoped + governing-department).
 *
 * Per-record view/update/delete/approve authorization lives entirely in the
 * unified engine (AccessDecision::can) via ProjectPolicy — this service no longer
 * carries a parallel authz model. Only the context-aware CREATE scope, which the
 * engine does not express directly, remains here.
 */
class ProjectAuthorizationService
{
    // =====================================================================
    //  Context-aware project creation (department-scoped + governing-dept).
    //
    //  Two creation paths exist on top of the org-level functional grant:
    //    (1) Own-department subtree — a department manager/member may create a
    //        project for their own department or any descendant of it.
    //    (2) Governing department — a member of (the subtree of) the department
    //        that governs a project type may create that type for ANY department
    //        in the organization (e.g. Quality governs improvement, the PMO
    //        governs new/general projects).
    // =====================================================================

    /**
     * Can the user create a project of $type targeting $departmentId?
     *
     * When $departmentId is null the user's own department is used as the target
     * (a department creator submitting without an explicit pick lands in their
     * own department).
     */
    public function canCreate(User $user, ?string $type = null, ?int $departmentId = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Org-level functional grant: create anywhere within the organization.
        if (AccessDecision::can($user, Capability::PROJECTS_CREATE)) {
            return true;
        }

        $createDeptIds = $this->createScopeDepartmentIds($user);

        if ($createDeptIds === []) {
            return false;
        }

        // Governing-department path: a create-capable member of the governing
        // department (or its subtree) may create that type for ANY department.
        $governingId = $this->governingDepartmentIdForType($type);
        if ($governingId !== null) {
            $governingSubtree = AccessDecision::subtreeDepartmentIds([$governingId]);
            if (array_intersect($createDeptIds, $governingSubtree) !== []) {
                return true;
            }
        }

        // Own-department path: the target department must be inside the user's
        // create subtree. A null target defaults to the user's home department.
        $target = $departmentId ?? $user->department_id;
        if ($target !== null && in_array((int) $target, $createDeptIds, true)) {
            return true;
        }

        return false;
    }

    /**
     * Can the user create a project at all (any type, any reachable department)?
     * Used for coarse UI gating (menu/route/button) — not for the final decision.
     */
    public function canCreateAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (AccessDecision::can($user, Capability::PROJECTS_CREATE)) {
            return true;
        }

        return $this->createScopeDepartmentIds($user) !== [];
    }

    /**
     * Can the user view any project at all? Used for coarse UI gating (the flat
     * view_projects menu/route guard the FE reads). Mirrors RiskAuthorizationService:
     * a scoped department/project role granting projects.view, an org grant, or a
     * governed type all qualify. super_admin and flat view_projects holders pass.
     */
    public function canViewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (AccessDecision::can($user, Capability::PROJECTS_VIEW)) {
            return true;
        }

        if (AccessDecision::can($user, Capability::PROJECTS_VIEW)
            || AccessDecision::grantsAtOrganization($user, Capability::PROJECTS_VIEW)) {
            return true;
        }

        if ($this->governedTypes($user) !== []) {
            return true;
        }

        $scopes = AccessDecision::grantingScopes($user, Capability::PROJECTS_VIEW);

        return ($scopes['department'] ?? []) !== [] || ($scopes['project'] ?? []) !== [];
    }

    /**
     * Department ids (subtree-expanded) where the user holds a scoped role that
     * grants projects.create.
     *
     * @return list<int>
     */
    public function createScopeDepartmentIds(User $user): array
    {
        $scopes = AccessDecision::grantingScopes($user, Capability::PROJECTS_CREATE);

        return AccessDecision::subtreeDepartmentIds($scopes['department'] ?? []);
    }

    /**
     * Project types whose governing department the user belongs to (subtree),
     * with a projects.view grant — i.e. the types this user oversees org-wide.
     *
     * @return list<string>
     */
    public function governedTypes(User $user): array
    {
        $map = ProjectSetting::getGoverningDepartments();
        if ($map === []) {
            return [];
        }

        $viewDeptIds = AccessDecision::subtreeDepartmentIds(
            AccessDecision::grantingScopes($user, Capability::PROJECTS_VIEW)['department'] ?? []
        );
        if ($viewDeptIds === []) {
            return [];
        }

        $types = [];
        foreach ($map as $type => $governingId) {
            $governingSubtree = AccessDecision::subtreeDepartmentIds([$governingId]);
            if (array_intersect($viewDeptIds, $governingSubtree) !== []) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * The governing department id configured for a project type, or null.
     */
    public function governingDepartmentIdForType(?string $type): ?int
    {
        return ProjectSetting::getGoverningDepartmentForType($type);
    }

    /**
     * The departments the user may target when creating a project of $type.
     *
     * Returns null when the choice is unrestricted (super_admin, an org-level
     * functional creator, or a governing-department member for this type — all of
     * whom may create for any department in the organization). Otherwise returns
     * the explicit list of department ids inside the user's create subtree. An
     * empty array means the user cannot create at all.
     *
     * @return list<int>|null null = any department in the organization
     */
    public function creatableDepartmentIds(User $user, ?string $type = null): ?array
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        if (AccessDecision::can($user, Capability::PROJECTS_CREATE)) {
            return null;
        }

        $createDeptIds = $this->createScopeDepartmentIds($user);
        if ($createDeptIds === []) {
            return [];
        }

        // A governing-department member may create the governed type anywhere.
        $governingId = $this->governingDepartmentIdForType($type);
        if ($governingId !== null) {
            $governingSubtree = AccessDecision::subtreeDepartmentIds([$governingId]);
            if (array_intersect($createDeptIds, $governingSubtree) !== []) {
                return null;
            }
        }

        // Own-department path: restricted to the user's create subtree. Mirrors
        // canCreate() so the picker and the final decision never disagree.
        return $createDeptIds;
    }
}
