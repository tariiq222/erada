<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateMemberRoleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeProjectWithAdminAndMember(): array
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $org->id]);
        $this->grantCanonicalSuperAdmin($admin);

        $project = Project::factory()->create([
            'organization_id' => $org->id,
        ]);

        $member = User::factory()->create(['organization_id' => $org->id]);
        $this->assignCanonicalRole($member, 'project_member', 'project', $project->id);
        $this->assignCanonicalRole($admin, 'project_manager', 'project', $project->id);

        return [$admin, $project, $member, $org];
    }

    public function test_super_admin_updates_member_to_viewer(): void
    {
        [$admin, $project, $member] = $this->makeProjectWithAdminAndMember();

        $this->actingAs($admin)
            ->putJson(
                "/api/projects/{$project->id}/members/{$member->id}",
                ['role' => 'viewer'],
                ['X-Skip-Csrf' => '1'],
            )
            ->assertOk()
            ->assertJsonPath('message', 'Member role updated successfully');

        $this->assertCanonicalProjectRole($member, $project, 'project_viewer');
    }

    public function test_project_manager_without_super_admin_cannot_promote_to_manager(): void
    {
        [$admin, $project] = $this->makeProjectWithAdminAndMember();

        $projectManager = User::factory()->create(['organization_id' => $project->organization_id]);
        $this->assignCanonicalRole($projectManager, 'project_manager', 'project', $project->id);

        $target = User::factory()->create(['organization_id' => $project->organization_id]);
        $this->assignCanonicalRole($target, 'project_member', 'project', $project->id);

        $this->actingAs($projectManager)
            ->putJson(
                "/api/projects/{$project->id}/members/{$target->id}",
                ['role' => 'manager'],
                ['X-Skip-Csrf' => '1'],
            )
            ->assertStatus(403);

        $this->assertCanonicalProjectRole($target, $project, 'project_member');
    }

    public function test_super_admin_promotes_member_to_manager(): void
    {
        [$admin, $project, $member] = $this->makeProjectWithAdminAndMember();

        $this->actingAs($admin)
            ->putJson(
                "/api/projects/{$project->id}/members/{$member->id}",
                ['role' => 'manager'],
                ['X-Skip-Csrf' => '1'],
            )
            ->assertOk()
            ->assertJsonPath('message', 'Member role updated successfully');

        $this->assertCanonicalProjectRole($member, $project, 'project_manager');
    }

    public function test_non_member_user_id_returns_404(): void
    {
        [$admin, $project] = $this->makeProjectWithAdminAndMember();

        $stranger = User::factory()->create(['organization_id' => $project->organization_id]);

        $this->actingAs($admin)
            ->putJson(
                "/api/projects/{$project->id}/members/{$stranger->id}",
                ['role' => 'viewer'],
                ['X-Skip-Csrf' => '1'],
            )
            ->assertStatus(404);
    }

    public function test_cross_org_member_is_rejected(): void
    {
        [$admin, $project, $member] = $this->makeProjectWithAdminAndMember();

        $otherOrg = Organization::factory()->create();
        $crossOrgActor = User::factory()->create(['organization_id' => $project->organization_id]);
        $this->assignCanonicalRole($crossOrgActor, 'project_manager', 'project', $project->id);

        $crossOrgMember = User::factory()->create(['organization_id' => $otherOrg->id]);
        $this->assignCanonicalRole($crossOrgMember, 'project_member', 'project', $project->id);

        $this->actingAs($crossOrgActor)
            ->putJson(
                "/api/projects/{$project->id}/members/{$crossOrgMember->id}",
                ['role' => 'viewer'],
                ['X-Skip-Csrf' => '1'],
            )
            ->assertStatus(403);

        $this->assertCanonicalProjectRole($crossOrgMember, $project, 'project_member');
    }

    private function assertCanonicalProjectRole(User $user, Project $project, string $roleName): void
    {
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => AuthorizationRole::query()->where('name', $roleName)->value('id'),
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_id' => $project->id,
        ]);
    }
}
