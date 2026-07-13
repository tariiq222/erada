<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected User $orgAAdmin;

    protected User $orgAPm;

    protected User $orgAMember;

    protected User $orgBAdmin;

    protected User $orgBMember;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);

        $this->orgAAdmin = $this->makeUser('admin', $this->orgA);
        $this->orgAPm = $this->makeUser('project_manager', $this->orgA);
        $this->orgAMember = $this->makeUser('member', $this->orgA);
        $this->orgBAdmin = $this->makeUser('admin', $this->orgB);
        $this->orgBMember = $this->makeUser('member', $this->orgB);
        $this->superAdmin = $this->makeUser('super_admin', $this->orgA);
    }

    private function makeUser(string $role, Organization $org): User
    {
        $dept = $org->id === $this->orgA->id ? $this->deptA : $this->deptB;

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($user, $role);

        return $user;
    }

    // ========== SC1: Cross-org user visibility ==========

    public function test_admin_from_org_a_cannot_view_user_from_org_b(): void
    {
        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->getJson("/api/users/{$this->orgBMember->id}");

        $response->assertStatus(403);
    }

    public function test_admin_from_org_a_cannot_update_user_from_org_b(): void
    {
        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->putJson("/api/users/{$this->orgBMember->id}", [
                'name' => 'Hacked Name',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_from_org_a_cannot_delete_user_from_org_b(): void
    {
        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->deleteJson("/api/users/{$this->orgBMember->id}");

        $response->assertStatus(403);
    }

    // ========== SC2: Role escalation prevention ==========

    public function test_admin_cannot_assign_super_admin_role(): void
    {
        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'new@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'roles' => ['super_admin'],
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_assign_admin_role(): void
    {
        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'newadmin@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'roles' => ['admin'],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'newadmin@example.com',
        ]);

        $user = User::where('email', 'newadmin@example.com')->first();
        $this->assertContains('admin', $user->canonicalRoleNames());
    }

    public function test_project_manager_cannot_assign_admin_role(): void
    {
        $response = $this->actingAs($this->orgAPm, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'newpm@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'roles' => ['admin'],
            ]);

        $response->assertStatus(403);
    }

    public function test_project_manager_cannot_create_user_without_permission(): void
    {
        $response = $this->actingAs($this->orgAPm, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'newpm2@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'roles' => ['project_manager'],
            ]);

        $response->assertStatus(403);
    }

    public function test_member_cannot_create_user_with_roles(): void
    {
        $response = $this->actingAs($this->orgAMember, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'newmember@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'roles' => ['member'],
            ]);

        $response->assertStatus(403);
    }

    // ========== SC3: Org-lock on user creation ==========

    public function test_created_user_inherits_creator_organization(): void
    {
        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'neworg@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'neworg@example.com',
            'organization_id' => $this->orgA->id,
        ]);
    }

    public function test_super_admin_can_assign_explicit_organization(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'superorg@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'organization_id' => $this->orgB->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'superorg@example.com',
            'organization_id' => $this->orgB->id,
        ]);
    }

    // ========== SC4: Null-org deny-not-bypass ==========

    public function test_null_org_admin_cannot_create_user(): void
    {
        $nullOrgAdmin = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($nullOrgAdmin, 'admin');

        $response = $this->actingAs($nullOrgAdmin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'nullorg@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertStatus(403);
    }

    public function test_null_org_admin_cannot_view_org_user(): void
    {
        $nullOrgAdmin = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($nullOrgAdmin, 'admin');

        $response = $this->actingAs($nullOrgAdmin, 'sanctum')
            ->getJson("/api/users/{$this->orgAMember->id}");

        $response->assertStatus(403);
    }

    // ========== SC5: super_admin bypass ==========

    public function test_super_admin_can_view_any_user(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/users/{$this->orgBMember->id}");

        $response->assertStatus(200);
    }

    public function test_super_admin_can_update_any_user(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/users/{$this->orgBMember->id}", [
                'name' => 'Updated by Super',
            ]);

        $response->assertStatus(200);
    }

    public function test_super_admin_can_assign_admin_role(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'New Super User',
                'email' => 'superuser@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'roles' => ['admin'],
            ]);

        $response->assertStatus(201);
        $user = User::where('email', 'superuser@example.com')->first();
        $this->assertContains('admin', $user->canonicalRoleNames());
    }

    // ========== SC6: canAccessDepartment enforcement ==========

    public function test_admin_cannot_assign_department_role_in_other_org(): void
    {
        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->postJson("/api/departments/{$this->deptB->id}/roles", [
                'user_id' => $this->orgBMember->id,
                'role' => 'department_manager',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_assign_department_role_in_same_org(): void
    {
        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->postJson("/api/departments/{$this->deptA->id}/roles", [
                'user_id' => $this->orgAMember->id,
                'role' => 'department_manager',
            ]);

        $response->assertStatus(201);
    }
}
