<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Scopes\UserProjectScope;
use App\Modules\Projects\Scopes\UserTaskScope;
use App\Modules\Projects\Services\ProjectQueryService;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ListElementParityTest — Phase 0, Tasks 2 and 3.
 *
 * Proves that "what I can open" equals "what appears in my list" for vertical
 * (subtree) access: a manager with a department-scoped projects.view role on a
 * parent (sector) department can both open AND list a project (and its tasks)
 * that live in a child department, after subtree expansion is wired into the
 * project list scopes.
 *
 * NOTE ON SETUP (deviation from the plan's illustrative snippet): this branch
 * does not yet contain ScopedDepartmentRolesSeeder or DepartmentCapacityRole
 * (introduced in a later phase). The real, existing mechanism for a department
 * manager is a department-scoped ScopedRoleDefinition + assignScopedRole, mirrored
 * from DepartmentAuthzParityTest. The Phase 0 production change (subtree expansion)
 * is independent of that seeder.
 */
class ListElementParityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Department $sector;

    private Department $child;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->sector = Department::factory()->create([
            'organization_id' => $this->org->id,
            'parent_id' => null,
        ]);
        $this->child = Department::factory()->create([
            'organization_id' => $this->org->id,
            'parent_id' => $this->sector->id,
        ]);

        // A department-scoped role that grants projects.view (and tasks.view).
        $roleDefinition = $this->createDeptViewRoleDefinition('dept_manager_view');

        $this->manager = User::factory()->create(['organization_id' => $this->org->id]);
        $this->manager->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: ScopedRole::SCOPE_DEPARTMENT,
            scopeId: $this->sector->id,
            inheritToChildren: true,
        );

        Cache::flush();
    }

    // =========================================================
    // Task 2: project list/element parity (child-department project)
    // =========================================================

    public function test_sector_manager_sees_child_department_project_in_list(): void
    {
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->child->id,
        ]);

        // element: can open
        $this->assertTrue(
            AccessDecision::can($this->manager->fresh(), Capability::PROJECTS_VIEW, $project),
            'manager can open a project in a child department via the scope chain'
        );

        // list: appears (parity)
        $visible = app(ProjectQueryService::class)
            ->applyPermissionFilter(Project::query(), $this->manager->fresh())
            ->pluck('id');

        $this->assertTrue(
            $visible->contains($project->id),
            'the child-department project must appear in the manager list (subtree parity)'
        );
    }

    public function test_user_project_scope_also_sees_child_department_project(): void
    {
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->child->id,
        ]);

        $visible = app(UserProjectScope::class)
            ->apply(Project::query(), $this->manager->fresh())
            ->pluck('id');

        $this->assertTrue(
            $visible->contains($project->id),
            'UserProjectScope (dashboard) must match the list scope via subtree expansion'
        );
    }

    // =========================================================
    // Task 3: task list/element parity (task in a child-department project)
    // =========================================================

    public function test_sector_manager_sees_task_in_child_department_project(): void
    {
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->child->id,
        ]);
        $task = Task::factory()->create(['project_id' => $project->id]);

        $this->assertTrue(
            AccessDecision::can($this->manager->fresh(), Capability::TASKS_VIEW, $task),
            'manager can open a task in a child-department project via the scope chain'
        );

        $visible = app(UserTaskScope::class)
            ->apply(Task::query(), $this->manager->fresh())
            ->pluck('id');

        $this->assertTrue(
            $visible->contains($task->id),
            'the task must appear in the manager task list (inherits project subtree parity)'
        );
    }

    // =========================================================
    // Helper: a department-scoped role definition granting projects.view
    // =========================================================

    private function createDeptViewRoleDefinition(string $roleKey): ScopedRoleDefinition
    {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_DEPARTMENT],
            [
                'label_ar' => 'القسم',
                'label_en' => 'Department',
                'model_class' => Department::class,
                'supports_hierarchy' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        $existingId = DB::table('scoped_role_definitions')
            ->where('scope_type_id', $scopeType->id)
            ->where('role_key', $roleKey)
            ->value('id');

        if (! $existingId) {
            $existingId = DB::table('scoped_role_definitions')->insertGetId([
                'scope_type_id' => $scopeType->id,
                'role_key' => $roleKey,
                'name' => $roleKey,
                'display_name' => $roleKey,
                'scope_type' => ScopedRole::SCOPE_DEPARTMENT,
                'label_ar' => $roleKey,
                'label_en' => $roleKey,
                'is_admin_role' => false,
                'permissions' => json_encode($this->expandFlags([
                    Capability::PROJECTS_VIEW,
                    Capability::TASKS_VIEW,
                ], [
                    'can_edit' => false,
                    'can_delete' => false,
                    'can_view_all' => true,
                    'can_manage_members' => false,
                ])),
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::flush();

        return ScopedRoleDefinition::find($existingId);
    }

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
