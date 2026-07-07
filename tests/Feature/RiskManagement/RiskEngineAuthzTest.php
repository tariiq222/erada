<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Models\Risk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * RiskEngineAuthzTest — engine-based gates for RiskController / RiskActionController /
 * RiskAssessmentController helpers (authorizeRisk / authorizeAction).
 *
 * Task 3 of Wave 3 controllers sweep. Asserts that the three controller helpers
 * only grant access through the unified AuthZ engine (Capability::RISKS_*), NOT
 * through legacy Spatie permission strings (create_risks / edit_risks / etc).
 *
 * RED today (pre-cutover): the helpers still call $user->hasPermissionTo(),
 * so the granted-capability cases return 200/201 (FAILS) and the missing-capability
 * case already returns 403 (PASSES) — but only because the user has no role at all.
 * After migration to AccessDecision::can() the same cases pass because the engine
 * sees the granted capability and the Spatie string no longer bridges.
 *
 * URL notes (verified against app/Modules/RiskManagement/Routes/api.php):
 *   - All risk-management routes are mounted under the risk-management prefix
 *     by the module's service provider. Final URLs therefore start with
 *     /api/risk-management/..., NOT /api/risks/... as in the brief.
 *   - Status change payload key is to_status (ChangeRiskStatusRequest rules),
 *     not status (the brief was a placeholder).
 *   - Reassess endpoint is POST /risks/{risk}/assessments (RiskAssessmentController
 *     store), not a dedicated /reassess route.
 */
class RiskEngineAuthzTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private function makeRiskAndUser(string $capability): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->grantEngineCapability($user, $capability);
        // Risk::factory()->create() spawns its own Department (with its own
        // organization_id). If the chain's department is in a different org
        // from the user, the engine walks up to a foreign org and the user's
        // org-scoped ScopedRole never matches the chain — matchViaRoles returns
        // null. Pin the department to the user's org so the chain resolves
        // cleanly to the same organization the ScopedRole was granted on.
        $department = Department::factory()->create(['organization_id' => $org->id]);
        $risk = Risk::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $department->id,
        ]);

        return [$user, $risk, $org];
    }

    public function test_view_risk_requires_engine_capability(): void
    {
        [$user, $risk] = $this->makeRiskAndUser(Capability::RISKS_VIEW);
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/risk-management/risks/{$risk->id}")
            ->assertStatus(200);
    }

    public function test_create_risk_requires_engine_capability(): void
    {
        [$user, $risk, $org] = $this->makeRiskAndUser(Capability::RISKS_CREATE);
        // Payload intentionally lacks the full StoreRiskRequest required fields
        // (discovery_date / initial_likelihood / initial_impact) — that is the
        // point: we want the engine gate to PASS and the FormRequest validation
        // to FAIL with 422, proving authz is no longer the 403.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/risk-management/risks', ['title' => 'x'])
            ->assertStatus(422);
    }

    public function test_update_risk_requires_engine_capability(): void
    {
        [$user, $risk] = $this->makeRiskAndUser(Capability::RISKS_EDIT);
        // 'type' has Rule::in([RiskType::cases()]) validation, so an invalid
        // value triggers 422 once the engine gate passes. A bare 'title' PUT
        // is a successful update (200), which would not prove authz ran.
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/risk-management/risks/{$risk->id}", ['type' => 'bogus'])
            ->assertStatus(422);
    }

    public function test_delete_risk_requires_engine_capability(): void
    {
        [$user, $risk] = $this->makeRiskAndUser(Capability::RISKS_DELETE);
        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/risk-management/risks/{$risk->id}")
            ->assertStatus(200);
    }

    public function test_reassess_risk_requires_engine_capability(): void
    {
        [$user, $risk] = $this->makeRiskAndUser(Capability::RISKS_REASSESS);
        // Reassess route is POST /api/risk-management/risks/{risk}/assessments.
        // Missing likelihood/impact => 422 (validation) once engine grants.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/risk-management/risks/{$risk->id}/assessments", [])
            ->assertStatus(422);
    }

    public function test_change_status_requires_engine_capability(): void
    {
        [$user, $risk] = $this->makeRiskAndUser(Capability::RISKS_CHANGE_STATUS);
        // ChangeRiskStatusRequest uses to_status (RiskStatus enum), not status.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/risk-management/risks/{$risk->id}/status-changes", ['to_status' => 'open'])
            ->assertStatus(422);
    }

    public function test_missing_capability_denies(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $department = Department::factory()->create(['organization_id' => $org->id]);
        $risk = Risk::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $department->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/risk-management/risks/{$risk->id}")
            ->assertStatus(403);
    }
}
