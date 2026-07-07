<?php

namespace Tests\Unit\HR;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the source column scopes on ScopedRole, the DepartmentCapacityRole
 * policy model, and the department/cross-cutting role-definition seeding.
 */
class DepartmentCapacityRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_scopes_filter_auto_and_manual(): void
    {
        $user = User::factory()->create();

        ScopedRole::create([
            'user_id' => $user->id, 'role' => 'dept_member',
            'scope_type' => 'department', 'scope_id' => 1,
            'inherit_to_children' => true, 'source' => 'auto',
        ]);
        ScopedRole::create([
            'user_id' => $user->id, 'role' => 'dept_manager',
            'scope_type' => 'department', 'scope_id' => 2,
            'inherit_to_children' => true, 'source' => 'manual',
        ]);

        $this->assertSame(1, ScopedRole::auto()->where('user_id', $user->id)->count());
        $this->assertSame(1, ScopedRole::manual()->where('user_id', $user->id)->count());
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

    public function test_department_role_definitions_are_seeded(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);

        $deptScopeId = DB::table('scope_types')->where('key', 'department')->value('id');

        $this->assertDatabaseHas('scoped_role_definitions', [
            'scope_type_id' => $deptScopeId, 'role_key' => 'dept_manager',
        ]);
        $this->assertDatabaseHas('scoped_role_definitions', [
            'scope_type_id' => $deptScopeId, 'role_key' => 'dept_member',
        ]);
    }
}
