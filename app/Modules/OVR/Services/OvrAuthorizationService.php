<?php

namespace App\Modules\OVR\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\User;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\OvrSetting;

/**
 * Department-scoped + governing-department authorization for OVR incident reports
 * (mirrors RiskAuthorizationService). A single governing department applies to all
 * reports.
 *
 * Creation paths (on top of an org-level functional grant):
 *   (1) Own-department subtree — a department manager/member may create reports for
 *       their own department or any descendant of it.
 *   (2) Governing department — a member of (the subtree of) the OVR governing
 *       department may create a report for ANY department in the organization, and
 *       sees every report org-wide.
 */
class OvrAuthorizationService
{
    public function canCreate(User $user, ?int $reporterDepartmentId = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (AccessDecision::can($user, Capability::OVR_CREATE)) {
            return true;
        }

        $createDeptIds = $this->createScopeDepartmentIds($user);
        if ($createDeptIds === []) {
            return false;
        }

        if ($this->governs($user)) {
            return true;
        }

        $target = $reporterDepartmentId ?? $user->department_id;

        return $target !== null && in_array((int) $target, $createDeptIds, true);
    }

    public function canCreateAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Engine-only (matches the policy contract: a flat Spatie ovr.create
        // permission alone is not sufficient here — the engine grant or a
        // department-scoped create role qualifies). The policy::create() applies
        // its own flat back-compat OR separately.
        if (AccessDecision::can($user, Capability::OVR_CREATE)) {
            return true;
        }

        return $this->createScopeDepartmentIds($user) !== [];
    }

    /**
     * @return list<int>
     */
    public function createScopeDepartmentIds(User $user): array
    {
        $scopes = AccessDecision::grantingScopes($user, Capability::OVR_CREATE);

        return AccessDecision::subtreeDepartmentIds($scopes['department'] ?? []);
    }

    /**
     * Departments the user may target when creating a report. null => unrestricted
     * (super_admin, org-level creator, or governing-department member). [] => none.
     *
     * @return list<int>|null
     */
    public function creatableDepartmentIds(User $user): ?array
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        if (AccessDecision::can($user, Capability::OVR_CREATE)) {
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
     * Is the user a member of (the subtree of) the OVR governing department with an
     * ovr.view grant — i.e. an org-wide OVR overseer?
     */
    public function governs(User $user): bool
    {
        $governingId = OvrSetting::getGoverningDepartmentId();
        if ($governingId === null) {
            return false;
        }

        $viewDeptIds = AccessDecision::subtreeDepartmentIds(
            AccessDecision::grantingScopes($user, Capability::OVR_VIEW)['department'] ?? []
        );
        if ($viewDeptIds === []) {
            return false;
        }

        $governingSubtree = AccessDecision::subtreeDepartmentIds([$governingId]);

        return array_intersect($viewDeptIds, $governingSubtree) !== [];
    }

    /**
     * Can the user see any report at all (for coarse list/route gating)?
     */
    public function canViewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (AccessDecision::can($user, Capability::OVR_VIEW)
            || AccessDecision::grantsAtOrganization($user, Capability::OVR_VIEW)) {
            return true;
        }

        if ($this->governs($user)) {
            return true;
        }

        $scopes = AccessDecision::grantingScopes($user, Capability::OVR_VIEW);

        return ($scopes['department'] ?? []) !== [];
    }

    /**
     * Single source of truth for "may this user view confidential OVR rows?"
     *
     * Used by IncidentReportPolicy::checkConfidentialAccess,
     * IncidentReport::userMayViewConfidential (list scope),
     * IncidentReport::mayAccessSensitive (engine gate), and any future caller.
     *
     * The rule has three layers, evaluated in order:
     *   (1) Non-confidential report — always allow.
     *   (2) Need-to-know floor — the reporter or assignee always sees their own
     *       confidential report. (For the list scope this is also OR'd in SQL;
     *       returning true here is harmless and short-circuits the filter.)
     *   (3) Explicit OVR confidential grant — an active scoped role whose
     *       definition lists Capability::OVR_CONFIDENTIAL in permissions[].
     *       is_admin_role alone does NOT grant (see CutoverValidationTest).
     *
     * The legacy `ovr.view_confidential` flat string was retired at the data
     * layer by `2026_07_07_000010_strip_legacy_ovr_view_confidential` and is no
     * longer read by any code path; the dual-key check that used to live here
     * is gone.
     */
    public function mayViewConfidential(User $user, IncidentReport $report): bool
    {
        if (! $report->is_confidential) {
            return true;
        }

        if ($report->reporter_id === $user->id || $report->assigned_to === $user->id) {
            return true;
        }

        return $user->activeScopedRoles()
            ->with('roleDefinition')
            ->get()
            ->contains(function ($scopedRole) {
                $def = $scopedRole->roleDefinition
                    ?? ScopedRoleDefinition::findByKey($scopedRole->scope_type, $scopedRole->role);

                return $def
                    && is_array($def->permissions)
                    && in_array(Capability::OVR_CONFIDENTIAL, $def->permissions, true);
            });
    }
}
