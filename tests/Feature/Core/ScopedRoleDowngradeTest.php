<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Removing a manual scoped department role:
 *  - if the department's capacity policy STILL expects that role for this user
 *    (member role for a member, manager role for the manager), the row is
 *    DOWNGRADED to source='auto' instead of being deleted.
 *  - otherwise it is deleted.
 *
 * Review Round 2 point 2 — the easy-to-miss case, with both branches tested.
 */
class ScopedRoleDowngradeTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ScopedDepartmentRolesSeeder::class);

        $this->organization = Organization::factory()->create();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_removing_manual_role_still_expected_by_policy_downgrades_to_auto(): void
    {
        $admin = $this->superAdmin();
        $dept = Department::factory()->create(['organization_id' => $this->organization->id]);
        DepartmentCapacityRole::create([
            'department_id' => $dept->id, 'capacity' => 'member', 'role_key' => 'dept_member',
        ]);

        $user = User::factory()->create([
            'department_id' => $dept->id,
            'organization_id' => $this->organization->id,
        ]);
        // promote the auto row to manual (simulate an explicit grant on top)
        $user->scopedRoles()
            ->where('scope_id', $dept->id)
            ->where('role', 'dept_member')
            ->update(['source' => 'manual']);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/departments/{$dept->id}/roles/{$user->id}", [
                'role' => 'dept_member',
            ])->assertOk();

        // still present, but downgraded to auto (policy still expects it)
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $user->id, 'role' => 'dept_member',
            'scope_type' => 'department', 'scope_id' => $dept->id, 'source' => 'auto',
        ]);
    }

    public function test_removing_manual_role_not_expected_deletes_it(): void
    {
        $admin = $this->superAdmin();
        $dept = Department::factory()->create(['organization_id' => $this->organization->id]); // no policy → not expected
        $user = User::factory()->create(['organization_id' => $this->organization->id]);
        $user->scopedRoles()->create([
            'role' => 'dept_manager', 'scope_type' => 'department', 'scope_id' => $dept->id,
            'inherit_to_children' => true, 'source' => 'manual',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/departments/{$dept->id}/roles/{$user->id}", [
                'role' => 'dept_manager',
            ])->assertOk();

        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'user_id' => $user->id, 'scope_id' => $dept->id, 'role' => 'dept_manager',
        ]);
    }

    public function test_removing_nonexistent_role_returns_404(): void
    {
        $admin = $this->superAdmin();
        $dept = Department::factory()->create(['organization_id' => $this->organization->id]);
        $user = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/departments/{$dept->id}/roles/{$user->id}", [
                'role' => 'dept_member',
            ])->assertNotFound();
    }
}
