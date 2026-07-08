<?php

namespace Tests\Unit\Policies;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Policies\ProjectPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * اختبارات وحدوية لـ ProjectPolicy
 *
 * تركز على ثلاثة محاور:
 * 1. الأدوار السياقية (PROJECT_MEMBER / PROJECT_VIEWER / PROJECT_MANAGER)
 * 2. عزل المؤسسة (sharesOrganization) لمستخدمي مؤسسات أخرى و null-org
 * 3. الفصل بين تعديل/إدارة من جهة، والحذف من جهة (الحذف للـ admin/super فقط)
 *
 * ملاحظات تشغيل:
 * - الاستدعاء المباشر `(new ProjectPolicy)->method(...)` يتجاوز `before()`.
 *   لذلك تُستخدم Gate::forUser فقط في اختبارات super_admin حيث نعتمد على
 *   التجاوز عبر before(). للاختبارات الأخرى نستخدم الاستدعاء المباشر +
 *   تأكيد مماثل عبر Gate::denies للتأكد من سلوك المستخدم النهائي.
 */
class ProjectPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        // إعادة البذر صراحةً لمطابقة نمط ProjectAuthorizationServiceTest حتى لو
        // كان TestCase الأساسي يبذر تلقائياً.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->department = Department::factory()->create();

        Cache::flush();
        $this->seedProjectScopeDefinitions();
        $this->seedOrgScopeDefinitions();
    }

    /**
     * أنشئ مستخدماً نشطاً ضمن مؤسسة/قسم محددين (الافتراضي: نفس قسم/مؤسسة المشروع).
     */
    private function makeUser(string $role = 'viewer', ?int $orgId = null, ?int $deptId = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $orgId ?? $this->department->organization_id,
            'department_id' => $deptId ?? $this->department->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * أنشئ مشروعاً في نفس مؤسسة/قسم التحضير الافتراضي.
     */
    private function makeProject(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'organization_id' => $this->department->organization_id,
            'department_id' => $this->department->id,
        ], $overrides));
    }

    /**
     * مستخدم بلا منظمة ولا قسم — لاختبارات D-02/D-04 (null-org isolation).
     */
    private function makeNullOrgUser(string $role = 'viewer'): User
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'department_id' => null,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * أنشئ بيئة مؤسسة "أخرى" (org B) كاملة مع قسم ومشروع.
     *
     * @return array{0: Organization, 1: Department, 2: Project}
     */
    private function makeOrgProject(): array
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        return [$org, $dept, $project];
    }

    /**
     * ينشئ ScopeType=project وتعريفات الأدوار manager/member/viewer للمحرّك.
     * مطابق لـ ProjectPolicyParityTest::seedProjectScopeDefinitions().
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
                'permissions' => json_encode([
                    'projects.view',
                    'projects.edit',
                    'projects.manage_members',
                    'projects.assign_roles',
                    'tasks.view',
                    'tasks.create',
                    'tasks.edit',
                    'tasks.delete',
                    'tasks.complete',
                ]),
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
                'permissions' => json_encode(['projects.view', 'tasks.view']),
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
                'permissions' => json_encode(['projects.view', 'tasks.view']),
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($definitions as $def) {
            $exists = DB::table('scoped_role_definitions')
                ->where('scope_type_id', $def['scope_type_id'])
                ->where('role_key', $def['role_key'])
                ->exists();

            if (! $exists) {
                DB::table('scoped_role_definitions')->insert($def);
            }
        }

        Cache::flush();
    }

    /**
     * ينشئ ScopeType=organization وتعريف دور admin بـ is_admin_role=true.
     * اللازم لكي يمنح المحرّك admin صلاحيات edit/delete على المشاريع.
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
     * يُسند للمستخدم دوراً سياقياً على مستوى المؤسسة (للـ admin).
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

    // ========================================================================
    // (1-4) Scoped Member (PROJECT_MEMBER) — جميعها DENY
    // ========================================================================

    /**
     * عضو سياقي في المشروع (PROJECT_MEMBER) لا يجوز له تعديل بيانات المشروع.
     * هذا يحمي مبدأ "العضوية ليست إدارة".
     */
    public function test_scoped_member_cannot_update_project(): void
    {
        $user = $this->makeUser('viewer');
        $project = $this->makeProject();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        $this->assertFalse(
            (new ProjectPolicy)->update($user, $project),
            'يجب رفض تعديل المشروع لعضو سياقي (PROJECT_MEMBER) عبر الاستدعاء المباشر للسياسة'
        );
        $this->assertTrue(
            Gate::forUser($user)->denies('update', $project),
            'يجب أن ترفض بوابة Gate تعديل المشروع لعضو سياقي (PROJECT_MEMBER)'
        );
    }

    /**
     * عضو سياقي لا يجوز له حذف المشروع — الحذف صلاحية admin/super_admin فقط.
     */
    public function test_scoped_member_cannot_delete_project(): void
    {
        $user = $this->makeUser('viewer');
        $project = $this->makeProject();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        $this->assertFalse(
            (new ProjectPolicy)->delete($user, $project),
            'يجب رفض حذف المشروع لعضو سياقي (PROJECT_MEMBER)'
        );
        $this->assertTrue(
            Gate::forUser($user)->denies('delete', $project),
            'يجب أن ترفض بوابة Gate حذف المشروع لعضو سياقي (PROJECT_MEMBER)'
        );
    }

    /**
     * عضو سياقي لا يجوز له إدارة أعضاء المشروع.
     */
    public function test_scoped_member_cannot_assign_project_roles(): void
    {
        $user = $this->makeUser('viewer');
        $project = $this->makeProject();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        $this->assertFalse(
            (new ProjectPolicy)->assignProjectRoles($user, $project),
            'يجب رفض إسناد الأدوار السياقية لعضو سياقي (PROJECT_MEMBER)'
        );
        $this->assertTrue(
            Gate::forUser($user)->denies('assignProjectRoles', $project),
            'يجب أن ترفض بوابة Gate إسناد الأدوار السياقية لعضو سياقي (PROJECT_MEMBER)'
        );
    }

    /**
     * عضو سياقي لا يجوز له إسناد أدوار سياقية لأعضاء آخرين في المشروع.
     *
     * (مدمج مع test_scoped_member_cannot_assign_project_roles أعلاه بعد
     * توحيد manageMembers → assignProjectRoles؛ كان الاختباران مكررين
     * أصلاً قبل التوحيد فبقي اختبار واحد يغطي السلوك.)
     */
    // Removed in main-ci-recovery-direction-r: was an exact duplicate of
    // test_scoped_member_cannot_assign_project_roles above. The single
    // remaining test pins that a PROJECT_MEMBER cannot assign roles and
    // is also denied by the Gate facade.

    // ========================================================================
    // (5-6) Scoped Viewer (PROJECT_VIEWER) — DENY
    // ========================================================================

    /**
     * مشاهد سياقي (PROJECT_VIEWER) لا يجوز له تعديل المشروع.
     */
    public function test_scoped_viewer_cannot_update_project(): void
    {
        $user = $this->makeUser('viewer');
        $project = $this->makeProject();
        $user->assignProjectRole($project, ScopedRole::PROJECT_VIEWER);

        $this->assertFalse(
            (new ProjectPolicy)->update($user, $project),
            'يجب رفض تعديل المشروع لمشاهد سياقي (PROJECT_VIEWER)'
        );
        $this->assertTrue(
            Gate::forUser($user)->denies('update', $project),
            'يجب أن ترفض بوابة Gate تعديل المشروع لمشاهد سياقي (PROJECT_VIEWER)'
        );
    }

    /**
     * مشاهد سياقي لا يجوز له إسناد أدوار للمشروع.
     */
    public function test_scoped_viewer_cannot_assign_project_roles(): void
    {
        $user = $this->makeUser('viewer');
        $project = $this->makeProject();
        $user->assignProjectRole($project, ScopedRole::PROJECT_VIEWER);

        $this->assertFalse(
            (new ProjectPolicy)->assignProjectRoles($user, $project),
            'يجب رفض إسناد الأدوار السياقية لمشاهد سياقي (PROJECT_VIEWER)'
        );
        $this->assertTrue(
            Gate::forUser($user)->denies('assignProjectRoles', $project),
            'يجب أن ترفض بوابة Gate إسناد الأدوار السياقية لمشاهد سياقي (PROJECT_VIEWER)'
        );
    }

    // ========================================================================
    // (7-10) Scoped Manager (PROJECT_MANAGER) — Positive + Critical Deny
    // ========================================================================

    /**
     * مدير المشروع (PROJECT_MANAGER) يجوز له تعديل بيانات المشروع
     * عبر فرع isProjectAdmin في السياسة.
     */
    public function test_scoped_manager_can_update_project(): void
    {
        $user = $this->makeUser('viewer');
        $project = $this->makeProject();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MANAGER);

        $this->assertTrue(
            (new ProjectPolicy)->update($user, $project),
            'يجب السماح بتعديل المشروع لمدير سياقي (PROJECT_MANAGER) عبر isProjectAdmin'
        );
    }

    /**
     * مدير المشروع يجوز له إسناد الأدوار السياقية داخل مشروعه عبر isProjectLeader.
     */
    public function test_scoped_manager_can_assign_project_roles(): void
    {
        $user = $this->makeUser('viewer');
        $project = $this->makeProject();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MANAGER);

        $this->assertTrue(
            (new ProjectPolicy)->assignProjectRoles($user, $project),
            'يجب السماح بإسناد الأدوار لمدير سياقي (PROJECT_MANAGER) عبر isProjectLeader'
        );
    }

    /**
     * القاعدة الحرجة: مدير المشروع (scoped manager) لا يجوز له حذف المشروع.
     * الحذف صلاحية admin (نظامي) أو super_admin فقط — مطابقةً لـ
     * ProjectAuthorizationService و ProjectPolicy::delete.
     */
    public function test_scoped_manager_cannot_delete_project(): void
    {
        $user = $this->makeUser('viewer');
        $project = $this->makeProject();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MANAGER);

        $this->assertFalse(
            (new ProjectPolicy)->delete($user, $project),
            'يجب رفض الحذف لمدير سياقي (PROJECT_MANAGER) — الحذف لـ admin/super_admin فقط'
        );
        $this->assertTrue(
            Gate::forUser($user)->denies('delete', $project),
            'يجب أن ترفض بوابة Gate الحذف لمدير سياقي (PROJECT_MANAGER)'
        );
    }

    // ========================================================================
    // (11-12) Cross-Organization Isolation
    // ========================================================================

    /**
     * مستخدم من مؤسسة أخرى (org B) لا يجوز له عرض مشروع تابع لمؤسسة A.
     * هذا يحقق عزل المؤسسة (D-02/D-04) في فرع view.
     */
    public function test_user_from_other_org_cannot_view_project(): void
    {
        [, , $projectB] = $this->makeOrgProject();
        $userA = $this->makeUser('admin'); // admin بصلاحيات واسعة في org A

        $this->assertFalse(
            (new ProjectPolicy)->view($userA, $projectB),
            'يجب رفض عرض مشروع من مؤسسة أخرى حتى لو كان المستخدم admin'
        );
        $this->assertTrue(
            Gate::forUser($userA)->denies('view', $projectB),
            'يجب أن ترفض بوابة Gate عرض مشروع من مؤسسة أخرى'
        );
    }

    /**
     * مستخدم من مؤسسة أخرى لا يجوز له تعديل مشروع في مؤسسة A.
     */
    public function test_user_from_other_org_cannot_update_project(): void
    {
        [, , $projectB] = $this->makeOrgProject();
        $userA = $this->makeUser('admin');

        $this->assertFalse(
            (new ProjectPolicy)->update($userA, $projectB),
            'يجب رفض تعديل مشروع من مؤسسة أخرى حتى لو كان المستخدم admin'
        );
        $this->assertTrue(
            Gate::forUser($userA)->denies('update', $projectB),
            'يجب أن ترفض بوابة Gate تعديل مشروع من مؤسسة أخرى'
        );
    }

    // ========================================================================
    // (13-14) Null-Organization User Isolation
    // ========================================================================

    /**
     * مستخدم بلا منظمة (organization_id = null) لا يجوز له عرض أي مشروع
     * بمنظمة قابلة للتحديد — حتى لو كان admin/project_manager.
     */
    public function test_user_with_null_organization_cannot_view_project(): void
    {
        $project = $this->makeProject();
        $nullOrgUser = $this->makeNullOrgUser('admin');

        $this->assertFalse(
            (new ProjectPolicy)->view($nullOrgUser, $project),
            'يجب رفض عرض المشروع لمستخدم بلا منظمة (null-org)'
        );
        $this->assertTrue(
            Gate::forUser($nullOrgUser)->denies('view', $project),
            'يجب أن ترفض بوابة Gate عرض المشروع لمستخدم بلا منظمة'
        );
    }

    /**
     * مستخدم بلا منظمة لا يجوز له تعديل أي مشروع بمنظمة قابلة للتحديد.
     */
    public function test_user_with_null_organization_cannot_update_project(): void
    {
        $project = $this->makeProject();
        $nullOrgUser = $this->makeNullOrgUser('admin');

        $this->assertFalse(
            (new ProjectPolicy)->update($nullOrgUser, $project),
            'يجب رفض تعديل المشروع لمستخدم بلا منظمة (null-org)'
        );
        $this->assertTrue(
            Gate::forUser($nullOrgUser)->denies('update', $project),
            'يجب أن ترفض بوابة Gate تعديل المشروع لمستخدم بلا منظمة'
        );
    }

    // ========================================================================
    // (15-17) Admin (نظامي) — Positive + Department Scope
    // ========================================================================

    /**
     * admin في نفس قسم المشروع يجوز له تعديله.
     * مع engine=ON: يحتاج المحرّك دوراً سياقياً على مستوى المؤسسة (org-scoped admin).
     */
    public function test_admin_in_department_can_update_project(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantOrgAdminScopedRole($admin);
        $project = $this->makeProject();

        $this->assertTrue(
            (new ProjectPolicy)->update($admin, $project),
            'يجب السماح للـ admin بتعديل مشروع في نفس مؤسسته (عبر org-scoped role)'
        );
    }

    /**
     * admin في نفس مؤسسة المشروع يجوز له حذفه.
     * مع engine=ON: يحتاج المحرّك دوراً سياقياً على مستوى المؤسسة (org-scoped admin).
     */
    public function test_admin_in_department_can_delete_project(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantOrgAdminScopedRole($admin);
        $project = $this->makeProject();

        $this->assertTrue(
            (new ProjectPolicy)->delete($admin, $project),
            'يجب السماح للـ admin بحذف مشروع في نفس مؤسسته (عبر org-scoped role)'
        );
    }

    /**
     * admin في قسم مختلف عن قسم المشروع (لكن نفس المؤسسة) لا يجوز له تعديل المشروع.
     * هذا يوثق قاعدة الحدود على مستوى القسم لـ admin (department-scoping rule).
     * ملاحظة: مع engine=ON، المحرّك يمنح الوصول عبر org-scoped role بصرف النظر عن القسم.
     * هذا الاختبار يتحقق من أن admin بدون org-scoped role لا يمكنه التعديل.
     */
    public function test_admin_in_other_department_can_update_project(): void
    {
        $otherDept = Department::factory()->create([
            'organization_id' => $this->department->organization_id,
        ]);
        $admin = $this->makeUser('admin', $otherDept->organization_id, $otherDept->id);
        // admin is an organization-wide (CEO-level) role — not bound to a
        // department — so it may edit any project in the same organization.
        $project = $this->makeProject();

        $this->assertTrue(
            (new ProjectPolicy)->update($admin, $project),
            'admin (organization-wide) must be allowed to update a project in another department'
        );
        $this->assertTrue(
            Gate::forUser($admin)->allows('update', $project),
            'Gate must allow an organization-wide admin to update a project in another department'
        );
    }

    // ========================================================================
    // (18) Super Admin — Bypass عبر before()
    // ========================================================================

    /**
     * super_admin يتجاوز كل الصلاحيات عبر before() — يجب أن تعود before(true)
     * وأن تسمح Gate بكل العمليات الحساسة على مشروع في نفس المؤسسة.
     */
    public function test_super_admin_bypasses_all_via_before(): void
    {
        $sa = $this->makeUser('super_admin');
        $project = $this->makeProject();

        $this->assertTrue(
            (new ProjectPolicy)->before($sa, 'any_ability'),
            'before() يجب أن يرجع true لأي صلاحية لـ super_admin'
        );

        $this->assertTrue(
            Gate::forUser($sa)->allows('view', $project),
            'super_admin يجب أن يستطيع عرض أي مشروع عبر بوابة Gate'
        );
        $this->assertTrue(
            Gate::forUser($sa)->allows('update', $project),
            'super_admin يجب أن يستطيع تعديل أي مشروع عبر بوابة Gate'
        );
        $this->assertTrue(
            Gate::forUser($sa)->allows('delete', $project),
            'super_admin يجب أن يستطيع حذف أي مشروع عبر بوابة Gate'
        );
        $this->assertTrue(
            Gate::forUser($sa)->allows('manageMembers', $project),
            'super_admin يجب أن يستطيع إدارة الأعضاء عبر بوابة Gate'
        );
        $this->assertTrue(
            Gate::forUser($sa)->allows('assignProjectRoles', $project),
            'super_admin يجب أن يستطيع إسناد الأدوار عبر بوابة Gate'
        );
    }
}
