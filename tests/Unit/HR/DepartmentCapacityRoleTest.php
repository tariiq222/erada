<?php

namespace Tests\Unit\HR;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers canonical assignment provenance, the DepartmentCapacityRole policy
 * model, and canonical department/cross-cutting role seeding.
 */
class DepartmentCapacityRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_scopes_filter_auto_and_manual(): void
    {
        $user = User::factory()->create();

        $autoRole = AuthorizationRole::query()->create(['name' => 'capacity_auto', 'label' => 'Auto']);
        $manualRole = AuthorizationRole::query()->create(['name' => 'capacity_manual', 'label' => 'Manual']);
        AuthorizationRoleAssignment::create([
            'user_id' => $user->id, 'authorization_role_id' => $autoRole->id,
            'scope_type' => 'department', 'scope_id' => 1,
            'inherit_to_children' => true, 'source' => 'auto',
        ]);
        AuthorizationRoleAssignment::create([
            'user_id' => $user->id, 'authorization_role_id' => $manualRole->id,
            'scope_type' => 'department', 'scope_id' => 2,
            'inherit_to_children' => true, 'source' => 'manual',
        ]);

        $this->assertSame(1, AuthorizationRoleAssignment::query()->where('source', 'auto')->where('user_id', $user->id)->count());
        $this->assertSame(1, AuthorizationRoleAssignment::query()->where('source', 'manual')->where('user_id', $user->id)->count());
    }

    public function test_capacity_role_belongs_to_department_and_persists(): void
    {
        $dept = Department::factory()->create();

        $policy = DepartmentCapacityRole::create([
            'department_id' => $dept->id,
            'capacity' => DepartmentCapacityRole::CAPACITY_MANAGER,
            'role_key' => 'dept_manager',
        ]);

        $this->assertSame($dept->id, $policy->department->id);
        $this->assertDatabaseHas('department_capacity_roles', [
            'department_id' => $dept->id,
            'capacity' => 'manager',
            'role_key' => 'dept_manager',
        ]);
    }

    public function test_canonical_department_roles_are_seeded(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);

        $this->assertDatabaseHas('authorization_roles', [
            'scope_type' => 'department', 'name' => 'dept_manager', 'is_active' => true,
        ]);
        $this->assertDatabaseHas('authorization_roles', [
            'scope_type' => 'department', 'name' => 'dept_member', 'is_active' => true,
        ]);
    }
}
