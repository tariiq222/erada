<?php

namespace Tests\Unit\Traits;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اختبارات HasScopedRoles Trait
 *
 * تتحقق من:
 * - أدوار المشاريع (HasProjectRoles)
 * - أدوار الأقسام (HasDepartmentRoles)
 * - تعيين وإزالة الأدوار
 */
class HasScopedRolesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();
        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);
    }

    // ========== اختبارات أدوار المشاريع ==========

    /**
     * تعيين دور في مشروع
     */
    public function test_assign_project_role(): void
    {
        $role = $this->user->assignProjectRole($this->project, ScopedRole::PROJECT_MANAGER);

        $this->assertNotNull($role);
        $this->assertEquals(ScopedRole::PROJECT_MANAGER, $role->role);
        $this->assertEquals(ScopedRole::SCOPE_PROJECT, $role->scope_type);
        $this->assertEquals($this->project->id, $role->scope_id);
    }

    /**
     * الحصول على دور المستخدم في مشروع
     */
    public function test_role_in_project(): void
    {
        $this->user->assignProjectRole($this->project, ScopedRole::PROJECT_MEMBER);

        $role = $this->user->roleInProject($this->project);

        $this->assertEquals(ScopedRole::PROJECT_MEMBER, $role);
    }

    /**
     * لا يوجد دور في مشروع
     */
    public function test_role_in_project_returns_null_when_no_role(): void
    {
        $role = $this->user->roleInProject($this->project);

        $this->assertNull($role);
    }

    /**
     * هل لديه دور في المشروع
     */
    public function test_has_role_in_project(): void
    {
        $this->user->assignProjectRole($this->project, ScopedRole::PROJECT_MANAGER);

        $this->assertTrue($this->user->hasRoleInProject($this->project));
        $this->assertTrue($this->user->hasRoleInProject($this->project, ScopedRole::PROJECT_MANAGER));
        $this->assertFalse($this->user->hasRoleInProject($this->project, ScopedRole::PROJECT_MEMBER));
    }

    /**
     * مدير المشروع يحمل دور manager ويطابق hasRoleInProject(manager).
     */
    public function test_project_manager_has_manager_role(): void
    {
        $this->user->assignProjectRole($this->project, ScopedRole::PROJECT_MANAGER);

        $this->assertTrue($this->user->hasRoleInProject($this->project, ScopedRole::PROJECT_MANAGER));
        $this->assertEquals(ScopedRole::PROJECT_MANAGER, $this->user->roleInProject($this->project));
    }

    /**
     * عضو المشروع يحمل دور member فقط ولا يُعتبر مديراً.
     */
    public function test_project_member_is_not_manager(): void
    {
        $this->user->assignProjectRole($this->project, ScopedRole::PROJECT_MEMBER);

        $this->assertFalse($this->user->hasRoleInProject($this->project, ScopedRole::PROJECT_MANAGER));
        $this->assertTrue($this->user->hasRoleInProject($this->project));
        $this->assertEquals(ScopedRole::PROJECT_MEMBER, $this->user->roleInProject($this->project));
    }

    /**
     * إزالة دور من مشروع
     */
    public function test_revoke_project_role(): void
    {
        $this->user->assignProjectRole($this->project, ScopedRole::PROJECT_MANAGER);
        $this->assertTrue($this->user->hasRoleInProject($this->project));

        $this->user->revokeProjectRole($this->project);

        $this->assertFalse($this->user->hasRoleInProject($this->project));
    }

    /**
     * الحصول على مشاريع المستخدم
     */
    public function test_get_projects_with_roles(): void
    {
        $project2 = Project::factory()->create();
        $this->user->assignProjectRole($this->project, ScopedRole::PROJECT_MANAGER);
        $this->user->assignProjectRole($project2, ScopedRole::PROJECT_MEMBER);

        $projects = $this->user->getProjectsWithRoles();

        $this->assertCount(2, $projects);
        $this->assertTrue($projects->contains($this->project));
        $this->assertTrue($projects->contains($project2));
    }

    // ========== اختبارات أدوار الأقسام ==========

    /**
     * تعيين دور في قسم
     */
    public function test_assign_department_role(): void
    {
        $role = $this->user->assignDepartmentRole($this->department, ScopedRole::DEPARTMENT_MANAGER);

        $this->assertNotNull($role);
        $this->assertEquals(ScopedRole::DEPARTMENT_MANAGER, $role->role);
        $this->assertEquals(ScopedRole::SCOPE_DEPARTMENT, $role->scope_type);
    }

    /**
     * الحصول على دور المستخدم في قسم
     */
    public function test_role_in_department(): void
    {
        $this->user->assignDepartmentRole($this->department, ScopedRole::DEPARTMENT_SUPERVISOR);

        $role = $this->user->roleInDepartment($this->department);

        $this->assertEquals(ScopedRole::DEPARTMENT_SUPERVISOR, $role);
    }

    /**
     * هل هو مدير القسم
     */
    public function test_is_department_manager(): void
    {
        $this->user->assignDepartmentRole($this->department, ScopedRole::DEPARTMENT_MANAGER);

        $this->assertTrue($this->user->isDepartmentManager($this->department));
        // التحقق من الدور مباشرة
        $this->assertEquals(ScopedRole::DEPARTMENT_MANAGER, $this->user->roleInDepartment($this->department));
    }

    /**
     * إزالة دور من قسم
     */
    public function test_revoke_department_role(): void
    {
        $this->user->assignDepartmentRole($this->department, ScopedRole::DEPARTMENT_MANAGER);
        $this->assertTrue($this->user->hasRoleInDepartment($this->department));

        $this->user->revokeDepartmentRole($this->department);

        $this->assertFalse($this->user->hasRoleInDepartment($this->department));
    }

    // ========== اختبارات عامة ==========

    /**
     * الحصول على جميع الأدوار السياقية
     */
    public function test_get_all_scoped_roles(): void
    {
        $this->user->assignProjectRole($this->project, ScopedRole::PROJECT_MANAGER);
        $this->user->assignDepartmentRole($this->department, ScopedRole::DEPARTMENT_SUPERVISOR);

        // التحقق من عدد الأدوار
        $projectRoles = $this->user->activeScopedRoles()
            ->ofType(ScopedRole::SCOPE_PROJECT)
            ->count();
        $departmentRoles = $this->user->activeScopedRoles()
            ->ofType(ScopedRole::SCOPE_DEPARTMENT)
            ->count();

        $this->assertEquals(1, $projectRoles);
        $this->assertEquals(1, $departmentRoles);
    }

    /**
     * تعيين دور جديد يحذف القديم
     */
    public function test_assigning_new_role_replaces_old(): void
    {
        $this->user->assignProjectRole($this->project, ScopedRole::PROJECT_MEMBER);
        $this->assertEquals(ScopedRole::PROJECT_MEMBER, $this->user->roleInProject($this->project));

        $this->user->assignProjectRole($this->project, ScopedRole::PROJECT_MANAGER);
        $this->assertEquals(ScopedRole::PROJECT_MANAGER, $this->user->roleInProject($this->project));

        // يجب أن يكون هناك دور واحد فقط
        $count = $this->user->activeScopedRoles()
            ->inScope(ScopedRole::SCOPE_PROJECT, $this->project->id)
            ->count();
        $this->assertEquals(1, $count);
    }
}
