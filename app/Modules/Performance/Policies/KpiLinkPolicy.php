<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Support\KpiOrgGuard;

/**
 * KpiLinkPolicy - Phase 4: per-record org-isolation for KpiLink.
 *
 * Link operations use KPIS_MANAGE (a change on the KPI):
 *  - view via /kpis/{kpi}/links (route-bound kpi) ⇒ KPIS_VIEW.
 *  - create/update via /kpis/{kpi}/links ⇒ KPIS_MANAGE.
 *  - destroy ⇒ KPIS_MANAGE.
 *
 * The same-org gate checks link.organization_id directly
 * (the column exists on kpi_links).
 *
 * Phase 9-D-D1a — Cluster tree widening applies to view() only:
 *   - link inherits its org from the parent kpi (denormalized at write time).
 *   - view() uses the same engine rescue pattern: KPIS_VIEW || CLUSTER_TREE_VIEW.
 *   - create / update / delete stay strict same-org via precheck.
 */
class KpiLinkPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user, ?Kpi $kpi = null): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_VIEW);
    }

    /**
     * Phase 9-D-D1a — Cluster tree widening applies to view() only.
     *
     * Decision paths (same pattern as KpiPolicy::view):
     *  1) KPIS_VIEW on link (same org): engine's same-org + role check.
     *  2) CLUSTER_TREE_VIEW on link: engine's rescue branch verifies the
     *     ancestor walk via link.organization_id (matching the parent kpi).
     */
    public function view(User $user, KpiLink $link): bool
    {
        // super_admin is handled in the engine (short-circuit in whyCan::step 1).
        // null-org actor is handled in the engine (org_isolation_denied in step 2).

        // Path 1: same-org KPIS_VIEW via engine.
        if (AccessDecision::can($user, Capability::KPIS_VIEW, $link)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — requires BOTH entitlements on actor.org.
        if (! AccessDecision::can($user, Capability::KPIS_VIEW)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $link);
    }

    public function create(User $user, ?Kpi $kpi = null): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        // If a kpi is passed, verify same-org (the create path via /kpis/{kpi}/links).
        if ($kpi !== null && ! app(KpiOrgGuard::class)->sameOrganizationForKpi($user, $kpi)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    public function update(User $user, KpiLink $link): bool
    {
        if (! $this->precheck($user, $link)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    public function delete(User $user, KpiLink $link): bool
    {
        if (! $this->precheck($user, $link)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    /**
     * precheck: actor/org gate + same-org via KpiOrgGuard.
     *
     * Used for writes only (update / delete) — not applied to view().
     */
    protected function precheck(User $user, KpiLink $link): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(KpiOrgGuard::class)->sameOrganizationForLink($user, $link);
    }
}
