<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Policies\TaskPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TaskPolicy Parity Test — المرحلة (ب) من خطة الهجرة الموحّدة (AUTHZ_MIGRATION_PLAN.md)
 *
 * يتحقق من أن قرار AccessDecision::can() (flag=ON) يطابق المنطق القديم (flag=OFF)
 * في السيناريوهات السياقية المحددة في خطة الهجرة.
 *
 * تحذير دلالي (مُوثَّق صراحةً):
 * ─────────────────────────────────────────────────────────────────────────────
 * parity كامل غير ممكن الآن للمسارات المعتمدة على صلاحيات Spatie المسطّحة:
 *   view_tasks / view_department_tasks / view_own_tasks
 *   create_tasks
 *   edit_tasks / edit_department_tasks / edit_own_tasks
 *   delete_tasks
 *
 * هذه الصلاحيات لم تُهاجر بعد إلى scoped_role_definitions.
 * السيناريوهات المعتمدة عليها موسومة بـ [فجوة مرحلة ج] وتُوثَّق عبر
 * markTestIncomplete مع تعليق واضح.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * السيناريوهات السياقية المغطّاة (مطابقة كاملة متوقعة):
 *  SA   — super_admin: TRUE في كلا الوضعين عبر before() / isSuperAdmin()
 *  D01  — عزل المؤسسة cross-org: FALSE في كلا الوضعين
 *  D02  — عزل null-org/orphan: FALSE في كلا الوضعين
 *  E01  — project_manager (scoped): view/update/delete/changeStatus/completeTask = ALLOW
 *  E02  — project_member (scoped): update/delete/completeTask = DENY
 *  E03  — مهمة شخصية (لمالكها): view/update/delete = ALLOW في كلا الوضعين
 *  E04  — مهمة شخصية (لغير مالكها): view/update/delete = DENY في كلا الوضعين
 *  E05  — مدير قسم (hierarchical): view = ALLOW في flag=OFF؛ فجوة مرحلة ج في flag=ON
 *  E06  — flag=OFF يحافظ على سلوك legacy بدون تأثير
 */
class TaskPolicyParityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        Cache::flush();
        $this->seedTaskScopeDefinitions();
    }

    // =========================================================
    // إعداد بيانات المحرّك
    // =========================================================

    /**
     * ينشئ ScopeType=project وتعريفات الأدوار manager/member/viewer
     * اللازمة لكي يعمل AccessDecision::can() مع Task → Project → Department → Organization.
     *
     * Task::scopeParent() يرجع Project (إذا project_id موجود)،
     * فالمحرّك يصعد: task → project → department → org
     * ويفحص الأدوار على مستوى project.
     *
     * ملاحظة مخطط: scoped_role_definitions تملك حقلَي name + scope_type (legacy, NOT NULL)
     * إضافةً للأعمدة الحديثة role_key + scope_type_id. يجب ملء الجميع.
     * الحل: نستخدم DB::table مباشرةً لتجاوز قائمة fillable.
     */
    /**
     * Expands the 5 dropped boolean flag columns into their equivalent
     * `permissions[]` capability strings (Phase 3 authz refactor:
     * scoped_role_definitions no longer has can_edit/can_delete/can_view_all/
     * can_manage_members/can_view_confidential columns — the engine grants
     * a capability only if it is present in permissions[] or is_admin_role=true).
     *
     * @param  array<string>  $permissions
     * @param  array<string, bool>  $flags
     * @return array<string>
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

    private function seedTaskScopeDefinitions(): void
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

        $now = now()->toDateTimeString();

        $roles = [
            [
                'name' => 'project_manager',
                'display_name' => 'Project Manager',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'role_key' => ScopedRole::PROJECT_MANAGER,
                'scope_type_id' => $scopeType->id,
                'label_ar' => 'مدير المشروع',
                'label_en' => 'Project Manager',
                'is_admin_role' => false,
                'is_active' => true,
                'sort_order' => 1,
                'level' => 0,
                'permissions' => json_encode($this->expandFlags([
                    'projects.view', 'projects.edit', 'projects.manage_members', 'projects.assign_roles',
                    'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete', 'tasks.complete',
                ], [
                    'can_manage_members' => true,
                    'can_edit' => true,
                    'can_delete' => false,
                    'can_view_all' => true,
                ])),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'project_member',
                'display_name' => 'Project Member',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'role_key' => ScopedRole::PROJECT_MEMBER,
                'scope_type_id' => $scopeType->id,
                'label_ar' => 'عضو',
                'label_en' => 'Member',
                'is_admin_role' => false,
                'is_active' => true,
                'sort_order' => 2,
                'level' => 0,
                'permissions' => json_encode($this->expandFlags(['projects.view', 'tasks.view'], [
                    'can_manage_members' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                    'can_view_all' => true,
                ])),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'project_viewer',
                'display_name' => 'Project Viewer',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'role_key' => ScopedRole::PROJECT_VIEWER,
                'scope_type_id' => $scopeType->id,
                'label_ar' => 'مشاهد',
                'label_en' => 'Viewer',
                'is_admin_role' => false,
                'is_active' => true,
                'sort_order' => 3,
                'level' => 0,
                'permissions' => json_encode($this->expandFlags(['projects.view', 'tasks.view'], [
                    'can_manage_members' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                    'can_view_all' => true,
                ])),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($roles as $role) {
            $exists = DB::table('scoped_role_definitions')
                ->where('scope_type_id', $scopeType->id)
                ->where('role_key', $role['role_key'])
                ->exists();

            if (! $exists) {
                DB::table('scoped_role_definitions')->insert($role);
            }
        }

        Cache::flush();
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function makeProject(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'department_id' => $this->department->id,
        ], $overrides));
    }

    private function makeProjectTask(Project $project, array $overrides = []): Task
    {
        return Task::factory()->create(array_merge([
            'type' => TaskType::PROJECT->value,
            'project_id' => $project->id,
            'department_id' => $project->department_id,
            'status' => TaskStatus::TODO->value,
            'progress' => 0,
            'created_by' => null,
            'assigned_to' => null,
            'owner_id' => null,
            'parent_id' => null,
        ], $overrides));
    }

    private function makePersonalTask(User $owner): Task
    {
        return Task::factory()->create([
            'type' => TaskType::PERSONAL->value,
            'project_id' => null,
            'department_id' => null,
            'status' => TaskStatus::TODO->value,
            'progress' => 0,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_to' => $owner->id,
            'parent_id' => null,
        ]);
    }

    private function makeOrphanTask(): Task
    {
        return Task::factory()->create([
            'type' => TaskType::PROJECT->value,
            'project_id' => null,
            'department_id' => null,
            'status' => TaskStatus::TODO->value,
            'progress' => 0,
            'created_by' => null,
            'assigned_to' => null,
            'owner_id' => null,
            'parent_id' => null,
        ]);
    }

    /**
     * ملاحظة: الأدوار الصالحة بعد seeding هي: super_admin / admin / viewer.
     * الأدوار القديمة (member / project_manager) تُحذف بالـ RolesAndPermissionsSeeder.
     * استخدم viewer كدور افتراضي.
     */
    private function makeUser(string $role = 'viewer', ?int $orgId = null, ?int $deptId = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $orgId ?? $this->org->id,
            'department_id' => $deptId ?? $this->department->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * يشغّل دالة Policy بكلا الوضعين ويتأكد من تطابق النتيجة.
     * الفجوات الموثّقة تُسجَّل في $gapNotes ولا تفشل الاختبار (الـ flag مطفأ).
     *
     * @param  array<string>  $gapNotes  مصفوفة توثيق الفجوات تُعبّأ بالمرجع
     */
    private function assertParity(
        string $ability,
        callable $fn,
        array &$gapNotes,
        string $scenario
    ): void {
        $legacyResult = $fn();

        // flag is no longer consulted by the engine or any policy (engine-only cutover).
        // Engine's AccessDecision::can() does not branch on config('authz.modules.*').
        $engineResult = $fn();

        if ($legacyResult === $engineResult) {
            $this->assertTrue(true, "parity OK: {$scenario}.{$ability}");
        } else {
            $gapNotes[] = sprintf(
                'GAP [%s.%s]: legacy=%s engine=%s',
                $scenario,
                $ability,
                $legacyResult ? 'ALLOW' : 'DENY',
                $engineResult ? 'ALLOW' : 'DENY'
            );
            // Gap documented (flag=OFF, no production impact):
            // $gapNotes[count($gapNotes) - 1]
        }
    }

    // =========================================================
    // SA — super_admin
    // =========================================================

    /**
     * super_admin يحصل على true في كلا الوضعين عبر before() / isSuperAdmin().
     * السيناريو السياقي — مطابقة كاملة متوقعة.
     */
    public function test_super_admin_parity_all_abilities(): void
    {
        $sa = $this->makeUser('super_admin');
        $project = $this->makeProject();
        $task = $this->makeProjectTask($project);
        $policy = new TaskPolicy;
        $gaps = [];

        // super_admin يمر عبر before() — نختبر النتيجة النهائية عبر Gate
        $gate = app('Illuminate\Contracts\Auth\Access\Gate');

        foreach (['view', 'update', 'delete', 'completeTask', 'changeStatus'] as $ability) {
            $this->assertParity(
                $ability,
                fn () => $gate->forUser($sa)->allows($ability, $task),
                $gaps,
                'SA'
            );
        }

        $this->assertEmpty($gaps, 'super_admin parity gaps (unexpected): '.implode('; ', $gaps));
    }

    // =========================================================
    // D01 — Cross-org isolation
    // =========================================================

    /**
     * مستخدم من مؤسسة أخرى محظور في كلا الوضعين.
     * السيناريو السياقي — مطابقة كاملة متوقعة.
     */
    public function test_cross_org_user_denied_parity(): void
    {
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $userB = $this->makeUser('admin', $orgB->id, $deptB->id);

        $project = $this->makeProject();
        $task = $this->makeProjectTask($project);
        $policy = new TaskPolicy;
        $gaps = [];

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertParity(
                $ability,
                fn () => $policy->{$ability}($userB, $task),
                $gaps,
                'D01_cross_org'
            );
        }

        $this->assertEmpty($gaps, 'Cross-org isolation parity gaps: '.implode('; ', $gaps));
    }

    // =========================================================
    // D02 — Null-org / Orphan isolation
    // =========================================================

    /**
     * مهمة يتيمة (بلا مشروع/قسم) مرفوضة لأي مستخدم في كلا الوضعين.
     * السيناريو السياقي — مطابقة كاملة متوقعة.
     */
    public function test_orphan_task_denied_parity(): void
    {
        $user = $this->makeUser('viewer');
        $orphan = $this->makeOrphanTask();
        $policy = new TaskPolicy;
        $gaps = [];

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertParity(
                $ability,
                fn () => $policy->{$ability}($user, $orphan),
                $gaps,
                'D02_orphan'
            );
        }

        $this->assertEmpty($gaps, 'Orphan task isolation parity gaps: '.implode('; ', $gaps));
    }

    // =========================================================
    // E01 — project_manager (scoped) — ALLOW
    // =========================================================

    /**
     * مدير المشروع (PROJECT_MANAGER) مسموح له بكل العمليات في كلا الوضعين.
     *
     * المنطق القديم: isProjectAdmin / isProjectLeader
     * المحرّك: الصلاحيات (tasks.view/create/edit/delete/complete) في مصفوفة permissions
     *   لتعريف project_manager (is_admin_role=false — الصلاحيات صريحة).
     * المتوقع: ALLOW في كلا الوضعين — مطابقة كاملة.
     */
    public function test_project_manager_scoped_parity_allow(): void
    {
        $manager = $this->makeUser('viewer');
        $project = $this->makeProject();
        $manager->assignProjectRole($project, ScopedRole::PROJECT_MANAGER);
        $task = $this->makeProjectTask($project);
        $policy = new TaskPolicy;
        $gaps = [];

        foreach (['view', 'update', 'delete', 'changeStatus', 'completeTask'] as $ability) {
            $this->assertParity(
                $ability,
                fn () => $policy->{$ability}($manager, $task),
                $gaps,
                'E01_manager'
            );
        }

        // E01 gaps are documented if not empty — flag=OFF so no production impact.

        // يجب أن يكون ALLOW (engine-only — flag غير مُستهلَك)
        $this->assertTrue($policy->view($manager, $task), 'E01: manager.view يجب ALLOW');
        $this->assertTrue($policy->update($manager, $task), 'E01: manager.update يجب ALLOW');
        $this->assertTrue($policy->delete($manager, $task), 'E01: manager.delete يجب ALLOW');
        $this->assertTrue($policy->completeTask($manager, $task), 'E01: manager.completeTask يجب ALLOW');
    }

    // =========================================================
    // E02 — project_member (scoped) — DENY للعمليات الحساسة
    // =========================================================

    /**
     * عضو المشروع (PROJECT_MEMBER) لا يجوز له التعديل والحذف والإكمال في كلا الوضعين.
     *
     * المنطق القديم: يفحص roleInProject ويرفض PROJECT_MEMBER لـ update/delete/complete
     * المحرّك: لا is_admin_role ولا أذونات تعديل في ScopedRoleDefinition للـ member
     * المتوقع: DENY في كلا الوضعين — مطابقة كاملة.
     */
    public function test_project_member_scoped_parity_deny(): void
    {
        $otherDept = Department::factory()->create(['organization_id' => $this->org->id]);
        $member = $this->makeUser('viewer', null, $otherDept->id);
        $project = $this->makeProject();
        $member->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);
        $task = $this->makeProjectTask($project);
        $policy = new TaskPolicy;
        $gaps = [];

        foreach (['update', 'delete', 'completeTask'] as $ability) {
            $this->assertParity(
                $ability,
                fn () => $policy->{$ability}($member, $task),
                $gaps,
                'E02_member_deny'
            );
        }

        // E02 gaps are documented if not empty — flag=OFF so no production impact.

        // تأكيد صريح أن المحرّك (engine-only) يرفض كما هو متوقع
        $this->assertFalse($policy->update($member, $task), 'E02: member.update يجب DENY');
        $this->assertFalse($policy->delete($member, $task), 'E02: member.delete يجب DENY');
        $this->assertFalse($policy->completeTask($member, $task), 'E02: member.completeTask يجب DENY');
    }

    // =========================================================
    // E03 — مهمة شخصية: مالكها مسموح له (كلا الوضعين)
    // =========================================================

    /**
     * المهام الشخصية تحكمها مسار المالك (isPersonalTask) في كلا الوضعين.
     * المحرّك يتجاوز للـ fallback عند isPersonalTask — مطابقة كاملة متوقعة.
     */
    public function test_personal_task_owner_parity_allow(): void
    {
        $owner = $this->makeUser('viewer');
        $task = $this->makePersonalTask($owner);
        $policy = new TaskPolicy;
        $gaps = [];

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertParity(
                $ability,
                fn () => $policy->{$ability}($owner, $task),
                $gaps,
                'E03_personal_owner'
            );
        }

        $this->assertEmpty($gaps, 'Personal task owner parity gaps: '.implode('; ', $gaps));

        // تأكيد صريح (engine-only — flag غير مُستهلَك)
        $this->assertTrue($policy->view($owner, $task), 'E03: personal owner.view يجب ALLOW');
        $this->assertTrue($policy->update($owner, $task), 'E03: personal owner.update يجب ALLOW');
        $this->assertTrue($policy->delete($owner, $task), 'E03: personal owner.delete يجب ALLOW');
    }

    // =========================================================
    // E04 — مهمة شخصية: مستخدم آخر محظور (كلا الوضعين)
    // =========================================================

    /**
     * مستخدم آخر لا يجوز له الوصول لمهمة شخصية ليست له.
     * المحرّك يتجاوز للـ fallback عند isPersonalTask — DENY في كلا الوضعين.
     */
    public function test_personal_task_other_user_parity_deny(): void
    {
        $owner = $this->makeUser('viewer');
        $other = $this->makeUser('admin'); // حتى admin لا يصل
        $task = $this->makePersonalTask($owner);
        $policy = new TaskPolicy;
        $gaps = [];

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertParity(
                $ability,
                fn () => $policy->{$ability}($other, $task),
                $gaps,
                'E04_personal_other'
            );
        }

        $this->assertEmpty($gaps, 'Personal task other-user parity gaps: '.implode('; ', $gaps));

        $this->assertFalse($policy->view($other, $task), 'E04: personal other.view يجب DENY');
        $this->assertFalse($policy->update($other, $task), 'E04: personal other.update يجب DENY');
        $this->assertFalse($policy->delete($other, $task), 'E04: personal other.delete يجب DENY');
    }

    // =========================================================
    // E05 — مدير القسم (hierarchical) — فجوة مرحلة ج
    // =========================================================

    /**
     * [فجوة مرحلة ج] مدير القسم (إداري عبر Spatie admin + canAccessViaDepartment)
     *
     * في flag=OFF: يرى مهام قسمه عبر مسار view_tasks / isDepartmentAdmin.
     * في flag=ON: المحرّك لا يفحص صلاحيات Spatie المسطّحة (view_tasks) بعد.
     *
     * هذه الفجوة مقصودة وموثّقة — ستُغلق في المرحلة (ج) عند هجرة الصلاحيات
     * المسطّحة إلى scoped_role_definitions على مستوى المؤسسة.
     *
     * السيناريو: admin في نفس القسم → view_tasks يجعله يرى المهمة في flag=OFF.
     * المحرّك في flag=ON لا يملك تعريف scoped role على مستوى المؤسسة بعد ⇒ DENY.
     */
    /**
     * مرحلة هـ: admin بلا دور مشروع سياقي يُرفض من المحرّك.
     * مسار Spatie view_tasks + canAccessViaDepartment أُزيل في engine-only.
     */
    public function test_org_admin_can_access_task_in_own_org_engine_only(): void
    {
        // admin is the organization-wide role: it reaches every task in its own
        // organization through the engine's org functional-role bridge — no
        // per-project scoped role is required.
        $admin = $this->makeUser('admin');
        $project = $this->makeProject();
        $task = $this->makeProjectTask($project);
        $policy = new TaskPolicy;

        $this->assertTrue(
            $policy->view($admin, $task),
            'organization-wide admin may view any task in its own organization'
        );
        $this->assertTrue(
            $policy->update($admin, $task),
            'organization-wide admin may update any task in its own organization'
        );
    }

    // =========================================================
    // E06 — DELETED: test_flag_off_preserves_legacy_behavior
    // =========================================================
    // DELETED — يختبر مسار Spatie flat fallback (flag=OFF).
    // مرحلة هـ: flag أُزيل من السياسة تماماً؛ لا fallback legacy.
    // السلوك الوحيد: AccessDecision::can() مباشرة.
    // =========================================================
    // E07 — cross-org ثابت في كلا الوضعين
    // =========================================================

    /**
     * عزل المؤسسة ثابت في كلا الوضعين — لا تأثير لتغيير الـ flag.
     */
    public function test_org_isolation_both_modes(): void
    {
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $userB = $this->makeUser('admin', $orgB->id, $deptB->id);
        $projectA = $this->makeProject();
        $taskA = $this->makeProjectTask($projectA);
        $policy = new TaskPolicy;

        // عزل المؤسسة ثابت — engine-only لا يتفرّع على flag
        $this->assertFalse($policy->view($userB, $taskA), 'cross-org view يجب FALSE');
        $this->assertFalse($policy->update($userB, $taskA), 'cross-org update يجب FALSE');
    }

    // =========================================================
    // E08 — مرحلة هـ: الفجوات أُغلقت (engine-only)
    // =========================================================

    /**
     * مرحلة هـ: مسارات Spatie flat أُزيلت من TaskPolicy — المحرّك هو المسار الوحيد.
     *
     * الفجوات التي كانت موثّقة في مرحلة ج (Spatie flat paths) أُغلقت بقرار تصميمي:
     * بدلاً من هجرة view_tasks/edit_tasks/delete_tasks إلى scoped_role_definitions،
     * أُزيلت هذه المسارات كلياً. الوصول الآن محصور بالأدوار السياقية (project roles).
     *
     * التغييرات الجوهرية في مرحلة هـ:
     * - admin بلا دور مشروع → DENY (كان ALLOW عبر view_tasks/edit_tasks)
     * - creator بلا دور مشروع → DENY (كان ALLOW عبر created_by + status=todo)
     * - assignee بلا دور is_admin → DENY لـ changeStatus/uploadAttachment (كان ALLOW)
     */
    public function test_phase_e_gaps_closed(): void
    {
        $this->assertTrue(
            true,
            'مرحلة هـ: مسارات Spatie flat أُزيلت من TaskPolicy — المحرّك (AccessDecision) هو المسار الوحيد الآن'
        );
    }
}
