<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Policies\TaskPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * P0-09 / P0-05 — عزل المهام بين المنظمات وتوحيد إنفاذ TaskPolicy لكل فعل × دور.
 *
 * project_manager يملك صلاحيات tasks.view/edit/delete/complete في مصفوفة permissions
 * لتعريفه السياقي (وليس عبر is_admin_role=true) فيجب أن يبقى محصوراً داخل
 * منظمته ولا يصل لمهام منظمة أخرى.
 *
 * Phase 2 يوسّع هذا السويت ليغطي:
 * - D-02: المهمة غير الشخصية بلا org قابلة للتحديد تُرفض لغير المالك/المُسند.
 * - D-04: assign() يرفض هدفاً عابراً للمؤسسة (cross-org) ويقبل نفس-المؤسسة/عضو-المشروع.
 * - D-05: مدير المشروع (scoped manager) يعدّل ويحذف؛ عضو المشروع (member) لا يفعل أياً منهما (مصفوفة permissions هي التي تمنح).
 * - D-01: المهمة الشخصية يحكمها المالك فقط.
 * - delegation: changeStatus→update و restore→delete.
 * - D-03: super_admin يتجاوز عبر before().
 */
class TaskAccessIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected User $pmA;

    protected Task $taskB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);

        $this->pmA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $deptA->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->pmA, 'viewer');

        $projectB = Project::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $deptB->id,
        ]);

        $this->taskB = Task::factory()->create([
            'type' => 'project',
            'project_id' => $projectB->id,
            'department_id' => $deptB->id,
        ]);
    }

    // ========== Helpers (lifted from ProjectAccessIsolationTest) ==========

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

    private function makeProject(Organization $org, Department $dept): Project
    {
        return Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
    }

    // ========== Pre-existing cross-org tests (keep) ==========

    public function test_pm_cannot_show_task_in_other_org(): void
    {
        $response = $this->actingAs($this->pmA, 'sanctum')
            ->getJson("/api/unified-tasks/{$this->taskB->id}");

        $this->assertContains($response->status(), [403, 404], 'يجب منع رؤية مهمة منظمة أخرى');
    }

    public function test_pm_cannot_update_task_in_other_org(): void
    {
        $response = $this->actingAs($this->pmA, 'sanctum')
            ->putJson("/api/unified-tasks/{$this->taskB->id}", [
                'title' => 'محاولة تعديل عابرة للمنظمات',
            ]);

        $this->assertContains($response->status(), [403, 404], 'يجب منع تعديل مهمة منظمة أخرى');
    }

    public function test_pm_cannot_delete_task_in_other_org(): void
    {
        $response = $this->actingAs($this->pmA, 'sanctum')
            ->deleteJson("/api/unified-tasks/{$this->taskB->id}");

        $this->assertContains($response->status(), [403, 404], 'يجب منع حذف مهمة منظمة أخرى');
        $this->assertDatabaseHas('tasks', ['id' => $this->taskB->id, 'deleted_at' => null]);
    }

    // ========== D-02: non-personal unlinked task (null-org bypass) ==========

    /**
     * D-02 (RED before Wave 1): مهمة غير شخصية (type=department) بلا project_id/department_id
     * تتجاوز عزل المؤسسة حالياً. بعد الإصلاح: pmA (يملك view/edit/delete_tasks العامة، منظمة أخرى،
     * ليس مالكاً/مُسنداً) يُمنع على view/update/delete.
     */
    public function test_non_personal_unlinked_task_denied_to_non_owner(): void
    {
        $orphan = Task::factory()->create([
            'type' => 'department',
            'project_id' => null,
            'department_id' => null,
        ]);

        $this->actingAs($this->pmA, 'sanctum')
            ->getJson("/api/unified-tasks/{$orphan->id}")
            ->assertStatus(403);

        $this->actingAs($this->pmA, 'sanctum')
            ->putJson("/api/unified-tasks/{$orphan->id}", ['title' => 'x'])
            ->assertStatus(403);

        $this->actingAs($this->pmA, 'sanctum')
            ->deleteJson("/api/unified-tasks/{$orphan->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('tasks', ['id' => $orphan->id, 'deleted_at' => null]);
    }

    // ========== D-04: assign() write-target IDOR ==========

    /**
     * D-04 (RED before Wave 1): هدف assign من مؤسسة أخرى يجب أن يُرفض (403/422).
     */
    public function test_assign_to_user_in_other_org_rejected(): void
    {
        $deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $projectA = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $deptA->id,
        ]);
        $taskA = Task::factory()->create([
            'type' => 'project',
            'project_id' => $projectA->id,
            'department_id' => $deptA->id,
        ]);
        $leaderA = $this->makeUser($this->orgA, $deptA, 'viewer');
        $this->assignCanonicalRole($leaderA, 'project_manager', 'project', $projectA->id);

        $deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);
        $foreign = $this->makeUser($this->orgB, $deptB, 'viewer');

        $response = $this->actingAs($leaderA, 'sanctum')
            ->patchJson("/api/unified-tasks/{$taskA->id}/assign", [
                'assigned_to' => $foreign->id,
            ]);

        $this->assertContains($response->status(), [403, 422], 'تعيين عابر للمؤسسات يجب أن يُرفض');
        $this->assertDatabaseMissing('tasks', [
            'id' => $taskA->id,
            'assigned_to' => $foreign->id,
        ]);
    }

    /**
     * D-04 (positive-lock): هدف عضو في نفس مشروع المهمة → 200 والتعيين يُحفظ.
     */
    public function test_assign_to_project_member_succeeds(): void
    {
        $deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $projectA = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $deptA->id,
        ]);
        $taskA = Task::factory()->create([
            'type' => 'project',
            'project_id' => $projectA->id,
            'department_id' => $deptA->id,
        ]);
        $leaderA = $this->makeUser($this->orgA, $deptA, 'viewer');
        $this->assignCanonicalRole($leaderA, 'project_manager', 'project', $projectA->id);

        $member = $this->makeUser($this->orgA, $deptA, 'viewer');
        $this->assignCanonicalRole($member, 'project_member', 'project', $projectA->id);

        $this->actingAs($leaderA, 'sanctum')
            ->patchJson("/api/unified-tasks/{$taskA->id}/assign", [
                'assigned_to' => $member->id,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('tasks', [
            'id' => $taskA->id,
            'assigned_to' => $member->id,
        ]);
    }

    /**
     * D-04 (positive-lock, RESOLVED 2026-06-08): عضو نفس المؤسسة لكن خارج المشروع
     * يجب أن يُسمح بتعيينه (نفس المؤسسة يكفي — لا تُشترط عضوية المشروع). المرفوض cross-org فقط.
     */
    public function test_assign_to_user_same_org_outside_project_allowed(): void
    {
        $deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $projectA = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $deptA->id,
        ]);
        $taskA = Task::factory()->create([
            'type' => 'project',
            'project_id' => $projectA->id,
            'department_id' => $deptA->id,
        ]);
        $leaderA = $this->makeUser($this->orgA, $deptA, 'viewer');
        $this->assignCanonicalRole($leaderA, 'project_manager', 'project', $projectA->id);

        // same org, NOT a member of projectA
        $otherDeptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $sameOrgOutsider = $this->makeUser($this->orgA, $otherDeptA, 'viewer');

        $this->actingAs($leaderA, 'sanctum')
            ->patchJson("/api/unified-tasks/{$taskA->id}/assign", [
                'assigned_to' => $sameOrgOutsider->id,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('tasks', [
            'id' => $taskA->id,
            'assigned_to' => $sameOrgOutsider->id,
        ]);
    }

    public function test_project_manager_of_sibling_project_cannot_assign_task(): void
    {
        $deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $projectA = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $deptA->id,
        ]);
        $siblingProject = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $deptA->id,
        ]);
        $taskA = Task::factory()->create([
            'type' => 'project',
            'project_id' => $projectA->id,
            'department_id' => $deptA->id,
        ]);
        $siblingManager = $this->makeUser($this->orgA, $deptA, 'viewer');
        $this->assignCanonicalRole($siblingManager, 'project_manager', 'project', $siblingProject->id);
        $assignee = $this->makeUser($this->orgA, $deptA, 'viewer');

        $this->actingAs($siblingManager, 'sanctum')
            ->patchJson("/api/unified-tasks/{$taskA->id}/assign", [
                'assigned_to' => $assignee->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('tasks', [
            'id' => $taskA->id,
            'assigned_to' => $assignee->id,
        ]);
    }

    // ========== D-05 / S-06: PM edits but does not delete ==========

    /**
     * D-05 (موحّد بعد توحيد الأدوار): مدير المشروع (scoped manager) يعدّل (200)
     * ويحذف (200, soft-deleted)؛ عضو المشروع (member) لا يعدّل (403) ولا يحذف (403).
     *
     * بعد التوحيد: تعريف project_manager يحمل tasks.edit/tasks.delete في مصفوفة
     * permissions فيمنحهما المحرّك، وتعريف project_member يفتقر لهما فيرفض. فالمدير
     * يعدّل ويحذف، والعضو لا يفعل أياً منهما.
     */
    public function test_project_manager_can_edit_and_delete_but_member_cannot(): void
    {
        $deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $projectA = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $deptA->id,
        ]);
        $taskA = Task::factory()->create([
            'type' => 'project',
            'project_id' => $projectA->id,
            'department_id' => $deptA->id,
        ]);

        // عضو المشروع: لا يعدّل ولا يحذف
        $member = $this->makeUser($this->orgA, $deptA, 'viewer');
        $this->assignCanonicalRole($member, 'project_member', 'project', $projectA->id);

        $this->actingAs($member, 'sanctum')
            ->putJson("/api/unified-tasks/{$taskA->id}", ['title' => 'member edit'])
            ->assertStatus(403);

        $this->actingAs($member, 'sanctum')
            ->deleteJson("/api/unified-tasks/{$taskA->id}")
            ->assertStatus(403);
        $this->assertDatabaseHas('tasks', ['id' => $taskA->id, 'deleted_at' => null]);

        // مدير المشروع: يعدّل ثم يحذف
        $manager = $this->makeUser($this->orgA, $deptA, 'viewer');
        $this->assignCanonicalRole($manager, 'project_manager', 'project', $projectA->id);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/unified-tasks/{$taskA->id}", ['title' => 'manager edit'])
            ->assertStatus(200);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/unified-tasks/{$taskA->id}")
            ->assertStatus(200);
        $this->assertSoftDeleted('tasks', ['id' => $taskA->id]);
    }

    // ========== D-01: personal task ownership path ==========

    /**
     * D-01 regression-lock: المهمة الشخصية يحكمها المالك فقط؛ مستخدم آخر (حتى نفس المؤسسة) → 403.
     */
    public function test_personal_task_owner_can_edit_and_delete_but_other_user_cannot(): void
    {
        $deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $owner = $this->makeUser($this->orgA, $deptA, 'viewer');

        $personal = Task::factory()->create([
            'type' => 'personal',
            'project_id' => null,
            'department_id' => null,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_to' => $owner->id,
        ]);

        // owner edits → 200
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/unified-tasks/{$personal->id}", ['title' => 'mine'])
            ->assertStatus(200);

        // a DIFFERENT same-org user is denied view/update
        $other = $this->makeUser($this->orgA, $deptA, 'viewer');
        $this->actingAs($other, 'sanctum')
            ->getJson("/api/unified-tasks/{$personal->id}")
            ->assertStatus(403);
        $this->actingAs($other, 'sanctum')
            ->putJson("/api/unified-tasks/{$personal->id}", ['title' => 'theirs'])
            ->assertStatus(403);

        // owner deletes → 200, soft-deleted
        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/unified-tasks/{$personal->id}")
            ->assertStatus(200);
        $this->assertSoftDeleted('tasks', ['id' => $personal->id]);
    }

    // ========== delegation: changeStatus -> update ==========

    /**
     * delegation regression-lock: updateStatus (changeStatus→update) يطبّق نفس allow/deny كـ update.
     * مدير المشروع (scoped manager, isProjectAdmin) → 200؛ مستخدم نفس المؤسسة من مشروع آخر (غير مخوّل) → 403.
     */
    public function test_change_status_mirrors_update_authorization(): void
    {
        $deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $projectA = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $deptA->id,
        ]);
        $taskA = Task::factory()->create([
            'type' => 'project',
            'project_id' => $projectA->id,
            'department_id' => $deptA->id,
        ]);

        // authorized: project manager (isProjectAdmin) → can changeStatus
        $pm = $this->makeUser($this->orgA, $deptA, 'viewer');
        $this->assignCanonicalRole($pm, 'project_manager', 'project', $projectA->id);
        $this->actingAs($pm, 'sanctum')
            ->patchJson("/api/unified-tasks/{$taskA->id}/status", ['status' => 'in_progress'])
            ->assertStatus(200);

        // unauthorized: same org but a DIFFERENT department, member of an UNRELATED project → 403
        // (no role in taskA's project, and not in taskA's department so edit_own_tasks/owner path fails)
        $otherDeptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $otherProject = $this->makeProject($this->orgA, $otherDeptA);
        $outsider = $this->makeUser($this->orgA, $otherDeptA, 'viewer');
        $this->assignCanonicalRole($outsider, 'project_member', 'project', $otherProject->id);
        $this->actingAs($outsider, 'sanctum')
            ->patchJson("/api/unified-tasks/{$taskA->id}/status", ['status' => 'in_progress'])
            ->assertStatus(403);
    }

    /**
     * delegation regression-lock: restore() يفوّض إلى delete() (TaskPolicy::restore).
     * لا يوجد route مكشوف لـ restore، فنثبت إنفاذ الـ authorization على مستوى الـ Gate مباشرة:
     * عضو المشروع (member) لا يملك delete ⇒ لا يملك restore؛ مدير المشروع (manager) يملكهما.
     */
    public function test_restore_mirrors_delete_authorization(): void
    {
        $deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $projectA = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $deptA->id,
        ]);
        $taskA = Task::factory()->create([
            'type' => 'project',
            'project_id' => $projectA->id,
            'department_id' => $deptA->id,
        ]);

        $member = $this->makeUser($this->orgA, $deptA, 'viewer');
        $this->assignCanonicalRole($member, 'project_member', 'project', $projectA->id);

        $manager = $this->makeUser($this->orgA, $deptA, 'viewer');
        $this->assignCanonicalRole($manager, 'project_manager', 'project', $projectA->id);

        // restore delegates to delete: member denied both, manager allowed both.
        $this->assertTrue(Gate::forUser($member)->denies('restore', $taskA));
        $this->assertTrue(Gate::forUser($member)->denies('delete', $taskA));
        $this->assertTrue(Gate::forUser($manager)->allows('restore', $taskA));
        $this->assertTrue(Gate::forUser($manager)->allows('delete', $taskA));

        // sanity: restore and delete resolve identically for the same actor (TaskPolicy::restore -> delete)
        $policy = new TaskPolicy;
        $this->assertSame($policy->delete($member, $taskA), $policy->restore($member, $taskA));
        $this->assertSame($policy->delete($manager, $taskA), $policy->restore($manager, $taskA));
    }

    // ========== D-03: super_admin bypass sanity ==========

    /**
     * D-03 sanity: super_admin يتجاوز عبر before() — view/update/delete على taskB (منظمة أخرى) كلها تنجح.
     */
    public function test_super_admin_bypasses_all(): void
    {
        $deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $superAdmin = $this->makeUser($this->orgA, $deptA, 'super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/unified-tasks/{$this->taskB->id}")
            ->assertStatus(200);

        $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/unified-tasks/{$this->taskB->id}", ['title' => 'sa edit'])
            ->assertStatus(200);

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/unified-tasks/{$this->taskB->id}")
            ->assertStatus(200);
        $this->assertSoftDeleted('tasks', ['id' => $this->taskB->id]);
    }
}
