<?php

namespace Tests\Unit\Authorization;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\CapabilityAlias;
use App\Modules\Core\Enums\Permission;
use PHPUnit\Framework\TestCase;

/**
 * CapabilityAlias is the single bridge between the legacy flat permission
 * vocabulary and the canonical module.action Capability vocabulary
 * (Phase 2 of ADR-UNIFIED-ROLE-ACCESS). These self-checks keep the two in
 * sync so the flat list stays a DERIVED alias, not an independently
 * hand-maintained one.
 *
 * Phase 9 retires the legacy Meetings kebab strings
 * (view-meetings / manage-meetings / record-decisions). The pin
 * test_no_legacy_kebab_alias_remain guards against the alias map
 * accidentally re-adding them.
 */
class CapabilityAliasTest extends TestCase
{
    public function test_every_flat_permission_enum_case_has_an_alias_entry(): void
    {
        $map = CapabilityAlias::map();

        foreach (Permission::values() as $flat) {
            $this->assertArrayHasKey(
                $flat,
                $map,
                "flat permission '{$flat}' is missing from CapabilityAlias::map()"
            );
        }
    }

    public function test_every_non_null_alias_target_is_a_real_capability(): void
    {
        $caps = Capability::all();

        foreach (CapabilityAlias::map() as $flat => $capability) {
            if ($capability === null) {
                continue;
            }
            $this->assertContains(
                $capability,
                $caps,
                "alias '{$flat}' points to '{$capability}' which is not a Capability constant"
            );
        }
    }

    public function test_transition_aliases_have_no_capability(): void
    {
        // Documented transition aliases must map to null.
        foreach (CapabilityAlias::transitionAliases() as $flat) {
            $this->assertNull(
                CapabilityAlias::toCapability($flat),
                "'{$flat}' is listed as a transition alias but resolves to a capability"
            );
        }

        // Phase 8-C: 'view_dashboard' was promoted off the ponytail in
        // Phase 8-C and now resolves to Capability::DASHBOARD_VIEW. The
        // remaining transition aliases live under Dashboard/Reports and
        // RiskManagement — pick one of each as a regression guard so the
        // transitionAliases() list never silently empties out.
        $this->assertContains('view_reports', CapabilityAlias::transitionAliases());
        $this->assertContains('view_own_risks', CapabilityAlias::transitionAliases());
        $this->assertNotContains('view_dashboard', CapabilityAlias::transitionAliases());
    }

    /**
     * Phase 9 pin: the legacy Meetings kebab strings were retired from
     * both the Permission enum and the CapabilityAlias map. Asserting
     * the map never carries them again keeps the cleanup durable.
     */
    public function test_no_legacy_kebab_alias_remain(): void
    {
        $map = CapabilityAlias::map();

        foreach (['view-meetings', 'manage-meetings', 'record-decisions'] as $legacy) {
            $this->assertArrayNotHasKey(
                $legacy,
                $map,
                "legacy kebab alias '{$legacy}' must not be in CapabilityAlias::map() (Phase 9 retirement)"
            );
            $this->assertNull(
                CapabilityAlias::toCapability($legacy),
                "legacy kebab alias '{$legacy}' must resolve to null after Phase 9"
            );
        }
    }

    /**
     * CSD-CA23078-CORE-001 pin: the legacy `edit_department_*` aliases
     * previously resolved to the un-reach-capped canonical capabilities
     * (`PROJECTS_EDIT` / `TASKS_EDIT`). That mapping silently widened
     * department-scoped grants onto peer-department reach. The corrective
     * change is to drop the alias resolution path entirely so any FUTURE
     * occurrence of the legacy string is treated as a transition alias
     * and the engine consults the post-cutover reach map (narrowed by
     * migration `2026_07_12_000016_narrow_legacy_department_aliases`)
     * instead of falling through to the unrestricted canonical capability.
     *
     * Asserting the resolution is null keeps the cleanup durable.
     */
    public function test_legacy_department_aliases_resolve_to_null(): void
    {
        foreach (['edit_department_projects', 'edit_department_tasks'] as $legacy) {
            $this->assertNull(
                CapabilityAlias::toCapability($legacy),
                "legacy department alias '{$legacy}' must resolve to null after CSD-CA23078-CORE-001"
            );
        }

        // Cross-check: a capability lookup on the canonical names still
        // resolves. The corrective change is alias-only — the canonical
        // vocabulary must remain unchanged.
        $this->assertSame(Capability::PROJECTS_EDIT, CapabilityAlias::toCapability('edit_projects'));
        $this->assertSame(Capability::TASKS_EDIT, CapabilityAlias::toCapability('edit_tasks'));
    }
}
