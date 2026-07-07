<?php

namespace Tests\Unit\Performance\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Policies\KpiLinkPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * KpiLinkPolicyTest - Phase 4: per-record KpiLink authz + org isolation.
 */
class KpiLinkPolicyTest extends TestCase
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

    private function makeLinkFor(Organization $org, ?Organization $linkOrg = null): KpiLink
    {
        $kpi = Kpi::factory()->create(['organization_id' => $org->id]);

        $link = new KpiLink([
            'kpi_id' => $kpi->id,
            'linkable_type' => 'project',
            'linkable_id' => 0,
            'relationship_type' => 'related',
        ]);
        $link->forceFill(['organization_id' => ($linkOrg ?? $org)->id])->save();

        return $link;
    }

    public function test_super_admin_can_view_any_link(): void
    {
        $super = $this->makeSuperAdmin();
        $org = Organization::factory()->create();
        $link = $this->makeLinkFor($org);
        $policy = new KpiLinkPolicy;

        $this->assertTrue($policy->view($super, $link));
    }

    public function test_same_org_user_with_view_can_view_link(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_VIEW, $org->id);
        $link = $this->makeLinkFor($org);
        $policy = new KpiLinkPolicy;

        $this->assertTrue($policy->view($user, $link));
    }

    public function test_cross_org_user_cannot_view_link(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_VIEW, $orgA->id);
        $foreignLink = $this->makeLinkFor($orgB);
        $policy = new KpiLinkPolicy;

        $this->assertFalse($policy->view($user, $foreignLink));
    }

    public function test_null_org_user_cannot_view_link(): void
    {
        $user = $this->makeUserWith(Capability::KPIS_VIEW, null);
        $org = Organization::factory()->create();
        $link = $this->makeLinkFor($org);
        $policy = new KpiLinkPolicy;

        $this->assertFalse($policy->view($user, $link));
    }

    public function test_user_with_manage_can_delete_same_org_link(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_MANAGE, $org->id);
        $link = $this->makeLinkFor($org);
        $policy = new KpiLinkPolicy;

        $this->assertTrue($policy->delete($user, $link));
    }

    public function test_cross_org_user_cannot_delete_link(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_MANAGE, $orgA->id);
        $foreignLink = $this->makeLinkFor($orgB);
        $policy = new KpiLinkPolicy;

        $this->assertFalse($policy->delete($user, $foreignLink));
    }
}
