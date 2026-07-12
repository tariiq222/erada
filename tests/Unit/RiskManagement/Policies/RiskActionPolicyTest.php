<?php

namespace Tests\Unit\RiskManagement\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\RiskManagement\Policies\RiskActionPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * RiskActionPolicy unit tests — engine-only path (Phase هـ Task 4).
 *
 * RiskAction now implements ScopeAware: scopeParent() returns the parent Risk,
 * so the engine walks the scope chain (RiskAction → Risk → org) to enforce
 * org isolation. GAP-3 is closed.
 */
class RiskActionPolicyTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    /** User with a canonical contextual risk-manager grant on the parent Risk. */
    private User $userA;

    /** User in orgB — cross-org. */
    private User $userB;

    private User $superAdmin;

    private Risk $riskInOrgA;

    private RiskAction $actionInOrgA;

    private RiskActionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);

        $this->riskInOrgA = Risk::factory()->forOrganization($this->orgA)->create();

        $this->actionInOrgA = RiskAction::factory()->create([
            'risk_id' => $this->riskInOrgA->id,
            'organization_id' => $this->orgA->id,
        ]);

        $this->userA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);

        $this->userB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'is_active' => true,
        ]);

        // Grant userA a contextual 'risk_manager' role on the parent Risk.
        // The engine walks RiskAction → Risk → org, so a role on the Risk
        // grants access to its child RiskActions too.
        $this->grantEngineCapability(
            $this->userA,
            [Capability::RISKS_VIEW, Capability::RISKS_EDIT],
            'risk',
            $this->riskInOrgA->id,
            'risk_manager',
        );

        $this->policy = new RiskActionPolicy;
    }

    // ========== super_admin ==========

    public function test_super_admin_can_view_risk_action_in_any_org(): void
    {
        $this->assertTrue($this->policy->view($this->superAdmin, $this->actionInOrgA));
    }

    // ========== contextual canonical assignment on parent Risk grants access ==========

    public function test_user_with_risk_manager_role_on_parent_risk_can_view_action(): void
    {
        $this->assertTrue($this->policy->view($this->userA, $this->actionInOrgA));
    }

    public function test_user_with_risk_manager_role_on_parent_risk_can_update_action(): void
    {
        $this->assertTrue($this->policy->update($this->userA, $this->actionInOrgA));
    }

    // ========== org isolation (D-02 / GAP-3 now closed) ==========

    public function test_cross_org_user_cannot_view_risk_action(): void
    {
        $this->assertFalse($this->policy->view($this->userB, $this->actionInOrgA));
    }

    public function test_cross_org_user_cannot_update_risk_action(): void
    {
        $this->assertFalse($this->policy->update($this->userB, $this->actionInOrgA));
    }

    public function test_cross_org_user_cannot_delete_risk_action(): void
    {
        $this->assertFalse($this->policy->delete($this->userB, $this->actionInOrgA));
    }

    // ========== no role = no access ==========

    public function test_user_without_any_role_cannot_view_risk_action(): void
    {
        $userNoRole = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->view($userNoRole, $this->actionInOrgA));
    }
}
