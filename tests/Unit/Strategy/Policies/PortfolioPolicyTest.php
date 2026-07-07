<?php

namespace Tests\Unit\Strategy\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Policies\PortfolioPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
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

        Role::firstOrCreate(['name' => 'pmo', 'guard_name' => 'web']);

        // PMO is an organization-level functional role: the engine grants it the
        // strategy capabilities via an org-scoped role definition (the bridge in
        // AccessDecision::grantedViaOrgFunctionalRole). Without this definition
        // the engine has no data to allow PMO, so seed it explicitly.
        $orgScope = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_ORGANIZATION],
            [
                'label_ar' => 'organization',
                'label_en' => 'organization',
                'model_class' => Organization::class,
                'supports_hierarchy' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        if (ScopedRoleDefinition::findByKey(ScopedRole::SCOPE_ORGANIZATION, 'pmo') === null) {
            DB::table('scoped_role_definitions')->insert([
                'name' => 'organization.pmo',
                'display_name' => 'pmo',
                'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
                'scope_type_id' => $orgScope->id,
                'role_key' => 'pmo',
                'label_ar' => 'pmo',
                'label_en' => 'pmo',
                'permissions' => json_encode($this->expandFlags([
                    Capability::STRATEGY_MANAGE_PRIORITY,
                    Capability::STRATEGY_CHANGE_STATUS,
                    Capability::STRATEGY_ASSIGN_OWNER,
                ], ['can_edit' => true, 'can_view_all' => true])),
                'is_admin_role' => false,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->pmoInOrgA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);
        $this->pmoInOrgA->assignRole('pmo');

        $this->pmoInOrgB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'is_active' => true,
        ]);
        $this->pmoInOrgB->assignRole('pmo');

        $this->superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');

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

    /**
     * Expand the retired boolean flags (can_edit/can_delete/can_view_all/
     * can_manage_members/can_view_confidential) into their equivalent
     * permissions[] entries, mirroring the pre-Phase-3 engine's
     * capabilityMatchesFlags action-suffix matching (see the
     * 2026_07_01_100001_backfill_granular_flags_into_permissions migration).
     *
     * @param  array<int, string>  $permissions
     * @param  array<string, bool>  $flags
     * @return array<int, string>
     */
    private function expandFlags(array $permissions, array $flags): array
    {
        $byAction = fn (array $actions): array => array_values(array_filter(
            Capability::all(),
            function (string $c) use ($actions) {
                $a = str_contains($c, '.') ? substr($c, strrpos($c, '.') + 1) : $c;

                return in_array($a, $actions, true);
            }
        ));

        if (! empty($flags['can_edit'])) {
            $permissions = array_merge($permissions, $byAction(['edit', 'update']));
        }
        if (! empty($flags['can_delete'])) {
            $permissions = array_merge($permissions, $byAction(['delete', 'remove']));
        }
        if (! empty($flags['can_view_all'])) {
            $permissions = array_merge($permissions, $byAction(['view', 'view_all', 'view_reports']));
        }
        if (! empty($flags['can_manage_members'])) {
            $permissions = array_merge($permissions, $byAction(['manage_members', 'assign_roles']));
        }
        if (! empty($flags['can_view_confidential'])) {
            $permissions[] = Capability::OVR_VIEW_CONFIDENTIAL;
        }

        return array_values(array_unique($permissions));
    }
}
