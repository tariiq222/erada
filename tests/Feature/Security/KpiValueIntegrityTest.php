<?php

namespace Tests\Feature\Security;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * M-10/M-11: KPI current_value is measurement-derived (not client-settable),
 * and KPI/measurement mutations are audit-logged.
 */
class KpiValueIntegrityTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private function adminFor(Organization $org): User
    {
        $dept = Department::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $admin = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id, 'is_active' => true]);
        $this->grantCanonicalAdmin($admin);

        return $admin;
    }

    public function test_update_cannot_set_current_value_directly(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->adminFor($org);
        $kpi = Kpi::factory()->create(['organization_id' => $org->id, 'current_value' => 10, 'baseline' => 10]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/performance/kpis/{$kpi->id}", ['current_value' => 999, 'name' => 'Renamed'])
            ->assertOk();

        $this->assertEqualsWithDelta(10.0, (float) $kpi->fresh()->current_value, 0.001, 'current_value must not be client-settable');
    }

    public function test_measurement_updates_value_and_writes_audit(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->adminFor($org);
        $kpi = Kpi::factory()->create(['organization_id' => $org->id, 'current_value' => 10, 'baseline' => 10]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/performance/kpis/{$kpi->id}/measurements", [
                'value' => 42,
                'measurement_date' => now()->toDateString(),
            ])->assertSuccessful();

        $this->assertEqualsWithDelta(42.0, (float) $kpi->fresh()->current_value, 0.001, 'measurement must drive current_value');
        $this->assertTrue(
            ActivityLog::where('loggable_type', Kpi::class)->where('loggable_id', $kpi->id)->exists(),
            'KPI value change must be audit-logged'
        );
    }
}
