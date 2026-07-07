<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectSetting;
use App\Modules\Projects\Scopes\UserProjectScope;
use App\Modules\Projects\Services\ProjectAuthorizationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Governed project creation + visibility.
 *
 * Rules under test:
 *  - Own-department path: a department manager/member may create projects (any
 *    type) only for their own department subtree, not for an unrelated department.
 *  - Governing-department path: a member of the governing department for a type
 *    may create that type for ANY department, and sees every project of that type
 *    org-wide. The PMO (governor of 'development') oversees the whole portfolio.
 *  - A higher-level manager covers their whole department subtree.
 *  - Org isolation and the "no role -> nothing" floor are preserved.
 */
class ProjectCreationGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Department $parentDept;   // higher-level department (manager covers subtree)

    private Department $childDept;    // child of parentDept

    private Department $otherDept;    // unrelated department, same org

    private Department $qualityDept;  // governs 'improvement'

    private Department $pmoDept;      // governs 'development'

    private ProjectAuthorizationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $this->seedAdminScopedRoleDefinition();
        Cache::flush();

        $this->org = Organization::factory()->create();

        $this->parentDept = $this->makeDept('PARENT', null, Department::LEVEL_DEPARTMENT);
        $this->childDept = $this->makeDept('CHILD', $this->parentDept->id, Department::LEVEL_SECTION);
        $this->otherDept = $this->makeDept('OTHER', null, Department::LEVEL_DEPARTMENT);
        $this->qualityDept = $this->makeDept('QUALITY', null, Department::LEVEL_DEPARTMENT);
        $this->pmoDept = $this->makeDept('PMO', null, Department::LEVEL_DEPARTMENT);

        ProjectSetting::setGoverningDepartments([
            'improvement' => $this->qualityDept->id,
            'development' => $this->pmoDept->id,
        ]);

        $this->svc = app(ProjectAuthorizationService::class);
        Cache::flush();
    }

    private function seedAdminScopedRoleDefinition(): void
    {
        $orgScopeId = DB::table('scope_types')->where('key', 'organization')->value('id');

        $definition = ScopedRoleDefinition::firstOrNew([
            'scope_type_id' => $orgScopeId,
            'role_key' => 'admin',
        ]);

        $definition->forceFill([
            'name' => 'organization.admin',
            'display_name' => 'admin',
            'scope_type' => 'organization',
            'label_ar' => 'مدير إدارة',
            'label_en' => 'Admin',
            'permissions' => [
                Capability::SETTINGS_VIEW,
                Capability::SETTINGS_EDIT,
                Capability::SETTINGS_MANAGE,
            ],
            'is_admin_role' => true,
            'is_active' => true,
            'sort_order' => 10,
        ])->save();
    }

    private function makeDept(string $code, ?int $parentId, int $level): Department
    {
        return Department::factory()->create([
            'code' => $code.'-'.uniqid(),
            'organization_id' => $this->org->id,
            'parent_id' => $parentId,
            'level' => $level,
            'is_active' => true,
        ]);
    }

    private function makeUser(?int $deptId): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $deptId,
            'is_active' => true,
        ]);
    }

    private function withDeptRole(User $user, string $roleKey, Department $dept): User
    {
        $user->assignScopedRole($roleKey, ScopedRole::SCOPE_DEPARTMENT, $dept->id, null, true);
        Cache::flush();

        return $user;
    }

    private function makeProject(Department $dept, string $type): Project
    {
        return Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
            'type' => $type,
        ]);
    }

    /** @return list<int> */
    private function visibleProjectIds(User $user): array
    {
        $q = Project::query();
        (new UserProjectScope)->apply($q, $user);

        return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    // ===================== Creation: own-department path =====================

    public function test_department_member_can_create_for_own_department(): void
    {
        $member = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);

        $this->assertTrue($this->svc->canCreateAny($member));
        $this->assertTrue($this->svc->canCreate($member, 'improvement', $this->childDept->id));
        $this->assertTrue($this->svc->canCreate($member, 'development', $this->childDept->id));
    }

    public function test_department_member_cannot_create_for_unrelated_department(): void
    {
        $member = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);

        $this->assertFalse($this->svc->canCreate($member, 'improvement', $this->otherDept->id));
        $this->assertFalse($this->svc->canCreate($member, 'development', $this->otherDept->id));
    }

    public function test_higher_manager_can_create_across_their_subtree(): void
    {
        // Manager on the parent department, inheriting to children.
        $manager = $this->withDeptRole($this->makeUser($this->parentDept->id), 'dept_manager', $this->parentDept);

        $this->assertTrue($this->svc->canCreate($manager, 'development', $this->parentDept->id));
        $this->assertTrue($this->svc->canCreate($manager, 'development', $this->childDept->id), 'subtree child is allowed');
        $this->assertFalse($this->svc->canCreate($manager, 'development', $this->otherDept->id), 'outside subtree denied');
    }

    public function test_null_target_defaults_to_own_department(): void
    {
        $member = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);

        // No department supplied -> falls back to the user's home department.
        $this->assertTrue($this->svc->canCreate($member, 'improvement', null));
    }

    // ===================== Creation: governing-department path =====================

    public function test_quality_member_can_create_improvement_for_any_department(): void
    {
        $quality = $this->withDeptRole($this->makeUser($this->qualityDept->id), 'dept_member', $this->qualityDept);

        // Governs improvement -> may create improvement for an unrelated department.
        $this->assertTrue($this->svc->canCreate($quality, 'improvement', $this->otherDept->id));
        // But does NOT govern 'development' -> cannot create a new project for a foreign dept.
        $this->assertFalse($this->svc->canCreate($quality, 'development', $this->otherDept->id));
    }

    public function test_pmo_member_can_create_new_for_any_department(): void
    {
        $pmo = $this->withDeptRole($this->makeUser($this->pmoDept->id), 'dept_member', $this->pmoDept);

        $this->assertTrue($this->svc->canCreate($pmo, 'development', $this->otherDept->id));
    }

    // ===================== Creatable-departments picker =====================

    public function test_creatable_departments_for_regular_member_is_own_subtree(): void
    {
        $member = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);

        $ids = $this->svc->creatableDepartmentIds($member, 'improvement');
        $this->assertEqualsCanonicalizing([$this->childDept->id], $ids);
    }

    public function test_creatable_departments_for_governing_member_is_unrestricted_for_its_type(): void
    {
        $quality = $this->withDeptRole($this->makeUser($this->qualityDept->id), 'dept_member', $this->qualityDept);

        // Governs improvement -> any department (null).
        $this->assertNull($this->svc->creatableDepartmentIds($quality, 'improvement'));
        // Does not govern 'development' -> restricted to own subtree.
        $this->assertEqualsCanonicalizing([$this->qualityDept->id], $this->svc->creatableDepartmentIds($quality, 'development'));
    }

    public function test_creatable_departments_empty_for_user_without_role(): void
    {
        $outsider = $this->makeUser($this->otherDept->id);

        $this->assertSame([], $this->svc->creatableDepartmentIds($outsider, 'improvement'));
    }

    // ===================== The "no role" floor =====================

    public function test_user_without_any_role_cannot_create(): void
    {
        $outsider = $this->makeUser($this->otherDept->id); // no scoped role at all

        $this->assertFalse($this->svc->canCreateAny($outsider));
        $this->assertFalse($this->svc->canCreate($outsider, 'improvement', $this->otherDept->id));
    }

    // ===================== Visibility =====================

    public function test_regular_department_member_sees_only_own_department_projects(): void
    {
        $member = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);

        $own = $this->makeProject($this->childDept, 'improvement');
        $foreign = $this->makeProject($this->otherDept, 'improvement');

        $visible = $this->visibleProjectIds($member);
        $this->assertContains($own->id, $visible);
        $this->assertNotContains($foreign->id, $visible);
    }

    public function test_quality_member_sees_all_improvement_projects_org_wide(): void
    {
        $quality = $this->withDeptRole($this->makeUser($this->qualityDept->id), 'dept_member', $this->qualityDept);

        $improvementElsewhere = $this->makeProject($this->otherDept, 'improvement');
        $newElsewhere = $this->makeProject($this->otherDept, 'development');

        $this->assertEquals(['improvement'], $this->svc->governedTypes($quality));

        $visible = $this->visibleProjectIds($quality);
        $this->assertContains($improvementElsewhere->id, $visible, 'governs improvement org-wide');
        $this->assertNotContains($newElsewhere->id, $visible, 'does not govern new');
    }

    public function test_pmo_member_sees_all_projects_org_wide(): void
    {
        $pmo = $this->withDeptRole($this->makeUser($this->pmoDept->id), 'dept_member', $this->pmoDept);

        $improvementElsewhere = $this->makeProject($this->otherDept, 'improvement');
        $newElsewhere = $this->makeProject($this->otherDept, 'development');

        $visible = $this->visibleProjectIds($pmo);
        $this->assertContains($improvementElsewhere->id, $visible);
        $this->assertContains($newElsewhere->id, $visible);
    }

    public function test_higher_manager_sees_child_department_projects(): void
    {
        $manager = $this->withDeptRole($this->makeUser($this->parentDept->id), 'dept_manager', $this->parentDept);

        $childProject = $this->makeProject($this->childDept, 'development');
        $foreign = $this->makeProject($this->otherDept, 'development');

        $visible = $this->visibleProjectIds($manager);
        $this->assertContains($childProject->id, $visible, 'subtree visibility');
        $this->assertNotContains($foreign->id, $visible);
    }

    // ===================== Admin governing-department endpoints =====================

    public function test_admin_can_read_and_update_governing_departments(): void
    {
        $admin = $this->makeUser($this->parentDept->id);
        $admin->assignRole('admin');
        Cache::flush();

        $this->actingAs($admin)
            ->getJson('/api/projects/governing-departments')
            ->assertOk()
            ->assertJsonStructure(['types', 'mapping', 'departments']);

        $this->actingAs($admin)
            ->withHeader('X-Skip-Csrf', '1')
            ->putJson('/api/projects/governing-departments', [
                'mapping' => [
                    'improvement' => $this->otherDept->id,
                    'development' => $this->pmoDept->id,
                ],
            ])
            ->assertOk();

        $this->assertSame($this->otherDept->id, ProjectSetting::getGoverningDepartmentForType('improvement'));
        $this->assertSame($this->pmoDept->id, ProjectSetting::getGoverningDepartmentForType('development'));
    }

    public function test_non_admin_cannot_update_governing_departments(): void
    {
        $member = $this->withDeptRole($this->makeUser($this->childDept->id), 'dept_member', $this->childDept);

        $this->actingAs($member)
            ->withHeader('X-Skip-Csrf', '1')
            ->putJson('/api/projects/governing-departments', [
                'mapping' => ['improvement' => $this->childDept->id],
            ])
            ->assertForbidden();
    }

    public function test_cross_org_projects_are_never_visible(): void
    {
        $pmo = $this->withDeptRole($this->makeUser($this->pmoDept->id), 'dept_member', $this->pmoDept);

        $otherOrg = Organization::factory()->create();
        $otherOrgDept = Department::factory()->create([
            'organization_id' => $otherOrg->id,
            'level' => Department::LEVEL_DEPARTMENT,
        ]);
        $foreignOrgProject = Project::factory()->create([
            'organization_id' => $otherOrg->id,
            'department_id' => $otherOrgDept->id,
            'type' => 'improvement',
        ]);

        $this->assertNotContains($foreignOrgProject->id, $this->visibleProjectIds($pmo));
    }
}
