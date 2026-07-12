<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Contracts\AuthorizationAssignmentActorGuard;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalAuthorizationRoleAssignmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $subject;

    private Department $department;

    private Project $project;

    private AuthorizationRole $firstRole;

    private AuthorizationRole $secondRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $organization = Organization::factory()->create();
        $this->department = Department::factory()->create(['organization_id' => $organization->id]);
        $this->actor = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $this->department->id,
        ]);
        $this->grantCanonicalSuperAdmin($this->actor);
        $this->subject = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $this->department->id,
        ]);
        $this->project = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $this->department->id,
        ]);
        $this->firstRole = AuthorizationRole::query()->create([
            'name' => 'canonical-scoped-first',
            'label' => 'Canonical Scoped First',
            'scope_type' => 'project',
            'is_active' => true,
        ]);
        $this->secondRole = AuthorizationRole::query()->create([
            'name' => 'canonical-scoped-second',
            'label' => 'Canonical Scoped Second',
            'scope_type' => 'project',
            'is_active' => true,
        ]);

        $this->app->bind(AuthorizationAssignmentActorGuard::class, fn () => new class implements AuthorizationAssignmentActorGuard
        {
            public function allows(User $actor, User $subject, AuthorizationRole $role, AssignmentScope $scope): bool
            {
                return true;
            }
        });
    }

    public function test_project_assignment_update_and_revoke_use_only_canonical_rows(): void
    {
        $assign = $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/roles", [
                'user_id' => $this->subject->id,
                'role_id' => $this->firstRole->id,
            ]);

        $assign->assertCreated()
            ->assertJsonPath('data.user_id', $this->subject->id)
            ->assertJsonPath('data.role_id', $this->firstRole->id)
            ->assertJsonPath('data.scope_type', 'project')
            ->assertJsonPath('data.scope_id', $this->project->id)
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.source', 'manual');

        $this->actingAs($this->actor, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/roles/{$this->subject->id}", [
                'role_id' => $this->secondRole->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.user_id', $this->subject->id)
            ->assertJsonPath('data.role_id', $this->secondRole->id)
            ->assertJsonPath('data.scope_type', 'project')
            ->assertJsonPath('data.scope_id', $this->project->id)
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.source', 'manual');

        $this->assertDatabaseMissing('authorization_role_assignments', [
            'authorization_role_id' => $this->firstRole->id,
            'user_id' => $this->subject->id,
            'scope_type' => 'project',
            'scope_id' => $this->project->id,
        ]);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $this->secondRole->id,
            'user_id' => $this->subject->id,
            'scope_type' => 'project',
            'scope_id' => $this->project->id,
            'source' => 'manual',
            'granted_by' => $this->actor->id,
        ]);

        $this->actingAs($this->actor, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/roles/{$this->subject->id}", [
                'role_id' => $this->secondRole->id,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('authorization_role_assignments', [
            'authorization_role_id' => $this->secondRole->id,
            'user_id' => $this->subject->id,
            'scope_type' => 'project',
            'scope_id' => $this->project->id,
        ]);
    }

    public function test_department_endpoint_derives_scope_and_refuses_to_revoke_automatic_assignment(): void
    {
        $departmentRole = AuthorizationRole::query()->create([
            'name' => 'canonical-department-assignment',
            'label' => 'Canonical Department Assignment',
            'scope_type' => 'department',
            'is_active' => true,
        ]);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/departments/{$this->department->id}/roles", [
                'user_id' => $this->subject->id,
                'role_id' => $departmentRole->id,
                'scope_type' => 'all',
                'scope_id' => 999999,
                'inherit_to_children' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $this->subject->id)
            ->assertJsonPath('data.scope_type', 'department')
            ->assertJsonPath('data.scope_id', $this->department->id)
            ->assertJsonPath('data.inherit_to_children', true)
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.source', 'manual');

        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $departmentRole->id,
            'user_id' => $this->subject->id,
            'scope_type' => 'department',
            'scope_id' => $this->department->id,
            'inherit_to_children' => true,
            'source' => 'manual',
        ]);

        AuthorizationRoleAssignment::query()
            ->where('authorization_role_id', $departmentRole->id)
            ->where('user_id', $this->subject->id)
            ->where('scope_type', 'department')
            ->where('scope_id', $this->department->id)
            ->update(['source' => 'auto']);

        $this->actingAs($this->actor, 'sanctum')
            ->deleteJson("/api/departments/{$this->department->id}/roles/{$this->subject->id}", [
                'role_id' => $departmentRole->id,
            ])
            ->assertConflict();

        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $departmentRole->id,
            'user_id' => $this->subject->id,
            'scope_type' => 'department',
            'scope_id' => $this->department->id,
            'source' => 'auto',
        ]);
    }

    public function test_legacy_role_key_is_rejected_by_canonical_payload_validation(): void
    {
        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/roles", [
                'user_id' => $this->subject->id,
                'role' => 'project_member',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_id');
    }

    public function test_reads_use_canonical_shape_hide_inactive_roles_and_scope_available_roles(): void
    {
        $projectRole = AuthorizationRole::query()->create(['name' => 'canonical-project-role', 'label' => 'Project role', 'scope_type' => 'project']);
        $departmentRole = AuthorizationRole::query()->create(['name' => 'canonical-department-role', 'label' => 'Department role', 'scope_type' => 'department']);
        $inactiveRole = AuthorizationRole::query()->create(['name' => 'inactive-read-role', 'label' => 'Inactive', 'scope_type' => 'project', 'is_active' => false]);

        foreach ([
            [$projectRole, 'project', $this->project->id, $this->project->organization_id],
            [$inactiveRole, 'project', $this->project->id, $this->project->organization_id],
            [$departmentRole, 'department', $this->department->id, $this->department->organization_id],
        ] as [$role, $scopeType, $scopeId, $organizationId]) {
            AuthorizationRoleAssignment::query()->create([
                'authorization_role_id' => $role->id,
                'user_id' => $this->subject->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'organization_id' => $organizationId,
                'source' => 'manual',
                'granted_by' => $this->actor->id,
            ]);
        }

        $projectResponse = $this->actingAs($this->actor, 'sanctum')->getJson("/api/projects/{$this->project->id}/roles")
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $this->subject->id)
            ->assertJsonPath('data.0.scope_type', 'project')
            ->assertJsonPath('data.0.scope_id', $this->project->id)
            ->assertJsonPath('data.0.source', 'manual')
            ->assertJsonMissing(['role_id' => $inactiveRole->id]);
        $this->assertContains($projectRole->id, collect($projectResponse->json('available_roles'))->pluck('id')->all());
        $this->assertNotContains($departmentRole->id, collect($projectResponse->json('available_roles'))->pluck('id')->all());

        $departmentResponse = $this->actingAs($this->actor, 'sanctum')->getJson("/api/departments/{$this->department->id}/roles")
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $this->subject->id)
            ->assertJsonPath('data.0.scope_type', 'department')
            ->assertJsonPath('data.0.scope_id', $this->department->id)
            ->assertJsonPath('data.0.source', 'manual');
        $this->assertContains($departmentRole->id, collect($departmentResponse->json('available_roles'))->pluck('id')->all());
        $this->assertNotContains($projectRole->id, collect($departmentResponse->json('available_roles'))->pluck('id')->all());
    }
}
