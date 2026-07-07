<?php

namespace Tests\Unit\Services;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectCrudService;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectCrudServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ProjectCrudService $service;

    protected User $user;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create();
        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $this->service = app(ProjectCrudService::class);
    }

    public function test_create_project_with_minimal_data(): void
    {
        $data = [
            'name' => 'Test Project',
            'department_id' => $this->department->id,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
        ];

        $project = $this->service->createProject($data, $this->user);

        $this->assertInstanceOf(Project::class, $project);
        $this->assertDatabaseHas('projects', [
            'name' => 'Test Project',
            'created_by' => $this->user->id,
        ]);
        $this->assertEquals($this->user->id, $project->created_by);
    }

    public function test_create_project_creator_becomes_scoped_manager(): void
    {
        // بعد التوحيد: منشئ المشروع يصبح مدير المشروع كدور سياقي (scoped manager)،
        // لا كعمود manager_id (الذي حُذف). أي manager_id يُمرَّر في $data يُتجاهَل.
        $data = [
            'name' => 'Creator Manager Test',
            'department_id' => $this->department->id,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
        ];

        $project = $this->service->createProject($data, $this->user);

        // المنشئ مدير المشروع عبر دور سياقي manager
        $this->assertTrue($this->user->isProjectAdmin($project));
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'scope_id' => $project->id,
            'scope_type' => ScopedRole::SCOPE_PROJECT,
            'user_id' => $this->user->id,
            'role' => ScopedRole::PROJECT_MANAGER,
        ]);
        // accessor المدير يرجع المنشئ
        $this->assertEquals($this->user->id, $project->manager?->id);
    }

    public function test_create_project_with_milestones(): void
    {
        $data = [
            'name' => 'Project With Milestones',
            'department_id' => $this->department->id,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
            'milestones' => [
                [
                    'name' => 'Milestone 1',
                    'start_date' => now()->format('Y-m-d'),
                    'due_date' => now()->addMonths(1)->format('Y-m-d'),
                    'order' => 1,
                ],
            ],
        ];

        $project = $this->service->createProject($data, $this->user);

        $this->assertDatabaseHas('milestones', [
            'project_id' => $project->id,
            'name' => 'Milestone 1',
        ]);
    }

    public function test_create_project_with_team_members(): void
    {
        $member = User::factory()->create(['is_active' => true]);

        $data = [
            'name' => 'Team Test',
            'department_id' => $this->department->id,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
            'team_members' => [
                ['user_id' => $member->id, 'role' => 'member'],
            ],
        ];

        $project = $this->service->createProject($data, $this->user);

        // Member should appear in model_has_scoped_roles as member
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'scope_id' => $project->id,
            'scope_type' => ScopedRole::SCOPE_PROJECT,
            'user_id' => $member->id,
            'role' => ScopedRole::PROJECT_MEMBER,
        ]);
    }

    public function test_create_project_creator_listed_in_team_members_no_duplicate(): void
    {
        // The creator ($this->user) becomes the project manager (scoped). If the
        // creator is also listed in team_members, createTeamMembers detects the
        // existing membership and skips it, so the creator keeps a single
        // (manager) row with no duplicate.
        $data = [
            'name' => 'No Duplicate Test',
            'department_id' => $this->department->id,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
            'team_members' => [
                ['user_id' => $this->user->id, 'role' => 'manager'],
            ],
        ];

        $project = $this->service->createProject($data, $this->user);

        $count = \DB::table('model_has_scoped_roles')
            ->where('scope_id', $project->id)
            ->where('scope_type', ScopedRole::SCOPE_PROJECT)
            ->where('user_id', $this->user->id)
            ->count();

        // صف واحد فقط (manager) — لا تكرار
        $this->assertEquals(1, $count);
        $this->assertEquals(ScopedRole::PROJECT_MANAGER, $this->user->roleInProject($project));
    }

    public function test_update_project_basic_fields(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);

        $this->service->updateProject($project, ['name' => 'Updated Name'], $this->user);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_update_project_replaces_risks(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);

        // First set risks
        $this->service->updateProject($project, [
            'risks' => [
                ['description' => 'Risk 1', 'probability' => 'medium', 'impact' => 'high', 'mitigation' => 'test'],
            ],
        ], $this->user);

        $this->assertDatabaseHas('project_risks', [
            'project_id' => $project->id,
            'risk' => 'Risk 1',
        ]);

        // Replace with new risks
        $this->service->updateProject($project, [
            'risks' => [
                ['description' => 'Risk 2', 'probability' => 'low', 'impact' => 'low', 'mitigation' => 'test2'],
            ],
        ], $this->user);

        $this->assertDatabaseMissing('project_risks', [
            'project_id' => $project->id,
            'risk' => 'Risk 1',
        ]);
        $this->assertDatabaseHas('project_risks', [
            'project_id' => $project->id,
            'risk' => 'Risk 2',
        ]);
    }

    public function test_update_project_without_risks_key_preserves_existing_risks(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);

        // Create a risk
        $this->service->updateProject($project, [
            'risks' => [
                ['description' => 'Existing Risk', 'probability' => 'medium', 'impact' => 'high', 'mitigation' => 'mitigate'],
            ],
        ], $this->user);

        $this->assertDatabaseHas('project_risks', [
            'project_id' => $project->id,
            'risk' => 'Existing Risk',
        ]);

        // Update without risks key
        $this->service->updateProject($project, ['name' => 'New Name'], $this->user);

        // Risk must still exist
        $this->assertDatabaseHas('project_risks', [
            'project_id' => $project->id,
            'risk' => 'Existing Risk',
        ]);
    }

    public function test_delete_project_soft_deletes(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);
        $projectId = $project->id;

        $result = $this->service->deleteProject($project);

        $this->assertTrue($result);
        $this->assertSoftDeleted('projects', ['id' => $projectId]);
    }

    public function test_delete_project_removes_tasks(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);

        Task::factory()->create([
            'project_id' => $project->id,
            'type' => 'project',
            'created_by' => $this->user->id,
        ]);

        $this->service->deleteProject($project);

        $this->assertSoftDeleted('tasks', ['project_id' => $project->id]);
    }

    public function test_delete_project_detaches_members(): void
    {
        $member = User::factory()->create(['is_active' => true]);
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);
        $member->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        $this->service->deleteProject($project);

        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'scope_id' => $project->id,
            'scope_type' => ScopedRole::SCOPE_PROJECT,
            'user_id' => $member->id,
        ]);
    }
}
