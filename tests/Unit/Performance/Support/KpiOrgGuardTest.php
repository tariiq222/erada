<?php

namespace Tests\Unit\Performance\Support;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Models\KpiMeasurement;
use App\Modules\Performance\Support\KpiOrgGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * KpiOrgGuardTest - Phase 4: verify the single source of truth for same-org gates.
 */
class KpiOrgGuardTest extends TestCase
{
    use RefreshDatabase;

    private KpiOrgGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new KpiOrgGuard;
    }

    public function test_kpi_org_id_reads_direct_column(): void
    {
        $org = Organization::factory()->create();
        $kpi = Kpi::factory()->create(['organization_id' => $org->id]);

        $this->assertSame($org->id, $this->guard->kpiOrgId($kpi));
    }

    public function test_kpi_org_id_returns_null_for_null_model(): void
    {
        $this->assertNull($this->guard->kpiOrgId(null));
    }

    public function test_measurement_org_id_reads_direct_column(): void
    {
        $org = Organization::factory()->create();
        $kpi = Kpi::factory()->create(['organization_id' => $org->id]);
        $m = new KpiMeasurement([
            'kpi_id' => $kpi->id,
            'value' => 10,
            'measurement_date' => now()->toDateString(),
        ]);
        $m->forceFill(['organization_id' => $org->id])->save();

        $this->assertSame($org->id, $this->guard->measurementOrgId($m));
    }

    public function test_link_org_id_reads_direct_column(): void
    {
        $org = Organization::factory()->create();
        $kpi = Kpi::factory()->create(['organization_id' => $org->id]);
        $link = new KpiLink([
            'kpi_id' => $kpi->id,
            'linkable_type' => 'project',
            'linkable_id' => 0,
            'relationship_type' => 'related',
        ]);
        $link->forceFill(['organization_id' => $org->id])->save();

        $this->assertSame($org->id, $this->guard->linkOrgId($link));
    }

    public function test_same_org_actor_and_target_match_returns_true(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $this->assertTrue($this->guard->sameOrganization($user, $org->id));
    }

    public function test_same_org_actor_and_target_mismatch_returns_false(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->guard->sameOrganization($user, $orgB->id));
    }

    public function test_same_org_null_actor_org_returns_false(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);

        $this->assertFalse($this->guard->sameOrganization($user, $org->id));
    }

    public function test_same_org_null_target_org_returns_false(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->guard->sameOrganization($user, null));
    }

    public function test_same_org_super_admin_bypass_returns_true_even_cross_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $super = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $super->assignRole('super_admin');

        $this->assertTrue($this->guard->sameOrganization($super, $orgA->id));
        $this->assertTrue($this->guard->sameOrganization($super, $orgB->id));
    }

    public function test_same_org_for_kpi_uses_direct_column(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);
        $foreignKpi = Kpi::factory()->create(['organization_id' => $orgB->id]);

        $this->assertFalse($this->guard->sameOrganizationForKpi($user, $foreignKpi));
    }

    public function test_abort_unless_same_organization_throws_on_mismatch(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);

        $this->expectException(AccessDeniedHttpException::class);

        $this->guard->abortUnlessSameOrganization($user, $orgB->id);
    }

    public function test_abort_unless_same_organization_silently_passes_for_super_admin(): void
    {
        $org = Organization::factory()->create();
        $super = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $super->assignRole('super_admin');

        // No exception expected.
        $this->guard->abortUnlessSameOrganization($super, $org->id);
        $this->assertTrue(true);
    }
}
