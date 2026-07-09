<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiMeasurement;
use App\Modules\Performance\Support\KpiOrgGuard;

/**
 * KpiMeasurementPolicy - Phase 4: per-record org-isolation for KpiMeasurement.
 *
 * Recording a measurement is an edit operation on the KPI (it updates
 * current_value via a booted hook). Therefore:
 *  - view uses KPIS_VIEW.
 *  - create/update uses KPIS_MANAGE.
 *  - delete does not exist in the routes (there is no DELETE endpoint for measurements).
 *
 * The same-org gate checks measurement.organization_id directly
 * (the column exists on kpi_measurements).
 *
 * Phase 9-D-D1a — Cluster tree widening applies to view() only:
 *   - measurement inherits its org from the parent kpi (denormalized at write time).
 *   - view() uses the same engine rescue pattern: KPIS_VIEW || CLUSTER_TREE_VIEW.
 *   - create / update stay strict same-org via precheck.
 */
class KpiMeasurementPolicy
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
     *  1) KPIS_VIEW on measurement (same org): engine's same-org + role check.
     *  2) CLUSTER_TREE_VIEW on measurement: engine's rescue branch verifies the
     *     ancestor walk via measurement.organization_id (matching the parent kpi).
     */
    public function view(User $user, KpiMeasurement $measurement): bool
    {
        // super_admin is handled in the engine (short-circuit in whyCan::step 1).
        // null-org actor is handled in the engine (org_isolation_denied in step 2).

        // Path 1: same-org KPIS_VIEW via engine.
        if (AccessDecision::can($user, Capability::KPIS_VIEW, $measurement)) {
            return true;
        }

        // Path 2: cross-org cluster_tree widening — requires BOTH entitlements on actor.org.
        if (! AccessDecision::can($user, Capability::KPIS_VIEW)) {
            return false;
        }
        if (! AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW)) {
            return false;
        }

        return AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $measurement);
    }

    public function create(User $user, ?Kpi $kpi = null): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        // If a kpi is passed, verify same-org (the create path via /kpis/{kpi}/measurements).
        if ($kpi !== null && ! app(KpiOrgGuard::class)->sameOrganizationForKpi($user, $kpi)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    public function update(User $user, KpiMeasurement $measurement): bool
    {
        if (! $this->precheck($user, $measurement)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    public function delete(User $user, KpiMeasurement $measurement): bool
    {
        if (! $this->precheck($user, $measurement)) {
            return false;
        }

        return AccessDecision::can($user, Capability::KPIS_MANAGE);
    }

    /**
     * precheck: actor/org gate + same-org via KpiOrgGuard.
     *
     * Used for writes only (update / delete) — not applied to view().
     */
    protected function precheck(User $user, KpiMeasurement $measurement): bool
    {
        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        return app(KpiOrgGuard::class)->sameOrganizationForMeasurement($user, $measurement);
    }
}
