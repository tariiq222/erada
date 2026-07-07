<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * عزل الوصول للمشاريع — P0-05 (Org Isolation) / P0-07 / P0-08 (Scoped Roles)
 *
 * يثبت أن:
 * - صلاحية مستخدم في مشروع/منظمة A لا تمنحه وصولاً لمشروع/منظمة B.
 * - الإسناد السياقي للأدوار محصور بالمشروع نفسه ولا يعبر المنظمات.
 *
 * ملاحظة Wave 4-7: محرّك AuthZ هو المسار الوحيد. `assignRole('viewer')` يمنح
 * صلاحية مسطّحة `projects.view` على مستوى المؤسسة (عبر scoped_role_definitions
 * للـ viewer)، لذا اختبارات العزل تتجنّب الأدوار المسطّحة وتستخدم أدواراً سياقية
 * على المشروع فقط.
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
        $this->seedProjectScopeDefinitions();
    }

    /**
     * ينشئ ScopeType=project وتعريفات الأدوار للمحرّك (engine=ON).
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
                'permissions' => json_encode($this->expandFlags(
                    ['projects.view', 'projects.edit', 'projects.manage_members', 'projects.assign_roles'],
                    ['can_manage_members' => true, 'can_edit' => true, 'can_delete' => false, 'can_view_all' => true]
                )),
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
                'permissions' => json_encode($this->expandFlags(['projects.view'], [
                    'can_manage_members' => false, 'can_edit' => false, 'can_delete' => false, 'can_view_all' => true,
                ])),
                'is_active' => true,
                'sort_order' => 2,
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

    private function makeUser(Organization $org, Department $dept, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        if ($role) {
            $user->assignRole($role);
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
            $manager->assignProjectRole($project, ScopedRole::PROJECT_MANAGER, $manager->id);
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
                'role' => ScopedRole::PROJECT_MANAGER,
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'user_id' => $adminA->id,
            'scope_type' => ScopedRole::SCOPE_PROJECT,
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

        $leader->assignProjectRole($projectA, ScopedRole::PROJECT_MANAGER, $leader->id);

        $target = $this->makeUser($this->orgA, $this->deptA);

        $response = $this->actingAs($leader, 'sanctum')
            ->postJson("/api/projects/{$projectB->id}/roles", [
                'user_id' => $target->id,
                'role' => ScopedRole::PROJECT_MEMBER,
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'user_id' => $target->id,
            'scope_type' => ScopedRole::SCOPE_PROJECT,
            'scope_id' => $projectB->id,
        ]);
    }

    /**
     * P0-07: عضو (member) في مشروع A لا يرى مشروع B (نفس المنظمة، مشروع مختلف).
     */
    public function test_member_of_project_a_cannot_view_project_b(): void
    {
        // Engine cutover: لا منح المستخدم دور `viewer` المسطّح (يمنح projects.view
        // على مستوى المؤسسة عبر scoped_role_definitions)، بل نكتفي بالدور السياقي
        // على مشروع A لاختبار العزل P0-07 بين المشاريع داخل نفس المؤسسة.
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

    /**
     * Expand legacy granular flags into the equivalent explicit permissions
     * (Phase 3, ADR-UNIFIED-ROLE-ACCESS — the flag columns were dropped from
     * scoped_role_definitions; the engine now reads permissions[] only).
     *
     * @param  array<int, string>  $permissions
     * @param  array<string, bool>  $flags
     * @return array<int, string>
     */
    private function expandFlags(array $permissions, array $flags): array
    {
        $byAction = fn (array $actions): array => array_values(array_filter(
            Capability::all(),
            function (string $c) use ($actions) {
                $a = str_contains($c, '.') ? substr($c, strrpos($c, '.') + 1) : $c;

                return in_array($a, $actions, true);
            }
        ));
        if (! empty($flags['can_edit'])) {
            $permissions = array_merge($permissions, $byAction(['edit', 'update']));
        }
        if (! empty($flags['can_delete'])) {
            $permissions = array_merge($permissions, $byAction(['delete', 'remove']));
        }
        if (! empty($flags['can_view_all'])) {
            $permissions = array_merge($permissions, $byAction(['view', 'view_all', 'view_reports']));
        }
        if (! empty($flags['can_manage_members'])) {
            $permissions = array_merge($permissions, $byAction(['manage_members', 'assign_roles']));
        }
        if (! empty($flags['can_view_confidential'])) {
            $permissions[] = Capability::OVR_VIEW_CONFIDENTIAL;
        }

        return array_values(array_unique($permissions));
    }
}
