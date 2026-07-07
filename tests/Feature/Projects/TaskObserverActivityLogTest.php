<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اختبار انحدار (Regression) لـ TaskObserver — P2-8.
 *
 * يضمن أن كل عملية حفظ على Task (create أو update) تُنتج سطراً واحداً فقط
 * في ActivityLog، وأن نسبة إنجاز المشروع تُحدَّث مرة واحدة بالضبط عبر هوك
 * `saved()` الموحَّد (لا تكرار عبر saved/created/updated).
 */
class TaskObserverActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_creating_task_produces_exactly_one_activity_log_entry(): void
    {
        $this->actingAs($this->user);

        $project = Project::factory()->create(['progress' => 0]);
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'progress' => 50,
        ]);

        $count = ActivityLog::query()
            ->where('loggable_type', Task::class)
            ->where('loggable_id', $task->id)
            ->count();

        $this->assertSame(
            1,
            $count,
            'Creating a task must produce exactly one ActivityLog entry (no duplicates from saved()).'
        );

        $this->assertSame('created', ActivityLog::query()
            ->where('loggable_type', Task::class)
            ->where('loggable_id', $task->id)
            ->value('action'));
    }

    public function test_updating_task_with_tracked_change_produces_exactly_one_activity_log_entry(): void
    {
        $this->actingAs($this->user);

        $project = Project::factory()->create(['progress' => 0]);
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'progress' => 50,
        ]);

        $countBefore = ActivityLog::query()
            ->where('loggable_type', Task::class)
            ->where('loggable_id', $task->id)
            ->count();

        $task->update(['progress' => 75]);

        $countAfter = ActivityLog::query()
            ->where('loggable_type', Task::class)
            ->where('loggable_id', $task->id)
            ->count();

        $this->assertSame(
            1,
            $countAfter - $countBefore,
            'Updating a tracked field must add exactly one ActivityLog entry (no duplicates from saved()).'
        );

        $newAction = ActivityLog::query()
            ->where('loggable_type', Task::class)
            ->where('loggable_id', $task->id)
            ->orderByDesc('id')
            ->value('action');

        $this->assertSame('updated', $newAction);
    }

    public function test_saving_task_updates_project_progress_exactly_once_via_saved_hook(): void
    {
        $this->actingAs($this->user);

        $project = Project::factory()->create(['progress' => 0]);
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'progress' => 60,
            'type' => 'project',
        ]);

        // بعد الإنشاء: نسبة المشروع = متوسط نسبة المهام = 60
        $this->assertSame(60, (int) $project->fresh()->progress);

        // تحديث progress على نفس المهمة — يجب أن تنتقل نسبة المشروع إلى 80
        $task->update(['progress' => 80]);

        $this->assertSame(80, (int) $project->fresh()->progress);

        // سجل النشاط يجب أن يبقى منضبطاً: سطر واحد للإنشاء + سطر واحد للتحديث = 2
        $actions = ActivityLog::query()
            ->where('loggable_type', Task::class)
            ->where('loggable_id', $task->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(['created', 'updated'], $actions);
    }

    public function test_non_project_task_does_not_trigger_project_progress_update(): void
    {
        $this->actingAs($this->user);

        $project = Project::factory()->create(['progress' => 42]);

        // مهمة شخصية (type != project) — لا يجب أن تلمس نسبة المشروع
        $task = Task::factory()->create([
            'project_id' => null,
            'type' => 'personal',
            'progress' => 90,
        ]);

        $this->assertSame(42, (int) $project->fresh()->progress);
        $this->assertFalse($task->isProjectTask());

        $logsForThisTask = ActivityLog::query()
            ->where('loggable_type', Task::class)
            ->where('loggable_id', $task->id)
            ->count();

        $this->assertSame(1, $logsForThisTask);
    }
}
