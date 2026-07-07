<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * RiskSettingsEngineAuthzTest — engine-based gates for RiskSettingsController
 * helpers (authorizeSettings + authorizeGovernance).
 *
 * Task 4 of Wave 3 controllers sweep. Asserts that the two controller helpers
 * only grant access through the unified AuthZ engine:
 *   - authorizeSettings  -> Capability::RISKS_EDIT
 *   - authorizeGovernance -> Capability::SETTINGS_MANAGE
 *
 * URL notes (verified against app/Modules/RiskManagement/Routes/api.php):
 *   - All risk-management routes are mounted under the risk-management prefix
 *     by the module's service provider. Final URLs therefore start with
 *     /api/risk-management/..., NOT /api/risks/... as in the brief.
 *   - Settings index   -> GET  /api/risk-management/settings
 *   - Governance read  -> GET  /api/risk-management/settings/governing-department
 */
class RiskSettingsEngineAuthzTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_settings_requires_risks_edit(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->grantEngineCapability($user, Capability::RISKS_EDIT);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/risk-management/settings')
            ->assertStatus(200);
    }

    public function test_governance_requires_settings_manage(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->grantEngineCapability($user, Capability::SETTINGS_MANAGE);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/risk-management/settings/governing-department')
            ->assertStatus(200);
    }

    public function test_missing_capability_denies(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/risk-management/settings')
            ->assertStatus(403);
    }
}
