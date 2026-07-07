<?php

namespace Tests\Feature\Performance;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * KpiEngineAuthzTest — engine-based gates for KpiController, KpiMeasurementController,
 * and KpiLinkController helpers (each controller has its own authorizePerformance()
 * method that maps an ability to a Capability constant).
 *
 * Task 5 of Wave 3 controllers sweep. Asserts the three helpers grant access only
 * through the unified AuthZ engine (Capability::KPIS_VIEW / Capability::KPIS_MANAGE),
 * NOT through legacy Spatie permission strings (view_kpis / manage_kpis).
 *
 * RED today (pre-cutover): the helpers still call $user->hasPermissionTo(),
 * so the granted-capability cases return 403 (FAILS) because the engine is not
 * asked and the user has no flat role at all. After migration to AccessDecision::can()
 * the same cases pass because the engine sees the granted capability.
 *
 * URL notes (verified against app/Modules/Performance/Routes/api.php):
 *   - Performance routes are mounted under the /api/performance prefix by the
 *     module's service provider, NOT /api/kpis as in the brief.
 *   - KPI resource routes use Route::apiResource('kpis', KpiController::class),
 *     so GET /api/performance/kpis and POST /api/performance/kpis are valid.
 */
class KpiEngineAuthzTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private function makeUserWith(string $capability): User
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->grantEngineCapability($user, $capability);

        return $user;
    }

    public function test_view_kpis_requires_engine_capability(): void
    {
        $user = $this->makeUserWith(Capability::KPIS_VIEW);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/performance/kpis')
            ->assertStatus(200);
    }

    public function test_create_kpi_requires_manage_capability(): void
    {
        $user = $this->makeUserWith(Capability::KPIS_MANAGE);

        // KpiController::store() does inline $request->validate() with mostly
        // nullable rules, so a payload with 'name' actually validates and
        // succeeds once the engine gate passes. 201 proves the engine let it
        // through; pre-cutover (legacy Spatie hasPermissionTo) the same user
        // would have been 403 because no flat role existed.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/performance/kpis', ['name' => 'x'])
            ->assertStatus(201);
    }

    public function test_missing_capability_denies(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/performance/kpis')
            ->assertStatus(403);
    }
}
