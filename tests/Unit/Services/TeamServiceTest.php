<?php

namespace Tests\Unit\Services;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Exceptions\ProjectMemberAlreadyExistsException;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\Project\TeamService;
use Database\Seeders\RolesAndPermissionsSeeder;
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
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->service = new TeamService;
        $this->department = Department::factory()->create();
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
    }

    private function makeProject(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'department_id' => $this->department->id,
        ], $overrides));
    }

    public function test_can_add_member_to_project(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();

        $result = $this->service->addMember($project, ['user_id' => $user->id, 'role' => 'member']);

        $this->assertTrue($result);
        $this->assertTrue($project->members()->where('user_id', $user->id)->exists());
    }

    public function test_returns_false_when_user_id_is_empty(): void
    {
        $project = $this->makeProject();

        $result = $this->service->addMember($project, ['user_id' => null]);

        $this->assertFalse($result);
    }

    public function test_maps_arabic_roles_to_team_member(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();

        $this->service->addMember($project, ['user_id' => $user->id, 'role' => 'مطور']);

        $pivot = $project->members()->where('user_id', $user->id)->first()->pivot;
        $this->assertEquals(ScopedRole::PROJECT_MEMBER, $pivot->role);
    }

    public function test_arabic_team_lead_role_maps_to_manager(): void
    {
        // بعد التوحيد: 'قائد فريق' يُطابَق إلى 'manager' في ROLE_MAPPING → يُضاف كمدير
        $project = $this->makeProject();
        $user = $this->makeUser();

        $result = $this->service->addMember($project, ['user_id' => $user->id, 'role' => 'قائد فريق']);

        $this->assertTrue($result);
        $pivot = $project->members()->where('user_id', $user->id)->first()->pivot;
        $this->assertEquals(ScopedRole::PROJECT_MANAGER, $pivot->role);
    }

    public function test_manager_role_input_maps_to_manager(): void
    {
        // بعد التوحيد: 'manager' يُطابَق إلى 'manager' في ROLE_MAPPING → يُضاف كمدير
        $project = $this->makeProject();
        $user = $this->makeUser();

        $result = $this->service->addMember($project, ['user_id' => $user->id, 'role' => 'manager']);

        $this->assertTrue($result);
        $pivot = $project->members()->where('user_id', $user->id)->first()->pivot;
        $this->assertEquals(ScopedRole::PROJECT_MANAGER, $pivot->role);
    }

    public function test_can_create_multiple_team_members(): void
    {
        $project = $this->makeProject();
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();

        $this->service->createTeamMembers($project, [
            ['user_id' => $user1->id, 'role' => 'member'],
            ['user_id' => $user2->id, 'role' => 'viewer'],
        ]);

        $this->assertEquals(2, $project->members()->count());
    }

    public function test_can_update_member_role(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        // Update role to 'viewer' (valid mapped role)
        $result = $this->service->updateMemberRole($project, $user->id, 'viewer');

        $this->assertTrue($result);
        $pivot = $project->members()->where('user_id', $user->id)->first()->pivot;
        $this->assertEquals('viewer', $pivot->role);
    }

    public function test_update_member_role_to_manager_promotes_member(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        // بعد التوحيد: 'manager' يُطابَق إلى 'manager' → تتم ترقية العضو إلى مدير
        $result = $this->service->updateMemberRole($project, $user->id, 'manager');

        $this->assertTrue($result);
        $pivot = $project->members()->where('user_id', $user->id)->first()->pivot;
        $this->assertEquals(ScopedRole::PROJECT_MANAGER, $pivot->role);
    }

    public function test_can_remove_member(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        $result = $this->service->removeMember($project, $user->id);

        $this->assertTrue($result);
        $this->assertFalse($project->members()->where('user_id', $user->id)->exists());
    }

    public function test_can_remove_scoped_manager(): void
    {
        // بعد التوحيد: المدير دور سياقي. removeMember يستدعي revokeProjectRole
        // الذي يزيل أي دور للمستخدم في المشروع (بما فيه manager).
        $manager = $this->makeUser();
        $project = $this->makeProject();
        $manager->assignProjectRole($project, ScopedRole::PROJECT_MANAGER);

        $result = $this->service->removeMember($project, $manager->id);

        $this->assertTrue($result);
        $this->assertNull($manager->roleInProject($project));
        $this->assertFalse($project->members()->where('user_id', $manager->id)->exists());
    }

    public function test_can_replace_team_members(): void
    {
        $project = $this->makeProject();
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $user3 = $this->makeUser();

        $user1->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);
        $user2->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        $this->service->replaceTeamMembers($project, [
            ['user_id' => $user3->id, 'role' => 'member'],
        ]);

        $this->assertEquals(1, $project->members()->count());
        $this->assertTrue($project->members()->where('user_id', $user3->id)->exists());
        $this->assertFalse($project->members()->where('user_id', $user1->id)->exists());
    }

    public function test_get_members_by_role(): void
    {
        $project = $this->makeProject();
        $member1 = $this->makeUser();
        $viewer = $this->makeUser();

        $member1->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);
        $viewer->assignProjectRole($project, ScopedRole::PROJECT_VIEWER);

        $members = $this->service->getMembersByRole($project, ScopedRole::PROJECT_MEMBER);
        $viewers = $this->service->getMembersByRole($project, ScopedRole::PROJECT_VIEWER);

        $this->assertCount(1, $members);
        $this->assertCount(1, $viewers);
    }

    public function test_get_members_count(): void
    {
        $project = $this->makeProject();
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();

        $user1->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);
        $user2->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        $this->assertEquals(2, $this->service->getMembersCount($project));
    }

    public function test_is_member_returns_true_for_existing_member(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        $this->assertTrue($this->service->isMember($project, $user->id));
    }

    public function test_is_member_returns_false_for_non_member(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();

        $this->assertFalse($this->service->isMember($project, $user->id));
    }

    // =================================================================
    // P2-2 regression tests: explicit errors on addMember dup + role/remove existence
    // =================================================================

    public function test_add_member_throws_when_member_already_exists(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();

        $first = $this->service->addMember($project, ['user_id' => $user->id, 'role' => 'member']);
        $this->assertTrue($first);

        $this->expectException(ProjectMemberAlreadyExistsException::class);
        $this->service->addMember($project, ['user_id' => $user->id, 'role' => 'member']);
    }

    public function test_add_member_throws_on_invalid_role(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->addMember($project, ['user_id' => $user->id, 'role' => 'unknown_role']);
    }

    public function test_add_member_returns_false_when_user_not_found(): void
    {
        $project = $this->makeProject();

        // مستخدم غير موجود في قاعدة البيانات
        $result = $this->service->addMember($project, ['user_id' => 999999, 'role' => 'member']);

        $this->assertFalse($result);
    }

    public function test_update_member_role_returns_false_when_user_not_found(): void
    {
        $project = $this->makeProject();

        $result = $this->service->updateMemberRole($project, 999999, 'manager');

        $this->assertFalse($result);
    }

    public function test_update_member_role_returns_false_when_user_not_a_member(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();

        // المستخدم موجود لكنه ليس عضواً في المشروع
        $result = $this->service->updateMemberRole($project, $user->id, 'manager');

        $this->assertFalse($result);
        $this->assertNull($user->roleInProject($project));
    }

    public function test_update_member_role_returns_true_on_success(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        $result = $this->service->updateMemberRole($project, $user->id, 'manager');

        $this->assertTrue($result);
        $pivot = $project->members()->where('user_id', $user->id)->first()->pivot;
        $this->assertEquals(ScopedRole::PROJECT_MANAGER, $pivot->role);
    }

    public function test_remove_member_returns_false_when_user_not_found(): void
    {
        $project = $this->makeProject();

        $result = $this->service->removeMember($project, 999999);

        $this->assertFalse($result);
    }

    public function test_remove_member_returns_false_when_user_not_a_member(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();

        // المستخدم موجود لكنه ليس عضواً في المشروع
        $result = $this->service->removeMember($project, $user->id);

        $this->assertFalse($result);
    }

    public function test_remove_member_returns_true_on_success(): void
    {
        $project = $this->makeProject();
        $user = $this->makeUser();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        $result = $this->service->removeMember($project, $user->id);

        $this->assertTrue($result);
        $this->assertFalse($project->members()->where('user_id', $user->id)->exists());
    }
}
