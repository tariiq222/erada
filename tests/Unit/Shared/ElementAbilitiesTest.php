<?php

namespace Tests\Unit\Shared;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Support\ElementAbilities;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ElementAbilities — per-record ability helper.
 *
 * Each action key is mapped to a Capability constant and resolved through
 * AccessDecision::can(user, capability, record). The frontend never re-derives
 * scope-chain logic; it reads abilities.{action} straight off the response.
 */
class ElementAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_maps_each_capability_through_engine(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);

        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $manager = User::factory()->create(['organization_id' => $org->id]);
        $dept->update(['manager_id' => $manager->id]);
        DepartmentCapacityRole::create([
            'department_id' => $dept->id,
            'capacity' => 'manager',
            'role_key' => 'dept_manager',
        ]);
        app(ScopedDepartmentRoleSyncService::class)->syncUser($manager->fresh());

        $abilities = ElementAbilities::resolve($manager->fresh(), $project, [
            'view' => Capability::PROJECTS_VIEW,
            'delete' => Capability::PROJECTS_DELETE,
        ]);

        $this->assertTrue($abilities['view']);
        $this->assertTrue($abilities['delete']);

        $outsider = User::factory()->create(['organization_id' => $org->id]);
        $out = ElementAbilities::resolve($outsider, $project, [
            'view' => Capability::PROJECTS_VIEW,
        ]);

        $this->assertFalse($out['view']);
    }

    public function test_resolve_returns_false_when_user_is_null(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $abilities = ElementAbilities::resolve(null, $project, [
            'view' => Capability::PROJECTS_VIEW,
            'edit' => Capability::PROJECTS_EDIT,
        ]);

        $this->assertFalse($abilities['view']);
        $this->assertFalse($abilities['edit']);
    }

    public function test_resolve_returns_one_boolean_per_map_entry_preserving_keys(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $user = User::factory()->create(['organization_id' => $org->id]);

        $abilities = ElementAbilities::resolve($user, $project, [
            'view' => Capability::PROJECTS_VIEW,
            'edit' => Capability::PROJECTS_EDIT,
            'delete' => Capability::PROJECTS_DELETE,
            'manage_members' => Capability::PROJECTS_MANAGE_MEMBERS,
            'change_status' => Capability::PROJECTS_CHANGE_STATUS,
            'close' => Capability::PROJECTS_CLOSE,
        ]);

        $this->assertSame(
            ['view', 'edit', 'delete', 'manage_members', 'change_status', 'close'],
            array_keys($abilities)
        );
        foreach ($abilities as $value) {
            $this->assertIsBool($value);
        }
    }
}
