<?php

namespace Tests\Unit\Strategy\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Policies\PortfolioPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $pmoInOrgA;

    private User $pmoInOrgB;

    private User $superAdmin;

    private Portfolio $portfolioInOrgA;

    private PortfolioPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->pmoInOrgA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->pmoInOrgA, 'manager', capabilities: $this->pmoCapabilities());

        $this->pmoInOrgB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->pmoInOrgB, 'manager', capabilities: $this->pmoCapabilities());

        $this->superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);

        $this->portfolioInOrgA = Portfolio::factory()->create([
            'organization_id' => $this->orgA->id,
        ]);

        $this->policy = new PortfolioPolicy;
    }

    public function test_pmo_in_other_org_cannot_manage_priority(): void
    {
        $this->assertFalse($this->policy->managePriority($this->pmoInOrgB, $this->portfolioInOrgA));
    }

    public function test_pmo_in_same_org_can_manage_priority(): void
    {
        $this->assertTrue($this->policy->managePriority($this->pmoInOrgA, $this->portfolioInOrgA));
    }

    public function test_pmo_in_other_org_cannot_assign_owner(): void
    {
        $this->assertFalse($this->policy->assignOwner($this->pmoInOrgB, $this->portfolioInOrgA));
    }

    public function test_pmo_in_same_org_can_assign_owner(): void
    {
        $this->assertTrue($this->policy->assignOwner($this->pmoInOrgA, $this->portfolioInOrgA));
    }

    public function test_pmo_in_other_org_cannot_change_strategic_status(): void
    {
        $this->assertFalse($this->policy->changeStrategicStatus($this->pmoInOrgB, $this->portfolioInOrgA));
    }

    public function test_pmo_in_same_org_can_change_strategic_status(): void
    {
        $this->assertTrue($this->policy->changeStrategicStatus($this->pmoInOrgA, $this->portfolioInOrgA));
    }

    public function test_pmo_in_other_org_cannot_force_close(): void
    {
        $this->assertFalse($this->policy->forceClose($this->pmoInOrgB, $this->portfolioInOrgA));
    }

    public function test_pmo_in_same_org_can_force_close(): void
    {
        $this->assertTrue($this->policy->forceClose($this->pmoInOrgA, $this->portfolioInOrgA));
    }

    public function test_super_admin_can_manage_priority_in_any_org(): void
    {
        $this->assertTrue($this->policy->managePriority($this->superAdmin, $this->portfolioInOrgA));
    }

    public function test_super_admin_can_assign_owner_in_any_org(): void
    {
        $this->assertTrue($this->policy->assignOwner($this->superAdmin, $this->portfolioInOrgA));
    }

    /** @return list<string> */
    private function pmoCapabilities(): array
    {
        return [
            Capability::STRATEGY_MANAGE_PRIORITY,
            Capability::STRATEGY_CHANGE_STATUS,
            Capability::STRATEGY_ASSIGN_OWNER,
        ];
    }
}
