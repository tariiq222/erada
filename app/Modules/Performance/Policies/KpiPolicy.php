<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Support\KpiOrgGuard;

/**
 * KpiPolicy - Phase 4: per-record org-isolation for Kpi.
 *
 * The engine `AccessDecision::can($user, Capability::KPIS_*, $kpi)` can derive
 * organization_id because Kpi is ScopeAware and carries the column directly.
 * Even so, we add this Policy as a unified guard (Phase 2/3 pattern) to:
 *   - unify fail-closed on a null-org actor.
 *   - unify the same-org gate via KpiOrgGuard for writes.
 *   - give the Gate an explicit registration point (Gate::policy) to route
 *     view/create/update/delete from controllers and authorization helpers.
 *
 * Behavior:
 *  - super_admin ⇒ always true (via Gate::before + before()).
 *  - actor without organization_id ⇒ deny.
 *  - kpi from another organization ⇒ deny (with the cluster_tree exception in
 *    view() only, below).
 *  - kpi without organization_id ⇒ deny (orphan).
 *  - KPIS_VIEW for reads, KPIS_MANAGE for update/delete/create.
 *
 * Phase 9-D-D1a — Cluster tree read widening:
 *   - view() allows AccessDecision::can(CLUSTER_TREE_VIEW, $kpi) as a second
 *     path if and only if the actor holds Capability::KPIS_VIEW + CLUSTER_TREE_VIEW
 *     on actor.organization_id. The engine's rescue branch verifies the ancestor
 *     walk + non-sensitive target.
 *   - update / delete / create / manage stay unchanged (strict same-org via precheck).
 *   - Does not widen to gain write access in any other module.
 *
 * Does not rely on Spatie directly. The Capability constants flow through
 * AccessDecision so the engine verifies contextual roles.
 */
class KpiPolicy
{
    /**
     * Super Admin bypasses all abilities.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_VIEW);
    }

    /**
     * Phase 9-D-D1a — Cluster tree widening applies to view() only.
     *
     * Decision paths:
     *  1) KPIS_VIEW on kpi (same org): engine's same-org + role check.
     *  2) CLUSTER_TREE_VIEW on kpi (cluster ancestor): engine's rescue branch
     *     verifies the ancestor walk + non-sensitive + scoped-role grant. Only
     *     fires if the actor holds Capability::KPIS_VIEW + CLUSTER_TREE_VIEW on
     *     actor.organization_id — two explicit checks before the rescue.
     *
     * Missing either capability ⇒ deny. Writes are unaffected (they go through
     * update/delete/create).
     */
    public function view(User $user, Kpi $kpi): bool
    {
        // super_admin is handled in the engine (short-circuit in whyCan::step 1).
        // null-org actor is handled in the engine (org_isolation_denied in step 2).

        // Path 1: same-org KPIS_VIEW via engine.
        if (AccessDecision::can($user, Capability::KPIS_VIEW, $kpi)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — requires BOTH entitlements on actor.org.
        if (! AccessDecision::can($user, Capability::KPIS_VIEW)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $kpi);
    }

    public function create(User $user): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    public function update(User $user, Kpi $kpi): bool
    {
        if (! $this->precheck($user, $kpi)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    public function delete(User $user, Kpi $kpi): bool
    {
        if (! $this->precheck($user, $kpi)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    /**
     * precheck: actor/org gate + same-org via KpiOrgGuard.
     *
     * Used for writes only (update / delete) — not applied to view() because
     * the cluster_tree widening needs a second path outside strict same-org.
     */
    protected function precheck(User $user, Kpi $kpi): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(KpiOrgGuard::class)->sameOrganizationForKpi($user, $kpi);
    }
}
