<?php

namespace Tests\Unit\Tasks;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Repositories\EloquentTaskRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskRepositoryAndEnumTest extends TestCase
{
    use RefreshDatabase;

    private EloquentTaskRepository $repository;

    private User $user;

    private Department $department;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentTaskRepository;
        $this->department = Department::factory()->create();
        $this->project = Project::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
        ]);
        $this->user = User::factory()->create([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
        ]);
    }

    public function test_repository_filters_searches_sorts_and_caps_pagination(): void
    {
        $matching = Task::factory()->create([
            'title' => 'Needle task for repository search',
            'description' => 'Unique searchable description',
            'type' => TaskType::PROJECT->value,
            'project_id' => $this->project->id,
            'department_id' => $this->department->id,
            'assigned_to' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => TaskStatus::IN_PROGRESS->value,
            'priority' => TaskPriority::HIGH->value,
            'due_date' => now()->addDays(3)->toDateString(),
            'progress' => 65,
        ]);
        Task::factory()->create([
            'title' => 'Other unrelated task',
            'type' => TaskType::DEPARTMENT->value,
            'project_id' => null,
            'department_id' => $this->department->id,
            'assigned_to' => null,
            'created_by' => null,
            'status' => TaskStatus::TODO->value,
            'priority' => TaskPriority::LOW->value,
        ]);

        $page = $this->repository->getPaginated([
            'type' => TaskType::PROJECT->value,
            'project_id' => $this->project->id,
            'department_id' => $this->department->id,
            'status' => TaskStatus::IN_PROGRESS->value,
            'priority' => TaskPriority::HIGH->value,
            'assigned_to' => $this->user->id,
            'my_tasks' => true,
            'user_id' => $this->user->id,
            'upcoming' => 7,
            'active' => true,
            'root_only' => true,
            'search' => 'Needle',
            'sort_by' => 'progress',
            'sort_dir' => 'asc',
        ], 150, $this->user);

        $this->assertSame(100, $page->perPage());
        $this->assertSame([$matching->id], $page->getCollection()->pluck('id')->all());
        $this->assertTrue($page->first()->relationLoaded('assignee'));
        $this->assertSame(0, $page->first()->incomplete_subtasks_count);
    }

    public function test_repository_my_tasks_defaults_to_active_roots_ordered_by_priority_and_due_date(): void
    {
        $completed = Task::factory()->completed()->create(['assigned_to' => $this->user->id]);
        $child = Task::factory()->create(['assigned_to' => $this->user->id]);
        Task::factory()->create([
            'assigned_to' => $this->user->id,
            'parent_id' => $child->id,
            'status' => TaskStatus::TODO->value,
        ]);
        $critical = Task::factory()->create([
            'assigned_to' => $this->user->id,
            'status' => TaskStatus::TODO->value,
            'priority' => TaskPriority::CRITICAL->value,
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $page = $this->repository->getUserTasksPaginated($this->user->id, [], 10);
        $ids = $page->getCollection()->pluck('id')->all();

        $this->assertSame($critical->id, $ids[0]);
        $this->assertNotContains($completed->id, $ids);
        $this->assertNotContains($child->subtasks()->first()->id, $ids);
    }

    public function test_repository_create_update_find_delete_and_stats_are_behavioral(): void
    {
        $task = $this->repository->create([
            'title' => 'Created through repository',
            'description' => 'Repository create path',
            'type' => TaskType::PERSONAL->value,
            'status' => TaskStatus::TODO->value,
            'priority' => TaskPriority::MEDIUM->value,
            'owner_id' => $this->user->id,
            'created_by' => $this->user->id,
        ]);

        $this->assertTrue($task->relationLoaded('owner'));
        $found = $this->repository->findWithRelations($task->id);
        $this->assertSame($task->id, $found?->id);
        $this->assertTrue($found->relationLoaded('comments'));

        $updated = $this->repository->update($task, [
            'status' => TaskStatus::IN_REVIEW->value,
            'priority' => TaskPriority::CRITICAL->value,
            'progress' => 90,
        ]);
        $this->assertSame(TaskStatus::IN_REVIEW, $updated->status);
        $this->assertSame(TaskPriority::CRITICAL, $updated->priority);

        Task::factory()->create([
            'type' => TaskType::DEPARTMENT->value,
            'department_id' => $this->department->id,
            'assigned_to' => $this->user->id,
            'status' => TaskStatus::TODO->value,
            'priority' => TaskPriority::LOW->value,
        ]);

        $stats = $this->repository->getStats(['my_tasks' => true, 'user_id' => $this->user->id], $this->user);
        $this->assertGreaterThanOrEqual(2, $stats['total']);
        $this->assertGreaterThanOrEqual(1, $stats['by_status']['in_review']);
        $this->assertGreaterThanOrEqual(1, $stats['by_priority']['critical']);
        $this->assertArrayHasKey('upcoming_7_days', $stats);
        $this->assertArrayHasKey('personal', $stats['by_type']);

        $subtask = Task::factory()->create(['parent_id' => $updated->id]);
        $this->assertTrue($this->repository->delete($updated));
        $this->assertSoftDeleted('tasks', ['id' => $updated->id]);
        $this->assertSoftDeleted('tasks', ['id' => $subtask->id]);
    }

    public function test_task_enums_expose_labels_colors_order_values_and_state_helpers(): void
    {
        $this->assertSame('للتنفيذ', TaskStatus::TODO->label());
        $this->assertSame('blue', TaskStatus::IN_PROGRESS->color());
        $this->assertSame('check-circle', TaskStatus::COMPLETED->icon());
        $this->assertSame('معلقة', TaskStatus::ON_HOLD->label());
        $this->assertSame('orange', TaskStatus::ON_HOLD->color());
        $this->assertSame('pause-circle', TaskStatus::ON_HOLD->icon());
        $this->assertSame('x-circle', TaskStatus::CANCELLED->icon());
        $this->assertTrue(TaskStatus::IN_REVIEW->isActive());
        $this->assertTrue(TaskStatus::CANCELLED->isClosed());
        $this->assertContains('on_hold', TaskStatus::values());
        $this->assertSame(['todo', 'in_progress', 'in_review'], TaskStatus::activeStatuses());

        $this->assertSame('حرجة', TaskPriority::CRITICAL->label());
        $this->assertSame('orange', TaskPriority::URGENT->color());
        $this->assertSame(5, TaskPriority::CRITICAL->order());
        $this->assertContains('urgent', TaskPriority::values());

        $this->assertSame('مهمة متكررة', TaskType::RECURRING->label());
        $this->assertSame('purple', TaskType::DEPARTMENT->color());
        $this->assertSame(['project', 'personal', 'department', 'recurring'], TaskType::values());
    }
}
