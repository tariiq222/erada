<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for GET /api/users — proves the org-isolation floor at the HTTP
 * boundary now flows through UserOrganizationScope (Phase 3) instead of the
 * private UserController::applyUserVisibility helper.
 */
class UserIndexIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);
    }

    private function adminUser(Organization $org, Department $dept): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($user);

        return $user;
    }

    private function memberUser(Organization $org, Department $dept): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        // viewer is a functional role the engine recognizes for view tests.

        return $user;
    }

    public function test_org_a_admin_does_not_see_org_b_users_in_index(): void
    {
        $admin = $this->adminUser($this->orgA, $this->deptA);

        $this->memberUser($this->orgA, $this->deptA);
        $this->memberUser($this->orgB, $this->deptB);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/users')
            ->assertOk();

        $data = $response->json('data');
        $ids = collect($data)->pluck('id')->all();

        // admin sees self + org A member (2 users from org A only).
        $this->assertContains($admin->id, $ids);
        $this->assertCount(2, $data, 'admin must only see org A users');
    }

    public function test_super_admin_sees_all_users_in_index(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $this->adminUser($this->orgA, $this->deptA);
        $this->adminUser($this->orgB, $this->deptB);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/users')
            ->assertOk();

        $data = $response->json('data');

        // 3 users total: super_admin + 2 admins.
        $this->assertGreaterThanOrEqual(3, count($data));
    }

    public function test_search_does_not_leak_across_orgs(): void
    {
        $admin = $this->adminUser($this->orgA, $this->deptA);

        $orgAUser = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
            'name' => 'Ahmed SameName',
        ]);
        $this->grantCanonicalViewer($orgAUser);

        $orgBUser = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'is_active' => true,
            'name' => 'Ahmed SameName',
        ]);
        $this->grantCanonicalViewer($orgBUser);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/users?search=SameName')
            ->assertOk();

        $data = $response->json('data');
        $ids = collect($data)->pluck('id')->all();

        $this->assertContains($orgAUser->id, $ids);
        $this->assertNotContains($orgBUser->id, $ids, 'search must NOT leak cross-org users');
    }
}
