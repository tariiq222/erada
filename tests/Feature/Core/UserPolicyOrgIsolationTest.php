<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\UserPolicy;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Org-isolation coverage for UserPolicy at the policy layer. Mirrors the
 * existing tests/Unit/Policies/UserPolicyTest but adds the explicit
 * null-org-target / null-org-actor scenarios Phase 3 emphasized.
 *
 * The policy is the seam that ViewUserRequest / UpdateUserRequest / DeleteUserRequest
 * hit before allowing a User-scoped mutation.
 */
class UserPolicyOrgIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected UserPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->policy = new UserPolicy;

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);
    }

    private function makeUser(string $role, Organization $org, Department $dept): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        if ($role !== 'norole') {
            $this->assignCanonicalRole($user, $role);
        }

        return $user;
    }

    public function test_same_org_view_allowed(): void
    {
        $admin = $this->makeUser('admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgA, $this->deptA);

        $this->assertTrue($this->policy->view($admin, $target));
    }

    public function test_cross_org_view_denied(): void
    {
        $admin = $this->makeUser('admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgB, $this->deptB);

        $this->assertFalse($this->policy->view($admin, $target));
    }

    public function test_cross_org_update_denied(): void
    {
        $admin = $this->makeUser('admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgB, $this->deptB);

        $this->assertFalse($this->policy->update($admin, $target));
    }

    public function test_cross_org_delete_denied(): void
    {
        $admin = $this->makeUser('admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgB, $this->deptB);

        $this->assertFalse($this->policy->delete($admin, $target));
    }

    public function test_null_org_target_denied_for_view(): void
    {
        $admin = $this->makeUser('admin', $this->orgA, $this->deptA);
        $nullOrgTarget = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->view($admin, $nullOrgTarget));
    }

    public function test_null_org_target_denied_for_update(): void
    {
        $admin = $this->makeUser('admin', $this->orgA, $this->deptA);
        $nullOrgTarget = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);

        $this->assertFalse($this->policy->update($admin, $nullOrgTarget));
    }

    public function test_null_org_actor_cannot_view_org_user(): void
    {
        $nullOrgAdmin = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);

        $target = $this->makeUser('viewer', $this->orgA, $this->deptA);

        $this->assertFalse($this->policy->view($nullOrgAdmin, $target));
    }

    public function test_super_admin_can_view_across_orgs(): void
    {
        // $user->can() goes through Laravel's AuthorizesRequests pipeline which
        // invokes UserPolicy::before() — the super_admin bypass short-circuit.
        // Calling $this->policy->view() directly would bypass before().
        $superAdmin = $this->makeUser('super_admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgB, $this->deptB);

        $this->assertTrue($superAdmin->can('view', $target));
    }

    public function test_super_admin_can_update_across_orgs(): void
    {
        $superAdmin = $this->makeUser('super_admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgB, $this->deptB);

        $this->assertTrue($superAdmin->can('update', $target));
    }

    public function test_super_admin_can_delete_across_orgs(): void
    {
        $superAdmin = $this->makeUser('super_admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgB, $this->deptB);

        $this->assertTrue($superAdmin->can('delete', $target));
    }

    public function test_self_view_always_allowed(): void
    {
        $user = $this->makeUser('viewer', $this->orgA, $this->deptA);

        $this->assertTrue($this->policy->view($user, $user));
    }

    public function test_self_delete_blocked(): void
    {
        $user = $this->makeUser('admin', $this->orgA, $this->deptA);

        $this->assertFalse($this->policy->delete($user, $user));
    }

    public function test_non_super_admin_cannot_target_super_admin(): void
    {
        $admin = $this->makeUser('admin', $this->orgA, $this->deptA);
        $superAdmin = $this->makeUser('super_admin', $this->orgB, $this->deptB);

        $this->assertFalse($this->policy->update($admin, $superAdmin));
        $this->assertFalse($this->policy->delete($admin, $superAdmin));
    }
}
