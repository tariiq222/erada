<?php

namespace Tests\Unit\RiskManagement\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Policies\RiskPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RiskPolicy unit tests — engine-only path (Phase هـ Task 4).
 *
 * Access is now decided exclusively by AccessDecision::can() via contextual
 * ScopedRoles. Spatie flat permissions are no longer read by this policy.
 */
class RiskPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    /** User with a contextual 'risk_manager' ScopedRole on riskInOrgA. */
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
        $this->superAdmin->assignRole('super_admin');

        $this->riskInOrgA = Risk::factory()->forOrganization($this->orgA)->create();

        $this->userA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_active' => true,
        ]);

        $this->userB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'is_active' => true,
        ]);

        $this->seedRiskScopeDefinitions();

        // Grant userA a contextual 'risk_manager' role scoped to the risk itself.
        $this->userA->assignScopedRole(
            role: 'risk_manager',
            scopeType: 'risk',
            scopeId: $this->riskInOrgA->id,
            grantedBy: $this->superAdmin->id,
        );

        $this->policy = new RiskPolicy;
    }

    // ========== super_admin ==========

    public function test_super_admin_can_view_risk_in_any_org(): void
    {
        $this->assertTrue($this->policy->view($this->superAdmin, $this->riskInOrgA));
    }

    // ========== contextual ScopedRole grants access ==========

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

    // ========== helpers ==========

    /**
     * Seed a 'risk' ScopeType + 'risk_manager' ScopedRoleDefinition for the engine.
     * Idempotent — safe to call in setUp.
     */
    private function seedRiskScopeDefinitions(): void
    {
        $now = now();

        $scopeTypeId = DB::table('scope_types')
            ->where('key', 'risk')
            ->value('id');

        if ($scopeTypeId === null) {
            $scopeTypeId = DB::table('scope_types')->insertGetId([
                'key' => 'risk',
                'label_ar' => 'الخطر',
                'label_en' => 'Risk',
                'model_class' => Risk::class,
                'icon' => null,
                'color' => 'danger',
                'supports_hierarchy' => true,
                'supports_expiry' => false,
                'is_active' => true,
                'sort_order' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $exists = DB::table('scoped_role_definitions')
            ->where('scope_type_id', $scopeTypeId)
            ->where('role_key', 'risk_manager')
            ->exists();

        if (! $exists) {
            DB::table('scoped_role_definitions')->insert([
                'name' => 'risk.risk_manager',
                'display_name' => 'Risk Manager',
                'scope_type' => 'risk',
                'scope_type_id' => $scopeTypeId,
                'role_key' => 'risk_manager',
                'label_ar' => 'مدير الخطر',
                'label_en' => 'Risk Manager',
                'description' => null,
                'color' => 'danger',
                'permissions' => json_encode([
                    Capability::RISKS_VIEW,
                    Capability::RISKS_EDIT,
                    Capability::RISKS_DELETE,
                    Capability::RISKS_REASSESS,
                    Capability::RISKS_CHANGE_STATUS,
                ]),
                'is_admin_role' => false,
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Cache::flush();
    }
}
