<?php

namespace Tests\Unit\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Policies\ProjectPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
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
        $this->grantCanonicalAdmin($adminUser);

        // مستخدم cross-org (بدون أي سياق في org A)
        $crossOrg = User::factory()->create(['organization_id' => $otherOrg->id]);
        $this->grantCanonicalViewer($crossOrg);

        $this->assertTrue($this->policy->viewAny($adminUser));
        $this->assertTrue($this->policy->create($adminUser));
        $this->assertTrue($this->policy->view($adminUser, $project));
        $this->assertTrue($this->policy->update($adminUser, $project));
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
        $this->grantCanonicalAdmin($adminSameDepartment);

        // An admin is an organization-wide role (CEO-level): it is NOT scoped
        // to a department, so an admin in any department sees and manages every
        // project in the organization (granted via the org functional-role
        // bridge). Department managers — a different role — are the ones bound
        // to their department.
        $adminInOtherDepartment = User::factory()->create(['organization_id' => $org->id, 'department_id' => $otherDepartment->id]);
        $this->grantCanonicalAdmin($adminInOtherDepartment);

        // عضو بدور PROJECT_MEMBER فقط (view only via engine)
        $member = User::factory()->create(['organization_id' => $org->id, 'department_id' => $otherDepartment->id]);
        $this->grantCanonicalViewer($member);
        $project->members()->attach($member->id, ['role' => 'member', 'scope_type' => 'project']);
        $this->assignCanonicalRole($member, 'project_member', 'project', (int) $project->id);

        $this->assertTrue($this->policy->view($adminSameDepartment, $project));
        $this->assertTrue($this->policy->update($adminSameDepartment, $project));
        $this->assertTrue($this->policy->delete($adminSameDepartment, $project));
        $this->assertTrue($this->policy->restore($adminSameDepartment, $project));
        $this->assertTrue($this->policy->assignProjectRoles($adminSameDepartment, $project));

        // admin is organization-wide, so a different department is no barrier.
        $this->assertTrue($this->policy->view($adminInOtherDepartment, $project));
        $this->assertTrue($this->policy->update($adminInOtherDepartment, $project));
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
