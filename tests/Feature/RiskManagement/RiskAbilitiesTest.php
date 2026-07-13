<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Shared\Support\ElementAbilities;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * RiskAbilitiesTest — /api/risks/{id} response carries the engine-computed
 * abilities map (view, edit, delete, reassess, change_status).
 */
class RiskAbilitiesTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(ScopedDepartmentRolesSeeder::class);
    }

    public function test_risk_response_carries_engine_abilities(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $mgr = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id]);
        $this->grantEngineCapability($mgr, [
            Capability::RISKS_VIEW,
            Capability::RISKS_EDIT,
            Capability::RISKS_DELETE,
            Capability::RISKS_REASSESS,
            Capability::RISKS_CHANGE_STATUS,
        ], 'department', $dept->id, 'risk_test_manager');

        $risk = Risk::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $this->actingAs($mgr->fresh(), 'sanctum')
            ->getJson("/api/risk-management/risks/{$risk->id}")
            ->assertOk()
            ->assertJsonPath('data.abilities.view', true)
            ->assertJsonPath('data.abilities.edit', true)
            ->assertJsonPath('data.abilities.delete', true)
            ->assertJsonPath('data.abilities.reassess', true)
            ->assertJsonPath('data.abilities.change_status', true);
    }

    public function test_risk_outsider_gets_all_abilities_false_via_helper(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $risk = Risk::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $outsider = User::factory()->create(['organization_id' => $org->id]);

        $abilities = ElementAbilities::resolve(
            $outsider,
            $risk,
            [
                'view' => Capability::RISKS_VIEW,
                'edit' => Capability::RISKS_EDIT,
                'delete' => Capability::RISKS_DELETE,
                'reassess' => Capability::RISKS_REASSESS,
                'change_status' => Capability::RISKS_CHANGE_STATUS,
            ]
        );

        foreach ($abilities as $key => $value) {
            $this->assertFalse($value, "Expected abilities.{$key} to be false for outsider");
        }
    }
}
