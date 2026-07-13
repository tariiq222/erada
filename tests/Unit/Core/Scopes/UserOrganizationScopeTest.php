<?php

namespace Tests\Unit\Core\Scopes;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Core\Scopes\UserOrganizationScope;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for UserOrganizationScope — the unified horizontal-org filter that
 * UserController::index / stats / list now consume instead of the private
 * applyUserVisibility helper.
 *
 * Semantics are byte-for-byte identical to the previous behavior:
 *   - super_admin sees all users
 *   - null-org actor sees nothing (fail-closed)
 *   - admin sees full org
 *   - non-admin sees dept subtree + own dept
 *   - non-admin without any dept/managed-dept sees nobody
 */
class UserOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    private UserOrganizationScope $scope;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA1;

    protected Department $deptA2;

    protected Department $deptB1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->scope = new UserOrganizationScope;

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->deptA1 = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptA2 = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB1 = Department::factory()->create(['organization_id' => $this->orgB->id]);
    }

    private function makeUser(string $role, Organization $org, ?Department $dept = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept?->id,
            'is_active' => true,
        ]);

        if ($role !== 'norole') {
            if ($role === 'super_admin') {
                $assignment = $this->grantCanonicalSuperAdmin($user);
                $assignment->role->update(['is_system' => true]);
            } else {
                $this->assignCanonicalRole($user, $role);
            }
        }

        return $user;
    }

    public function test_super_admin_sees_all_users_across_orgs(): void
    {
        $superAdmin = $this->makeUser('super_admin', $this->orgA, $this->deptA1);

        $userA = $this->makeUser('viewer', $this->orgA, $this->deptA2);
        $userB = $this->makeUser('viewer', $this->orgB, $this->deptB1);

        $query = User::query();
        $filtered = $this->scope->applyToUsers($query, $superAdmin);

        $ids = $filtered->pluck('id')->sort()->values()->all();

        $this->assertContains($userA->id, $ids);
        $this->assertContains($userB->id, $ids);
        $this->assertContains($superAdmin->id, $ids);
    }

    public function test_null_org_user_sees_nothing(): void
    {
        $nullOrgUser = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->deptA1->id,
            'is_active' => true,
        ]);
        // No role assigned — engine will resolve as "no capability".

        $userA = $this->makeUser('viewer', $this->orgA, $this->deptA1);

        $query = User::query();
        $filtered = $this->scope->applyToUsers($query, $nullOrgUser);

        $this->assertCount(0, $filtered->get());
        $this->assertNotContains($userA->id, $filtered->pluck('id')->all());
    }

    public function test_admin_sees_full_org_without_dept_narrowing(): void
    {
        $admin = $this->makeUser('admin', $this->orgA, $this->deptA1);

        $userA1 = $this->makeUser('viewer', $this->orgA, $this->deptA1);
        $userA2 = $this->makeUser('viewer', $this->orgA, $this->deptA2);
        $userB = $this->makeUser('viewer', $this->orgB, $this->deptB1);

        $query = User::query();
        $filtered = $this->scope->applyToUsers($query, $admin);

        $ids = $filtered->pluck('id')->all();

        $this->assertContains($admin->id, $ids);
        $this->assertContains($userA1->id, $ids);
        $this->assertContains($userA2->id, $ids);
        $this->assertNotContains($userB->id, $ids, 'admin must NOT see org B users');
    }

    public function test_non_admin_sees_only_their_department(): void
    {
        $viewer = $this->makeUser('viewer', $this->orgA, $this->deptA1);

        $userA1 = $this->makeUser('viewer', $this->orgA, $this->deptA1);
        $userA2 = $this->makeUser('viewer', $this->orgA, $this->deptA2);
        $userB = $this->makeUser('viewer', $this->orgB, $this->deptB1);

        $query = User::query();
        $filtered = $this->scope->applyToUsers($query, $viewer);

        $ids = $filtered->pluck('id')->all();

        $this->assertContains($userA1->id, $ids);
        $this->assertNotContains($userA2->id, $ids, 'non-admin must not see other dept users');
        $this->assertNotContains($userB->id, $ids, 'non-admin must not see org B users');
    }

    public function test_non_admin_without_department_sees_nobody(): void
    {
        $noDeptViewer = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => null,
            'is_active' => true,
        ]);

        $userA1 = $this->makeUser('viewer', $this->orgA, $this->deptA1);

        $query = User::query();
        $filtered = $this->scope->applyToUsers($query, $noDeptViewer);

        $this->assertCount(0, $filtered->get());
        $this->assertNotContains($userA1->id, $filtered->pluck('id')->all());
    }

    public function test_null_org_user_fail_closed_even_with_role(): void
    {
        // Even a viewer-role user without org_id sees nothing.
        $nullOrgViewer = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->deptA1->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalViewer($nullOrgViewer);

        $query = User::query();
        $filtered = $this->scope->applyToUsers($query, $nullOrgViewer);

        $this->assertCount(0, $filtered->get());
    }

    public function test_managed_departments_included_in_subtree(): void
    {
        // Managed departments resolve from canonical department-scoped assignments,
        // not Department::manager_id — the same seam used by UserController.
        $manager = $this->makeUser('viewer', $this->orgA, $this->deptA1);
        $this->assignCanonicalRole($manager, 'dept_manager', 'department', (int) $this->deptA2->id);

        $userInA1 = $this->makeUser('viewer', $this->orgA, $this->deptA1);
        $userInA2 = $this->makeUser('viewer', $this->orgA, $this->deptA2);

        $query = User::query();
        $filtered = $this->scope->applyToUsers($query, $manager);

        $ids = $filtered->pluck('id')->all();

        $this->assertContains($userInA1->id, $ids, 'own dept visible');
        $this->assertContains($userInA2->id, $ids, 'managed dept visible');
    }

    public function test_org_b_user_does_not_see_org_a(): void
    {
        $userB = $this->makeUser('viewer', $this->orgB, $this->deptB1);

        $userA1 = $this->makeUser('viewer', $this->orgA, $this->deptA1);
        $userA2 = $this->makeUser('admin', $this->orgA, $this->deptA1);

        $query = User::query();
        $filtered = $this->scope->applyToUsers($query, $userB);

        $ids = $filtered->pluck('id')->all();

        $this->assertNotContains($userA1->id, $ids);
        $this->assertNotContains($userA2->id, $ids);
    }
}
