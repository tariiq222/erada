<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Shared\Support\ElementAbilities;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RiskAbilitiesTest — /api/risks/{id} response carries the engine-computed
 * abilities map (view, edit, delete, reassess, change_status).
 */
class RiskAbilitiesTest extends TestCase
{
    use RefreshDatabase;

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
        DepartmentCapacityRole::create([
            'department_id' => $dept->id,
            'capacity' => 'manager',
            'role_key' => 'dept_manager',
        ]);

        $mgr = User::factory()->create(['organization_id' => $org->id]);
        // Engine-only path (Wave 3 task 8): ScopedDepartmentRoleSyncService
        // assigns the dept_manager scoped role which grants Capability::RISKS_VIEW
        // (+ RISKS_CREATE / RISKS_EDIT via permissions array, and DELETE/REASSESS/
        // CHANGE_STATUS via the can_delete/can_view_all flag mapping on the
        // ScopedRoleDefinition). The legacy Spatie view_risks fallback has been
        // removed; the engine is the only authz source.
        $dept->update(['manager_id' => $mgr->id]);
        app(ScopedDepartmentRoleSyncService::class)->syncUser($mgr->fresh());

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
