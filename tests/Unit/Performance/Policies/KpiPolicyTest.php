<?php

namespace Tests\Unit\Performance\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Policies\KpiPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * KpiPolicyTest - Phase 4: per-record Kpi authz + org isolation.
 *
 * Mirrors the EmployeeProfilePolicyTest pattern (before() + precheck() + capability
 * delegation). Asserts the four canonical isolation cases plus capability boundaries.
 */
class KpiPolicyTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private function makeUserWith(string $capability, ?int $orgId = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $orgId,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, $capability);

        return $user;
    }

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_super_admin_can_view_any_kpi(): void
    {
        $super = $this->makeSuperAdmin();
        $policy = new KpiPolicy;

        $this->assertTrue($policy->view($super, Kpi::factory()->create()));
    }

    public function test_same_org_user_with_view_can_view(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_VIEW, $org->id);
        $kpi = Kpi::factory()->create(['organization_id' => $org->id]);
        $policy = new KpiPolicy;

        $this->assertTrue($policy->view($user, $kpi));
    }

    public function test_cross_org_user_cannot_view(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_VIEW, $orgA->id);
        $foreignKpi = Kpi::factory()->create(['organization_id' => $orgB->id]);
        $policy = new KpiPolicy;

        $this->assertFalse($policy->view($user, $foreignKpi));
    }

    public function test_null_org_user_cannot_view(): void
    {
        $user = $this->makeUserWith(Capability::KPIS_VIEW, null);
        $kpi = Kpi::factory()->create();
        $policy = new KpiPolicy;

        $this->assertFalse($policy->view($user, $kpi));
    }

    public function test_user_with_manage_can_update_same_org_kpi(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_MANAGE, $org->id);
        $kpi = Kpi::factory()->create(['organization_id' => $org->id]);
        $policy = new KpiPolicy;

        $this->assertTrue($policy->update($user, $kpi));
    }

    public function test_user_with_manage_cannot_update_cross_org_kpi(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_MANAGE, $orgA->id);
        $foreignKpi = Kpi::factory()->create(['organization_id' => $orgB->id]);
        $policy = new KpiPolicy;

        $this->assertFalse($policy->update($user, $foreignKpi));
    }

    public function test_user_without_manage_cannot_update_same_org_kpi(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_VIEW, $org->id);
        $kpi = Kpi::factory()->create(['organization_id' => $org->id]);
        $policy = new KpiPolicy;

        $this->assertFalse($policy->update($user, $kpi));
    }

    public function test_user_with_manage_can_delete_same_org_kpi(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_MANAGE, $org->id);
        $kpi = Kpi::factory()->create(['organization_id' => $org->id]);
        $policy = new KpiPolicy;

        $this->assertTrue($policy->delete($user, $kpi));
    }

    public function test_user_with_manage_cannot_delete_cross_org_kpi(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_MANAGE, $orgA->id);
        $foreignKpi = Kpi::factory()->create(['organization_id' => $orgB->id]);
        $policy = new KpiPolicy;

        $this->assertFalse($policy->delete($user, $foreignKpi));
    }

    public function test_view_any_requires_view_capability(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_VIEW, $org->id);
        $policy = new KpiPolicy;

        $this->assertTrue($policy->viewAny($user));
    }

    public function test_view_any_without_capability_denies(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        // No capability granted.
        $policy = new KpiPolicy;

        $this->assertFalse($policy->viewAny($user));
    }

    public function test_create_requires_manage_capability(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_MANAGE, $org->id);
        $policy = new KpiPolicy;

        $this->assertTrue($policy->create($user));
    }

    public function test_create_with_only_view_capability_denies(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_VIEW, $org->id);
        $policy = new KpiPolicy;

        $this->assertFalse($policy->create($user));
    }
}
