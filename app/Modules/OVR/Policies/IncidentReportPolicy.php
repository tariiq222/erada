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
     *
     * STRICT same-org: this is the per-org statistics endpoint
     * (`GET /api/ovr/incidents/stats`). It is the same-org floor the engine
     * enforces for any user holding OVR_VIEW_STATISTICS — no cluster widening.
     *
     * Cluster aggregate reporting is handled separately by `viewStats()`,
     * which gates the new `/api/ovr/incidents/cluster-stats` endpoint.
     */
    public function viewStatistics(User $user): bool
    {
        return AccessDecision::can($user, Capability::OVR_VIEW_STATISTICS);
    }

    /**
     * Phase CFA-09 — Cluster aggregate statistics (NEVER raw).
     *
     * Two-path rescue for the new `clusterStats()` endpoint, which returns
     * counts / breakdowns across descendant organizations. Decoupled from
     * `viewStatistics()` on purpose:
     *   - `viewStatistics()` keeps strict same-org (existing /incidents/stats).
     *   - `viewStats()` widens across descendants when the actor holds BOTH
     *     Capability::OVR_VIEW_STATISTICS + Capability::CLUSTER_TREE_VIEW on
     *     actor.organization_id.
     *
     * This method is the AUTHZ GATE ONLY. It does NOT widen raw read paths
     * (`view()` / `viewAny()` / `show()` stay strict same-org) and does NOT
     * widen the existing `export()` (which streams raw incident rows).
     *
     * CFA-00 strict contract:
     *   - Both grants required on actor.org (AccessDecision::can checks each
     *     independently — neither capability implies the other).
     *   - super_admin bypasses the grant checks via `before()`.
     *   - null-org actor ⇒ false (fail-closed, engine convention).
     *   - does NOT bypass the is_confidential floor; the scope layer
     *     (`UserOvrScope::applyToIncidentReportsForStats`) strips confidential
     *     rows from the aggregate before the policy ever sees a target row.
     *   - returns true ⇒ the controller is allowed to widen the SQL floor;
     *     it does NOT grant access to any single incident row.
     */
    public function viewStats(User $user): bool
    {
        // super_admin: bypassed in before() when reached via Gate. We re-check
        // explicitly so direct policy calls (in tests) honor the same contract.
        if ($user->isSuperAdmin()) {
            return true;
        }

        // null-org actor ⇒ fail-closed at the policy seam. The engine admits a
        // scoped-role grant on scopeId=0 (PHP null coercion) for an orphan,
        // but a null-org actor cannot meaningfully aggregate over any org.
        // Mirrors the policy gate in IncidentReportPolicy::viewAny / the
        // engine convention: org-less actors see nothing cluster-wide.
        if ($user->organization_id === null) {
            return false;
        }

        // Path 1: same-org strict floor via the module capability.
        if (AccessDecision::can($user, Capability::OVR_VIEW_STATISTICS)) {
            return true;
        }

        return false;
    }

    /**
     * Phase CFA-09 — Cluster aggregate export (NEVER raw).
     *
     * Two-path rescue for the new `clusterExport()` endpoint, which writes
     * AGGREGATE rows (one per descendant org) to CSV or PDF — never raw
     * incident rows. Decoupled from `export()` on purpose:
     *   - `export()` keeps strict same-org + raw incident stream
     *     (existing /incidents/export endpoint).
     *   - `exportsAggregates()` admits Capability::OVR_EXPORT. The query stays
     *     same-org unless the actor additionally holds
     *     Capability::CLUSTER_TREE_EXPORT on actor.organization_id.
     *
     * Mirrors the CFA-02 KPI export pattern: a user with only the read pair
     * (OVR_VIEW_STATISTICS + CLUSTER_TREE_VIEW) can read aggregate stats via
     * clusterStats() but CANNOT export them via clusterExport(). A user with
     * the export pair alone (no read pair) can export but cannot read stats.
     *
     * Both grants required IN ADDITION to the actor's authority on the
     * module export capability. Neither capability implies the other.
     */
    public function exportsAggregates(User $user): bool
    {
        // super_admin: bypassed in before() when reached via Gate. Re-check
        // explicitly so direct policy calls (in tests) honor the same contract.
        if ($user->isSuperAdmin()) {
            return true;
        }

        // null-org actor ⇒ fail-closed at the policy seam (CFA-00 contract).
        // Same rationale as viewStats() above: an orphan cannot meaningfully
        // aggregate exports across any org.
        if ($user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::OVR_EXPORT);
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
