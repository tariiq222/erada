<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * UnifiedTaskAuthorizationTest — مرحلة هـ: engine-only
 *
 * تغييرات مرحلة هـ (سلوك مُضيَّق عن قصد):
 * - admin بلا دور مشروع → DENY (أُزيل مسار canAccessViaDepartment).
 * - assignee (PROJECT_MEMBER) → DENY لـ changeStatus/uploadAttachment (can_edit=false).
 * - assignee (PROJECT_MEMBER) → ALLOW لـ view/comment (can_view_all=true).
 *
 * Phase 0 owner floor (deliberate behavior change, supersedes the prior stance):
 * - creator/owner → ALLOW edit while the task lifecycle permits (status != completed);
 *   ALLOW view unconditionally; DENY delete (ownership never grants delete).
 *
 * الاختبارات المحذوفة من هذا الملف (سلوك flat-fallback):
 * - test_admin_in_project_department_can_complete_project_task: يختبر admin+canAccessViaDepartment.
 * - test_assignee_project_member_can_send_task_to_review: changeStatus → TASKS_EDIT → DENY لـ MEMBER.
 * - test_assignee_can_upload_attachment_on_assigned_task: uploadAttachment → TASKS_EDIT → DENY لـ MEMBER.
 * - test_assignee_can_change_status_via_policy: changeStatus → TASKS_EDIT → DENY لـ MEMBER.
 */
class UnifiedTaskAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
    }

    public function test_super_admin_can_complete_project_task(): void
    {
        $fixture = $this->projectTaskFixture();
        $superAdmin = $this->userIn($fixture['organization'], $fixture['department'], 'super_admin');
        $task = $this->projectTask($fixture['project'], $fixture['department']);

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson($this->statusUrl($task), ['status' => TaskStatus::COMPLETED->value])
            ->assertOk();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => TaskStatus::COMPLETED->value,
        ]);
    }

    // DELETED: test_admin_in_project_department_can_complete_project_task
    // مرحلة هـ: admin بلا دور مشروع سياقي يُرفض (DENY) — مسار canAccessViaDepartment أُزيل.
    // السلوك المقصود: الإكمال يتطلب capability canonical داخل نطاق المشروع.

    public function test_project_manager_can_complete_project_task(): void
    {
        $fixture = $this->projectTaskFixture();
        $task = $this->projectTask($fixture['project'], $fixture['department']);

        // مدير المشروع (scoped manager, is_admin_role=true) يملك صلاحية إكمال المهمة.
        $this->actingAs($fixture['manager'], 'sanctum')
            ->patchJson($this->statusUrl($task), ['status' => TaskStatus::COMPLETED->value])
            ->assertOk();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => TaskStatus::COMPLETED->value,
        ]);
    }

    public function test_second_project_manager_can_complete_project_task(): void
    {
        $fixture = $this->projectTaskFixture();
        $task = $this->projectTask($fixture['project'], $fixture['department']);

        // المشرف (دور قيادي = scoped manager) يملك الإكمال كذلك.
        $this->actingAs($fixture['supervisor'], 'sanctum')
            ->patchJson($this->statusUrl($task), ['status' => TaskStatus::COMPLETED->value])
            ->assertOk();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => TaskStatus::COMPLETED->value,
        ]);
    }

    // DELETED: test_assignee_project_member_can_send_task_to_review
    // مرحلة هـ: changeStatus → TASKS_EDIT → DENY لـ PROJECT_MEMBER (can_edit=false).
    // السلوك المقصود: تغيير الحالة صلاحية التعديل، لا صلاحية assigned_to.

    /**
     * PROJECT_MEMBER يرى المهمة (can_view_all=true) → يستطيع التعليق.
     * comment() يفوّض إلى view() → ALLOW لـ PROJECT_MEMBER في مشروعه.
     */
    public function test_assignee_can_comment_on_assigned_task(): void
    {
        $fixture = $this->projectTaskFixture();
        $assignee = $this->userIn($fixture['organization'], $fixture['department'], 'viewer');
        $this->assignCanonicalRole($assignee, 'project_member', 'project', $fixture['project']->id);
        $task = $this->projectTask($fixture['project'], $fixture['department'], [
            'assigned_to' => $assignee->id,
        ]);

        $this->assertTrue(Gate::forUser($assignee)->allows('comment', $task));
    }

    // DELETED: test_assignee_can_upload_attachment_on_assigned_task
    // مرحلة هـ: uploadAttachment → TASKS_EDIT → DENY لـ PROJECT_MEMBER (can_edit=false).

    // DELETED: test_assignee_can_change_status_via_policy
    // مرحلة هـ: changeStatus → TASKS_EDIT → DENY لـ PROJECT_MEMBER (can_edit=false).

    /**
     * PROJECT_MEMBER لا يستطيع إكمال المهمة (completeTask = صلاحية قيادية).
     */
    public function test_assignee_project_member_cannot_complete_task(): void
    {
        $fixture = $this->projectTaskFixture();
        $assignee = $this->userIn($fixture['organization'], $fixture['department'], 'viewer');
        $this->assignCanonicalRole($assignee, 'project_member', 'project', $fixture['project']->id);
        $task = $this->projectTask($fixture['project'], $fixture['department'], [
            'assigned_to' => $assignee->id,
        ]);

        $this->actingAs($assignee, 'sanctum')
            ->patchJson($this->statusUrl($task), ['status' => TaskStatus::COMPLETED->value])
            ->assertForbidden();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => TaskStatus::TODO->value,
        ]);
    }

    /**
     * PROJECT_MEMBER لا يستطيع تعديل بيانات المهمة (update → TASKS_EDIT, can_edit=false).
     */
    public function test_assignee_project_member_cannot_update_task_details(): void
    {
        $fixture = $this->projectTaskFixture();
        $assignee = $this->userIn($fixture['organization'], $fixture['department'], 'viewer');
        $this->assignCanonicalRole($assignee, 'project_member', 'project', $fixture['project']->id);
        $task = $this->projectTask($fixture['project'], $fixture['department'], [
            'assigned_to' => $assignee->id,
        ]);

        $this->actingAs($assignee, 'sanctum')
            ->putJson($this->taskUrl($task), ['title' => 'تعديل غير مسموح للمكلف'])
            ->assertForbidden();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => $task->title,
        ]);
    }

    /**
     * Phase 0 owner floor (deliberate behavior change): a task creator with no
     * contextual project role MAY edit their own task while its lifecycle allows
     * (status != completed). This supersedes the prior engine-only cutover stance
     * that denied any created_by edit. View is unconditional for the owner; edit
     * is lifecycle-gated via Task::isOwnerEditable(); ownership never grants delete.
     */
    public function test_creator_can_update_in_progress_task_via_owner_floor(): void
    {
        $fixture = $this->projectTaskFixture();
        $creator = $this->userIn($fixture['organization'], $fixture['department'], 'viewer');
        $task = $this->projectTask($fixture['project'], $fixture['department'], [
            'created_by' => $creator->id,
            'assigned_to' => $fixture['manager']->id,
            'status' => TaskStatus::IN_PROGRESS->value,
        ]);

        $this->actingAs($creator, 'sanctum')
            ->putJson($this->taskUrl($task), ['title' => 'تعديل بعد بدء التنفيذ'])
            ->assertOk();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'تعديل بعد بدء التنفيذ',
        ]);
    }

    /**
     * Owner floor is lifecycle-gated: once the task is completed, the creator can
     * no longer edit it through the owner floor (and holds no project role).
     */
    public function test_creator_cannot_update_completed_task_owner_floor_lifecycle_gated(): void
    {
        $fixture = $this->projectTaskFixture();
        $creator = $this->userIn($fixture['organization'], $fixture['department'], 'viewer');
        $task = $this->projectTask($fixture['project'], $fixture['department'], [
            'created_by' => $creator->id,
            'assigned_to' => $fixture['manager']->id,
            'status' => TaskStatus::COMPLETED->value,
        ]);

        $this->actingAs($creator, 'sanctum')
            ->putJson($this->taskUrl($task), ['title' => 'تعديل بعد الإكمال'])
            ->assertForbidden();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => $task->title,
        ]);
    }

    /**
     * Ownership never grants delete: a creator with no project role cannot delete
     * their own task even while it is editable (status != completed).
     */
    public function test_creator_cannot_delete_own_task_via_owner_floor(): void
    {
        $fixture = $this->projectTaskFixture();
        $creator = $this->userIn($fixture['organization'], $fixture['department'], 'viewer');
        $task = $this->projectTask($fixture['project'], $fixture['department'], [
            'created_by' => $creator->id,
            'assigned_to' => $fixture['manager']->id,
            'status' => TaskStatus::IN_PROGRESS->value,
        ]);

        $this->actingAs($creator, 'sanctum')
            ->deleteJson($this->taskUrl($task))
            ->assertForbidden();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'deleted_at' => null,
        ]);
    }

    /**
     * PROJECT_VIEWER يرى المهمة (can_view_all=true) لكنه لا يعدّلها (can_edit=false).
     */
    public function test_viewer_can_view_task_but_cannot_update_it(): void
    {
        $fixture = $this->projectTaskFixture();
        $task = $this->projectTask($fixture['project'], $fixture['department']);

        // الراعي (سابقاً) أصبح مشاهداً (scoped viewer)
        $this->actingAs($fixture['sponsor'], 'sanctum')
            ->getJson($this->taskUrl($task))
            ->assertOk();

        $this->actingAs($fixture['sponsor'], 'sanctum')
            ->putJson($this->taskUrl($task), ['title' => 'تعديل غير مسموح للمشاهد'])
            ->assertForbidden();
    }

    public function test_user_from_other_organization_cannot_view_update_or_change_status(): void
    {
        $fixture = $this->projectTaskFixture();
        $task = $this->projectTask($fixture['project'], $fixture['department']);
        [$otherOrg, $otherDept] = $this->organizationDepartment();
        $otherUser = $this->userIn($otherOrg, $otherDept, 'viewer');

        $this->actingAs($otherUser, 'sanctum')
            ->getJson($this->taskUrl($task))
            ->assertForbidden();

        $this->actingAs($otherUser, 'sanctum')
            ->putJson($this->taskUrl($task), ['title' => 'محاولة عابرة للمنظمات'])
            ->assertForbidden();

        $this->actingAs($otherUser, 'sanctum')
            ->patchJson($this->statusUrl($task), ['status' => TaskStatus::IN_REVIEW->value])
            ->assertForbidden();
    }

    /**
     * @return array{
     *     organization: Organization,
     *     department: Department,
     *     project: Project,
     *     manager: User,
     *     supervisor: User,
     *     sponsor: User
     * }
     */
    private function projectTaskFixture(): array
    {
        [$organization, $department] = $this->organizationDepartment();
        $otherDepartment = Department::factory()->create(['organization_id' => $organization->id]);

        $manager = $this->userIn($organization, $otherDepartment, 'viewer');
        $supervisor = $this->userIn($organization, $otherDepartment, 'viewer');
        $sponsor = $this->userIn($organization, $otherDepartment, 'viewer');

        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
        ]);

        // بعد توحيد الأدوار: المدير والمشرف (سابقاً) كلاهما scoped manager.
        // الراعي (سابقاً) يصبح مشاهداً (scoped viewer).
        $this->assignCanonicalRole($manager, 'project_manager', 'project', $project->id);
        $this->assignCanonicalRole($supervisor, 'project_manager', 'project', $project->id);
        $this->assignCanonicalRole($sponsor, 'project_viewer', 'project', $project->id);

        return [
            'organization' => $organization,
            'department' => $department,
            'project' => $project,
            'manager' => $manager,
            'supervisor' => $supervisor,
            'sponsor' => $sponsor,
        ];
    }

    /**
     * @return array{0: Organization, 1: Department}
     */
    private function organizationDepartment(): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);

        return [$organization, $department];
    }

    private function userIn(Organization $organization, Department $department, string $role): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($user, $role);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function projectTask(Project $project, Department $department, array $overrides = []): Task
    {
        return Task::factory()->create(array_merge([
            'type' => TaskType::PROJECT->value,
            'project_id' => $project->id,
            'department_id' => $department->id,
            'status' => TaskStatus::TODO->value,
            'progress' => 0,
            'created_by' => null,
            'assigned_to' => null,
            'owner_id' => null,
            'parent_id' => null,
        ], $overrides));
    }

    private function taskUrl(Task $task): string
    {
        return "/api/unified-tasks/{$task->id}";
    }

    private function statusUrl(Task $task): string
    {
        return "{$this->taskUrl($task)}/status";
    }
}
