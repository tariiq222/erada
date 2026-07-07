<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScopedRoleControllerTest extends TestCase
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
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->admin->assignRole('super_admin');

        $this->targetUser = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $this->project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);
        // المدير يُمثَّل كدور سياقي (scoped role) لا كعمود
        $this->admin->assignProjectRole($this->project, ScopedRole::PROJECT_MANAGER, $this->admin->id);
    }

    // ========== Project Role Tests ==========

    public function test_can_list_project_members(): void
    {
        $this->targetUser->assignProjectRole($this->project, ScopedRole::PROJECT_MEMBER, $this->admin->id);

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
                'role' => ScopedRole::PROJECT_MEMBER,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'user_id', 'role', 'project_id'],
            ]);

        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $this->targetUser->id,
            'scope_type' => ScopedRole::SCOPE_PROJECT,
            'scope_id' => $this->project->id,
            'role' => ScopedRole::PROJECT_MEMBER,
        ]);
    }

    public function test_assign_project_role_with_expiration(): void
    {
        $expiresAt = now()->addDays(30)->startOfMinute();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/roles", [
                'user_id' => $this->targetUser->id,
                'role' => ScopedRole::PROJECT_VIEWER,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(201);

        $role = ScopedRole::where('user_id', $this->targetUser->id)
            ->where('scope_type', ScopedRole::SCOPE_PROJECT)
            ->where('scope_id', $this->project->id)
            ->first();

        $this->assertNotNull($role);
        $this->assertEquals(ScopedRole::PROJECT_VIEWER, $role->role);
        $this->assertNotNull($role->expires_at);
        $this->assertTrue(abs($role->expires_at->diffInSeconds($expiresAt)) < 60);
    }

    public function test_assign_project_role_validation_rejects_invalid_role(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/roles", [
                'user_id' => $this->targetUser->id,
                'role' => 'invalid_role_xyz',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_can_update_project_role(): void
    {
        $this->targetUser->assignProjectRole($this->project, ScopedRole::PROJECT_MEMBER, $this->admin->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/roles/{$this->targetUser->id}", [
                'role' => ScopedRole::PROJECT_MANAGER,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $this->targetUser->id,
            'scope_type' => ScopedRole::SCOPE_PROJECT,
            'scope_id' => $this->project->id,
            'role' => ScopedRole::PROJECT_MANAGER,
        ]);
    }

    public function test_can_remove_user_from_project(): void
    {
        $this->targetUser->assignProjectRole($this->project, ScopedRole::PROJECT_MEMBER, $this->admin->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/roles/{$this->targetUser->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'user_id' => $this->targetUser->id,
            'scope_type' => ScopedRole::SCOPE_PROJECT,
            'scope_id' => $this->project->id,
        ]);
    }

    public function test_unauthenticated_cannot_assign_roles(): void
    {
        $this->postJson("/api/projects/{$this->project->id}/roles", [
            'user_id' => $this->targetUser->id,
            'role' => ScopedRole::PROJECT_MEMBER,
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
                'role' => ScopedRole::DEPARTMENT_MANAGER,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $this->targetUser->id,
            'scope_type' => ScopedRole::SCOPE_DEPARTMENT,
            'scope_id' => $this->department->id,
            'role' => ScopedRole::DEPARTMENT_MANAGER,
        ]);
    }

    public function test_can_remove_user_from_department(): void
    {
        $this->targetUser->assignScopedRole(
            ScopedRole::DEPARTMENT_MANAGER,
            ScopedRole::SCOPE_DEPARTMENT,
            $this->department->id,
            $this->admin->id
        );

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/departments/{$this->department->id}/roles/{$this->targetUser->id}", [
                'role' => ScopedRole::DEPARTMENT_MANAGER,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'user_id' => $this->targetUser->id,
            'scope_type' => ScopedRole::SCOPE_DEPARTMENT,
            'scope_id' => $this->department->id,
        ]);
    }

    // ========== User Scoped Roles ==========

    public function test_can_get_user_scoped_roles(): void
    {
        $this->targetUser->assignProjectRole($this->project, ScopedRole::PROJECT_MEMBER, $this->admin->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/scoped-roles/user/{$this->targetUser->id}");

        $response->assertStatus(200);

        // Response is {"data": {"projects": [...], "departments": [...]}}
        $data = $response->json('data');
        $this->assertArrayHasKey('projects', $data);
        $this->assertArrayHasKey('departments', $data);
        $this->assertNotEmpty($data['projects']);
        $this->assertEquals(ScopedRole::PROJECT_MEMBER, $data['projects'][0]['role']);
        $this->assertEquals($this->project->id, $data['projects'][0]['scope_id']);
    }

    // ========== Audit Logs ==========

    public function test_can_get_audit_logs(): void
    {
        // Assign a role via API to generate an audit log entry
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/roles", [
                'user_id' => $this->targetUser->id,
                'role' => ScopedRole::PROJECT_MEMBER,
            ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/scoped-roles/audit-logs');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'current_page', 'total']);
    }
}
