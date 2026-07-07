<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * اختبارات DashboardController
 *
 * تتحقق من:
 * - إحصائيات لوحة التحكم
 * - تصفية البيانات حسب الصلاحيات
 * - عمل الـ Scopes بشكل صحيح
 */
class DashboardControllerTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected Department $department1;

    protected Department $department2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department1 = Department::factory()->create(['name' => 'قسم 1']);
        $this->department2 = Department::factory()->create(['name' => 'قسم 2']);
    }

    /**
     * Super Admin يرى إحصائيات كل المشاريع
     */
    public function test_super_admin_sees_all_stats(): void
    {
        $superAdmin = User::factory()->create([
            'department_id' => $this->department1->id,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        Project::factory()->count(3)->create([
            'department_id' => $this->department1->id,
            'status' => 'in_progress',
        ]);
        Project::factory()->count(2)->create([
            'department_id' => $this->department2->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/dashboard/stats');

        $response->assertStatus(200)
            ->assertJsonPath('projects.total', 5)
            ->assertJsonPath('projects.active', 3)
            ->assertJsonPath('projects.completed', 2);
    }

    /**
     * Admin يرى إحصائيات قسمه فقط
     */
    public function test_admin_sees_only_department_stats(): void
    {
        $admin = User::factory()->create([
            'department_id' => $this->department1->id,
            'is_active' => true,
        ]);
        // Engine cutover: do NOT assignRole('admin') — its org-scoped definition
        // has is_admin_role=true and grants projects.view org-wide. We want to
        // exercise the per-department scope branch of UserProjectScope, so the
        // user has ONLY the engine grant below at department scope. Grant the
        // dashboard route permission directly (the route uses Spatie
        // `view_dashboard`).
        $admin->givePermissionTo(Permission::VIEW_DASHBOARD->value);
        $this->grantEngineCapability(
            $admin,
            Capability::PROJECTS_VIEW,
            'department',
            $this->department1->id
        );

        Project::factory()->count(3)->create([
            'department_id' => $this->department1->id,
            'status' => 'in_progress',
        ]);
        Project::factory()->count(2)->create([
            'department_id' => $this->department2->id,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/dashboard/stats');

        $response->assertStatus(200)
            ->assertJsonPath('projects.total', 3);
    }

    /**
     * أحدث المشاريع تعمل بشكل صحيح
     */
    public function test_recent_projects_returns_correct_data(): void
    {
        $user = User::factory()->create([
            'department_id' => $this->department1->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        Project::factory()->count(7)->create([
            'department_id' => $this->department1->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/recent-projects');

        $response->assertStatus(200);
        // يجب أن تعيد 5 مشاريع فقط (limit)
        $this->assertLessThanOrEqual(5, count($response->json()));
    }

    /**
     * المهام المتأخرة تُرجع المهام الصحيحة
     */
    public function test_overdue_tasks_returns_overdue_only(): void
    {
        $user = User::factory()->create([
            'department_id' => $this->department1->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        $project = Project::factory()->create([
            'department_id' => $this->department1->id,
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

        // مهام مكتملة (لا تحسب كمتأخرة)
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'completed',
            'due_date' => now()->subDays(5),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/overdue-tasks');

        $response->assertStatus(200);
        $this->assertEquals(3, count($response->json()));
    }

    /**
     * مهامي القادمة تعمل بشكل صحيح
     */
    public function test_my_upcoming_tasks_returns_user_tasks(): void
    {
        $user = User::factory()->create([
            'department_id' => $this->department1->id,
            'is_active' => true,
        ]);
        $user->assignRole('member');

        $project = Project::factory()->create([
            'department_id' => $this->department1->id,
        ]);

        // مهام المستخدم
        Task::factory()->count(3)->create([
            'project_id' => $project->id,
            'assigned_to' => $user->id,
            'status' => 'in_progress',
            'due_date' => now()->addDays(5),
        ]);

        // مهام مستخدم آخر
        $otherUser = User::factory()->create();
        Task::factory()->count(2)->create([
            'project_id' => $project->id,
            'assigned_to' => $otherUser->id,
            'status' => 'in_progress',
            'due_date' => now()->addDays(5),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/my-upcoming-tasks');

        $response->assertStatus(200);
        $this->assertEquals(3, count($response->json()));
    }

    /**
     * إحصائيات المشاريع حسب الحالة
     */
    public function test_projects_by_status_returns_correct_counts(): void
    {
        $user = User::factory()->create([
            'department_id' => $this->department1->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        Project::factory()->count(2)->create([
            'department_id' => $this->department1->id,
            'status' => 'draft',
        ]);
        Project::factory()->count(3)->create([
            'department_id' => $this->department1->id,
            'status' => 'in_progress',
        ]);
        Project::factory()->count(1)->create([
            'department_id' => $this->department1->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/projects-by-status');

        $response->assertStatus(200)
            ->assertJsonPath('draft', 2)
            ->assertJsonPath('in_progress', 3)
            ->assertJsonPath('completed', 1)
            ->assertJsonPath('on_hold', 0);
    }

    /**
     * الإحصائيات المتقدمة تعمل
     */
    public function test_advanced_stats_returns_data(): void
    {
        $user = User::factory()->create([
            'department_id' => $this->department1->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        Project::factory()->count(3)->create([
            'department_id' => $this->department1->id,
            'budget' => 100000,
            'actual_cost' => 80000,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/advanced-stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'avg_completion_time',
                'budget_summary' => [
                    'total_budget',
                    'total_actual',
                    'variance',
                ],
                'departments_performance',
                'overdue_projects',
                'monthly_trends',
            ]);
    }

    /**
     * المستخدم العادي يرى مشاريعه فقط في recent projects
     */
    public function test_normal_user_sees_only_own_projects(): void
    {
        $user = User::factory()->create([
            'department_id' => $this->department1->id,
            'is_active' => true,
        ]);
        // Engine cutover: any flat Spatie role grants either org-wide view_all
        // (legacy `member`) or org-wide projects.view via permissions (viewer).
        // Both bypass the per-project filter we want to exercise. We give the
        // user the dashboard route permission directly (the route uses Spatie
        // `view_dashboard`) and rely solely on the per-project scoped role for
        // visibility — exactly what production grants on a plain member would do.
        $user->givePermissionTo(Permission::VIEW_DASHBOARD->value);

        // مشروع المستخدم (دور سياقي manager بدل عمود manager_id)
        $ownProject = Project::factory()->create([
            'department_id' => $this->department1->id,
        ]);
        $user->assignProjectRole($ownProject, ScopedRole::PROJECT_MANAGER);

        // مشاريع أخرى
        Project::factory()->count(3)->create([
            'department_id' => $this->department1->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/recent-projects');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json()));
    }
}
