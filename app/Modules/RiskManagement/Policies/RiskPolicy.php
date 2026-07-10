<?php

namespace App\Modules\RiskManagement\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Services\RiskAuthorizationService;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * RiskPolicy — سياسة الوصول للمخاطر
 *
 * يعتمد كلياً على محرّك AuthZ الموحّد (AccessDecision::can).
 * المنطق القديم (Spatie flat permissions) أُزيل في Phase هـ Task 4.
 *
 * Phase CFA-05 — Cluster Full Authority widening:
 *   - view() widens via RISKS_VIEW + CLUSTER_TREE_VIEW on actor.org; the
 *     engine's rescue branch verifies the ancestor walk + non-sensitive target.
 *   - reassess() widens via RISKS_REASSESS + CLUSTER_TREE_MANAGE (governance
 *     write; same engine rescue branch).
 *   - changeStatus() widens via RISKS_CHANGE_STATUS + CLUSTER_TREE_MANAGE
 *     (governance write; same engine rescue branch).
 *   - create / update / delete stay strict same-org — no write widening for
 *     arbitrary CRUD; only the governance-write subset (reassess /
 *     change_status) widens (per CFA-00 owner decision 2026-07-09).
 *   - viewReports is treated at the controller level (RiskDashboardController)
 *     where the cluster_export widening lives, not here.
 */
class RiskPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return AccessDecision::can($user, Capability::RISKS_VIEW);
    }

    /**
     * Phase CFA-05 — Cluster tree widening applies to view() only.
     *
     * Decision paths:
     *  1) RISKS_VIEW on risk (same org): engine's same-org + role check.
     *  2) CLUSTER_TREE_VIEW on risk (cluster ancestor): engine's rescue
     *     branch verifies the ancestor walk + non-sensitive + scoped-role
     *     grant. Only fires if the actor holds Capability::RISKS_VIEW +
     *     CLUSTER_TREE_VIEW on actor.organization_id — two explicit checks
     *     before the rescue.
     *
     * Missing either capability ⇒ deny. Writes (update / delete / create /
     * reassess / changeStatus) are unaffected — they go through their own
     * dedicated abilities below.
     */
    public function view(User $user, Risk $risk): bool
    {
        // super_admin is handled in before() / the engine (short-circuit in whyCan::step 1).
        // null-org actor is handled in the engine (org_isolation_denied in step 2).

        // Path 1: same-org RISKS_VIEW via engine.
        if (AccessDecision::can($user, Capability::RISKS_VIEW, $risk)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — requires BOTH entitlements on actor.org.
        $hasRisksView = AccessDecision::can($user, Capability::RISKS_VIEW);
        $hasClusterTreeView = AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW);
        if (! $hasRisksView || ! $hasClusterTreeView) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $risk);
    }

    /**
     * Generic create gate (can the user create a risk at all?). The context-aware
     * decision (target department) is enforced in StoreRiskRequest::authorize via
     * RiskAuthorizationService::canCreate.
     *
     * Per CFA-00 owner decision: create stays strict same-org. NO cluster
     * widening for create.
     */
    public function create(User $user): bool
    {
        return app(RiskAuthorizationService::class)->canCreateAny($user);
    }

    /**
     * Update a risk.
     *
     * Per CFA-00 owner decision: arbitrary field updates stay strict same-org.
     * The governance-write subset (reassess / change_status) widens via
     * CLUSTER_TREE_MANAGE in their dedicated abilities below.
     */
    public function update(User $user, Risk $risk): bool
    {
        return AccessDecision::can($user, Capability::RISKS_EDIT, $risk);
    }

    /**
     * Delete a risk.
     *
     * Per CFA-00 owner decision: delete stays strict same-org. No cluster
     * widening for delete.
     */
    public function delete(User $user, Risk $risk): bool
    {
        return AccessDecision::can($user, Capability::RISKS_DELETE, $risk);
    }

    /**
     * Phase CFA-05 — Reassess a risk (governance write).
     *
     * Same-org path: RISKS_REASSESS on risk (engine same-org + role check).
     * Cross-org path: RISKS_REASSESS + CLUSTER_TREE_MANAGE on actor.org +
     * engine rescue branch verifies ancestor walk + non-sensitive target.
     *
     * Two-path helper mirrors CFA-04 updateStatus pattern.
     */
    public function reassess(User $user, Risk $risk): bool
    {
        return $this->clusterManagedUpdate($user, $risk, Capability::RISKS_REASSESS);
    }

    /**
     * Phase CFA-05 — Change risk status (governance write).
     *
     * Same-org path: RISKS_CHANGE_STATUS on risk (engine same-org + role check).
     * Cross-org path: RISKS_CHANGE_STATUS + CLUSTER_TREE_MANAGE on actor.org +
     * engine rescue branch verifies ancestor walk + non-sensitive target.
     *
     * Two-path helper mirrors CFA-04 updateStatus pattern.
     */
    public function changeStatus(User $user, Risk $risk): bool
    {
        return $this->clusterManagedUpdate($user, $risk, Capability::RISKS_CHANGE_STATUS);
    }

    /**
     * Reports gate — does not widen here. The cluster widening for the
     * export/aggregate endpoints lives in RiskDashboardController::authorizeReports
     * (where CLUSTER_TREE_EXPORT pairs with RISKS_VIEW_REPORTS for cross-org
     * aggregate reporting).
     */
    public function viewReports(User $user): bool
    {
        return AccessDecision::can($user, Capability::RISKS_VIEW_REPORTS);
    }

    /**
     * Two-path cluster_tree.manage rescue for governance writes on Risks
     * (reassess + change_status only — NOT arbitrary CRUD updates).
     *
     * Mirrors the CFA-00 / CFA-04 view() pattern: same-org via engine strict
     * equality + scoped-role check; cross-org via the engine's cluster_tree
     * rescue branch which verifies ancestor walk + non-sensitive target. Both
     * grants are required IN ADDITION TO the actor's authority on the module
     * write capability — neither primitive implies the other.
     */
    protected function clusterManagedUpdate(User $user, Risk $risk, string $moduleCapability): bool
    {
        // Path 1: same-org via engine.
        if (AccessDecision::can($user, $moduleCapability, $risk)) {
            return true;
        }

        // Path 2: cross-org rescue — both grants required on actor.org.
        $hasModuleCap = AccessDecision::can($user, $moduleCapability);
        $hasClusterTreeManage = AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE);
        if (! $hasModuleCap || ! $hasClusterTreeManage) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_MANAGE, $risk);
    }
}
