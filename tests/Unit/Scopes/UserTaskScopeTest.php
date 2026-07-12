<?php

namespace Tests\Unit\Scopes;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Scopes\UserProjectScope;
use App\Modules\Projects\Scopes\UserTaskScope;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * اختبارات UserTaskScope — النطاق مقاد بمحرك AuthZ الموحّد (Wave 4-7).
 *
 * السلّم (all > department > own) يُمنح عبر Capability::TASKS_VIEW على النطاق
 * المناسب (organization = الكل / department = قسمه + شجرته / project = ارتباط
 * مباشر بمشروع). الصلاحيات المسطّحة view_tasks / view_department_tasks /
 * view_own_tasks مُنحت Wave 4-7 (مهاجَرة بالكامل).
 */
class UserTaskScopeTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected UserTaskScope $scope;

    protected Department $department1;

    protected Department $department2;

    protected Project $project1;

    protected Project $project2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->scope = new UserTaskScope(new UserProjectScope);
        $this->department1 = Department::factory()->create(['name' => 'قسم 1']);
        $this->department2 = Department::factory()->create(['name' => 'قسم 2']);

        $this->project1 = Project::factory()->create(['department_id' => $this->department1->id]);
        $this->project2 = Project::factory()->create(['department_id' => $this->department2->id]);
    }

    public function test_super_admin_sees_all_tasks(): void
    {
        $superAdmin = User::factory()->create(['department_id' => $this->department1->id]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        Task::factory()->count(3)->create(['project_id' => $this->project1->id]);
        Task::factory()->count(2)->create(['project_id' => $this->project2->id]);

        $query = Task::query();
        $this->scope->apply($query, $superAdmin);

        $this->assertEquals(5, $query->count());
    }

    public function test_department_scope_grant_sees_only_department_tasks(): void
    {
        $admin = User::factory()->create(['department_id' => $this->department1->id]);
        $this->grantEngineCapability($admin, Capability::TASKS_VIEW, 'department', $this->department1->id);

        Task::factory()->count(3)->create(['project_id' => $this->project1->id]);
        Task::factory()->count(2)->create(['project_id' => $this->project2->id]);

        $query = Task::query();
        $this->scope->apply($query, $admin);

        $this->assertEquals(3, $query->count());
    }

    public function test_project_scope_grant_sees_only_direct_tasks(): void
    {
        $user = User::factory()->create(['department_id' => $this->department1->id]);
        // منح المحرك على مستوى project لا يوسّع رؤية المهام، فقط الارتباط المباشر بالمهمة
        $this->grantEngineCapability($user, Capability::TASKS_VIEW, 'project');

        Task::factory()->create(['project_id' => $this->project1->id, 'assigned_to' => $user->id]);
        Task::factory()->create(['project_id' => $this->project1->id, 'created_by' => $user->id]);
        Task::factory()->count(3)->create(['project_id' => $this->project1->id]);

        $query = Task::query();
        $this->scope->apply($query, $user);

        $this->assertEquals(2, $query->count());
    }

    public function test_project_scope_grant_also_sees_managed_project_tasks(): void
    {
        $user = User::factory()->create(['department_id' => $this->department1->id]);
        $this->grantEngineCapability($user, Capability::TASKS_VIEW, 'project');

        // مشروع يديره بدور سياقي (في قسم آخر)
        $managedProject = Project::factory()->create(['department_id' => $this->department2->id]);
        $this->assignCanonicalRole($user, 'project_manager', 'project', (int) $managedProject->id);

        Task::factory()->count(2)->create(['project_id' => $managedProject->id]);
        Task::factory()->count(3)->create(['project_id' => $this->project1->id]);

        $query = Task::query();
        $this->scope->apply($query, $user);

        // 2 (مشروعه المُدار) + 0 مباشرة = 2
        $this->assertEquals(2, $query->count());
    }

    public function test_no_view_permission_sees_nothing(): void
    {
        $user = User::factory()->create(['department_id' => $this->department1->id]);

        Task::factory()->count(3)->create(['project_id' => $this->project1->id]);

        $query = Task::query();
        $this->scope->apply($query, $user);

        $this->assertEquals(0, $query->count());
    }

    public function test_apply_via_project_respects_project_scope_grant(): void
    {
        $user = User::factory()->create(['department_id' => $this->department1->id]);
        // projects.view على مستوى project = رؤية مشاريع الارتباطات المباشرة فقط
        $this->grantEngineCapability($user, Capability::PROJECTS_VIEW, 'project');

        // مشروع أنشأه المستخدم
        $userProject = Project::factory()->create([
            'department_id' => $this->department2->id,
            'created_by' => $user->id,
        ]);

        Task::factory()->count(2)->create(['project_id' => $userProject->id]);
        Task::factory()->count(3)->create(['project_id' => $this->project2->id]);

        $query = Task::query();
        $this->scope->applyViaProject($query, $user);

        $this->assertEquals(2, $query->count());
    }
}
