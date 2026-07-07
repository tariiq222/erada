<?php

namespace Tests\Unit\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Policies\ProjectPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectPolicyTopUpTest extends TestCase
{
    use DatabaseTransactions;

    private ProjectPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->policy = new ProjectPolicy;

        Cache::flush();
        $this->seedProjectScopeDefinitions();
        $this->seedOrgScopeDefinitions();
    }

    /**
     * ينشئ ScopeType=project وتعريفات الأدوار للمحرّك.
     */
    private function seedProjectScopeDefinitions(): void
    {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_PROJECT],
            [
                'label_ar' => 'مشروع',
                'label_en' => 'Project',
                'model_class' => Project::class,
                'supports_hierarchy' => true,
                'supports_expiry' => false,
                'is_active' => true,
                'sort_order' => 10,
            ]
        );

        $now = now();

        $definitions = [
            [
                'name' => 'project_manager',
                'display_name' => 'Project Manager',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'level' => 1,
                'scope_type_id' => $scopeType->id,
                'role_key' => ScopedRole::PROJECT_MANAGER,
                'label_ar' => 'مدير المشروع',
                'label_en' => 'Project Manager',
                'is_admin_role' => false,
                'permissions' => json_encode(['projects.view', 'projects.edit', 'projects.manage_members', 'projects.assign_roles']),
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'project_member',
                'display_name' => 'Project Member',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'level' => 2,
                'scope_type_id' => $scopeType->id,
                'role_key' => ScopedRole::PROJECT_MEMBER,
                'label_ar' => 'عضو',
                'label_en' => 'Member',
                'is_admin_role' => false,
                'permissions' => json_encode(['projects.view']),
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'project_viewer',
                'display_name' => 'Project Viewer',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'level' => 3,
                'scope_type_id' => $scopeType->id,
                'role_key' => ScopedRole::PROJECT_VIEWER,
                'label_ar' => 'مشاهد',
                'label_en' => 'Viewer',
                'is_admin_role' => false,
                'permissions' => json_encode(['projects.view']),
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($definitions as $def) {
            $exists = DB::table('scoped_role_definitions')
                ->where('name', $def['name'])
                ->where('scope_type', $def['scope_type'])
                ->exists();

            if (! $exists) {
                DB::table('scoped_role_definitions')->insert($def);
            }
        }

        Cache::flush();
    }

    /**
     * ينشئ ScopeType=organization وتعريف دور admin (is_admin_role=true).
     */
    private function seedOrgScopeDefinitions(): void
    {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_ORGANIZATION],
            [
                'label_ar' => 'المؤسسة',
                'label_en' => 'Organization',
                'model_class' => 'App\\Modules\\Core\\Models\\Organization',
                'supports_hierarchy' => false,
                'supports_expiry' => false,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        $now = now();

        $exists = DB::table('scoped_role_definitions')
            ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
            ->where('role_key', 'admin')
            ->exists();

        if (! $exists) {
            DB::table('scoped_role_definitions')->insert([
                'name' => 'organization_admin',
                'display_name' => 'Admin',
                'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
                'level' => 1,
                'scope_type_id' => $scopeType->id,
                'role_key' => 'admin',
                'label_ar' => 'مدير إدارة',
                'label_en' => 'Admin',
                'is_admin_role' => true,
                'permissions' => null,
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Cache::flush();
    }

    /**
     * يُسند للمستخدم دوراً سياقياً على مستوى المؤسسة (admin).
     */
    private function grantOrgAdminScopedRole(User $user): void
    {
        if ($user->organization_id === null) {
            return;
        }

        $exists = DB::table('model_has_scoped_roles')
            ->where('user_id', $user->id)
            ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
            ->where('scope_id', $user->organization_id)
            ->exists();

        if (! $exists) {
            DB::table('model_has_scoped_roles')->insert([
                'user_id' => $user->id,
                'role' => 'admin',
                'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
                'scope_id' => $user->organization_id,
                'inherit_to_children' => true,
                'granted_by' => null,
                'expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::flush();
    }

    /**
     * يتحقق من أن مستخدماً في نفس المؤسسة بدور سياقي admin يستطيع إجراء عمليات المشاريع
     * بينما مستخدم cross-org لا يستطيع.
     *
     * مع engine=ON: الصلاحية تأتي من org-scoped admin role (is_admin_role=true),
     * لا من Spatie flat permissions. العزل عبر org isolation في المحرّك.
     */
    public function test_system_permissions_allow_project_actions_only_inside_organization_scope(): void
    {
        [$org, $department, $project] = $this->projectFixture();
        $otherOrg = Organization::factory()->create();

        // مستخدم في نفس المؤسسة مع org-scoped admin role
        $adminUser = User::factory()->create(['organization_id' => $org->id, 'department_id' => $department->id]);
        $adminUser->assignRole('admin');
        $this->grantOrgAdminScopedRole($adminUser);

        // مستخدم cross-org (بدون أي سياق في org A)
        $crossOrg = User::factory()->create(['organization_id' => $otherOrg->id]);
        $crossOrg->assignRole('viewer');

        $this->assertTrue($this->policy->viewAny($adminUser));
        $this->assertTrue($this->policy->create($adminUser));
        $this->assertTrue($this->policy->view($adminUser, $project));
        $this->assertTrue($this->policy->update($adminUser, $project));
        $this->assertTrue($this->policy->manageMembers($adminUser, $project));
        $this->assertTrue($this->policy->assignProjectRoles($adminUser, $project));

        $this->assertFalse($this->policy->view($crossOrg, $project));
        $this->assertFalse($this->policy->update($crossOrg, $project));
        $this->assertFalse($this->policy->delete($crossOrg, $project));
        $this->assertFalse($this->policy->assignProjectRoles($crossOrg, $project));
    }

    /**
     * يتحقق من محاور admin (نفس/مختلف القسم) وعضوية المشروع على العرض والتعديل والحذف.
     *
     * مع engine=ON: الحذف يتطلب org-scoped admin role (is_admin_role=true + can_delete=true).
     * adminSameDepartment يحمل org-scoped role → يستطيع كل شيء.
     * adminOtherDepartment بدون org-scoped role → يُرفض.
     * member بدور PROJECT_MEMBER (is_admin_role=false) → view فقط.
     */
    public function test_admin_department_axis_and_project_membership_control_view_update_delete_and_restore(): void
    {
        [$org, $department, $project] = $this->projectFixture();
        $otherDepartment = Department::factory()->create(['organization_id' => $org->id]);

        // admin بدور org-scoped admin (يستطيع كل شيء في نفس المؤسسة)
        $adminSameDepartment = User::factory()->create(['organization_id' => $org->id, 'department_id' => $department->id]);
        $adminSameDepartment->assignRole('admin');
        $this->grantOrgAdminScopedRole($adminSameDepartment);

        // An admin is an organization-wide role (CEO-level): it is NOT scoped
        // to a department, so an admin in any department sees and manages every
        // project in the organization (granted via the org functional-role
        // bridge). Department managers — a different role — are the ones bound
        // to their department.
        $adminInOtherDepartment = User::factory()->create(['organization_id' => $org->id, 'department_id' => $otherDepartment->id]);
        $adminInOtherDepartment->assignRole('admin');

        // عضو بدور PROJECT_MEMBER فقط (view only via engine)
        $member = User::factory()->create(['organization_id' => $org->id, 'department_id' => $otherDepartment->id]);
        $member->assignRole('viewer');
        $project->members()->attach($member->id, ['role' => 'member', 'scope_type' => ScopedRole::SCOPE_PROJECT]);
        $member->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        $this->assertTrue($this->policy->view($adminSameDepartment, $project));
        $this->assertTrue($this->policy->update($adminSameDepartment, $project));
        $this->assertTrue($this->policy->delete($adminSameDepartment, $project));
        $this->assertTrue($this->policy->restore($adminSameDepartment, $project));
        $this->assertTrue($this->policy->manageMembers($adminSameDepartment, $project));
        $this->assertTrue($this->policy->assignProjectRoles($adminSameDepartment, $project));

        // admin is organization-wide, so a different department is no barrier.
        $this->assertTrue($this->policy->view($adminInOtherDepartment, $project));
        $this->assertTrue($this->policy->update($adminInOtherDepartment, $project));
        $this->assertTrue($this->policy->manageMembers($adminInOtherDepartment, $project));
        $this->assertTrue($this->policy->assignProjectRoles($adminInOtherDepartment, $project));
        $this->assertTrue($this->policy->view($member, $project));
        $this->assertFalse($this->policy->update($member, $project));
        $this->assertFalse($this->policy->forceDelete($adminSameDepartment, $project));
    }

    private function projectFixture(): array
    {
        $org = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create(['organization_id' => $org->id, 'department_id' => $department->id]);

        return [$org, $department, $project];
    }
}
