<?php

namespace Tests\Unit\Models;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected Department $department;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::factory()->create();
        $this->manager = User::factory()->create();
        $this->project = Project::factory()->create([
            'department_id' => $this->department->id,
            // pin a non-terminal status so updateProgress() actually recomputes;
            // a random 'completed' would short-circuit (Project::updateProgress
            // returns early on completed projects, leaving the factory's random
            // progress in place — the two progress tests then flake).
            'status' => 'in_progress',
            'progress' => 0,
        ]);
        // المدير يُمثَّل كدور سياقي (scoped role) لا كعمود manager_id
        $this->assignCanonicalRole($this->manager, 'project_manager', 'project', (int) $this->project->id);
    }

    /**
     * اختبار توليد كود المشروع التلقائي
     */
    public function test_project_code_is_generated_automatically(): void
    {
        $project = Project::factory()->create(['code' => null]);

        $this->assertNotNull($project->code);
        $this->assertMatchesRegularExpression('/^PRJ-\d{4}-\d{4}$/', $project->code);
    }

    /**
     * اختبار صيغة كود المشروع
     */
    public function test_project_code_format_is_correct(): void
    {
        $year = date('Y');
        $this->assertMatchesRegularExpression("/^PRJ-{$year}-\d{4}$/", $this->project->code);
    }

    /**
     * اختبار التسلسل الرقمي لكود المشروع
     */
    public function test_project_codes_are_sequential(): void
    {
        $projects = Project::factory()->count(3)->create();

        $codes = $projects->pluck('code')->sort()->values();

        for ($i = 1; $i < $codes->count(); $i++) {
            $prevNumber = intval(substr($codes[$i - 1], -4));
            $currNumber = intval(substr($codes[$i], -4));
            $this->assertGreaterThan($prevNumber, $currNumber);
        }
    }

    /**
     * اختبار العلاقة مع القسم
     */
    public function test_project_belongs_to_department(): void
    {
        $this->assertInstanceOf(Department::class, $this->project->department);
        $this->assertEquals($this->department->id, $this->project->department->id);
    }

    /**
     * اختبار العلاقة مع المدير
     */
    public function test_project_belongs_to_manager(): void
    {
        $this->assertInstanceOf(User::class, $this->project->manager);
        $this->assertEquals($this->manager->id, $this->project->manager->id);
    }

    /**
     * اختبار العلاقة مع المهام
     */
    public function test_project_has_many_tasks(): void
    {
        Task::factory()->count(3)->create([
            'project_id' => $this->project->id,
        ]);

        $this->assertCount(3, $this->project->tasks);
        $this->assertInstanceOf(Task::class, $this->project->tasks->first());
    }

    /**
     * اختبار العلاقة مع المراحل
     */
    public function test_project_has_many_milestones(): void
    {
        Milestone::factory()->count(2)->create([
            'project_id' => $this->project->id,
        ]);

        $this->assertCount(2, $this->project->milestones);
        $this->assertInstanceOf(Milestone::class, $this->project->milestones->first());
    }

    /**
     * اختبار حساب نسبة الإنجاز
     */
    public function test_calculate_progress_based_on_tasks(): void
    {
        // إنشاء مهام بنسب إنجاز مختلفة
        Task::factory()->create([
            'project_id' => $this->project->id,
            'progress' => 100,
            'parent_id' => null,
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'progress' => 50,
            'parent_id' => null,
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'progress' => 0,
            'parent_id' => null,
        ]);

        $expectedProgress = (100 + 50 + 0) / 3;
        $this->project->updateProgress();

        $this->assertEquals($expectedProgress, $this->project->fresh()->progress);
    }

    /**
     * اختبار تحديث التقدم تلقائياً عند إضافة مهمة مكتملة
     */
    public function test_progress_updates_when_task_is_added(): void
    {
        $initialProgress = $this->project->progress;

        Task::factory()->create([
            'project_id' => $this->project->id,
            'progress' => 100,
            'parent_id' => null,
        ]);

        $this->project->refresh();
        $this->assertNotEquals($initialProgress, $this->project->progress);
    }

    /**
     * اختبار التاريخ casts
     */
    public function test_date_fields_are_cast_correctly(): void
    {
        $this->assertInstanceOf(Carbon::class, $this->project->start_date);
        $this->assertInstanceOf(Carbon::class, $this->project->end_date);
    }

    /**
     * اختبار الحقول القابلة للتعبئة
     */
    public function test_fillable_fields(): void
    {
        $data = [
            'name' => 'مشروع جديد',
            'description' => 'وصف المشروع',
            'status' => 'planning',
            'priority' => 'high',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
            'budget' => 50000,
            'department_id' => $this->department->id,
        ];

        $project = Project::create($data);

        $this->assertEquals($data['name'], $project->name);
        $this->assertEquals($data['description'], $project->description);
        $this->assertEquals($data['status'], $project->status);
        $this->assertEquals($data['priority'], $project->priority);
        $this->assertEquals($data['department_id'], $project->department_id);
    }

    /**
     * اختبار Soft Deletes
     */
    public function test_project_uses_soft_deletes(): void
    {
        $this->project->delete();

        $this->assertSoftDeleted('projects', [
            'id' => $this->project->id,
        ]);

        $this->assertDatabaseHas('projects', [
            'id' => $this->project->id,
        ]);
    }

    /**
     * اختبار التحقق من الحالات المسموح بها
     */
    public function test_valid_statuses(): void
    {
        $validStatuses = ['draft', 'planning', 'in_progress', 'on_hold', 'completed', 'cancelled'];

        foreach ($validStatuses as $status) {
            $project = Project::factory()->create(['status' => $status]);
            $this->assertEquals($status, $project->status);
        }
    }

    /**
     * اختبار التحقق من الأولويات المسموح بها
     */
    public function test_valid_priorities(): void
    {
        $validPriorities = ['low', 'medium', 'high', 'critical'];

        foreach ($validPriorities as $priority) {
            $project = Project::factory()->create(['priority' => $priority]);
            $this->assertEquals($priority, $project->priority);
        }
    }

    /**
     * اختبار العلاقة مع أعضاء الفريق
     */
    public function test_project_has_many_members(): void
    {
        $members = User::factory()->count(2)->create();

        foreach ($members as $member) {
            $this->assignCanonicalRole($member, 'project_member', 'project', (int) $this->project->id);
        }

        $projectMembers = $this->project->members->whereIn('id', $members->modelKeys());
        $this->assertCount(2, $projectMembers);
        $this->assertEqualsCanonicalizing($members->modelKeys(), $projectMembers->modelKeys());
    }

    // ملاحظة: علاقة المشرف (supervisor) عبر عمود supervisor_id حُذفت بعد
    // توحيد أدوار المشاريع إلى الأدوار السياقية (scoped roles).
}
