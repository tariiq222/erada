<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationRoleAssignmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $targetUser;

    protected Department $department;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->admin = User::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->admin);

        $this->targetUser = User::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $this->project = Project::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
        ]);
        // المدير يُمثَّل كدور سياقي (scoped role) لا كعمود
        $this->assignCanonicalRole($this->admin, 'project_manager', 'project', $this->project->id);
    }

    // ========== Project Role Tests ==========

    public function test_can_list_project_members(): void
    {
        $this->assignCanonicalRole($this->targetUser, 'project_member', 'project', $this->project->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/roles");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'available_roles',
            ]);
    }

    public function test_can_assign_project_role(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/roles", [
                'user_id' => $this->targetUser->id,
                'role_id' => $this->roleId('project_member'),
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'user_id', 'role_id', 'role_name', 'project_id'],
            ]);

        $this->assertDatabaseHas('authorization_role_assignments', [
            'user_id' => $this->targetUser->id,
            'scope_type' => 'project',
            'scope_id' => $this->project->id,
            'authorization_role_id' => $this->roleId('project_member'),
        ]);
    }

    public function test_assign_project_role_with_expiration(): void
    {
        $expiresAt = now()->addDays(30)->startOfMinute();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/roles", [
                'user_id' => $this->targetUser->id,
                'role_id' => $this->roleId('project_viewer'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(201);

        $role = AuthorizationRoleAssignment::where('user_id', $this->targetUser->id)
            ->where('scope_type', 'project')
            ->where('scope_id', $this->project->id)
            ->first();

        $this->assertNotNull($role);
        $this->assertEquals($this->roleId('project_viewer'), $role->authorization_role_id);
        $this->assertNotNull($role->expires_at);
        $this->assertTrue($role->expires_at->isFuture());
        $this->assertTrue($role->expires_at->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_assign_project_role_validation_rejects_invalid_role(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/roles", [
                'user_id' => $this->targetUser->id,
                'role_id' => PHP_INT_MAX,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role_id']);
    }

    public function test_can_update_project_role(): void
    {
        $this->assignCanonicalRole($this->targetUser, 'project_member', 'project', $this->project->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/roles/{$this->targetUser->id}", [
                'role_id' => $this->roleId('project_manager'),
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('authorization_role_assignments', [
            'user_id' => $this->targetUser->id,
            'scope_type' => 'project',
            'scope_id' => $this->project->id,
            'authorization_role_id' => $this->roleId('project_manager'),
        ]);
    }

    public function test_can_remove_user_from_project(): void
    {
        $this->assignCanonicalRole($this->targetUser, 'project_member', 'project', $this->project->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/roles/{$this->targetUser->id}", [
                'role_id' => $this->roleId('project_member'),
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('authorization_role_assignments', [
            'user_id' => $this->targetUser->id,
            'scope_type' => 'project',
            'scope_id' => $this->project->id,
        ]);
    }

    public function test_unauthenticated_cannot_assign_roles(): void
    {
        $this->postJson("/api/projects/{$this->project->id}/roles", [
            'user_id' => $this->targetUser->id,
            'role_id' => $this->roleId('project_member'),
        ])->assertStatus(401);
    }

    // ========== Department Role Tests ==========

    public function test_can_list_department_managers(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/departments/{$this->department->id}/roles");

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'available_roles']);
    }

    public function test_can_assign_department_role(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/departments/{$this->department->id}/roles", [
                'user_id' => $this->targetUser->id,
                'role_id' => $this->roleId('dept_manager'),
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('authorization_role_assignments', [
            'user_id' => $this->targetUser->id,
            'scope_type' => 'department',
            'scope_id' => $this->department->id,
            'authorization_role_id' => $this->roleId('dept_manager'),
        ]);
    }

    public function test_can_remove_user_from_department(): void
    {
        $this->assignCanonicalRole($this->targetUser, 'dept_manager', 'department', $this->department->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/departments/{$this->department->id}/roles/{$this->targetUser->id}", [
                'role_id' => $this->roleId('dept_manager'),
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('authorization_role_assignments', [
            'user_id' => $this->targetUser->id,
            'scope_type' => 'department',
            'scope_id' => $this->department->id,
        ]);
    }

    private function roleId(string $name): int
    {
        return (int) AuthorizationRole::query()->where('name', $name)->valueOrFail('id');
    }

    // ========== User authorization-role assignments ==========

    public function test_can_get_user_authorization_role_assignments(): void
    {
        $this->assignCanonicalRole($this->targetUser, 'project_member', 'project', $this->project->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$this->targetUser->id}");

        $response->assertStatus(200);

        // Canonical response is a flat assignment list with explicit scope.
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals('project_member', $data[0]['role']);
        $this->assertEquals('project', $data[0]['scope_type']);
        $this->assertEquals($this->project->id, $data[0]['scope_id']);
    }

    // ========== Audit Logs ==========

    public function test_can_get_audit_logs(): void
    {
        // Assign a role via API to generate an audit log entry
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/roles", [
                'user_id' => $this->targetUser->id,
                'role_id' => $this->roleId('project_member'),
            ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/authorization-role-assignments/audit-logs');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'total']]);
    }
}
