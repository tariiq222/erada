<?php

namespace Tests\Unit\Services;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Exceptions\ProjectMemberAlreadyExistsException;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\Project\TeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamServiceTest extends TestCase
{
    use RefreshDatabase;

    private TeamService $service;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TeamService;
        $this->department = Department::factory()->create();

        foreach (['project_manager', 'project_member', 'project_viewer'] as $name) {
            AuthorizationRole::query()->updateOrCreate(
                ['name' => $name],
                ['label' => $name, 'is_active' => true, 'is_admin_role' => $name === 'project_manager'],
            );
        }
    }

    public function test_add_update_and_remove_use_only_canonical_auto_assignments(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();

        $this->assertTrue($this->service->addMember($project, ['user_id' => $user->id, 'role' => 'مطور']));
        $this->assertCanonical($project, $user, 'project_member');
        $this->assertDatabaseMissing('authorization_role_assignments', ['user_id' => $user->id, 'scope_type' => 'project', 'scope_id' => $project->id]);

        $this->assertTrue($this->service->updateMemberRole($project, $user->id, 'viewer'));
        $this->assertCanonical($project, $user, 'project_viewer');
        $this->assertDatabaseMissing('authorization_role_assignments', [
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_id' => $project->id,
            'authorization_role_id' => AuthorizationRole::where('name', 'project_member')->value('id'),
        ]);

        $this->assertTrue($this->service->removeMember($project, $user->id));
        $this->assertFalse($this->service->isMember($project, $user->id));
    }

    public function test_replace_preserves_manual_and_migration_assignments(): void
    {
        $project = $this->makeProject();
        $oldAuto = $this->makeUser();
        $manual = $this->makeUser();
        $migration = $this->makeUser();
        $replacement = $this->makeUser();

        $this->service->addMember($project, ['user_id' => $oldAuto->id, 'role' => 'member']);
        $this->assignment($project, $manual, 'project_viewer', 'manual');
        $this->assignment($project, $migration, 'project_member', 'migration');

        $this->service->replaceTeamMembers($project, [['user_id' => $replacement->id, 'role' => 'member']]);

        $this->assertFalse($this->service->isMember($project, $oldAuto->id));
        $this->assertTrue($this->service->isMember($project, $manual->id));
        $this->assertTrue($this->service->isMember($project, $migration->id));
        $this->assertCanonical($project, $replacement, 'project_member');
    }

    public function test_automatic_manager_survives_team_replacement(): void
    {
        $project = $this->makeProject();
        $manager = $this->makeUser();
        $member = $this->makeUser();

        $this->service->assignAutomaticManager($project, $manager);
        $this->service->replaceTeamMembers($project, [['user_id' => $member->id, 'role' => 'member']]);

        $this->assertCanonical($project, $manager, 'project_manager');
        $this->assertCanonical($project, $member, 'project_member');
        $this->assertSame(2, $this->service->getMembersCount($project));
    }

    public function test_duplicate_invalid_missing_and_cross_org_inputs_fail_closed(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();

        $this->assertFalse($this->service->addMember($project, ['user_id' => null]));
        $this->assertFalse($this->service->addMember($project, ['user_id' => 999999]));
        $this->assertFalse($this->service->updateMemberRole($project, $user->id, 'member'));

        $this->service->addMember($project, ['user_id' => $user->id, 'role' => 'member']);
        $this->expectException(ProjectMemberAlreadyExistsException::class);
        $this->service->addMember($project, ['user_id' => $user->id, 'role' => 'member']);
    }

    public function test_cross_organization_automatic_assignment_is_rejected(): void
    {
        $project = $this->makeProject();
        $foreign = User::factory()->create(['is_active' => true]);

        $this->expectException(\RuntimeException::class);
        $this->service->addMember($project, ['user_id' => $foreign->id, 'role' => 'member']);
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
    }

    private function makeProject(): Project
    {
        return Project::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
        ]);
    }

    private function assignment(Project $project, User $user, string $roleName, string $source): void
    {
        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => AuthorizationRole::where('name', $roleName)->value('id'),
            'user_id' => $user->id,
            'organization_id' => $project->organization_id,
            'scope_type' => 'project',
            'scope_id' => $project->id,
            'inherit_to_children' => false,
            'source' => $source,
        ]);
    }

    private function assertCanonical(Project $project, User $user, string $roleName): void
    {
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => AuthorizationRole::where('name', $roleName)->value('id'),
            'user_id' => $user->id,
            'organization_id' => $project->organization_id,
            'scope_type' => 'project',
            'scope_id' => $project->id,
            'source' => 'auto',
            'granted_by' => null,
        ]);
    }
}
