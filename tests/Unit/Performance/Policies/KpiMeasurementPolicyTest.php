<?php

namespace Tests\Unit\Performance\Policies;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiMeasurement;
use App\Modules\Performance\Policies\KpiMeasurementPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * KpiMeasurementPolicyTest - Phase 4: per-record KpiMeasurement authz + org isolation.
 */
class KpiMeasurementPolicyTest extends TestCase
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
        $this->grantCanonicalSuperAdmin($user);

        return $user;
    }

    private function makeMeasurementFor(Organization $org, ?Organization $measurementOrg = null): KpiMeasurement
    {
        $kpi = Kpi::factory()->create(['organization_id' => $org->id]);

        $m = new KpiMeasurement([
            'kpi_id' => $kpi->id,
            'value' => 10,
            'measurement_date' => now()->toDateString(),
        ]);
        $m->forceFill(['organization_id' => ($measurementOrg ?? $org)->id])->save();

        return $m;
    }

    public function test_super_admin_can_view_any_measurement(): void
    {
        $super = $this->makeSuperAdmin();
        $org = Organization::factory()->create();
        $m = $this->makeMeasurementFor($org);
        $policy = new KpiMeasurementPolicy;

        $this->assertTrue($policy->view($super, $m));
    }

    public function test_same_org_user_with_view_can_view(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_VIEW, $org->id);
        $m = $this->makeMeasurementFor($org);
        $policy = new KpiMeasurementPolicy;

        $this->assertTrue($policy->view($user, $m));
    }

    public function test_cross_org_user_cannot_view_measurement(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_VIEW, $orgA->id);
        $m = $this->makeMeasurementFor($orgB);
        $policy = new KpiMeasurementPolicy;

        $this->assertFalse($policy->view($user, $m));
    }

    public function test_null_org_user_cannot_view_measurement(): void
    {
        $user = $this->makeUserWith(Capability::KPIS_VIEW, null);
        $org = Organization::factory()->create();
        $m = $this->makeMeasurementFor($org);
        $policy = new KpiMeasurementPolicy;

        $this->assertFalse($policy->view($user, $m));
    }

    public function test_user_with_manage_can_update_same_org_measurement(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_MANAGE, $org->id);
        $m = $this->makeMeasurementFor($org);
        $policy = new KpiMeasurementPolicy;

        $this->assertTrue($policy->update($user, $m));
    }

    public function test_cross_org_user_cannot_update_measurement(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_MANAGE, $orgA->id);
        $m = $this->makeMeasurementFor($orgB);
        $policy = new KpiMeasurementPolicy;

        $this->assertFalse($policy->update($user, $m));
    }
}
