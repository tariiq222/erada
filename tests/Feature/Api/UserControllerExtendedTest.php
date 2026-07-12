<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserControllerExtendedTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected User $projectManager;

    protected User $member;

    protected Department $department;

    protected Department $otherDepartment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $organization = Organization::factory()->create();
        $this->department = Department::factory()->create(['organization_id' => $organization->id]);
        $this->otherDepartment = Department::factory()->create(['organization_id' => $organization->id]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);

        $this->admin = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->admin, 'admin');

        $this->projectManager = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->projectManager, 'project_manager');

        $this->member = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->member, 'member');
    }

    // ========== index ==========

    public function test_super_admin_sees_all_users_in_index(): void
    {
        // superAdmin يرى جميع المستخدمين بدون فلتر القسم
        $otherDeptUser = User::factory()->create([
            'department_id' => $this->otherDepartment->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/users');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($otherDeptUser->id, $ids);
    }

    public function test_admin_sees_all_organization_users_in_index(): void
    {
        // admin is organization-wide (CEO): sees users outside their own department.
        $otherDeptUser = User::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'department_id' => $this->otherDepartment->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/users');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($otherDeptUser->id, $ids);
    }

    public function test_member_only_sees_own_department(): void
    {
        // A non-admin (department-scoped) member is limited to their department subtree.
        $otherDeptUser = User::factory()->create([
            'organization_id' => $this->member->organization_id,
            'department_id' => $this->otherDepartment->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->member)
            ->getJson('/api/users');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($otherDeptUser->id, $ids);
    }

    public function test_department_manager_sees_child_department_users_in_picker(): void
    {
        $org = $this->admin->organization_id;
        $parent = Department::factory()->create(['organization_id' => $org]);
        $child = Department::factory()->create(['organization_id' => $org, 'parent_id' => $parent->id]);
        $sibling = Department::factory()->create(['organization_id' => $org]);

        $manager = User::factory()->create([
            'organization_id' => $org,
            'department_id' => $parent->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($manager, 'member');
        $this->assignCanonicalRole($manager, 'dept_manager', 'department', $parent->id);

        $childUser = User::factory()->create([
            'organization_id' => $org,
            'department_id' => $child->id,
            'is_active' => true,
        ]);
        $siblingUser = User::factory()->create([
            'organization_id' => $org,
            'department_id' => $sibling->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($manager)->getJson('/api/users/list');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->toArray();
        $this->assertContains($childUser->id, $ids);       // subtree (child) visible
        $this->assertNotContains($siblingUser->id, $ids);  // sibling department not visible
    }

    public function test_index_search_by_name(): void
    {
        $target = User::factory()->create([
            'name' => 'UniqueNameXYZ',
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/users?search=UniqueNameXYZ');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($target->id, $ids);
    }

    public function test_index_search_by_email(): void
    {
        $target = User::factory()->create([
            'email' => 'unique_email_xyz@example.com',
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/users?search=unique_email_xyz');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($target->id, $ids);
    }

    public function test_index_filter_by_is_active(): void
    {
        $inactive = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/users?is_active=false');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($inactive->id, $ids);
        // التأكد أن المستخدمين النشطين لا يظهرون
        $this->assertNotContains($this->admin->id, $ids);
    }

    public function test_index_filter_by_department_id(): void
    {
        $otherUser = User::factory()->create([
            'department_id' => $this->otherDepartment->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson("/api/users?department_id={$this->otherDepartment->id}");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($otherUser->id, $ids);
        $this->assertNotContains($this->admin->id, $ids);
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
    }

    // ========== list (dropdown) ==========

    public function test_list_returns_active_users_only(): void
    {
        $inactive = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/users/list');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->toArray();
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_list_super_admin_sees_all_departments(): void
    {
        $otherDeptUser = User::factory()->create([
            'department_id' => $this->otherDepartment->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/users/list');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->toArray();
        $this->assertContains($otherDeptUser->id, $ids);
    }

    public function test_list_non_super_admin_sees_own_department_only(): void
    {
        $otherDeptUser = User::factory()->create([
            'department_id' => $this->otherDepartment->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/users/list');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->toArray();
        $this->assertNotContains($otherDeptUser->id, $ids);
    }

    public function test_list_filter_by_single_department_id(): void
    {
        $deptUser = User::factory()->create([
            'department_id' => $this->otherDepartment->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson("/api/users/list?department_id={$this->otherDepartment->id}");

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->toArray();
        $this->assertContains($deptUser->id, $ids);
        $this->assertNotContains($this->admin->id, $ids);
    }

    public function test_list_filter_by_multiple_department_ids_as_string(): void
    {
        $dept3 = Department::factory()->create();
        $user3 = User::factory()->create([
            'department_id' => $dept3->id,
            'is_active' => true,
        ]);
        $otherUser = User::factory()->create([
            'department_id' => $this->otherDepartment->id,
            'is_active' => true,
        ]);

        $ids = "{$this->otherDepartment->id},{$dept3->id}";
        $response = $this->actingAs($this->superAdmin)
            ->getJson("/api/users/list?department_ids={$ids}");

        $response->assertOk();
        $resultIds = collect($response->json())->pluck('id')->toArray();
        $this->assertContains($user3->id, $resultIds);
        $this->assertContains($otherUser->id, $resultIds);
        $this->assertNotContains($this->admin->id, $resultIds);
    }

    public function test_list_filter_by_department_ids_as_array(): void
    {
        $deptUser = User::factory()->create([
            'department_id' => $this->otherDepartment->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson("/api/users/list?department_ids[]={$this->otherDepartment->id}");

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->toArray();
        $this->assertContains($deptUser->id, $ids);
    }

    public function test_list_requires_auth(): void
    {
        $this->getJson('/api/users/list')->assertUnauthorized();
    }
}
