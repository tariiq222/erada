<?php

namespace Tests\Unit\Services;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DashboardStatisticsService;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * اختبارات DashboardStatisticsService
 */
class DashboardStatisticsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DashboardStatisticsService $service;

    protected User $superAdmin;

    protected User $admin;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->service = app(DashboardStatisticsService::class);

        $this->department = Department::factory()->create();

        $this->superAdmin = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);

        $this->admin = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($this->admin);
    }

    // ========== اختبارات الإحصائيات الأساسية ==========

    /**
     * الإحصائيات الأساسية تُرجع البنية الصحيحة
     */
    public function test_basic_stats_returns_correct_structure(): void
    {
        $stats = $this->service->getBasicStats($this->superAdmin);

        $this->assertArrayHasKey('projects', $stats);
        $this->assertArrayHasKey('tasks', $stats);
        $this->assertArrayHasKey('users', $stats);

        // التحقق من بنية المشاريع
        $this->assertArrayHasKey('total', $stats['projects']);
        $this->assertArrayHasKey('active', $stats['projects']);
        $this->assertArrayHasKey('completed', $stats['projects']);

        // التحقق من بنية المهام
        $this->assertArrayHasKey('total', $stats['tasks']);
        $this->assertArrayHasKey('pending', $stats['tasks']);
        $this->assertArrayHasKey('overdue', $stats['tasks']);
    }

    /**
     * الإحصائيات تحسب الأعداد بشكل صحيح
     */
    public function test_basic_stats_calculates_correct_counts(): void
    {
        // إنشاء بيانات تجريبية
        Project::factory()->count(3)->create([
            'department_id' => $this->department->id,
            'status' => 'in_progress',
        ]);
        Project::factory()->count(2)->create([
            'department_id' => $this->department->id,
            'status' => 'completed',
        ]);

        Cache::flush();
        $stats = $this->service->getBasicStats($this->superAdmin);

        $this->assertEquals(5, $stats['projects']['total']);
        $this->assertEquals(3, $stats['projects']['active']);
        $this->assertEquals(2, $stats['projects']['completed']);
    }

    /**
     * الإحصائيات تُخزّن مؤقتاً
     */
    public function test_basic_stats_are_cached(): void
    {
        $stats1 = $this->service->getBasicStats($this->superAdmin);

        // إنشاء مشروع جديد
        Project::factory()->create([
            'department_id' => $this->department->id,
            'status' => 'in_progress',
        ]);

        // يجب أن تُرجع نفس النتيجة من الـ cache
        $stats2 = $this->service->getBasicStats($this->superAdmin);

        $this->assertEquals($stats1['projects']['total'], $stats2['projects']['total']);
    }

    /**
     * الـ Admin لا يرى عدد المستخدمين
     */
    public function test_admin_does_not_see_user_count(): void
    {
        $stats = $this->service->getBasicStats($this->admin);

        $this->assertNull($stats['users']);
    }

    /**
     * الـ SuperAdmin يرى عدد المستخدمين
     */
    public function test_super_admin_sees_user_count(): void
    {
        $stats = $this->service->getBasicStats($this->superAdmin);

        $this->assertNotNull($stats['users']);
        $this->assertIsInt($stats['users']);
    }

    // ========== اختبارات توزيع المشاريع حسب الحالة ==========

    /**
     * توزيع المشاريع يُرجع جميع الحالات
     */
    public function test_projects_by_status_returns_all_statuses(): void
    {
        $result = $this->service->getProjectsByStatus($this->superAdmin);

        $expectedStatuses = ['draft', 'planning', 'in_progress', 'on_hold', 'completed', 'cancelled'];
        foreach ($expectedStatuses as $status) {
            $this->assertArrayHasKey($status, $result);
        }
    }

    /**
     * توزيع المشاريع يحسب الأعداد بشكل صحيح
     */
    public function test_projects_by_status_counts_correctly(): void
    {
        Project::factory()->count(4)->create([
            'department_id' => $this->department->id,
            'status' => 'in_progress',
        ]);
        Project::factory()->count(2)->create([
            'department_id' => $this->department->id,
            'status' => 'completed',
        ]);

        $result = $this->service->getProjectsByStatus($this->superAdmin);

        $this->assertEquals(4, $result['in_progress']);
        $this->assertEquals(2, $result['completed']);
        $this->assertEquals(0, $result['draft']);
    }

    // ========== اختبارات الإحصائيات المتقدمة ==========

    /**
     * الإحصائيات المتقدمة تُرجع البنية الصحيحة
     */
    public function test_advanced_stats_returns_correct_structure(): void
    {
        Cache::flush();
        $stats = $this->service->getAdvancedStats($this->superAdmin);

        $this->assertArrayHasKey('avg_completion_time', $stats);
        $this->assertArrayHasKey('budget_summary', $stats);
        $this->assertArrayHasKey('departments_performance', $stats);
        $this->assertArrayHasKey('overdue_projects', $stats);
        $this->assertArrayHasKey('monthly_trends', $stats);
    }

    /**
     * ملخص الميزانية يحسب بشكل صحيح
     */
    public function test_budget_summary_calculates_correctly(): void
    {
        Project::factory()->create([
            'department_id' => $this->department->id,
            'budget' => 100000,
            'actual_cost' => 80000,
        ]);
        Project::factory()->create([
            'department_id' => $this->department->id,
            'budget' => 50000,
            'actual_cost' => 60000, // تجاوز الميزانية
        ]);

        Cache::flush();
        $stats = $this->service->getAdvancedStats($this->superAdmin);

        $budget = $stats['budget_summary'];
        $this->assertEquals(150000, $budget['total_budget']);
        $this->assertEquals(140000, $budget['total_actual']);
        $this->assertEquals(1, $budget['over_budget_count']);
    }

    /**
     * الاتجاهات الشهرية تُرجع 6 أشهر
     */
    public function test_monthly_trends_returns_six_months(): void
    {
        Cache::flush();
        $stats = $this->service->getAdvancedStats($this->superAdmin);

        $this->assertCount(6, $stats['monthly_trends']);
    }

    // ========== اختبارات أسماء الأشهر ==========

    /**
     * أسماء الأشهر العربية صحيحة
     */
    public function test_arabic_month_names_are_correct(): void
    {
        $this->assertEquals('يناير', $this->service->getArabicMonthName(1));
        $this->assertEquals('يونيو', $this->service->getArabicMonthName(6));
        $this->assertEquals('ديسمبر', $this->service->getArabicMonthName(12));
        $this->assertEquals('', $this->service->getArabicMonthName(13)); // شهر غير صالح
    }

    // ========== اختبارات المهام المتأخرة ==========

    /**
     * حساب المهام المتأخرة صحيح
     */
    public function test_overdue_tasks_counted_correctly(): void
    {
        $project = Project::factory()->create([
            'department_id' => $this->department->id,
            'status' => 'in_progress',
        ]);

        // مهام متأخرة
        Task::factory()->count(3)->create([
            'project_id' => $project->id,
            'status' => 'in_progress',
            'due_date' => now()->subDays(5),
        ]);

        // مهام غير متأخرة
        Task::factory()->count(2)->create([
            'project_id' => $project->id,
            'status' => 'in_progress',
            'due_date' => now()->addDays(5),
        ]);

        Cache::flush();
        $stats = $this->service->getBasicStats($this->superAdmin);

        $this->assertEquals(3, $stats['tasks']['overdue']);
    }
}
