<?php

namespace Tests\Unit\Policies;

use App\Modules\Core\Models\Organization;
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
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * اختبارات وحدوية لـ TaskPolicy (مرحلة هـ: engine-only)
 *
 * تغطي:
 * - الأدوار السياقية (PROJECT_MEMBER / PROJECT_VIEWER / PROJECT_MANAGER)
 * - مسار المهام الشخصية (isPersonalTask) — ملكية حصرية
 * - عزل المنظمة (D-02): المهمة بلا منظمة قابلة للتحديد تُرفض لغير super
 * - تفويض restore إلى delete
 * - تجاوز super_admin عبر before()
 *
 * ملاحظة: الأدوار الصالحة بعد seeding هي: super_admin / admin / viewer.
 * الأدوار القديمة (member / project_manager) أُزيلت من RolesAndPermissionsSeeder.
 * جميع makeUser() تستخدم 'viewer' كدور نظامي افتراضي؛ السلوك يحدده الدور السياقي.
 *
 * تفاصيل تشغيلية مهمة:
 * 1. الاستدعاء المباشر `(new TaskPolicy)->method(...)` يتجاوز before().
 *    لذلك يُستخدم في كل الاختبارات غير super_admin.
 * 2. اختبارات super_admin تستخدم Gate::forUser لإثبات مرور before() فعلياً.
 * 3. عند إنشاء Task بـ Task::factory() يجب تمرير override صريح لكل من
 *    project_id, department_id, assigned_to, created_by, owner_id لتجنّب
 *    إنشاء مشاريع/مستخدمين إضافيين عبر factories الفرعية.
 */
class TaskPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->department = Department::factory()->create();
        Cache::flush();
    }

    /**
     * مستخدم نشط ضمن مؤسسة/قسم محددين.
     * الدور الافتراضي 'viewer' (الأدوار الصالحة بعد seeding: super_admin / admin / viewer).
     */
    private function makeUser(string $role = 'viewer', ?int $orgId = null, ?int $deptId = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $orgId ?? $this->department->organization_id,
            'department_id' => $deptId ?? $this->department->id,
            'is_active' => true,
        ]);
        $role === 'super_admin'
                ? $this->grantCanonicalSuperAdmin($user)
                : $this->assignCanonicalRole($user, $role);

        return $user;
    }

    /**
     * مشروع في مؤسسة/قسم محدد (الافتراضي: قسم التحضير).
     */
    private function makeProjectInOrg(?int $orgId = null, ?int $deptId = null): Project
    {
        return Project::factory()->create([
            'organization_id' => $orgId ?? $this->department->organization_id,
            'department_id' => $deptId ?? $this->department->id,
        ]);
    }

    /**
     * مهمة مشروع (type=project) مرتبطة بمشروع وقسم — مع تصفير الحقول الخطرة.
     */
    private function makeProjectTask(Project $project, ?int $deptId = null, array $overrides = []): Task
    {
        return Task::factory()->create(array_merge([
            'type' => TaskType::PROJECT->value,
            'project_id' => $project->id,
            'department_id' => $deptId ?? $project->department_id,
            'status' => TaskStatus::TODO->value,
            'progress' => 0,
            'created_by' => null,
            'assigned_to' => null,
            'owner_id' => null,
            'parent_id' => null,
        ], $overrides));
    }

    /**
     * مهمة شخصية (type=personal) — owner_id/created_by/assigned_to كلها للمالك،
     * بدون project_id أو department_id.
     */
    private function makePersonalTask(User $owner, array $overrides = []): Task
    {
        return Task::factory()->create(array_merge([
            'type' => TaskType::PERSONAL->value,
            'project_id' => null,
            'department_id' => null,
            'status' => TaskStatus::TODO->value,
            'progress' => 0,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_to' => $owner->id,
            'parent_id' => null,
        ], $overrides));
    }

    /**
     * مهمة "يتيمة" (D-02): غير شخصية، بلا مشروع ولا قسم → منظمة غير قابلة للتحديد.
     */
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
     * بيئة "مؤسسة أخرى" كاملة لاختبارات عزل المنظمة.
     *
     * @return array{0: Organization, 1: Department, 2: Project}
     */
    private function makeOtherOrgEnv(): array
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
     * قسم إضافي في نفس مؤسسة التحضير — ليُجعل المستخدم في قسم مختلف عن قسم المشروع.
     */
    private function makeOtherDepartment(): Department
    {
        return Department::factory()->create([
            'organization_id' => $this->department->organization_id,
        ]);
    }

    // ========================================================================
    // (1-5) Scoped Member (PROJECT_MEMBER) — جميعها DENY
    // ========================================================================

    /**
     * عضو سياقي (غير مسند له) لا يجوز له تعديل مهمة مشروع.
     */
    public function test_scoped_member_cannot_update_project_task(): void
    {
        $otherDept = $this->makeOtherDepartment();
        $user = $this->makeUser('viewer', null, $otherDept->id);
        $project = $this->makeProjectInOrg();
        $this->assignCanonicalRole($user, 'project_member', 'project', (int) $project->id);
        $task = $this->makeProjectTask($project);

        $this->assertFalse(
            (new TaskPolicy)->update($user, $task),
            'يجب رفض تعديل المهمة لعضو سياقي (PROJECT_MEMBER) غير مسند له'
        );
        $this->assertTrue(
            Gate::forUser($user)->denies('update', $task),
            'يجب أن ترفض بوابة Gate تعديل المهمة لعضو سياقي'
        );
    }

    /**
     * عضو سياقي لا يجوز له حذف مهمة مشروع.
     */
    public function test_scoped_member_cannot_delete_project_task(): void
    {
        $otherDept = $this->makeOtherDepartment();
        $user = $this->makeUser('viewer', null, $otherDept->id);
        $project = $this->makeProjectInOrg();
        $this->assignCanonicalRole($user, 'project_member', 'project', (int) $project->id);
        $task = $this->makeProjectTask($project);

        $this->assertFalse(
            (new TaskPolicy)->delete($user, $task),
            'يجب رفض حذف المهمة لعضو سياقي (PROJECT_MEMBER) — الحذف للقيادة فقط'
        );
        $this->assertTrue(
            Gate::forUser($user)->denies('delete', $task),
            'يجب أن ترفض بوابة Gate حذف المهمة لعضو سياقي'
        );
    }

    /**
     * عضو سياقي لا يجوز له إكمال المهمة (completeTask صلاحية قيادية).
     */
    public function test_scoped_member_cannot_complete_project_task(): void
    {
        $otherDept = $this->makeOtherDepartment();
        $user = $this->makeUser('viewer', null, $otherDept->id);
        $project = $this->makeProjectInOrg();
        $this->assignCanonicalRole($user, 'project_member', 'project', (int) $project->id);
        $task = $this->makeProjectTask($project);

        $this->assertFalse(
            (new TaskPolicy)->completeTask($user, $task),
            'يجب رفض إكمال المهمة لعضو سياقي — completeTask للقيادة فقط'
        );
        $this->assertTrue(
            Gate::forUser($user)->denies('completeTask', $task),
            'يجب أن ترفض بوابة Gate إكمال المهمة لعضو سياقي'
        );
    }

    /**
     * عضو سياقي غير مكلف لا يجوز له تغيير حالة المهمة.
     * مرحلة هـ: changeStatus يرتبط بـ tasks.edit — PROJECT_MEMBER لا يملك can_edit.
     */
    public function test_scoped_member_cannot_change_status_of_project_task(): void
    {
        $otherDept = $this->makeOtherDepartment();
        $user = $this->makeUser('viewer', null, $otherDept->id);
        $project = $this->makeProjectInOrg();
        $this->assignCanonicalRole($user, 'project_member', 'project', (int) $project->id);
        $task = $this->makeProjectTask($project);

        $this->assertFalse(
            (new TaskPolicy)->changeStatus($user, $task),
            'يجب رفض تغيير الحالة لعضو سياقي (PROJECT_MEMBER) — engine-only: لا can_edit'
        );
        $this->assertTrue(
            Gate::forUser($user)->denies('changeStatus', $task),
            'يجب أن ترفض بوابة Gate تغيير الحالة لعضو سياقي'
        );
    }

    /**
     * عضو سياقي غير مكلف لا يجوز له رفع مرفقات على المهمة.
     * مرحلة هـ: uploadAttachment يرتبط بـ tasks.edit — PROJECT_MEMBER لا يملك can_edit.
     */
    public function test_scoped_member_cannot_upload_attachment_to_project_task(): void
    {
        $otherDept = $this->makeOtherDepartment();
        $user = $this->makeUser('viewer', null, $otherDept->id);
        $project = $this->makeProjectInOrg();
        $this->assignCanonicalRole($user, 'project_member', 'project', (int) $project->id);
        $task = $this->makeProjectTask($project);

        $this->assertFalse(
            (new TaskPolicy)->uploadAttachment($user, $task),
            'يجب رفض رفع المرفقات لعضو سياقي (PROJECT_MEMBER) — engine-only: لا can_edit'
        );
        $this->assertTrue(
            Gate::forUser($user)->denies('uploadAttachment', $task),
            'يجب أن ترفض بوابة Gate رفع المرفقات لعضو سياقي'
        );
    }

    // ========================================================================
    // (6-7) Scoped Viewer (PROJECT_VIEWER) — DENY
    // ========================================================================

    /**
     * مشاهد سياقي (PROJECT_VIEWER) لا يجوز له تعديل المهمة.
     */
    public function test_scoped_viewer_cannot_update_project_task(): void
    {
        $otherDept = $this->makeOtherDepartment();
        $user = $this->makeUser('viewer', null, $otherDept->id);
        $project = $this->makeProjectInOrg();
        $this->assignCanonicalRole($user, 'project_viewer', 'project', (int) $project->id);
        $task = $this->makeProjectTask($project);

        $this->assertFalse(
            (new TaskPolicy)->update($user, $task),
            'يجب رفض تعديل المهمة لمشاهد سياقي (PROJECT_VIEWER)'
        );
        $this->assertTrue(
            Gate::forUser($user)->denies('update', $task),
            'يجب أن ترفض بوابة Gate تعديل المهمة لمشاهد سياقي'
        );
    }

    /**
     * مشاهد سياقي لا يجوز له إكمال المهمة.
     */
    public function test_scoped_viewer_cannot_complete_project_task(): void
    {
        $otherDept = $this->makeOtherDepartment();
        $user = $this->makeUser('viewer', null, $otherDept->id);
        $project = $this->makeProjectInOrg();
        $this->assignCanonicalRole($user, 'project_viewer', 'project', (int) $project->id);
        $task = $this->makeProjectTask($project);

        $this->assertFalse(
            (new TaskPolicy)->completeTask($user, $task),
            'يجب رفض إكمال المهمة لمشاهد سياقي (PROJECT_VIEWER)'
        );
        $this->assertTrue(
            Gate::forUser($user)->denies('completeTask', $task),
            'يجب أن ترفض بوابة Gate إكمال المهمة لمشاهد سياقي'
        );
    }

    // ========================================================================
    // (8-9) Cross-Organization Isolation
    // ========================================================================

    /**
     * مستخدم من مؤسسة أخرى لا يجوز له عرض مهمة في مؤسسة A.
     */
    public function test_user_from_other_org_cannot_view_project_task(): void
    {
        [, $deptB, $projectB] = $this->makeOtherOrgEnv();
        $taskB = $this->makeProjectTask($projectB, $deptB->id);
        $userA = $this->makeUser('admin'); // admin بصلاحيات واسعة في org A

        $this->assertFalse(
            (new TaskPolicy)->view($userA, $taskB),
            'يجب رفض عرض مهمة من مؤسسة أخرى حتى لو كان المستخدم admin'
        );
        $this->assertTrue(
            Gate::forUser($userA)->denies('view', $taskB),
            'يجب أن ترفض بوابة Gate عرض مهمة من مؤسسة أخرى'
        );
    }

    /**
     * مستخدم من مؤسسة أخرى لا يجوز له تعديل مهمة في مؤسسة A.
     */
    public function test_user_from_other_org_cannot_update_project_task(): void
    {
        [, $deptB, $projectB] = $this->makeOtherOrgEnv();
        $taskB = $this->makeProjectTask($projectB, $deptB->id);
        $userA = $this->makeUser('admin');

        $this->assertFalse(
            (new TaskPolicy)->update($userA, $taskB),
            'يجب رفض تعديل مهمة من مؤسسة أخرى حتى لو كان المستخدم admin'
        );
        $this->assertTrue(
            Gate::forUser($userA)->denies('update', $taskB),
            'يجب أن ترفض بوابة Gate تعديل مهمة من مؤسسة أخرى'
        );
    }

    // ========================================================================
    // (10-13) Personal Task Ownership
    // ========================================================================

    /**
     * صاحب المهمة الشخصية يجوز له عرضها وتعديلها وحذفها (ملكية كاملة).
     */
    public function test_personal_task_owner_can_view_update_delete(): void
    {
        $owner = $this->makeUser('viewer');
        $task = $this->makePersonalTask($owner);
        $policy = new TaskPolicy;

        $this->assertTrue(
            $policy->view($owner, $task),
            'يجب السماح لصاحب المهمة الشخصية بعرضها'
        );
        $this->assertTrue(
            $policy->update($owner, $task),
            'يجب السماح لصاحب المهمة الشخصية بتعديلها'
        );
        $this->assertTrue(
            $policy->delete($owner, $task),
            'يجب السماح لصاحب المهمة الشخصية بحذفها'
        );
    }

    /**
     * مستخدم آخر (من نفس المؤسسة وبدون دور سياقي) لا يجوز له عرض مهمة شخصية لغيره.
     */
    public function test_personal_task_other_user_cannot_view(): void
    {
        $owner = $this->makeUser('viewer');
        $otherUser = $this->makeUser('viewer'); // نفس المؤسسة، نفس القسم
        $task = $this->makePersonalTask($owner);

        $this->assertFalse(
            (new TaskPolicy)->view($otherUser, $task),
            'يجب رفض عرض المهمة الشخصية لمستخدم آخر'
        );
    }

    /**
     * مستخدم آخر لا يجوز له تعديل مهمة شخصية لغيره.
     */
    public function test_personal_task_other_user_cannot_update(): void
    {
        $owner = $this->makeUser('viewer');
        $otherUser = $this->makeUser('viewer');
        $task = $this->makePersonalTask($owner);

        $this->assertFalse(
            (new TaskPolicy)->update($otherUser, $task),
            'يجب رفض تعديل المهمة الشخصية لمستخدم آخر'
        );
    }

    /**
     * مستخدم آخر لا يجوز له حذف مهمة شخصية لغيره.
     */
    public function test_personal_task_other_user_cannot_delete(): void
    {
        $owner = $this->makeUser('viewer');
        $otherUser = $this->makeUser('viewer');
        $task = $this->makePersonalTask($owner);

        $this->assertFalse(
            (new TaskPolicy)->delete($otherUser, $task),
            'يجب رفض حذف المهمة الشخصية لمستخدم آخر'
        );
    }

    // ========================================================================
    // (14) Null-Org Orphan Task (D-02 defense)
    // ========================================================================

    /**
     * مهمة يتيمة (project_id=null, department_id=null, غير شخصية) لا يجوز
     * عرضها/تعديلها/حذفها لأي مستخدم غير super_admin — حتى لو كان admin.
     * هذا يحقق دفاع D-02: غياب المنظمة القابلة للتحديد ⇒ رفض من المحرّك.
     *
     * مرحلة هـ: engine-only — AccessDecision::can() يرفض لأن scopeParent=null.
     */
    public function test_orphan_task_cannot_be_viewed_by_non_owner_with_view_tasks(): void
    {
        $otherDept = $this->makeOtherDepartment();
        $user = $this->makeUser('admin', null, $otherDept->id);
        $orphan = $this->makeOrphanTask();
        $policy = new TaskPolicy;

        $this->assertFalse(
            $policy->view($user, $orphan),
            'يجب رفض عرض المهمة اليتيمة (بلا مشروع/قسم) لـ admin — دفاع D-02 (engine-only)'
        );
        $this->assertFalse(
            $policy->update($user, $orphan),
            'يجب رفض تعديل المهمة اليتيمة لـ admin — دفاع D-02'
        );
        $this->assertFalse(
            $policy->delete($user, $orphan),
            'يجب رفض حذف المهمة اليتيمة لـ admin — دفاع D-02'
        );
    }

    // ========================================================================
    // (15-18) Positive Lock — Scoped Manager (الوحيد بصلاحيات إدارية قيادية)
    // ========================================================================

    /**
     * مدير المشروع (PROJECT_MANAGER) يجوز له تعديل المهمة — الصلاحية من tasks.edit في مصفوفة permissions.
     */
    public function test_scoped_manager_can_update_project_task(): void
    {
        $user = $this->makeUser('viewer');
        $project = $this->makeProjectInOrg();
        $this->assignCanonicalRole($user, 'project_manager', 'project', (int) $project->id);
        $task = $this->makeProjectTask($project);

        $this->assertTrue(
            (new TaskPolicy)->update($user, $task),
            'يجب السماح لمدير المشروع (PROJECT_MANAGER) بتعديل المهمة عبر tasks.edit في مصفوفة permissions'
        );
    }

    /**
     * مدير المشروع يجوز له حذف المهمة — الصلاحية من tasks.delete في مصفوفة permissions.
     */
    public function test_scoped_manager_can_delete_project_task(): void
    {
        $user = $this->makeUser('viewer');
        $project = $this->makeProjectInOrg();
        $this->assignCanonicalRole($user, 'project_manager', 'project', (int) $project->id);
        $task = $this->makeProjectTask($project);

        $this->assertTrue(
            (new TaskPolicy)->delete($user, $task),
            'يجب السماح لمدير المشروع بحذف المهمة عبر tasks.delete في مصفوفة permissions'
        );
    }

    /**
     * مدير المشروع يجوز له إكمال المهمة (completeTask) — صلاحية قيادية محصورة.
     */
    public function test_scoped_manager_can_complete_project_task(): void
    {
        $user = $this->makeUser('viewer');
        $project = $this->makeProjectInOrg();
        $this->assignCanonicalRole($user, 'project_manager', 'project', (int) $project->id);
        $task = $this->makeProjectTask($project);

        $this->assertTrue(
            (new TaskPolicy)->completeTask($user, $task),
            'يجب السماح لمدير المشروع بإكمال المهمة (TASKS_COMPLETE عبر tasks.complete في مصفوفة permissions)'
        );
    }

    /**
     * مدير المشروع يجوز له تغيير حالة المهمة (can_edit=true).
     */
    public function test_scoped_manager_can_change_status_of_project_task(): void
    {
        $user = $this->makeUser('viewer');
        $project = $this->makeProjectInOrg();
        $this->assignCanonicalRole($user, 'project_manager', 'project', (int) $project->id);
        $task = $this->makeProjectTask($project);

        $this->assertTrue(
            (new TaskPolicy)->changeStatus($user, $task),
            'يجب السماح لمدير المشروع بتغيير الحالة (TASKS_EDIT عبر can_edit=true)'
        );
    }

    // ========================================================================
    // (19) Positive Lock — Assignee (completeTask denied)
    // مرحلة هـ: مسار assigned_to الخاص بـ changeStatus أُزيل من السياسة.
    // completeTask للمكلف: DENY (قفل قيادي).
    // ========================================================================

    /**
     * المكلّف بمهمة لا يجوز له إكمالها (completeTask للقيادة فقط) — هذا قفل قيادي.
     * مرحلة هـ: engine-only — المكلف بلا دور is_admin_role لا يملك TASKS_COMPLETE.
     */
    public function test_task_assignee_cannot_complete_task(): void
    {
        $user = $this->makeUser('viewer');
        $project = $this->makeProjectInOrg();
        $task = $this->makeProjectTask($project, null, [
            'assigned_to' => $user->id,
        ]);

        $this->assertFalse(
            (new TaskPolicy)->completeTask($user, $task),
            'يجب رفض إكمال المهمة للمكلَّف — completeTask للقيادة فقط (مرحلة هـ: engine-only)'
        );
    }

    // ========================================================================
    // DELETED TESTS — مسارات flat-fallback أُزيلت في مرحلة هـ
    // ========================================================================
    //
    // test_task_assignee_can_change_status:
    //   DELETED — اختبار مسار flat-fallback ($task->assigned_to === $user->id في changeStatus).
    //   مرحلة هـ: changeStatus يستدعي TASKS_EDIT من المحرّك. المكلف بلا دور is_admin_role/can_edit
    //   يُرفض. السلوك مُضيَّق عن قصد — التعديل صلاحية دور سياقي لا صلاحية مكلّف.
    //
    // test_creator_can_update_todo_task:
    //   DELETED — اختبار مسار flat-fallback ($task->created_by === $user->id + status=todo).
    //   مرحلة هـ: engine-only — المنشئ بلا دور مشروع سياقي يُرفض (لا can_edit).
    //
    // test_creator_cannot_update_in_progress_task:
    //   DELETED — اختبار مسار flat-fallback (creator + status!=todo). مرحلة هـ:
    //   المنشئ بلا دور سياقي يُرفض في كلا الحالتين (todo وin_progress) — التمييز بينهما
    //   لم يعد ذا معنى في engine-only.

    // ========================================================================
    // (23) Restore Delegation
    // ========================================================================

    /**
     * restore() يجب أن يفوّض إلى delete() بالضبط — نتيجة متطابقة لكلا الدورين.
     */
    public function test_restore_delegates_to_delete(): void
    {
        $project = $this->makeProjectInOrg();
        $policy = new TaskPolicy;

        // (أ) عضو — كلاهما يجب أن يعود false
        $otherDept = $this->makeOtherDepartment();
        $member = $this->makeUser('viewer', null, $otherDept->id);
        $this->assignCanonicalRole($member, 'project_member', 'project', (int) $project->id);
        $taskForMember = $this->makeProjectTask($project);

        $this->assertSame(
            $policy->delete($member, $taskForMember),
            $policy->restore($member, $taskForMember),
            'restore() يجب أن يطابق delete() لعضو المشروع — كلاهما يجب أن يعود false'
        );
        $this->assertFalse(
            $policy->restore($member, $taskForMember),
            'restore لعضو المشروع يجب أن يعود false (مثل delete)'
        );

        // (ب) مدير — كلاهما يجب أن يعود true
        $manager = $this->makeUser('viewer');
        $this->assignCanonicalRole($manager, 'project_manager', 'project', (int) $project->id);
        $taskForManager = $this->makeProjectTask($project);

        $this->assertSame(
            $policy->delete($manager, $taskForManager),
            $policy->restore($manager, $taskForManager),
            'restore() يجب أن يطابق delete() لمدير المشروع — كلاهما يجب أن يعود true'
        );
        $this->assertTrue(
            $policy->restore($manager, $taskForManager),
            'restore لمدير المشروع يجب أن يعود true (مثل delete)'
        );
    }

    // ========================================================================
    // (24) Super Admin — Bypass عبر before()
    // ========================================================================

    /**
     * super_admin يتجاوز كل الصلاحيات عبر before() — حتى لمهمة في مؤسسة أخرى.
     * نستخدم Gate::forUser لإثبات استدعاء before() فعلياً.
     */
    public function test_super_admin_bypasses_all_via_before(): void
    {
        $sa = $this->makeUser('super_admin');
        [, $deptB, $projectB] = $this->makeOtherOrgEnv();
        $taskInOtherOrg = $this->makeProjectTask($projectB, $deptB->id);

        $this->assertTrue(
            (new TaskPolicy)->before($sa, 'any_ability'),
            'before() يجب أن يرجع true لأي صلاحية لـ super_admin'
        );
        $this->assertTrue(
            Gate::forUser($sa)->allows('view', $taskInOtherOrg),
            'super_admin يجب أن يستطيع عرض مهمة في مؤسسة أخرى عبر بوابة Gate'
        );
    }
}
