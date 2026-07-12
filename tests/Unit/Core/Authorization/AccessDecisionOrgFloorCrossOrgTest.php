<?php

namespace Tests\Unit\Core\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CanonicalAuthorizationFixtures;
use Tests\TestCase;

/**
 * AccessDecisionOrgFloorCrossOrgTest — CSD-CA23078-CORE-005 regression.
 *
 * Locks in the canonical owner-floor vs. organization-isolation ordering
 * invariant inside AccessDecision::evaluateCanonical(). The org gate
 * (sameOrganization) MUST run BEFORE the owner floor
 * (canonicalOwnerFloorGrants). Without that ordering, a user who created a
 * record while in Org A and was later moved to Org B would continue to see
 * and edit that record purely via ownership — leaking cross-org visibility.
 *
 * The super_admin short-circuit at the top of evaluateCanonical() is
 * preserved: a true super_admin continues to see/edit cross-org records.
 */
class AccessDecisionOrgFloorCrossOrgTest extends TestCase
{
    use CanonicalAuthorizationFixtures;
    use RefreshDatabase;

    /**
     * Primary regression: after a user is moved from the project's org into a
     * different org, the owner-floor path must NOT grant view or edit. The
     * org-isolation gate runs first and denies; the owner floor never sees
     * the request.
     */
    public function test_owner_floor_does_not_grant_cross_org_view_or_edit_after_user_moves_orgs(): void
    {
        // Setup: User A in Org A creates a Project in Org A.
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);

        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $projectA = Project::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'created_by' => $userA->id,
            'status' => 'planning',
        ]);

        // Sanity precondition: while in Org A, the owner-floor grants view+edit.
        $this->assertTrue(
            AccessDecision::can($userA->fresh(), Capability::PROJECTS_VIEW, $projectA),
            'precondition: owner-floor grants view while user shares org with record',
        );
        $this->assertTrue(
            AccessDecision::can($userA->fresh(), Capability::PROJECTS_EDIT, $projectA),
            'precondition: owner-floor grants edit while user shares org with record and project is editable',
        );

        // Path: super_admin moves User A from Org A to Org B via direct DB
        // write (mirrors the super_admin-only org-switch path). Drop the
        // memoized role / scope caches so the next can() sees the new org.
        User::query()->whereKey($userA->id)->update(['organization_id' => $orgB->id]);
        AccessDecision::flushCache();
        $userAInB = $userA->fresh();
        $this->assertNotSame(
            (int) $orgA->id,
            (int) $userAInB->organization_id,
            'precondition: User A was actually moved to Org B',
        );
        $this->assertSame(
            (int) $orgB->id,
            (int) $userAInB->organization_id,
            'precondition: User A now belongs to Org B',
        );

        // Primary assertion: owner-floor MUST NOT grant cross-org view.
        $this->assertFalse(
            AccessDecision::can($userAInB, Capability::PROJECTS_VIEW, $projectA),
            'org-isolation gate must run BEFORE owner floor — owner must not see cross-org project',
        );

        // Primary assertion: owner-floor MUST NOT grant cross-org edit.
        $this->assertFalse(
            AccessDecision::can($userAInB, Capability::PROJECTS_EDIT, $projectA),
            'org-isolation gate must run BEFORE owner floor — owner must not edit cross-org project',
        );

        // Trace-level cross-check: the decision is denied at the
        // org_isolation_denied layer, not at the owner_floor layer. If the
        // owner-floor ever short-circuited before the org gate, the layer
        // would be 'owner_floor' instead.
        $trace = AccessDecision::whyCan($userAInB, Capability::PROJECTS_VIEW, $projectA);
        $this->assertFalse(
            $trace['granted'],
            'whyCan() must report denied for cross-org owner',
        );
        $this->assertSame(
            'org_isolation_denied',
            $trace['layer'],
            "denial layer must be 'org_isolation_denied'; got '{$trace['layer']}' — owner floor ran first",
        );
    }

    /**
     * The super_admin short-circuit at the top of evaluateCanonical() runs
     * BEFORE the org gate, so a true super_admin continues to see and edit
     * cross-org records. This proves the ordering fix did not regress the
     * super_admin escape hatch.
     */
    public function test_super_admin_short_circuit_preserved_for_cross_org_project(): void
    {
        // Setup: a project in Org A; a true super_admin (no org binding).
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'department_id' => null,
        ]);
        $projectA = Project::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'status' => 'planning',
        ]);

        $this->grantCanonicalSuperAdmin($superAdmin);

        $this->assertTrue(
            AccessDecision::can($superAdmin->fresh(), Capability::PROJECTS_VIEW, $projectA),
            'super_admin short-circuit must grant view of cross-org project',
        );
        $this->assertTrue(
            AccessDecision::can($superAdmin->fresh(), Capability::PROJECTS_EDIT, $projectA),
            'super_admin short-circuit must grant edit of cross-org project',
        );

        $trace = AccessDecision::whyCan($superAdmin->fresh(), Capability::PROJECTS_VIEW, $projectA);
        $this->assertTrue($trace['granted']);
        $this->assertSame(
            'canonical_admin',
            $trace['layer'],
            "super_admin grant must flow through the 'canonical_admin' layer (top of evaluateCanonical)",
        );
    }
}
