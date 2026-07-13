<?php

namespace Tests\Unit\Scopes;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Scopes\UserProjectScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * اختبارات UserProjectScope — النطاق مقاد بمحرك AuthZ الموحّد (Wave 4-7).
 *
 * السلّم (all > department > own) صار يُمنح عبر Capability::PROJECTS_VIEW على
 * النطاق المناسب (organization = الكل / department = القسم + شجرته / project =
 * الارتباطات المباشرة). الصلاحيات المسطّحة view_projects / view_department_projects
 * / view_own_projects مُنحت Wave 4-7 (مهاجَرة بالكامل).
 */
class UserProjectScopeTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected UserProjectScope $scope;

    protected Department $department1;

    protected Department $department2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->scope = new UserProjectScope;
        $this->department1 = Department::factory()->create(['name' => 'قسم 1']);
        $this->department2 = Department::factory()->create(['name' => 'قسم 2']);
    }

    public function test_super_admin_sees_all_projects(): void
    {
        $superAdmin = User::factory()->create(['department_id' => $this->department1->id]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        Project::factory()->count(3)->create(['department_id' => $this->department1->id]);
        Project::factory()->count(2)->create(['department_id' => $this->department2->id]);

        $query = Project::query();
        $this->scope->apply($query, $superAdmin);

        $this->assertEquals(5, $query->count());
    }

    public function test_org_scope_grant_sees_all_projects(): void
    {
        $user = User::factory()->create(['department_id' => $this->department1->id]);
        $this->grantEngineCapability($user, Capability::PROJECTS_VIEW, 'organization', $user->organization_id);

        Project::factory()->count(3)->create(['department_id' => $this->department1->id]);
        Project::factory()->count(2)->create(['department_id' => $this->department2->id]);

        $query = Project::query();
        $this->scope->apply($query, $user);

        $this->assertEquals(5, $query->count());
    }

    public function test_department_scope_grant_sees_only_department_projects(): void
    {
        $admin = User::factory()->create(['department_id' => $this->department1->id]);
        $this->grantEngineCapability($admin, Capability::PROJECTS_VIEW, 'department', $this->department1->id);

        Project::factory()->count(3)->create(['department_id' => $this->department1->id]);
        Project::factory()->count(2)->create(['department_id' => $this->department2->id]);

        $query = Project::query();
        $this->scope->apply($query, $admin);

        $this->assertEquals(3, $query->count());
    }

    public function test_project_scope_grant_sees_only_direct_projects(): void
    {
        $user = User::factory()->create(['department_id' => $this->department1->id]);
        // منح المحرك على مستوى project لمنح رؤية مشاريع الارتباطات المباشرة فقط
        $this->grantEngineCapability($user, Capability::PROJECTS_VIEW, 'project');

        // مشروع أنشأه (في قسم آخر — لا يُرى بالقسم بل بالملكية)
        Project::factory()->create([
            'created_by' => $user->id,
            'department_id' => $this->department2->id,
        ]);
        // مشروع عضو فيه (دور سياقي member)
        $memberProject = Project::factory()->create(['department_id' => $this->department2->id]);
        $this->assignCanonicalRole($user, 'project_member', 'project', (int) $memberProject->id);

        // مشاريع لا علاقة له بها
        Project::factory()->count(3)->create(['department_id' => $this->department1->id]);

        $query = Project::query();
        $this->scope->apply($query, $user);

        $this->assertEquals(2, $query->count());
    }

    public function test_no_view_permission_sees_nothing(): void
    {
        $user = User::factory()->create(['department_id' => $this->department1->id]);

        Project::factory()->count(3)->create(['department_id' => $this->department1->id]);

        $query = Project::query();
        $this->scope->apply($query, $user);

        $this->assertEquals(0, $query->count());
    }

    public function test_apply_simple_respects_department_scope(): void
    {
        $admin = User::factory()->create(['department_id' => $this->department1->id]);
        $this->grantEngineCapability($admin, Capability::PROJECTS_VIEW, 'department', $this->department1->id);

        Project::factory()->count(2)->create(['department_id' => $this->department1->id]);
        Project::factory()->count(3)->create(['department_id' => $this->department2->id]);

        $query = Project::query();
        $this->scope->applySimple($query, $admin);

        $this->assertEquals(2, $query->count());
    }
}
