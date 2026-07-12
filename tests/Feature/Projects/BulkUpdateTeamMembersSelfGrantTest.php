<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\CanonicalAuthorizationFixtures;
use Tests\TestCase;

/**
 * BulkUpdateTeamMembersSelfGrantTest — coverage for CSD-CA23078-PROJECTS-002.
 *
 * The bulk project update path (`PATCH /api/projects/{id}`) used to grant the
 * actor themselves a project role with `source=auto`, bypassing the canonical
 * manual-assignment actor guard. Two defense layers now close the gap:
 *
 *  1) `UpdateProjectRequest::teamRules()` refuses any team_members entry
 *     whose `user_id` matches the actor and whose role is non-viewer
 *     (HTTP seam — returns 422 with a validation error).
 *  2) `TeamService::replaceTeamMembers()` re-applies the same predicate via
 *     `AuthorizationAssignmentActorGuard::allows()` so non-HTTP callers
 *     (CLI, jobs, internal service invocations) cannot bypass it either.
 *     Blocked entries are logged and skipped — the rest of the payload is
 *     processed normally so unrelated updates are not aborted.
 *
 * The dedicated `/api/projects/{id}/roles/*` endpoint is the only sanctioned
 * path for self-assignment and continues to work for actors who hold
 * `Capability::CORE_ASSIGN_ROLES` (super_admin or org-scoped admin).
 */
class BulkUpdateTeamMembersSelfGrantTest extends TestCase
{
    use CanonicalAuthorizationFixtures;
    use RefreshDatabase;

    protected Organization $org;

    protected Department $dept;

    protected Project $project;

    protected User $editor;

    protected User $otherUser;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create([
            'organization_id' => $this->org->id,
            'level' => Department::LEVEL_DEPARTMENT,
        ]);

        $this->project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => 'development',
        ]);

        // Editor: project-scoped project_manager (PROJECTS_EDIT + PROJECTS_ASSIGN_ROLES,
        // but NOT CORE_ASSIGN_ROLES). This is exactly the actor that used to be able
        // to bypass the actor guard via the bulk update path.
        $this->editor = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->editor, 'project_manager', 'project', $this->project->id);

        $this->otherUser = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);

        Cache::flush();
    }

    public function test_patch_project_with_team_members_containing_self_is_rejected(): void
    {
        $response = $this->actingAs($this->editor, 'sanctum')
            ->patchJson("/api/projects/{$this->project->id}", [
                'description' => 'تحديث',
                'team_members' => [
                    ['user_id' => $this->editor->id, 'role' => 'member'],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['team_members.0.role']);

        // No project_member assignment should have been created for the editor.
        $projectMemberRoleId = AuthorizationRole::query()->where('name', 'project_member')->value('id');
        $this->assertNotNull($projectMemberRoleId);
        $this->assertDatabaseMissing('authorization_role_assignments', [
            'authorization_role_id' => $projectMemberRoleId,
            'user_id' => $this->editor->id,
            'scope_type' => 'project',
            'scope_id' => $this->project->id,
            'source' => 'auto',
        ]);
    }

    public function test_patch_project_with_team_members_containing_other_user_is_accepted(): void
    {
        $response = $this->actingAs($this->editor, 'sanctum')
            ->patchJson("/api/projects/{$this->project->id}", [
                'description' => 'تحديث',
                'team_members' => [
                    ['user_id' => $this->otherUser->id, 'role' => 'member'],
                ],
            ]);

        $response->assertOk();

        // The bulk path produced the canonical authorization_role_assignments row
        // for the other user; the assignment sits at scope=project with the
        // project_member canonical role.
        $projectMemberRoleId = AuthorizationRole::query()->where('name', 'project_member')->value('id');
        $this->assertNotNull($projectMemberRoleId);

        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $projectMemberRoleId,
            'user_id' => $this->otherUser->id,
            'scope_type' => 'project',
            'scope_id' => $this->project->id,
        ]);

        // And the editor (the actor) MUST NOT have ended up with a project_member
        // assignment via the bulk path — the self-assignment defense must not
        // have been silently bypassed by mixing self + other in the same call.
        $this->assertDatabaseMissing(AuthorizationRoleAssignment::class, [
            'authorization_role_id' => $projectMemberRoleId,
            'user_id' => $this->editor->id,
            'scope_type' => 'project',
            'scope_id' => $this->project->id,
            'source' => 'auto',
        ]);
    }

    public function test_dedicated_project_role_endpoint_still_works(): void
    {
        $projectMemberRoleId = AuthorizationRole::query()->where('name', 'project_member')->value('id');
        $this->assertNotNull($projectMemberRoleId);

        // The dedicated /members endpoint (which routes through
        // AuthorizationAssignmentService) must continue to create canonical
        // project-scoped assignments for authorized actors — this is the
        // sanctioned path for self-assignment as well as for cross-user
        // assignment.
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/members", [
                'user_id' => $this->otherUser->id,
                'role_id' => $projectMemberRoleId,
            ]);

        $response->assertCreated();

        // The canonical service writes source='manual' (provenance-trail
        // distinct from the bulk-path source='auto').
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $projectMemberRoleId,
            'user_id' => $this->otherUser->id,
            'scope_type' => 'project',
            'scope_id' => $this->project->id,
            'source' => 'manual',
        ]);
    }
}
