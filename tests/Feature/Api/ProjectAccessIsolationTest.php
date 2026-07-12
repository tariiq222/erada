<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * عزل الوصول للمشاريع — P0-05 (Org Isolation) / P0-07 / P0-08 (Scoped Roles)
 *
 * يثبت أن:
 * - صلاحية مستخدم في مشروع/منظمة A لا تمنحه وصولاً لمشروع/منظمة B.
 * - الإسناد السياقي للأدوار محصور بالمشروع نفسه ولا يعبر المنظمات.
 *
 * تستخدم الاختبارات إسنادات canonical محددة على المشروع.
 */
class ProjectAccessIsolationTest extends TestCase
{
    use DatabaseTransactions;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);

        Cache::flush();
    }

    private function makeUser(Organization $org, Department $dept, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        if ($role) {
            $this->assignCanonicalRole($user, $role);
        }

        return $user;
    }

    private function makeProject(Organization $org, Department $dept, ?User $manager = null): Project
    {
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        // المدير يُمثَّل كدور سياقي (scoped role) لا كعمود manager_id
        if ($manager) {
            $this->assignCanonicalRole($manager, 'project_manager', 'project', $project->id);
        }

        return $project;
    }

    /** P0-05: admin لا يرى مشروع منظمة أخرى (لا تسريب). */
    public function test_cross_org_admin_cannot_show_other_org_project(): void
    {
        $adminA = $this->makeUser($this->orgA, $this->deptA, 'admin');
        $projectB = $this->makeProject($this->orgB, $this->deptB);

        $response = $this->actingAs($adminA, 'sanctum')
            ->getJson("/api/projects/{$projectB->id}");

        $this->assertContains($response->status(), [403, 404], 'يجب منع رؤية مشروع منظمة أخرى');
    }

    /** P0-05: admin لا يرى أعضاء مشروع منظمة أخرى. */
    public function test_cross_org_admin_cannot_list_other_org_project_members(): void
    {
        $adminA = $this->makeUser($this->orgA, $this->deptA, 'admin');
        $projectB = $this->makeProject($this->orgB, $this->deptB);

        $response = $this->actingAs($adminA, 'sanctum')
            ->getJson("/api/projects/{$projectB->id}/roles");

        $this->assertContains($response->status(), [403, 404], 'يجب منع رؤية أعضاء مشروع منظمة أخرى');
    }

    /**
     * P0-05 + P0-08 (حرج): admin من منظمة A يجب ألا يسند دوراً في مشروع منظمة B.
     * هذا متجه تصعيد صلاحيات عبر المنظمات.
     */
    public function test_cross_org_admin_cannot_assign_role_in_other_org_project(): void
    {
        $adminA = $this->makeUser($this->orgA, $this->deptA, 'admin');
        $projectB = $this->makeProject($this->orgB, $this->deptB);

        $response = $this->actingAs($adminA, 'sanctum')
            ->postJson("/api/projects/{$projectB->id}/roles", [
                'user_id' => $adminA->id,
                'role_id' => $this->roleId('project_manager'),
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('authorization_role_assignments', [
            'user_id' => $adminA->id,
            'scope_type' => 'project',
            'scope_id' => $projectB->id,
        ]);
    }

    /**
     * P0-08: دور سياقي (project manager) في مشروع A لا يمنح إسناد أدوار في مشروع B.
     */
    public function test_project_manager_of_a_cannot_assign_role_in_project_b(): void
    {
        $leader = $this->makeUser($this->orgA, $this->deptA);
        $projectA = $this->makeProject($this->orgA, $this->deptA);
        $projectB = $this->makeProject($this->orgA, $this->deptA);

        $this->assignCanonicalRole($leader, 'project_manager', 'project', $projectA->id);

        $target = $this->makeUser($this->orgA, $this->deptA);

        $response = $this->actingAs($leader, 'sanctum')
            ->postJson("/api/projects/{$projectB->id}/roles", [
                'user_id' => $target->id,
                'role_id' => $this->roleId('project_member'),
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('authorization_role_assignments', [
            'user_id' => $target->id,
            'scope_type' => 'project',
            'scope_id' => $projectB->id,
        ]);
    }

    /**
     * P0-07: عضو (member) في مشروع A لا يرى مشروع B (نفس المنظمة، مشروع مختلف).
     */
    public function test_member_of_project_a_cannot_view_project_b(): void
    {
        // نكتفي بالدور canonical على مشروع A لاختبار العزل داخل المؤسسة.
        $member = $this->makeUser($this->orgA, $this->deptA);
        // مدير مشروعه A (دور سياقي manager يمنح رؤية المشروع عبر members)
        $projectA = $this->makeProject($this->orgA, $this->deptA, $member);
        $projectB = $this->makeProject($this->orgA, $this->deptA);

        // يرى مشروعه
        $this->actingAs($member, 'sanctum')
            ->getJson("/api/projects/{$projectA->id}")
            ->assertStatus(200);

        // لا يرى المشروع الآخر في نفس المنظمة
        $response = $this->actingAs($member, 'sanctum')
            ->getJson("/api/projects/{$projectB->id}");

        $this->assertContains($response->status(), [403, 404], 'يجب منع مدير مشروع A من رؤية مشروع B');
    }

    private function roleId(string $name): int
    {
        return (int) AuthorizationRole::query()->where('name', $name)->valueOrFail('id');
    }
}
