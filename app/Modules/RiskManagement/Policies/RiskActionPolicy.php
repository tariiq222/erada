<?php

namespace App\Modules\RiskManagement\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\RiskAction;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * RiskActionPolicy — سياسة الوصول لإجراءات المخاطر
 *
 * يعتمد كلياً على محرّك AuthZ الموحّد (AccessDecision::can).
 * RiskAction يطبّق ScopeAware — scopeParent() يرجع الـ Risk الأب —
 * فيُفرض عزل org عبر سلسلة الأب مباشرةً (إغلاق GAP-3).
 * المنطق القديم (Spatie flat permissions) أُزيل في Phase هـ Task 4.
 *
 * Phase CFA-05 — Cluster Full Authority widening:
 *   - view() widens via RISKS_VIEW + CLUSTER_TREE_VIEW on actor.org. The
 *     engine walks to the parent Risk (via ScopeAware::scopeParent); the
 *     org isolation check on the parent Risk fires automatically and the
 *     cluster rescue branch (cross-org ancestor walk) covers cluster
 *     widening for actions whose parent Risk is a descendant of actor.org.
 *   - create / update / delete stay strict same-org. Per CFA-00 owner
 *     decision: writes on actions do NOT widen (action writes modify the
 *     risk itself, which stays strict same-org). Only the risk-level
 *     governance writes (reassess / change_status) widen.
 *   - Existing RiskActionController paths keep their strict-org behavior
 *     via assertSameOrganization — this policy widening is a defensive
 *     preparation for any future per-action endpoint that calls the
 *     policy's view() directly.
 */
class RiskActionPolicy
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
     * The action's parent Risk (ScopeAware::scopeParent) drives the org
     * check; the engine's rescue branch evaluates the ancestor walk on
     * the target RiskAction itself (extractOrganizationId returns
     * action.organization_id, which equals the parent's org).
     *
     * Decision paths:
     *  1) RISKS_VIEW on action (same-org): engine's same-org + role check
     *     via the parent Risk scope chain.
     *  2) CLUSTER_TREE_VIEW on action (cluster ancestor): engine's rescue
     *     branch verifies the ancestor walk + non-sensitive + scoped-role
     *     grant. Only fires if the actor holds Capability::RISKS_VIEW +
     *     CLUSTER_TREE_VIEW on actor.organization_id — two explicit checks
     *     before the rescue.
     */
    public function view(User $user, RiskAction $riskAction): bool
    {
        // Path 1: same-org RISKS_VIEW via engine.
        if (AccessDecision::can($user, Capability::RISKS_VIEW, $riskAction)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — requires BOTH entitlements on actor.org.
        $hasRisksView = AccessDecision::can($user, Capability::RISKS_VIEW);
        $hasClusterTreeView = AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW);
        if (! $hasRisksView || ! $hasClusterTreeView) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $riskAction);
    }

    public function create(User $user): bool
    {
        return AccessDecision::can($user, Capability::RISKS_EDIT);
    }

    /**
     * Update a risk action.
     *
     * Per CFA-00 owner decision: update stays strict same-org. NO cluster
     * widening for action updates.
     */
    public function update(User $user, RiskAction $riskAction): bool
    {
        return AccessDecision::can($user, Capability::RISKS_EDIT, $riskAction);
    }

    /**
     * Delete a risk action.
     *
     * Per CFA-00 owner decision: delete stays strict same-org. NO cluster
     * widening for action deletes.
     */
    public function delete(User $user, RiskAction $riskAction): bool
    {
        return AccessDecision::can($user, Capability::RISKS_DELETE, $riskAction);
    }
}
