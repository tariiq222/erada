<?php

namespace Tests\Unit\Performance\Scopes;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Models\KpiMeasurement;
use App\Modules\Performance\Scopes\UserKpiScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UserKpiScopeTest - Phase 4: verify the single source of truth for KPI org floor.
 *
 * Tests the three variants (applyToKpis, applyToMeasurements, applyToLinks) across
 * the four canonical isolation cases: super_admin, normal user, null-org user,
 * and (for measurements/links) the parent-KPI chain.
 */
class UserKpiScopeTest extends TestCase
{
    use RefreshDatabase;

    private UserKpiScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserKpiScope;
    }

    public function test_super_admin_sees_all_kpis(): void
    {
        $super = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $super->assignRole('super_admin');

        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        Kpi::factory()->count(2)->create(['organization_id' => $orgA->id]);
        Kpi::factory()->count(3)->create(['organization_id' => $orgB->id]);

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $super);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_org_a_user_sees_only_org_a_kpis(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);

        Kpi::factory()->count(2)->create(['organization_id' => $orgA->id]);
        Kpi::factory()->count(3)->create(['organization_id' => $orgB->id]);

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $user);

        $this->assertSame(2, (clone $query)->count());
    }

    public function test_null_org_user_sees_no_kpis(): void
    {
        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        // No role — explicit orphan user (not super_admin).

        $orgA = Organization::factory()->create();
        Kpi::factory()->count(2)->create(['organization_id' => $orgA->id]);

        $query = Kpi::query();
        $this->scope->applyToKpis($query, $orphan);

        // whereRaw('false') ⇒ zero rows regardless of seed.
        $this->assertSame(0, (clone $query)->count());
    }

    public function test_apply_to_measurements_uses_parent_kpi_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);

        $kpiA = Kpi::factory()->create(['organization_id' => $orgA->id]);
        $kpiB = Kpi::factory()->create(['organization_id' => $orgB->id]);

        $this->makeMeasurement($kpiA, $orgA);
        $this->makeMeasurement($kpiB, $orgB);

        $query = KpiMeasurement::query();
        $this->scope->applyToMeasurements($query, $user);

        $this->assertSame(1, (clone $query)->count());
    }

    public function test_apply_to_links_uses_parent_kpi_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);

        $kpiA = Kpi::factory()->create(['organization_id' => $orgA->id]);
        $kpiB = Kpi::factory()->create(['organization_id' => $orgB->id]);

        $this->makeLink($kpiA, $orgA);
        $this->makeLink($kpiB, $orgB);

        $query = KpiLink::query();
        $this->scope->applyToLinks($query, $user);

        $this->assertSame(1, (clone $query)->count());
    }

    public function test_null_org_user_sees_no_measurements(): void
    {
        $orphan = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);

        $orgA = Organization::factory()->create();
        $kpiA = Kpi::factory()->create(['organization_id' => $orgA->id]);
        $this->makeMeasurement($kpiA, $orgA);

        $query = KpiMeasurement::query();
        $this->scope->applyToMeasurements($query, $orphan);

        $this->assertSame(0, (clone $query)->count());
    }

    private function makeMeasurement(Kpi $kpi, Organization $org): KpiMeasurement
    {
        $m = new KpiMeasurement([
            'kpi_id' => $kpi->id,
            'value' => 10,
            'measurement_date' => now()->toDateString(),
            'recorded_by' => null,
        ]);
        $m->forceFill(['organization_id' => $org->id])->save();

        return $m;
    }

    private function makeLink(Kpi $kpi, Organization $org): KpiLink
    {
        $link = new KpiLink([
            'kpi_id' => $kpi->id,
            'linkable_type' => 'project',
            'linkable_id' => 0,
            'relationship_type' => 'related',
        ]);
        $link->forceFill(['organization_id' => $org->id])->save();

        return $link;
    }
}
