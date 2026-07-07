<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class RiskDashboardEngineAuthzTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_dashboard_requires_engine_risks_view_reports(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        // no capability granted

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/risk-management/dashboard');
        $response->assertStatus(403);
    }

    public function test_dashboard_succeeds_with_engine_risks_view_reports(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->grantEngineCapability($user, Capability::RISKS_VIEW_REPORTS);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/risk-management/dashboard');
        $response->assertStatus(200);
    }

    public function test_dashboard_succeeds_for_super_admin(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $superRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user->assignRole($superRole);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/risk-management/dashboard');
        $response->assertStatus(200);
    }
}
