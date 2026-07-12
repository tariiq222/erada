<?php

namespace Tests\Unit\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Exceptions\ProjectMemberAlreadyExistsException;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\Project\MilestoneService;
use App\Modules\Projects\Services\Project\RiskService;
use App\Modules\Projects\Services\Project\StakeholderService;
use App\Modules\Projects\Services\Project\TaskService;
use App\Modules\Projects\Services\Project\TeamService;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProjectChildServicesTopUpTest extends TestCase
{
    use DatabaseTransactions;

    private Organization $organization;

    private Department $department;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create(['organization_id' => $this->organization->id]);
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        $this->project = Project::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_milestone_service_creates_updates_reorders_and_deletes_milestones_with_deliverables(): void
    {
        $service = new MilestoneService;

        $ids = $service->createMilestones($this->project, [
            ['name' => '', 'start_date' => now(), 'due_date' => now()->addDay()],
            [
                'name' => 'Planning',
                'description' => 'Plan phase',
                'start_date' => now()->toDateString(),
                'due_date' => now()->addDays(5)->toDateString(),
                'status' => 'in_progress',
                'progress' => 25,
                'deliverables' => [
                    ['name' => 'Charter', 'description' => 'Project charter', 'progress' => 10],
                    ['name' => ''],
                ],
            ],
            [
                'name' => 'Execution',
                'start_date' => now()->addDays(6)->toDateString(),
                'due_date' => now()->addDays(10)->toDateString(),
            ],
        ]);

        $this->assertArrayHasKey(1, $ids);
        $this->assertArrayHasKey(2, $ids);
        $milestone = $this->project->milestones()->whereKey($ids[1])->firstOrFail();
        $this->assertSame(2, $milestone->order);
        $this->assertSame(1, $milestone->deliverables()->count());

        $updated = $service->updateMilestone($milestone, [
            'name' => 'Updated planning',
            'description' => 'Updated',
            'status' => 'completed',
            'progress' => 100,
        ]);
        $this->assertSame('Updated planning', $updated->name);
        $this->assertSame('completed', $updated->status);

        $service->reorderMilestones($this->project, array_reverse(array_values($ids)));
        $this->assertSame(1, $this->project->milestones()->whereKey($ids[2])->value('order'));

        $this->assertTrue($service->deleteMilestone($updated));
        $this->assertSoftDeleted('milestones', ['id' => $updated->id]);
    }

    public function test_risk_service_creates_replaces_updates_filters_and_deletes_risks(): void
    {
        $service = new RiskService;

        $service->createRisks($this->project, [
            ['description' => ''],
            ['description' => 'Open high risk', 'probability' => 'high', 'impact' => 'high', 'mitigation' => 'Avoid'],
            ['risk' => 'Closed risk', 'status' => 'closed', 'impact' => 'low'],
        ]);

        $this->assertSame(1, $this->project->risks()->count());
        $risk = $this->project->risks()->firstOrFail();
        $this->assertSame('Open high risk', $risk->risk);
        $this->assertSame('critical', $risk->risk_level);

        // Single-record updates route through the relationship directly per the
        // service's documented recommendation (ProjectCrudService::syncRisks is
        // the batch counterpart). Tests exercise the relationship path here.
        $risk->update([
            'risk' => 'Updated risk',
            'probability' => 'medium',
            'impact' => 'high',
            'response' => 'Transfer',
            'status' => 'open',
        ]);
        $this->assertSame('Updated risk', $risk->fresh()->risk);
        $this->assertSame('Transfer', $risk->fresh()->response);
        $this->assertSame(1, $this->project->risks()->where('status', 'open')->count());
        $this->assertSame(1, $this->project->risks()->where('impact', 'high')->count());

        $risk->update(['status' => 'closed']);
        $this->assertSame('closed', $risk->fresh()->status);

        $service->syncRisks($this->project, [
            ['description' => 'Replacement', 'impact' => 'high'],
        ]);
        $this->assertSame(['Replacement'], $this->project->risks()->pluck('risk')->all());

        $this->project->risks()->first()->delete();
        // ProjectRisk uses SoftDeletes — `delete()` sets deleted_at, not
        // a hard row drop. assertSoftDeleted matches the contract;
        // assertDatabaseMissing would always fail because the row
        // remains in the table with deleted_at populated.
        $this->assertSoftDeleted('project_risks', ['risk' => 'Replacement']);
    }

    public function test_team_and_stakeholder_services_manage_members_and_stakeholders(): void
    {
        $team = new TeamService;
        $stakeholders = new StakeholderService;
        $manager = User::factory()->create(['organization_id' => $this->organization->id, 'department_id' => $this->department->id]);
        $member = User::factory()->create(['organization_id' => $this->organization->id, 'department_id' => $this->department->id]);

        $this->assertFalse($team->addMember($this->project, []));
        $this->assertFalse($team->addMember($this->project, ['user_id' => 999999]));
        $this->assertTrue($team->addMember($this->project, ['user_id' => $member->id, 'role' => 'مطور']));

        // Adding an existing member now raises an explicit exception.
        try {
            $team->addMember($this->project, ['user_id' => $member->id]);
            $this->fail('Expected ProjectMemberAlreadyExistsException for a duplicate member.');
        } catch (ProjectMemberAlreadyExistsException) {
            // expected
        }

        // Membership queries route through the project's `members()` relationship
        // directly — single-record service helpers were retired as dead code.
        $this->assertTrue($this->project->members()->where('user_id', $member->id)->exists());
        $this->assertSame(1, $this->project->members()->count());
        $this->assertSame(1, $team->getMembersByRole($this->project, 'project_member')->count());
        $this->assignCanonicalRole($member, 'project_viewer', 'project', (int) $this->project->id);
        $this->assertSame(1, $team->getMembersByRole($this->project, 'project_viewer')->count());

        $team->createTeamMembers($this->project, [
            ['user_id' => $manager->id, 'role' => 'مدير'],
        ]);
        $this->assertSame(1, $team->getMembersByRole($this->project, 'project_manager')->count());

        $stakeholders->createStakeholders($this->project, [
            ['name' => '   '],
            ['name' => 'Sponsor', 'role' => 'invalid-role', 'contact' => 'sponsor@example.test', 'influence' => 'high'],
        ]);
        $stakeholder = $this->project->stakeholders()->firstOrFail();
        $this->assertSame('other', $stakeholder->role);
        $this->assertSame('sponsor@example.test', $stakeholder->email);

        $updated = $stakeholders->updateStakeholder($stakeholder, [
            'name' => ' Updated Sponsor ',
            'role' => 'consultant',
            'email' => 'updated@example.test',
            'influence' => 'low',
        ]);
        $this->assertSame('Updated Sponsor', $updated->name);
        $this->assertSame('consultant', $updated->role);

        $this->assignCanonicalRole($manager, 'project_manager', 'project', (int) $this->project->id);
        $addedIds = $stakeholders->addProjectLeadersAsStakeholders($this->project);
        $this->assertContains($manager->id, $addedIds);
        $this->assertSame(1, $stakeholders->getHighInfluenceStakeholders($this->project)->count());
        $this->assertSame(1, $stakeholders->getStakeholdersByRole($this->project, 'implementer')->count());

        $stakeholders->replaceStakeholders($this->project, [
            ['name' => 'Replacement stakeholder', 'role' => 'operations'],
        ]);
        $this->assertSame(['Replacement stakeholder'], $this->project->stakeholders()->pluck('name')->all());
        $this->assertTrue($stakeholders->deleteStakeholder($this->project->stakeholders()->first()));
        $this->assertDatabaseMissing('stakeholders', ['name' => 'Replacement stakeholder']);

        $team->replaceTeamMembers($this->project, [['user_id' => $member->id, 'role' => 'عضو']]);
        // The explicit viewer assignment is manual, so automatic team replacement
        // and removal must not revoke it.
        $this->assertFalse($team->removeMember($this->project, $member->id));
        $this->assertTrue($this->project->members()->where('user_id', $member->id)->exists());
    }

    public function test_task_service_creates_updates_filters_reorders_assigns_and_deletes_tasks(): void
    {
        $milestoneService = new MilestoneService;
        $taskService = new TaskService;
        $assignee = User::factory()->create(['organization_id' => $this->organization->id, 'department_id' => $this->department->id]);
        $manager = User::factory()->create(['organization_id' => $this->organization->id, 'department_id' => $this->department->id]);
        $this->assignCanonicalRole($manager, 'project_manager', 'project', (int) $this->project->id);
        $milestoneIds = $milestoneService->createMilestones($this->project, [[
            'name' => 'Milestone',
            'start_date' => now()->toDateString(),
            'due_date' => now()->addDay()->toDateString(),
        ]]);

        $taskService->createTasks($this->project, [
            ['name' => ''],
            ['name' => 'First task', 'milestone_index' => 0, 'priority' => 'high', 'due_date' => now()->subDay()->toDateString()],
            ['title' => 'Skipped because name is required in createTasks'],
        ], array_values($milestoneIds), $this->user);

        $task = $this->project->tasks()->firstOrFail();
        $this->assertSame('First task', $task->title);
        $this->assertSame($manager->id, $task->assigned_to);

        $updated = $taskService->updateTask($task, [
            'name' => 'Updated task',
            'assigned_to' => $assignee->id,
            'status' => 'in_progress',
            'progress' => 40,
        ]);
        $this->assertSame('Updated task', $updated->title);
        $this->assertSame($assignee->id, $updated->assigned_to);
        $this->assertSame(1, $taskService->getTasksByStatus($this->project, 'in_progress')->count());
        $this->assertSame(1, $taskService->getOverdueTasks($this->project)->count());
        $this->assertSame(1, $taskService->getUserTasks($this->project, $assignee->id)->count());

        $completed = $taskService->changeStatus($updated, 'completed');
        $this->assertSame(100, (int) $completed->progress);
        $assigned = $taskService->assignTask($completed, $manager->id);
        $this->assertSame($manager->id, $assigned->assigned_to);

        $orderedIds = $this->project->tasks()->orderByDesc('id')->pluck('id')->all();
        $taskService->reorderTasks($this->project, $orderedIds);
        $this->assertSame(1, Task::query()->whereKey($orderedIds[0])->value('order'));

        $this->assertTrue($taskService->deleteTask($assigned));
        $this->assertSoftDeleted('tasks', ['id' => $assigned->id]);
    }

    public function test_expired_project_manager_is_not_used_as_a_leader_or_default_task_assignee(): void
    {
        $manager = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        $assignment = $this->assignCanonicalRole(
            $manager,
            'project_manager',
            'project',
            (int) $this->project->id,
        );
        $assignment->update(['expires_at' => now()->subMinute()]);

        $stakeholderIds = (new StakeholderService)->addProjectLeadersAsStakeholders($this->project);
        $task = (new TaskService)->createTask(
            $this->project,
            ['name' => 'Unassigned task'],
            [],
            $this->user,
        );

        $this->assertNotContains($manager->id, $stakeholderIds);
        $this->assertDatabaseMissing('stakeholders', [
            'project_id' => $this->project->id,
            'user_id' => $manager->id,
        ]);
        $this->assertNull($task->assigned_to);
    }
}
