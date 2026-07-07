<?php

namespace App\Modules\OVR\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Services\OvrAuthorizationService;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * IncidentReportPolicy — access policy for OVR incident reports.
 *
 * Engine-only model: every grant flows through AccessDecision::can(). There are
 * no Spatie flat-permission fall-backs (the legacy ovr.view_* / ovr.edit_* /
 * ovr.delete_* ladder was retired at the data layer by the authz cutover
 * migration `2026_06_28_060831_drop_view_ladder_grants`, and the legacy
 * ovr.view_confidential flat string was retired by
 * `2026_07_07_000010_strip_legacy_ovr_view_confidential`).
 *
 * Layered gates per method:
 *   (1) super_admin bypasses every check (see before()).
 *   (2) Organization isolation (sharesOrganization) is the first gate.
 *   (3) Per-action engine capability (e.g. OVR_EDIT, OVR_CHANGE_STATUS,
 *       OVR_ASSIGN) gates the action itself.
 *   (4) The OVR_CONFIDENTIAL engine capability, plus the reporter/assignee
 *       floor, gates confidential rows.
 */
class IncidentReportPolicy
{
    use HandlesAuthorization, HasOrganizationScope;

    /**
     * Super Admin bypasses every authorization, including the confidentiality layer.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * List view of reports (index / recent) — engine grant.
     */
    public function viewAny(User $user): bool
    {
        return app(OvrAuthorizationService::class)->canViewAny($user);
    }

    /**
     * View a single report — org isolation + engine grant + confidentiality layer.
     */
    public function view(User $user, IncidentReport $report): bool
    {
        if (! $this->sharesOrganization($user, $report->organization_id)) {
            return false;
        }

        if (! AccessDecision::can($user, Capability::OVR_VIEW, $report)) {
            return false;
        }

        return $this->checkConfidentialAccess($user, $report);
    }

    /**
     * Create a report — every authenticated org employee with OVR_CREATE may.
     *
     * The flat `ovr.create` permission is no longer consulted: the engine
     * grants OVR_CREATE to every org member per AUTHZ-DECISIONS.md (create
     * path is open to any authenticated org user).
     */
    public function create(User $user): bool
    {
        return AccessDecision::can($user, Capability::OVR_CREATE);
    }

    /**
     * Update a report — org isolation + engine grant + confidentiality layer.
     */
    public function update(User $user, IncidentReport $report): bool
    {
        if (! $this->sharesOrganization($user, $report->organization_id)) {
            return false;
        }

        if (! AccessDecision::can($user, Capability::OVR_EDIT, $report)) {
            return false;
        }

        return $this->checkConfidentialAccess($user, $report);
    }

    /**
     * Delete a report — org isolation + engine grant + confidentiality layer.
     */
    public function delete(User $user, IncidentReport $report): bool
    {
        if (! $this->sharesOrganization($user, $report->organization_id)) {
            return false;
        }

        if (! AccessDecision::can($user, Capability::OVR_DELETE, $report)) {
            return false;
        }

        return $this->checkConfidentialAccess($user, $report);
    }

    /**
     * Change a report's status — org isolation + OVR_CHANGE_STATUS engine grant + confidentiality layer.
     */
    public function changeStatus(User $user, IncidentReport $report): bool
    {
        return $this->authorizeEngineAction($user, $report, Capability::OVR_CHANGE_STATUS);
    }

    /**
     * Assign a processor to a report — org isolation + OVR_ASSIGN engine grant + confidentiality layer.
     */
    public function assign(User $user, IncidentReport $report): bool
    {
        return $this->authorizeEngineAction($user, $report, Capability::OVR_ASSIGN);
    }

    /**
     * Add a comment to a report — org isolation + OVR_COMMENT engine grant + confidentiality layer.
     */
    public function comment(User $user, IncidentReport $report): bool
    {
        return $this->authorizeEngineAction($user, $report, Capability::OVR_COMMENT);
    }

    /**
     * View internal comments — org isolation + OVR_VIEW_INTERNAL_COMMENTS engine grant + confidentiality layer.
     */
    public function viewInternalComments(User $user, IncidentReport $report): bool
    {
        return $this->authorizeEngineAction($user, $report, Capability::OVR_VIEW_INTERNAL_COMMENTS);
    }

    /**
     * Export reports — OVR_EXPORT engine grant.
     */
    public function export(User $user): bool
    {
        return AccessDecision::can($user, Capability::OVR_EXPORT);
    }

    /**
     * View statistics — OVR_VIEW_STATISTICS engine grant.
     */
    public function viewStatistics(User $user): bool
    {
        return AccessDecision::can($user, Capability::OVR_VIEW_STATISTICS);
    }

    // ===========================
    // Private helpers
    // ===========================

    /**
     * Action requiring org isolation + a per-action engine capability +
     * confidentiality layer. Used by changeStatus / assign / comment /
     * viewInternalComments, which share the same composite shape now that the
     * flat-permission back-door is closed.
     */
    private function authorizeEngineAction(
        User $user,
        IncidentReport $report,
        string $capability
    ): bool {
        if (! $this->sharesOrganization($user, $report->organization_id)) {
            return false;
        }

        if (! AccessDecision::can($user, $capability, $report)) {
            return false;
        }

        return $this->checkConfidentialAccess($user, $report);
    }

    /**
     * Confidentiality gate (defense-in-depth).
     *
     * AccessDecision::can() also enforces this need-to-know rule via the
     * SensitivelyScoped contract on IncidentReport (the engine denies upward
     * leakage of confidential reports). This policy-level check is KEPT on
     * purpose as a second, independent barrier: it guards the controller
     * surface even if a future call path reaches the policy without the engine,
     * and it keeps the OVR confidentiality semantics explicit at the module
     * boundary. Delegates to OvrAuthorizationService::mayViewConfidential so the
     * policy, the model scope gate, and the engine layer share one
     * implementation.
     *
     * is_admin_role alone does NOT grant confidential access. Access requires:
     * an OVR_CONFIDENTIAL engine capability in an active scoped role
     * definition's permissions[], OR being the reporter/assigned.
     */
    private function checkConfidentialAccess(User $user, IncidentReport $report): bool
    {
        return app(OvrAuthorizationService::class)
            ->mayViewConfidential($user, $report);
    }
}
