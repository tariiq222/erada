<?php

namespace Tests\Feature\Performance;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Projects\Models\Project;
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

    private function makeUserWith(string|array $capability, ?Organization $organization = null): User
    {
        $organization ??= Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $this->grantEngineCapability($user, $capability, 'organization', $organization->id);

        return $user;
    }

    private function makeKpi(Organization $organization, User $user): Kpi
    {
        return Kpi::factory()->create([
            'organization_id' => $organization->id,
            'owner_id' => $user->id,
            'created_by' => $user->id,
        ]);
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

    public function test_measurement_creation_uses_parent_kpi_organization(): void
    {
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_EDIT, $organization);
        $kpi = $this->makeKpi($organization, $user);

        $measurementId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/performance/kpis/{$kpi->id}/measurements", [
                'value' => 45,
                'measurement_date' => now()->toDateString(),
                'organization_id' => $foreignOrganization->id,
            ])
            ->assertCreated()
            ->json('measurement.id');

        $this->assertDatabaseHas('kpi_measurements', [
            'id' => $measurementId,
            'kpi_id' => $kpi->id,
            'organization_id' => $organization->id,
            'recorded_by' => $user->id,
        ]);
    }

    public function test_link_creation_uses_parent_kpi_organization(): void
    {
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $user = $this->makeUserWith(Capability::KPIS_EDIT, $organization);
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
        ]);
        $kpi = $this->makeKpi($organization, $user);

        $linkId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/performance/kpis/{$kpi->id}/links", [
                'linkable_type' => 'project',
                'linkable_id' => $project->id,
                'organization_id' => $foreignOrganization->id,
            ])
            ->assertCreated()
            ->json('link.id');

        $this->assertDatabaseHas('kpi_links', [
            'id' => $linkId,
            'kpi_id' => $kpi->id,
            'organization_id' => $organization->id,
            'linkable_id' => $project->id,
        ]);
    }

    public function test_link_from_sibling_kpi_cannot_be_destroyed_and_persists(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->makeUserWith([Capability::KPIS_MANAGE, Capability::KPIS_DELETE], $organization);
        $kpiOne = $this->makeKpi($organization, $user);
        $kpiTwo = $this->makeKpi($organization, $user);
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
        ]);
        $link = new KpiLink([
            'linkable_type' => Project::class,
            'linkable_id' => $project->id,
            'relationship_type' => 'related',
            'created_by' => $user->id,
        ]);
        $link->forceFill([
            'kpi_id' => $kpiTwo->id,
            'organization_id' => $organization->id,
        ])->save();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/performance/kpis/{$kpiOne->id}/links/{$link->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('kpi_links', [
            'id' => $link->id,
            'kpi_id' => $kpiTwo->id,
            'organization_id' => $organization->id,
            'deleted_at' => null,
        ]);
    }
}
