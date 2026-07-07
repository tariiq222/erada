<?php

namespace Tests\Unit\Models;

use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestoneTest extends TestCase
{
    use RefreshDatabase;

    protected Milestone $milestone;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();

        $this->milestone = Milestone::factory()->create([
            'project_id' => $this->project->id,
        ]);
    }

    /**
     * اختبار العلاقة مع المشروع
     */
    public function test_milestone_belongs_to_project(): void
    {
        $this->assertInstanceOf(Project::class, $this->milestone->project);
        $this->assertEquals($this->project->id, $this->milestone->project->id);
    }

    /**
     * اختبار العلاقة مع المهام
     */
    public function test_milestone_has_many_tasks(): void
    {
        $tasks = Task::factory()->count(3)->create([
            'project_id' => $this->project->id,
            'milestone_id' => $this->milestone->id,
        ]);

        $this->assertCount(3, $this->milestone->tasks);
        $this->assertInstanceOf(Task::class, $this->milestone->tasks->first());
    }

    /**
     * اختبار التاريخ casts
     */
    public function test_date_fields_are_cast_correctly(): void
    {
        $milestoneWithDates = Milestone::factory()->create([
            'project_id' => $this->project->id,
            'start_date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        $this->assertInstanceOf(Carbon::class, $milestoneWithDates->start_date);
        $this->assertInstanceOf(Carbon::class, $milestoneWithDates->due_date);
    }

    /**
     * اختبار الحقول القابلة للتعبئة
     */
    public function test_fillable_fields(): void
    {
        $data = [
            'name' => 'مرحلة جديدة',
            'description' => 'وصف المرحلة',
            'project_id' => $this->project->id,
            'status' => 'pending',
            'start_date' => now()->format('Y-m-d'),
            'due_date' => now()->addMonths(1)->format('Y-m-d'),
            'progress' => 0,
            'order' => 1,
        ];

        $milestone = Milestone::create($data);

        $this->assertEquals($data['name'], $milestone->name);
        $this->assertEquals($data['description'], $milestone->description);
        $this->assertEquals($data['project_id'], $milestone->project_id);
        $this->assertEquals($data['status'], $milestone->status);
        $this->assertEquals($data['order'], $milestone->order);
    }

    /**
     * اختبار التحقق من الحالات المسموح بها
     */
    public function test_valid_statuses(): void
    {
        $validStatuses = ['pending', 'in_progress', 'completed', 'overdue'];

        foreach ($validStatuses as $status) {
            $milestone = Milestone::factory()->create([
                'project_id' => $this->project->id,
                'status' => $status,
            ]);
            $this->assertEquals($status, $milestone->status);
        }
    }

    /**
     * اختبار المراحل المتأخرة
     */
    public function test_overdue_milestones(): void
    {
        $overdueMilestone = Milestone::factory()->create([
            'project_id' => $this->project->id,
            'due_date' => now()->subDays(2),
            'status' => 'in_progress',
        ]);

        $notOverdueMilestone = Milestone::factory()->create([
            'project_id' => $this->project->id,
            'due_date' => now()->addDays(2),
            'status' => 'in_progress',
        ]);

        $completedMilestone = Milestone::factory()->create([
            'project_id' => $this->project->id,
            'due_date' => now()->subDays(2),
            'status' => 'completed',
        ]);

        // التحقق من أن isOverdue يعمل بشكل صحيح
        $this->assertTrue($overdueMilestone->isOverdue());
        $this->assertFalse($notOverdueMilestone->isOverdue());
        $this->assertFalse($completedMilestone->isOverdue());
    }

    /**
     * اختبار إنشاء مرحلة مكتملة
     */
    public function test_completed_milestone_state(): void
    {
        $completedMilestone = Milestone::factory()->completed()->create([
            'project_id' => $this->project->id,
        ]);

        $this->assertEquals('completed', $completedMilestone->status);
        $this->assertEquals(100, $completedMilestone->progress);
        $this->assertNotNull($completedMilestone->completed_date);
    }

    /**
     * اختبار إنشاء مرحلة قيد التنفيذ
     */
    public function test_in_progress_milestone_state(): void
    {
        $inProgressMilestone = Milestone::factory()->inProgress()->create([
            'project_id' => $this->project->id,
        ]);

        $this->assertEquals('in_progress', $inProgressMilestone->status);
        $this->assertGreaterThan(0, $inProgressMilestone->progress);
        $this->assertLessThan(100, $inProgressMilestone->progress);
    }

    /**
     * اختبار إنشاء مرحلة معلقة
     */
    public function test_pending_milestone_state(): void
    {
        $pendingMilestone = Milestone::factory()->pending()->create([
            'project_id' => $this->project->id,
        ]);

        $this->assertEquals('pending', $pendingMilestone->status);
        $this->assertEquals(0, $pendingMilestone->progress);
    }

    /**
     * اختبار الترتيب
     */
    public function test_milestone_order(): void
    {
        $milestone1 = Milestone::factory()->create([
            'project_id' => $this->project->id,
            'order' => 1,
        ]);

        $milestone2 = Milestone::factory()->create([
            'project_id' => $this->project->id,
            'order' => 2,
        ]);

        $this->assertEquals(1, $milestone1->order);
        $this->assertEquals(2, $milestone2->order);
    }
}
