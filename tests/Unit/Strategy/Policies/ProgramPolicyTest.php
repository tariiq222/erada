<?php

namespace Tests\Unit\Strategy\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Policies\ProgramPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $pmoInOrgA;

    private User $pmoInOrgB;

    private User $superAdmin;

    private Program $programInOrgA;

    private ProgramPolicy $policy;

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

        $this->programInOrgA = Program::factory()->create([
            'organization_id' => $this->orgA->id,
        ]);

        $this->policy = new ProgramPolicy;
    }

    public function test_pmo_in_other_org_cannot_change_portfolio(): void
    {
        $this->assertFalse($this->policy->changePortfolio($this->pmoInOrgB, $this->programInOrgA));
    }

    public function test_pmo_in_same_org_can_change_portfolio(): void
    {
        $this->assertTrue($this->policy->changePortfolio($this->pmoInOrgA, $this->programInOrgA));
    }

    public function test_pmo_in_other_org_cannot_manage_weight(): void
    {
        $this->assertFalse($this->policy->manageWeight($this->pmoInOrgB, $this->programInOrgA));
    }

    public function test_pmo_in_same_org_can_manage_weight(): void
    {
        $this->assertTrue($this->policy->manageWeight($this->pmoInOrgA, $this->programInOrgA));
    }

    public function test_pmo_in_other_org_cannot_manage_projects(): void
    {
        $this->assertFalse($this->policy->manageProjects($this->pmoInOrgB, $this->programInOrgA));
    }

    public function test_pmo_in_same_org_can_manage_projects(): void
    {
        $this->assertTrue($this->policy->manageProjects($this->pmoInOrgA, $this->programInOrgA));
    }

    public function test_pmo_in_other_org_cannot_assign_program_manager(): void
    {
        $this->assertFalse($this->policy->assignProgramManager($this->pmoInOrgB, $this->programInOrgA));
    }

    public function test_pmo_in_same_org_can_assign_program_manager(): void
    {
        $this->assertTrue($this->policy->assignProgramManager($this->pmoInOrgA, $this->programInOrgA));
    }

    public function test_pmo_in_other_org_cannot_assign_executive_sponsor(): void
    {
        $this->assertFalse($this->policy->assignExecutiveSponsor($this->pmoInOrgB, $this->programInOrgA));
    }

    public function test_pmo_in_same_org_can_assign_executive_sponsor(): void
    {
        $this->assertTrue($this->policy->assignExecutiveSponsor($this->pmoInOrgA, $this->programInOrgA));
    }

    public function test_pmo_in_other_org_cannot_link_project(): void
    {
        $this->assertFalse($this->policy->linkProject($this->pmoInOrgB, $this->programInOrgA));
    }

    public function test_pmo_in_same_org_can_link_project(): void
    {
        $this->assertTrue($this->policy->linkProject($this->pmoInOrgA, $this->programInOrgA));
    }

    public function test_pmo_in_other_org_cannot_view_reports(): void
    {
        $this->assertFalse($this->policy->viewReports($this->pmoInOrgB, $this->programInOrgA));
    }

    public function test_pmo_in_same_org_can_view_reports(): void
    {
        $this->assertTrue($this->policy->viewReports($this->pmoInOrgA, $this->programInOrgA));
    }

    public function test_super_admin_can_change_portfolio_in_any_org(): void
    {
        $this->assertTrue($this->policy->changePortfolio($this->superAdmin, $this->programInOrgA));
    }

    public function test_super_admin_can_manage_weight_in_any_org(): void
    {
        $this->assertTrue($this->policy->manageWeight($this->superAdmin, $this->programInOrgA));
    }

    /** @return list<string> */
    private function pmoCapabilities(): array
    {
        return [
            Capability::STRATEGY_VIEW,
            Capability::STRATEGY_MANAGE_PRIORITY,
            Capability::STRATEGY_ASSIGN_OWNER,
            Capability::STRATEGY_MANAGE_PROJECTS,
        ];
    }
}
