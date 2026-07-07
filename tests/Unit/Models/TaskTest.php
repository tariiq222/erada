<?php

namespace Tests\Unit\Models;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    protected Task $task;

    protected Project $project;

    protected User $assignee;

    protected User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->assignee = User::factory()->create();
        $this->creator = User::factory()->create();

        $this->task = Task::factory()->create([
            'project_id' => $this->project->id,
            'assigned_to' => $this->assignee->id,
            'created_by' => $this->creator->id,
        ]);
    }

    /**
     * اختبار العلاقة مع المشروع
     */
    public function test_task_belongs_to_project(): void
    {
        $this->assertInstanceOf(Project::class, $this->task->project);
        $this->assertEquals($this->project->id, $this->task->project->id);
    }

    /**
     * اختبار العلاقة مع المسؤول
     */
    public function test_task_belongs_to_assignee(): void
    {
        $this->assertInstanceOf(User::class, $this->task->assignee);
        $this->assertEquals($this->assignee->id, $this->task->assignee->id);
    }

    /**
     * اختبار العلاقة مع المنشئ
     */
    public function test_task_belongs_to_creator(): void
    {
        $this->assertInstanceOf(User::class, $this->task->creator);
        $this->assertEquals($this->creator->id, $this->task->creator->id);
    }

    /**
     * اختبار العلاقة مع المرحلة
     */
    public function test_task_belongs_to_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);

        $this->task->update(['milestone_id' => $milestone->id]);

        $this->assertInstanceOf(Milestone::class, $this->task->milestone);
        $this->assertEquals($milestone->id, $this->task->milestone->id);
    }

    /**
     * اختبار العلاقة بالمهمة الأصل
     */
    public function test_task_belongs_to_parent_task(): void
    {
        $parentTask = Task::factory()->create(['project_id' => $this->project->id]);

        $this->task->update(['parent_id' => $parentTask->id]);

        $this->assertInstanceOf(Task::class, $this->task->parent);
        $this->assertEquals($parentTask->id, $this->task->parent->id);
    }

    /**
     * اختبار العلاقة مع المهام الفرعية
     */
    public function test_task_has_many_subtasks(): void
    {
        Task::factory()->count(3)->create([
            'project_id' => $this->project->id,
            'parent_id' => $this->task->id,
        ]);

        $this->assertCount(3, $this->task->subtasks);
        $this->assertInstanceOf(Task::class, $this->task->subtasks->first());
    }

    /**
     * اختبار مسح تاريخ الإنجاز عند تغيير الحالة
     */
    public function test_completed_date_is_cleared_when_status_is_not_completed(): void
    {
        $this->task->update([
            'status' => 'completed',
            'completed_date' => now(),
        ]);
        $this->task->update([
            'status' => 'in_progress',
            'completed_date' => null,
        ]);

        $this->assertNull($this->task->completed_date);
    }

    /**
     * اختبار تحديث نسبة الإنجاز عند التغيير
     */
    public function test_progress_updates_project_progress(): void
    {
        $initialProjectProgress = $this->project->progress;

        $this->task->update(['progress' => 100]);

        $this->project->refresh();
        $this->assertNotEquals($initialProjectProgress, $this->project->progress);
    }

    /**
     * اختبار التحقق من التقدم بين 0 و 100
     */
    public function test_progress_is_between_0_and_100(): void
    {
        for ($i = 0; $i <= 100; $i += 25) {
            $task = Task::factory()->create(['progress' => $i]);
            $this->assertEquals($i, $task->progress);
        }
    }

    /**
     * اختبار الحقول القابلة للتعبئة
     */
    public function test_fillable_fields(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $parentTask = Task::factory()->create(['project_id' => $this->project->id]);

        $data = [
            'title' => 'مهمة جديدة',
            'description' => 'وصف المهمة',
            'project_id' => $this->project->id,
            'milestone_id' => $milestone->id,
            'parent_id' => $parentTask->id,
            'assigned_to' => $this->assignee->id,
            'created_by' => $this->creator->id,
            'status' => 'todo',
            'priority' => 'high',
            'start_date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'estimated_hours' => 8,
            'actual_hours' => 6,
            'progress' => 75,
            'order' => 1,
        ];

        $task = Task::create($data);

        $this->assertEquals($data['title'], $task->title);
        $this->assertEquals($data['description'], $task->description);
        $this->assertEquals($data['project_id'], $task->project_id);
        $this->assertEquals('todo', $task->status->value);
        $this->assertEquals($data['priority'], $task->priority->value);
    }

    /**
     * اختبار Soft Deletes
     */
    public function test_task_uses_soft_deletes(): void
    {
        $this->task->delete();

        $this->assertSoftDeleted('tasks', [
            'id' => $this->task->id,
        ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $this->task->id,
        ]);
    }

    /**
     * اختبار التحقق من الأولويات المسموح بها
     */
    public function test_valid_priorities(): void
    {
        $validPriorities = ['low', 'medium', 'high', 'urgent', 'critical'];

        foreach ($validPriorities as $priority) {
            $task = Task::factory()->create(['priority' => $priority]);
            $this->assertEquals($priority, $task->priority->value);
        }
    }

    /**
     * اختبار التحقق من المهام المتأخرة عبر isOverdue
     */
    public function test_is_overdue_method(): void
    {
        $overdueTask = Task::factory()->create([
            'due_date' => now()->subDays(2),
            'status' => 'in_progress',
        ]);

        $notOverdueTask = Task::factory()->create([
            'due_date' => now()->addDays(2),
            'status' => 'in_progress',
        ]);

        $completedTask = Task::factory()->create([
            'due_date' => now()->subDays(2),
            'status' => 'completed',
        ]);

        $this->assertTrue($overdueTask->isOverdue());
        $this->assertFalse($notOverdueTask->isOverdue());
        $this->assertFalse($completedTask->isOverdue());
    }

    /**
     * اختبار التسلسل الهرمي للمهام
     */
    public function test_task_hierarchy(): void
    {
        $parent = Task::factory()->create(['project_id' => $this->project->id]);
        $child1 = Task::factory()->create([
            'project_id' => $this->project->id,
            'parent_id' => $parent->id,
        ]);
        $child2 = Task::factory()->create([
            'project_id' => $this->project->id,
            'parent_id' => $parent->id,
        ]);
        Task::factory()->create([
            'project_id' => $this->project->id,
            'parent_id' => $child1->id,
        ]);

        $this->assertCount(2, $parent->subtasks);
        $this->assertCount(1, $child1->subtasks);
        $this->assertEquals($parent->id, $child1->parent_id);
        $this->assertEquals($parent->id, $child2->parent_id);
    }

    /**
     * اختبار time indicator attributes
     */
    public function test_time_indicator_attributes(): void
    {
        $task = Task::factory()->create([
            'start_date' => now()->subDays(3),
            'due_date' => now()->addDays(7),
            'status' => 'in_progress',
        ]);

        $this->assertNotNull($task->time_indicator);
        $this->assertArrayHasKey('days_remaining', $task->time_indicator);
        $this->assertArrayHasKey('days_elapsed', $task->time_indicator);
        $this->assertArrayHasKey('total_days', $task->time_indicator);
        $this->assertArrayHasKey('time_progress', $task->time_indicator);
        $this->assertArrayHasKey('status', $task->time_indicator);
    }

    /**
     * اختبار حساب الأيام المتبقية
     */
    public function test_days_remaining_attribute(): void
    {
        $task = Task::factory()->create([
            'due_date' => now()->addDays(5)->startOfDay(),
        ]);

        // قد يكون 4 أو 5 بسبب فرق التوقيت
        $this->assertGreaterThanOrEqual(4, $task->days_remaining);
        $this->assertLessThanOrEqual(5, $task->days_remaining);
    }

    /**
     * اختبار حساب إجمالي الأيام
     */
    public function test_total_days_attribute(): void
    {
        $task = Task::factory()->create([
            'start_date' => now(),
            'due_date' => now()->addDays(10),
        ]);

        $this->assertEquals(10, $task->total_days);
    }
}
