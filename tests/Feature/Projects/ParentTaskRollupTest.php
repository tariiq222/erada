<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ParentTaskRollupTest - اختبارات رفع نسبة المهمة الأم من متوسط المهام الفرعية
 *
 * يتحقق أن TaskObserver::saved يحدّث نسبة المهمة الأم كمتوسط لنسب
 * المهام الفرعية (مع تجاهل الملغاة) عند حفظ أي مهمة فرعية،
 * وأن المنطق آمن من العودية وبعيد عن كتابة لا داعي لها.
 */
class ParentTaskRollupTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected Department $dept;

    protected User $user;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');

        $this->project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);
    }

    /**
     * Case A: مهمة أم لها مهمتان فرعيتان غير ملغاة بنسب 50 و 100
     * بعد حفظ أي منهما، تصبح نسبة الأم = 75 (متوسط).
     */
    public function test_parent_progress_is_average_of_subtask_progress(): void
    {
        $parent = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => null,
            'progress' => 0,
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => $parent->id,
            'status' => TaskStatus::IN_PROGRESS->value,
            'progress' => 50,
        ]);

        $subtask = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => $parent->id,
            'status' => TaskStatus::COMPLETED->value,
            'progress' => 100,
        ]);

        // حفظ المهمة الفرعية الثانية لتحفيز الـ saved hook
        $subtask->update(['progress' => 100]);

        $this->assertSame(75, (int) $parent->fresh()->progress);
    }

    /**
     * Case B: مهمة أم لها مهمة فرعية ملغاة وأخرى مكتملة
     * المتوسط يُحسب من الفرعيات غير الملغاة فقط → 100
     */
    public function test_cancelled_subtasks_are_excluded_from_average(): void
    {
        $parent = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => null,
            'progress' => 0,
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => $parent->id,
            'status' => TaskStatus::CANCELLED->value,
            'progress' => 30,
        ]);

        $subtask = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => $parent->id,
            'status' => TaskStatus::COMPLETED->value,
            'progress' => 100,
        ]);

        $subtask->update(['progress' => 100]);

        $this->assertSame(100, (int) $parent->fresh()->progress);
    }

    /**
     * Case C: عندما يختفي الأب (محذوف ناعماً) قبل حفظ الفرعية،
     * يجب ألّا ينهار الحفظ وأن يبقى كل شيء مستقراً
     * (parent_id يشير لسجل غير موجود → Task::find يرجع null).
     */
    public function test_subtask_save_succeeds_when_parent_was_soft_deleted(): void
    {
        $parent = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => null,
            'progress' => 42,
        ]);

        $subtask = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => $parent->id,
            'status' => TaskStatus::IN_PROGRESS->value,
            'progress' => 30,
        ]);

        // حذف ناعم للأب → Task::find($parent->id) سيُرجع null في saved()
        $parent->delete();

        // تحديث الفرعية يجب أن ينجح بدون أخطاء (المُراقِب يحرس ضد parent=null)
        $subtask->update(['progress' => 45]);

        $this->assertSame(45, (int) $subtask->fresh()->progress);
    }

    /**
     * Case D: عندما يساوي المتوسط نسبة الأم الحالية، لا يحدث كتابة على DB
     */
    public function test_no_db_write_when_average_equals_current_progress(): void
    {
        $parent = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => null,
            'progress' => 80,
        ]);

        $sub1 = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => $parent->id,
            'status' => TaskStatus::IN_PROGRESS->value,
            'progress' => 80,
        ]);

        $sub2 = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => $parent->id,
            'status' => TaskStatus::IN_PROGRESS->value,
            'progress' => 80,
        ]);

        DB::enableQueryLog();

        // حفظ بخاصية لا تغيّر شيئاً فعلياً
        $sub1->update(['title' => 'تحديث عنوان فقط']);
        $sub2->update(['title' => 'تحديث عنوان آخر فقط']);

        $queries = DB::getQueryLog();

        DB::disableQueryLog();

        // فلترة استعلامات UPDATE على جدول tasks للأم فقط
        // فلتر دقيق: استعلامات UPDATE على جدول tasks بشرط أن يكون
        // parent.id موجوداً في الـ bindings كقيمة مستقلة (وليس جزءاً
        // من سلسلة أطول مثل طابع زمني).
        $parentIdStr = (string) $parent->id;
        $parentUpdates = collect($queries)->filter(function ($q) use ($parentIdStr) {
            $sql = strtolower($q['query']);
            if (! str_contains($sql, 'update "tasks"')) {
                return false;
            }
            foreach ($q['bindings'] as $binding) {
                if ((string) $binding === $parentIdStr) {
                    return true;
                }
            }

            return false;
        });

        $this->assertCount(
            0,
            $parentUpdates,
            'لا يجب أن يحدث UPDATE على المهمة الأم عندما لا يتغير المتوسط'
        );
    }

    /**
     * Case E: سلسلة بثلاث مستويات (جد → أب → ابن)
     * حفظ الابن يسبّب تحديث الأب، وتحديث الأب يسبّب تحديث الجد،
     * ولا يحدث انفجار عودية (الاختبار ينتهي في وقت محدود).
     */
    public function test_three_level_chain_rolls_up_without_infinite_recursion(): void
    {
        $grandparent = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => null,
            'progress' => 0,
        ]);

        $parent = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => $grandparent->id,
            'progress' => 0,
        ]);

        $subtask = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => $parent->id,
            'status' => TaskStatus::IN_PROGRESS->value,
            'progress' => 50,
        ]);

        // تشغيل سلسلة الرفع عند حفظ الابن
        $subtask->update(['progress' => 60]);

        // الجد = متوسط الأب الوحيد = 60
        // الأب = متوسط الابن الوحيد = 60
        $this->assertSame(60, (int) $parent->fresh()->progress);
        $this->assertSame(60, (int) $grandparent->fresh()->progress);

        // التحقق من أن الحفظ ينتهي في وقت قصير (لا يوجد تكرار لا نهائي)
        $start = microtime(true);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => TaskType::PROJECT->value,
            'parent_id' => $parent->id,
            'status' => TaskStatus::IN_PROGRESS->value,
            'progress' => 80,
        ]);

        $elapsed = microtime(true) - $start;

        $this->assertLessThan(5.0, $elapsed, 'يجب أن ينتهي حفظ المهمة الفرعية في أقل من 5 ثوانٍ');
    }
}
