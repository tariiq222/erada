<?php

namespace App\Modules\OVR\Models;

use App\Modules\Core\Models\GovernanceRule;
use App\Modules\HR\Models\Department;

/**
 * Resolves the OVR governing department via the unified governance_rules table
 * (ADR-UNIFIED-ROLE-ACCESS, Phase 1). A single governor applies to all OVR; the
 * setter preserves the legacy single-governor semantics by resolving the org from
 * the target department.
 */
class OvrSetting
{
    /**
     * The configured governing department id for OVR, or null when unset.
     *
     * Reads from the unified governance_rules table. The legacy setting was global
     * (one governor system-wide), so a single OVR rule exists; return its unit.
     */
    public static function getGoverningDepartmentId(): ?int
    {
        return GovernanceRule::query()
            ->where('resource_type', GovernanceRule::TYPE_OVR)
            ->whereNull('resource_subtype')
            ->value('governing_unit_id');
    }

    /**
     * Set (or clear, when null) the governing department for OVR.
     *
     * The organization is resolved from the target department (a department belongs
     * to exactly one org), preserving the legacy single-governor semantics.
     */
    public static function setGoverningDepartmentId(?int $departmentId): void
    {
        if ($departmentId === null) {
            GovernanceRule::query()
                ->where('resource_type', GovernanceRule::TYPE_OVR)
                ->whereNull('resource_subtype')
                ->delete();
            GovernanceRule::clearCache();

            return;
        }

        $orgId = Department::query()->whereKey($departmentId)->value('organization_id');
        $orgId = $orgId === null ? null : (int) $orgId;

        // Single-governor invariant (legacy = one global OVR governor).
        GovernanceRule::query()
            ->where('resource_type', GovernanceRule::TYPE_OVR)
            ->whereNull('resource_subtype')
            ->delete();

        GovernanceRule::setGoverningUnit($orgId, GovernanceRule::TYPE_OVR, null, $departmentId, ['ovr.*']);
    }
}
