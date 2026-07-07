<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\Shared\Support\ElementAbilities;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * DepartmentAbilitiesTest — /api/departments/{department} response merges an
 * `abilities` key (view, edit, delete, manage_members, assign_roles) computed
 * via ElementAbilities into the raw payload returned by the controller.
 */
class DepartmentAbilitiesTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(ScopedDepartmentRolesSeeder::class);
    }

    public function test_department_show_response_carries_engine_abilities(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        DepartmentCapacityRole::create([
            'department_id' => $dept->id,
            'capacity' => 'manager',
            'role_key' => 'dept_manager',
        ]);

        $mgr = User::factory()->create(['organization_id' => $org->id]);
        // Route middleware now requires Capability::DEPARTMENTS_VIEW via engine_capability;
        // granting the engine capability satisfies the route gate.
        $this->grantEngineCapability($mgr, Capability::DEPARTMENTS_VIEW);
        $dept->update(['manager_id' => $mgr->id]);
        app(ScopedDepartmentRoleSyncService::class)->syncUser($mgr->fresh());

        $this->actingAs($mgr->fresh(), 'sanctum')
            ->getJson("/api/hr/departments/{$dept->id}")
            ->assertOk()
            ->assertJsonPath('abilities.view', true)
            ->assertJsonPath('abilities.edit', true)
            ->assertJsonPath('abilities.delete', true)
            ->assertJsonPath('abilities.manage_members', true)
            ->assertJsonPath('abilities.assign_roles', true);
    }

    public function test_department_outsider_gets_all_abilities_false_via_helper(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $outsider = User::factory()->create(['organization_id' => $org->id]);

        $abilities = ElementAbilities::resolve(
            $outsider,
            $dept,
            [
                'view' => Capability::DEPARTMENTS_VIEW,
                'edit' => Capability::DEPARTMENTS_EDIT,
                'delete' => Capability::DEPARTMENTS_DELETE,
                'manage_members' => Capability::DEPARTMENTS_MANAGE_MEMBERS,
                'assign_roles' => Capability::DEPARTMENTS_ASSIGN_ROLES,
            ]
        );

        foreach ($abilities as $key => $value) {
            $this->assertFalse($value, "Expected abilities.{$key} to be false for outsider");
        }
    }
}
