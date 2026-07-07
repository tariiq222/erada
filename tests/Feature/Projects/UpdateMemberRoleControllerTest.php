<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
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
        $admin->assignRole('super_admin');

        $project = Project::factory()->create([
            'organization_id' => $org->id,
        ]);

        $member = User::factory()->create(['organization_id' => $org->id]);
        $member->assignProjectRole($project, ScopedRole::PROJECT_MEMBER, $admin->id);
        $admin->assignProjectRole($project, ScopedRole::PROJECT_MANAGER, $admin->id);

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

        $this->assertTrue(
            $member->fresh()->hasRoleInProject($project, ScopedRole::PROJECT_VIEWER),
            'Member should now have PROJECT_VIEWER scoped role on the project.',
        );
    }

    public function test_project_manager_without_super_admin_cannot_promote_to_manager(): void
    {
        [$admin, $project] = $this->makeProjectWithAdminAndMember();

        $projectManager = User::factory()->create(['organization_id' => $project->organization_id]);
        $projectManager->assignProjectRole($project, ScopedRole::PROJECT_MANAGER, $admin->id);

        $target = User::factory()->create(['organization_id' => $project->organization_id]);
        $target->assignProjectRole($project, ScopedRole::PROJECT_MEMBER, $admin->id);

        $this->actingAs($projectManager)
            ->putJson(
                "/api/projects/{$project->id}/members/{$target->id}",
                ['role' => 'manager'],
                ['X-Skip-Csrf' => '1'],
            )
            ->assertStatus(403);

        $this->assertTrue(
            $target->fresh()->hasRoleInProject($project, ScopedRole::PROJECT_MEMBER),
            'Target member should remain PROJECT_MEMBER after failed promotion attempt.',
        );
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

        $this->assertTrue(
            $member->fresh()->hasRoleInProject($project, ScopedRole::PROJECT_MANAGER),
            'Member should now have PROJECT_MANAGER scoped role on the project.',
        );
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
        $crossOrgActor->assignProjectRole($project, ScopedRole::PROJECT_MANAGER, $admin->id);

        $crossOrgMember = User::factory()->create(['organization_id' => $otherOrg->id]);
        $crossOrgMember->assignProjectRole($project, ScopedRole::PROJECT_MEMBER, $admin->id);

        $this->actingAs($crossOrgActor)
            ->putJson(
                "/api/projects/{$project->id}/members/{$crossOrgMember->id}",
                ['role' => 'viewer'],
                ['X-Skip-Csrf' => '1'],
            )
            ->assertStatus(403);

        $this->assertTrue(
            $crossOrgMember->fresh()->hasRoleInProject($project, ScopedRole::PROJECT_MEMBER),
            'Cross-org member should retain PROJECT_MEMBER role after rejected update.',
        );
    }
}
