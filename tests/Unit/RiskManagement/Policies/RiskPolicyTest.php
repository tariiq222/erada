<?php

namespace Tests\Unit\RiskManagement\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Policies\RiskPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * RiskPolicy unit tests — engine-only path (Phase هـ Task 4).
 *
 * Access is now decided exclusively by AccessDecision::can() via contextual
 * canonical role assignments.
 */
class RiskPolicyTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    /** User with a canonical contextual risk-manager grant on riskInOrgA. */
    private User $userA;

    /** User in orgB — cross-org. */
    private User $userB;

    private User $superAdmin;

    private Risk $riskInOrgA;

    private RiskPolicy $policy;

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

        $this->userA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);

        $this->userB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'is_active' => true,
        ]);

        // Grant userA a contextual 'risk_manager' role scoped to the risk itself.
        $this->grantEngineCapability(
            $this->userA,
            [Capability::RISKS_VIEW, Capability::RISKS_EDIT],
            'risk',
            $this->riskInOrgA->id,
            'risk_manager',
        );

        $this->policy = new RiskPolicy;
    }

    // ========== super_admin ==========

    public function test_super_admin_can_view_risk_in_any_org(): void
    {
        $this->assertTrue($this->policy->view($this->superAdmin, $this->riskInOrgA));
    }

    // ========== contextual canonical assignment grants access ==========

    public function test_user_with_risk_manager_scoped_role_can_view_risk(): void
    {
        $this->assertTrue($this->policy->view($this->userA, $this->riskInOrgA));
    }

    public function test_user_with_risk_manager_scoped_role_can_update_risk(): void
    {
        $this->assertTrue($this->policy->update($this->userA, $this->riskInOrgA));
    }

    // ========== org isolation (D-02) — engine enforces these ==========

    public function test_user_in_other_org_with_permission_cannot_view_risk(): void
    {
        $this->assertFalse($this->policy->view($this->userB, $this->riskInOrgA));
    }

    public function test_user_in_other_org_with_permission_cannot_update_risk(): void
    {
        $this->assertFalse($this->policy->update($this->userB, $this->riskInOrgA));
    }

    public function test_user_in_other_org_with_permission_cannot_delete_risk(): void
    {
        $this->assertFalse($this->policy->delete($this->userB, $this->riskInOrgA));
    }

    // ========== no role = no access ==========

    public function test_user_without_any_role_in_same_org_cannot_view_risk(): void
    {
        $userNoRole = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->view($userNoRole, $this->riskInOrgA));
    }
}
