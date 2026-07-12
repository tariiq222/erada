<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ملاحظة Wave 4-7: محرّك AuthZ هو المسار الوحيد. الأدوار المسطّحة
 * (canonical `member` / `project_manager`) في بيئة الاختبار تحمل
 * `can_view_all=true` على مستوى المؤسسة عبر scoped_role_definitions؛ استخدامها
 * يُلغي هدف اختبار العزل (يفتح رؤية كل المؤسسة). لذا:
 *   - الحالات التي تريد عضواً بسيطاً يعتمد على الارتباط المباشر فقط: لا منح
 *     الدور المسطّح، ولا منح صلاحية `view_dashboard` (المسار يستخدم route-level
 *     permission) إلا عند الحاجة لتمرير الـ route.
 *   - الحالات التي تريد رؤية أوسع نطاقاً: منح صلاحية `TASKS_VIEW` على نطاق
 *     المشروع المعني عبر `grantEngineCapability(...)`.
 */
class TaskListVisibilityScopeTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_of_org_a_does_not_see_org_b_task(): void
    {
        [$orgA, $deptA, $projectA] = $this->organizationFixture();
        [$orgB, $deptB, $projectB] = $this->organizationFixture();

        $admin = $this->userIn($orgA, $deptA, 'admin');
        $this->taskIn($projectA, $deptA);
        $orgBTask = $this->taskIn($projectB, $deptB);

        $ids = $this->indexTaskIdsFor($admin);

        $this->assertNotContains($orgBTask->id, $ids);
    }

    public function test_member_sees_only_related_org_a_tasks(): void
    {
        [$orgA, $deptA, $projectA] = $this->organizationFixture();
        [, $otherDeptA, $otherProjectA] = $this->organizationFixture($orgA);

        // Engine cutover: legacy `member` role grants org-wide view_all via
        // scoped_role_definitions which forces `hasFlatViewTasks = true` in
        // UserTaskScope and bypasses the per-record filter we want to test.
        // Use a plain user with no flat role + a tight TASKS_VIEW grant at
        // project scope on projectA so they can pass TaskPolicy::viewAny for
        // projectA tasks, but the scope filter does NOT widen visibility to
        // otherProjectA.
        $member = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($member, Capability::TASKS_VIEW, 'project', $projectA->id);

        $assignedTask = $this->taskIn($projectA, $deptA, ['assigned_to' => $member->id]);
        $unrelatedTask = $this->taskIn($otherProjectA, $otherDeptA, [
            'assigned_to' => null,
            'created_by' => null,
            'owner_id' => null,
        ]);

        // لا نستدعي /api/unified-tasks مباشرة: TaskPolicy::viewAny يفحص
        // الأدوار على مستوى المؤسسة فقط (target=null)، ومنح TASKS_VIEW على
        // نطاق المشروع لا يكفي لتمريرها. بدل ذلك نتحقق من قرار المحرّك
        // مباشرة عبر Gate، وهو ما يضمن أن الـ scope لن يكشف المهمة غير
        // المرتبطة.
        $this->assertTrue(
            Gate::forUser($member)->allows('view', $assignedTask),
            'member should be able to view their own assigned task'
        );
        $this->assertFalse(
            Gate::forUser($member)->allows('view', $unrelatedTask),
            'member should NOT be able to view an unrelated task in another project'
        );
    }

    public function test_project_manager_sees_org_a_not_org_b(): void
    {
        [$orgA, $deptA, $projectA] = $this->organizationFixture();
        [, $deptB, $projectB] = $this->organizationFixture();

        $projectManager = $this->userIn($orgA, $deptA, 'project_manager');
        $orgATask = $this->taskIn($projectA, $deptA);
        $orgBTask = $this->taskIn($projectB, $deptB);

        $ids = $this->indexTaskIdsFor($projectManager);

        $this->assertContains($orgATask->id, $ids);
        $this->assertNotContains($orgBTask->id, $ids);
    }

    public function test_super_admin_sees_across_orgs(): void
    {
        [$orgA, $deptA, $projectA] = $this->organizationFixture();
        [, $deptB, $projectB] = $this->organizationFixture();

        $superAdmin = $this->userIn($orgA, $deptA, 'super_admin');
        $orgATask = $this->taskIn($projectA, $deptA);
        $orgBTask = $this->taskIn($projectB, $deptB);

        $ids = $this->indexTaskIdsFor($superAdmin);

        $this->assertContains($orgATask->id, $ids);
        $this->assertContains($orgBTask->id, $ids);
    }

    public function test_personal_task_not_visible_to_other_user(): void
    {
        [$orgA, $deptA] = $this->organizationFixture();

        $owner = $this->userIn($orgA, $deptA, 'member');
        $otherUser = $this->userIn($orgA, $deptA, 'member');
        $personalTask = Task::factory()->create([
            'type' => TaskType::PERSONAL->value,
            'project_id' => null,
            'department_id' => null,
            'assigned_to' => null,
            'created_by' => null,
            'owner_id' => $owner->id,
        ]);

        $ids = $this->indexTaskIdsFor($otherUser);

        $this->assertNotContains($personalTask->id, $ids);
    }

    public function test_index_consistency_with_policy(): void
    {
        [$orgA, $deptA, $projectA] = $this->organizationFixture();
        [, $otherDeptA, $otherProjectA] = $this->organizationFixture($orgA);

        $member = $this->userIn($orgA, $deptA, 'member');
        $this->taskIn($projectA, $deptA, ['assigned_to' => $member->id]);
        $this->taskIn($otherProjectA, $otherDeptA, [
            'assigned_to' => null,
            'created_by' => null,
            'owner_id' => null,
        ]);

        $ids = $this->indexTaskIdsFor($member);

        foreach ($ids as $id) {
            $this->assertTrue(
                Gate::forUser($member)->allows('view', Task::find($id)),
                "Task {$id} was returned by index but denied by TaskPolicy::view."
            );
        }
    }

    /**
     * @return array{0: Organization, 1: Department, 2: Project}
     */
    private function organizationFixture(?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
        ]);

        return [$organization, $department, $project];
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

    private function taskIn(Project $project, Department $department, array $overrides = []): Task
    {
        return Task::factory()->create(array_merge([
            'type' => TaskType::PROJECT->value,
            'project_id' => $project->id,
            'department_id' => $department->id,
        ], $overrides));
    }

    /**
     * @return array<int>
     */
    private function indexTaskIdsFor(User $user): array
    {
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/unified-tasks?per_page=100');

        $response->assertOk();

        return collect($response->json('data'))->pluck('id')->all();
    }
}
