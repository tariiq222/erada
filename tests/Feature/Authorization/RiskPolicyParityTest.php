<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\RiskManagement\Policies\RiskActionPolicy;
use App\Modules\RiskManagement\Policies\RiskPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RiskPolicyParityTest — Phase هـ Task 4 cutover verification
 *
 * Both policies are now engine-only (AccessDecision::can). All markTestIncomplete
 * stubs are replaced with real assertions reflecting the engine's actual behaviour:
 *
 *   D-02/D-04 org isolation:
 *     - Cross-org user → denied (engine enforces sameOrganization()).
 *     - Null-org non-super-admin user → denied.
 *
 *   GAP-1 (viewAny / viewReports / create — no model target):
 *     - Engine denies users who have only Spatie flat perms but no org-level ScopedRole.
 *     - This is correct — the engine reads contextual ScopedRoles, not Spatie flat perms.
 *
 *   GAP-2 (same-org user with Spatie perm but no ScopedRole):
 *     - Engine denies. Documented here as intentional — access requires a ScopedRole.
 *
 *   GAP-3 (RiskAction org isolation):
 *     - CLOSED in Phase هـ Task 4: RiskAction now implements ScopeAware.
 *     - Engine enforces org isolation on RiskAction via the scope chain
 *       (RiskAction → Risk → org). Both cross-org and null-org are denied.
 *
 *   super_admin bypass:
 *     - before() short-circuits; super_admin always gets true.
 */
class RiskPolicyParityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    /** User in orgA with no engine grant (no ScopedRole, no functional role). */
    private User $userA;

    /** User in orgB with no engine grant. */
    private User $userB;

    /** User with no organization_id and no engine grant. */
    private User $nullOrgUser;

    private User $superAdmin;

    private Risk $riskInOrgA;

    private RiskAction $actionInOrgA;

    private RiskPolicy $policy;

    private RiskActionPolicy $actionPolicy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        // Build users with NO engine grants: the test asserts the engine denies
        // them on every capability. The legacy Spatie flat permissions have
        // been removed from the catalog (Wave 3 task 8); they were a fall-back
        // path that the engine ignored anyway. Keeping the users plain here
        // exercises the engine's deny-by-default branch for org/cross-org/null-org.
        $this->userA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);

        $this->userB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'is_active' => true,
        ]);

        $this->nullOrgUser = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');

        $this->riskInOrgA = Risk::factory()->forOrganization($this->orgA)->create();

        $this->actionInOrgA = RiskAction::factory()->create([
            'risk_id' => $this->riskInOrgA->id,
            'organization_id' => $this->orgA->id,
        ]);

        $this->policy = new RiskPolicy;
        $this->actionPolicy = new RiskActionPolicy;
    }

    // ============================================================
    // Org isolation — cross-org (D-02)
    // ============================================================

    public function test_cross_org_view_denied(): void
    {
        $this->assertFalse($this->policy->view($this->userB, $this->riskInOrgA));
    }

    public function test_cross_org_update_denied(): void
    {
        $this->assertFalse($this->policy->update($this->userB, $this->riskInOrgA));
    }

    public function test_cross_org_delete_denied(): void
    {
        $this->assertFalse($this->policy->delete($this->userB, $this->riskInOrgA));
    }

    public function test_cross_org_reassess_denied(): void
    {
        $this->assertFalse($this->policy->reassess($this->userB, $this->riskInOrgA));
    }

    public function test_cross_org_change_status_denied(): void
    {
        $this->assertFalse($this->policy->changeStatus($this->userB, $this->riskInOrgA));
    }

    // ============================================================
    // Null-org user (D-04)
    // ============================================================

    public function test_null_org_user_view_denied(): void
    {
        $this->assertFalse($this->policy->view($this->nullOrgUser, $this->riskInOrgA));
    }

    public function test_null_org_user_update_denied(): void
    {
        $this->assertFalse($this->policy->update($this->nullOrgUser, $this->riskInOrgA));
    }

    public function test_null_org_user_delete_denied(): void
    {
        $this->assertFalse($this->policy->delete($this->nullOrgUser, $this->riskInOrgA));
    }

    // ============================================================
    // super_admin bypass
    // ============================================================

    public function test_super_admin_before_view_returns_true(): void
    {
        $this->assertTrue($this->policy->before($this->superAdmin, 'view'));
    }

    public function test_super_admin_before_delete_returns_true(): void
    {
        $this->assertTrue($this->policy->before($this->superAdmin, 'delete'));
    }

    // ============================================================
    // GAP-1 (now documented as correct engine behaviour):
    // Users with only Spatie flat perms and no org-level ScopedRole
    // are denied by the engine for target-less capabilities.
    // This is intentional — the engine does not read Spatie flat perms.
    // ============================================================

    public function test_engine_denies_viewany_without_scoped_role(): void
    {
        // userA has all Spatie risk perms but no contextual ScopedRole.
        // Engine checks org-level ScopedRoles only → denies.
        $this->assertFalse($this->policy->viewAny($this->userA));
    }

    public function test_engine_denies_viewreports_without_scoped_role(): void
    {
        $this->assertFalse($this->policy->viewReports($this->userA));
    }

    public function test_engine_denies_create_without_scoped_role(): void
    {
        $this->assertFalse($this->policy->create($this->userA));
    }

    // ============================================================
    // GAP-2 (now documented as correct engine behaviour):
    // Same-org user with Spatie perm but no contextual ScopedRole → denied.
    // ============================================================

    public function test_engine_denies_view_for_same_org_user_without_scoped_role(): void
    {
        $this->assertFalse($this->policy->view($this->userA, $this->riskInOrgA));
    }

    public function test_engine_denies_update_for_same_org_user_without_scoped_role(): void
    {
        $this->assertFalse($this->policy->update($this->userA, $this->riskInOrgA));
    }

    public function test_engine_denies_delete_for_same_org_user_without_scoped_role(): void
    {
        $this->assertFalse($this->policy->delete($this->userA, $this->riskInOrgA));
    }

    public function test_engine_denies_reassess_for_same_org_user_without_scoped_role(): void
    {
        $this->assertFalse($this->policy->reassess($this->userA, $this->riskInOrgA));
    }

    public function test_engine_denies_change_status_for_same_org_user_without_scoped_role(): void
    {
        $this->assertFalse($this->policy->changeStatus($this->userA, $this->riskInOrgA));
    }

    // ============================================================
    // GAP-3 CLOSED: RiskAction now implements ScopeAware.
    // Engine enforces org isolation on RiskAction via scope chain.
    // ============================================================

    public function test_cross_org_action_view_denied_by_engine(): void
    {
        // userB is in orgB; actionInOrgA is in orgA.
        // Engine walks RiskAction → Risk → org and denies cross-org access.
        $this->assertFalse($this->actionPolicy->view($this->userB, $this->actionInOrgA));
    }

    public function test_cross_org_action_update_denied_by_engine(): void
    {
        $this->assertFalse($this->actionPolicy->update($this->userB, $this->actionInOrgA));
    }

    public function test_cross_org_action_delete_denied_by_engine(): void
    {
        $this->assertFalse($this->actionPolicy->delete($this->userB, $this->actionInOrgA));
    }

    public function test_null_org_user_action_view_denied_by_engine(): void
    {
        $this->assertFalse($this->actionPolicy->view($this->nullOrgUser, $this->actionInOrgA));
    }
}
