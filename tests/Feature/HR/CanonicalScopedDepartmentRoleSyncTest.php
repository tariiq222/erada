<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CanonicalScopedDepartmentRoleSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_and_manager_capacities_create_canonical_automatic_assignments(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
        ]);
        $department->update(['manager_id' => $user->id]);

        $memberRole = $this->role('dept_member');
        $managerRole = $this->role('dept_manager');
        DepartmentCapacityRole::create([
            'department_id' => $department->id,
            'capacity' => DepartmentCapacityRole::CAPACITY_MEMBER,
            'role_key' => $memberRole->name,
        ]);
        DepartmentCapacityRole::create([
            'department_id' => $department->id,
            'capacity' => DepartmentCapacityRole::CAPACITY_MANAGER,
            'role_key' => $managerRole->name,
        ]);

        app(ScopedDepartmentRoleSyncService::class)->syncUser($user->fresh());

        foreach ([$memberRole, $managerRole] as $role) {
            $this->assertDatabaseHas('authorization_role_assignments', [
                'authorization_role_id' => $role->id,
                'user_id' => $user->id,
                'scope_type' => 'department',
                'scope_id' => $department->id,
                'organization_id' => $organization->id,
                'source' => 'auto',
                'granted_by' => null,
            ]);
        }
        $this->assertSame(2, DB::table('authorization_assignment_audits')
            ->where('event', 'canonical_assignment_assigned')
            ->where('target_user_id', $user->id)
            ->where('scope_type', 'department')
            ->where('scope_id', $department->id)
            ->count());
    }

    public function test_resync_deletes_only_stale_automatic_assignments(): void
    {
        $organization = Organization::factory()->create();
        $oldDepartment = Department::factory()->create(['organization_id' => $organization->id]);
        $newDepartment = Department::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $newDepartment->id,
        ]);
        $role = $this->role('dept_member');
        $manualRole = $this->role('dept_manager');
        DepartmentCapacityRole::create([
            'department_id' => $newDepartment->id,
            'capacity' => DepartmentCapacityRole::CAPACITY_MEMBER,
            'role_key' => $role->name,
        ]);

        foreach ([[$role, 'auto'], [$manualRole, 'manual']] as [$assignedRole, $source]) {
            DB::table('authorization_role_assignments')->insert([
                'authorization_role_id' => $assignedRole->id,
                'user_id' => $user->id,
                'scope_type' => 'department',
                'scope_id' => $oldDepartment->id,
                'organization_id' => $organization->id,
                'inherit_to_children' => true,
                'expires_at' => null,
                'source' => $source,
                'granted_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(ScopedDepartmentRoleSyncService::class)->syncUser($user);

        $this->assertDatabaseMissing('authorization_role_assignments', [
            'user_id' => $user->id,
            'scope_id' => $oldDepartment->id,
            'source' => 'auto',
        ]);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'user_id' => $user->id,
            'scope_id' => $oldDepartment->id,
            'source' => 'manual',
        ]);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_id' => $newDepartment->id,
            'source' => 'auto',
        ]);
        $this->assertDatabaseHas('authorization_assignment_audits', [
            'event' => 'canonical_assignment_revoked',
            'target_user_id' => $user->id,
            'scope_type' => 'department',
            'scope_id' => $oldDepartment->id,
            'role' => $role->name,
        ]);
        $this->assertDatabaseHas('authorization_assignment_audits', [
            'event' => 'canonical_assignment_assigned',
            'target_user_id' => $user->id,
            'scope_type' => 'department',
            'scope_id' => $newDepartment->id,
            'role' => $role->name,
        ]);
    }

    private function role(string $name): AuthorizationRole
    {
        return AuthorizationRole::query()->updateOrCreate(['name' => $name], [
            'label' => $name,
            'scope_type' => 'department',
            'is_admin_role' => false,
            'is_system' => false,
            'is_active' => true,
        ]);
    }
}
