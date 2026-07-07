<?php

namespace Tests\Unit\Policies;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\UserPolicy;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected UserPolicy $policy;

    protected Department $department;

    protected Department $otherDepartment;

    protected Organization $orgA;

    protected Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->policy = new UserPolicy;
        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->department = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->otherDepartment = Department::factory()->create(['organization_id' => $this->orgB->id]);
    }

    private function makeUser(string $role, ?Organization $org = null): User
    {
        $org = $org ?? $this->orgA;
        $department = $org->id === $this->orgA->id
            ? $this->department
            : ($org->id === $this->orgB->id ? $this->otherDepartment : null);

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $department?->id,
            'is_active' => true,
        ]);
        if ($role !== 'norole') {
            $user->assignRole($role);
        }

        return $user;
    }

    // ========== before (Super Admin bypass) ==========

    public function test_super_admin_can_do_anything(): void
    {
        $superAdmin = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->before($superAdmin, 'any_ability'));
    }

    public function test_non_super_admin_before_returns_null(): void
    {
        $admin = $this->makeUser('admin');

        $result = $this->policy->before($admin, 'viewAny');

        $this->assertNull($result);
    }

    // ========== viewAny ==========

    public function test_admin_can_view_any_users(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->viewAny($admin));
    }

    public function test_user_without_any_role_cannot_view_any(): void
    {
        $user = $this->makeUser('norole');

        $this->assertFalse($this->policy->viewAny($user));
    }

    // ========== view ==========

    public function test_user_can_view_themselves(): void
    {
        $user = $this->makeUser('member');

        $this->assertTrue($this->policy->view($user, $user));
    }

    public function test_admin_can_view_user_in_same_organization(): void
    {
        $admin = $this->makeUser('admin');
        $targetUser = $this->makeUser('member');

        $this->assertTrue($this->policy->view($admin, $targetUser));
    }

    public function test_admin_cannot_view_user_in_different_organization(): void
    {
        $admin = $this->makeUser('admin');
        $targetUser = $this->makeUser('member', $this->orgB);

        $this->assertFalse($this->policy->view($admin, $targetUser));
    }

    // ========== create ==========

    public function test_admin_can_create_users(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->create($admin));
    }

    public function test_team_member_cannot_create_users(): void
    {
        $user = $this->makeUser('member');

        $this->assertFalse($this->policy->create($user));
    }

    // ========== update ==========

    public function test_user_can_update_themselves(): void
    {
        $user = $this->makeUser('member');

        $this->assertTrue($this->policy->update($user, $user));
    }

    public function test_admin_can_update_user_in_same_organization(): void
    {
        $admin = $this->makeUser('admin');
        $targetUser = $this->makeUser('member');

        $this->assertTrue($this->policy->update($admin, $targetUser));
    }

    public function test_admin_cannot_update_user_in_different_organization(): void
    {
        $admin = $this->makeUser('admin');
        $targetUser = $this->makeUser('member', $this->orgB);

        $this->assertFalse($this->policy->update($admin, $targetUser));
    }

    public function test_non_super_admin_cannot_update_super_admin_in_different_org(): void
    {
        $admin = $this->makeUser('admin');
        $superAdmin = $this->makeUser('super_admin', $this->orgB);

        $this->assertFalse($this->policy->update($admin, $superAdmin));
    }

    // ========== delete ==========

    public function test_user_cannot_delete_themselves(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertFalse($this->policy->delete($admin, $admin));
    }

    public function test_user_without_any_role_cannot_delete(): void
    {
        $actor = $this->makeUser('norole');
        $targetUser = $this->makeUser('member');

        $this->assertFalse($this->policy->delete($actor, $targetUser));
    }

    public function test_cannot_delete_super_admin(): void
    {
        $admin = $this->makeUser('admin');
        $superAdmin = $this->makeUser('super_admin');

        $this->assertFalse($this->policy->delete($admin, $superAdmin));
    }

    public function test_without_permission_cannot_delete(): void
    {
        $user = $this->makeUser('member');
        $targetUser = $this->makeUser('member');

        $this->assertFalse($this->policy->delete($user, $targetUser));
    }

    // ========== restore ==========

    public function test_restore_follows_same_rules_as_delete(): void
    {
        $admin = $this->makeUser('admin');
        $targetUser = $this->makeUser('member');

        $this->assertEquals(
            $this->policy->delete($admin, $targetUser),
            $this->policy->restore($admin, $targetUser)
        );
    }

    // ========== forceDelete ==========

    public function test_nobody_can_force_delete_users(): void
    {
        $admin = $this->makeUser('admin');
        $targetUser = $this->makeUser('member');

        $this->assertFalse($this->policy->forceDelete($admin, $targetUser));
    }

    // ========== canAssignRole ==========

    public function test_super_admin_can_assign_any_role(): void
    {
        $superAdmin = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->canAssignRole($superAdmin, new User, ['super_admin', 'admin']));
    }

    public function test_admin_can_assign_admin_and_below(): void
    {
        $admin = $this->makeUser('admin');

        // admin is the organization-wide role and may grant any functional role
        // except super_admin.
        $this->assertTrue($this->policy->canAssignRole($admin, new User, ['admin', 'viewer']));
    }

    public function test_admin_cannot_assign_super_admin(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertFalse($this->policy->canAssignRole($admin, new User, ['super_admin']));
    }

    public function test_non_admin_cannot_assign_admin(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->canAssignRole($viewer, new User, ['admin']));
    }

    public function test_non_admin_cannot_assign_functional_roles(): void
    {
        // Functional-role assignment is reserved for super_admin and admin; a
        // non-admin (viewer) cannot grant any functional role.
        $viewer = $this->makeUser('viewer');

        $this->assertFalse($this->policy->canAssignRole($viewer, new User, ['viewer']));
        $this->assertFalse($this->policy->canAssignRole($viewer, new User, ['admin']));
        $this->assertFalse($this->policy->canAssignRole($viewer, new User, ['super_admin']));
    }

    public function test_can_assign_role_with_empty_roles(): void
    {
        $admin = $this->makeUser('admin');

        $this->assertTrue($this->policy->canAssignRole($admin, new User, []));
    }

    // ========== null-org deny ==========

    public function test_admin_cannot_view_user_with_null_org(): void
    {
        $admin = $this->makeUser('admin');
        $targetUser = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->view($admin, $targetUser));
    }

    public function test_admin_cannot_update_user_with_null_org(): void
    {
        $admin = $this->makeUser('admin');
        $targetUser = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->update($admin, $targetUser));
    }

    public function test_null_org_admin_cannot_view_org_user(): void
    {
        $admin = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $admin->assignRole('admin');
        $targetUser = $this->makeUser('member');

        $this->assertFalse($this->policy->view($admin, $targetUser));
    }
}
