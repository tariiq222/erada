<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * TasksAssignCapabilityTest — Wave 5, Task 5.2, updated for Phase 2.
 *
 * Targeted coverage for the PATCH /api/unified-tasks/{task}/assign endpoint.
 * Prior regression history (commit 87c7174d — "pre-deploy hardening" regression)
 * broke task assignment for org-B tasks; this suite locks that gate down.
 *
 * Phase 2 of master AuthZ unification plan: the authorize path is now
 * TaskController::assign() → authorizeTask('assign') → TaskPolicy::assign →
 * AccessDecision::can($user, Capability::TASKS_ASSIGN, $task). Capability::
 * TASKS_ASSIGN is the authoritative gate; Capability::TASKS_EDIT is NOT
 * sufficient (an edit-only user cannot delegate). The dedicated contract for
 * "assign-only" vs "edit-only" lives in TaskAssignAuthorizationTest.
 *
 * Defense-in-depth: a D-04 IDOR floor on assigned_to's organization_id still
 * runs in the controller after the authz check returns true.
 *
 * Coverage matrix (role × endpoint × expected outcome):
 *   - unauthenticated                          → 401
 *   - viewer (same org, no assign cap)         → 403
 *   - cross-org actor WITH assign cap          → 403 (org-floor beats capability grant)
 *   - same-org actor WITH assign cap           → 200
 *   - edit-only actor (no assign cap)          → 403  (Phase 2 contract)
 *   - super_admin                              → 200 (bypasses all checks including cross-org)
 */
class TasksAssignCapabilityTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private Department $deptA;

    private Department $deptB;

    private Project $projectA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create([
            'organization_id' => $this->orgA->id,
        ]);
        $this->deptB = Department::factory()->create([
            'organization_id' => $this->orgB->id,
        ]);
        $this->projectA = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
        ]);
    }

    /**
     * Same-org factory user. Pass a Spatie role string when needed; pass null
     * for users we'll attach scoped roles to directly (engine path).
     */
    private function makeUser(Organization $org, ?string $spatieRole = null): User
    {
        $dept = $org->id === $this->orgA->id ? $this->deptA : $this->deptB;
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        if ($spatieRole !== null) {
            $user->assignRole($spatieRole);
        }

        return $user;
    }

    /**
     * Build a project-type task in $org with no assignee/creator so we can
     * freely assign it without colliding with the auto-created factory child.
     */
    private function makeTask(Organization $org, ?Project $project = null): Task
    {
        $department = $org->id === $this->orgA->id ? $this->deptA : $this->deptB;

        return Task::factory()->create([
            'type' => TaskType::PROJECT->value,
            'project_id' => $project?->id ?? Project::factory()->create([
                'organization_id' => $org->id,
                'department_id' => $department->id,
            ])->id,
            'department_id' => $department->id,
            'assigned_to' => null,
            'created_by' => null,
            'status' => TaskStatus::TODO->value,
        ]);
    }

    public function test_assign_requires_authentication(): void
    {
        $task = $this->makeTask($this->orgA, $this->projectA);

        $this->patchJson("/api/unified-tasks/{$task->id}/assign", [
            'assigned_to' => $this->makeUser($this->orgA)->id,
        ])->assertStatus(401);
    }

    public function test_viewer_without_tasks_assign_capability_is_forbidden(): void
    {
        $task = $this->makeTask($this->orgA, $this->projectA);

        // viewer at org level has no edit capability, so assignment must be denied.
        $viewer = $this->makeUser($this->orgA, 'viewer');

        $this->actingAs($viewer, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/assign", [
                'assigned_to' => $this->makeUser($this->orgA)->id,
            ])
            ->assertForbidden();
    }

    public function test_cross_org_actor_with_capability_is_blocked_by_org_floor(): void
    {
        // Build a task in orgA. The cross-org actor holds the org-scoped edit
        // capability (so the engine would grant edit if there were no org
        // isolation layer). Same-org membership + same-org assignee are
        // satisfied by the same fixture path so we isolate the failure mode
        // to D-02/D-04 — the org-floor must deny BEFORE the policy decides.
        $foreignTask = $this->makeTask($this->orgA, $this->projectA);

        $crossOrgActor = $this->makeUser($this->orgB, 'admin');

        // Org-scoped role that bundles the engine-side grant for tasks.edit;
        // we bypass the controller-level assignee-org check by targeting a
        // same-org assignee, leaving the org-floor as the only denial cause.
        $this->grantEngineCapability($crossOrgActor, Capability::TASKS_EDIT);

        $sameOrgAssignee = $this->makeUser($this->orgA);

        $response = $this->actingAs($crossOrgActor, 'sanctum')
            ->patchJson("/api/unified-tasks/{$foreignTask->id}/assign", [
                'assigned_to' => $sameOrgAssignee->id,
            ]);

        // Accept [403, 404] — same isolation contract as ProjectOrganizationScopeTest:
        // either a 403 from the engine (org_isolation_denied) or a 404 from the
        // route model binding first. Both are secure.
        $this->assertContains(
            $response->status(),
            [403, 404],
            'cross-org actor must be denied by the org-floor (got '.$response->status().')'
        );
    }

    public function test_same_org_actor_with_tasks_assign_capability_succeeds(): void
    {
        $task = $this->makeTask($this->orgA, $this->projectA);

        $actor = $this->makeUser($this->orgA);
        // Phase 2: tasks.assign alone is now sufficient. The bundled
        // TASKS_EDIT used to be required because the controller gated on
        // update; the controller now gates on 'assign', which routes to
        // TaskPolicy::assign → Capability::TASKS_ASSIGN.
        $this->grantEngineCapability($actor, Capability::TASKS_ASSIGN);

        $assignee = $this->makeUser($this->orgA);

        $response = $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/assign", [
                'assigned_to' => $assignee->id,
            ])
            ->assertOk();

        $response->assertJsonPath('task.assignee.id', $assignee->id);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'assigned_to' => $assignee->id,
        ]);
    }

    public function test_tasks_assign_alone_is_sufficient_for_the_controller(): void
    {
        // Phase 2 contract: TASKS_ASSIGN alone passes the assign endpoint.
        // The previous contract required TASKS_EDIT too (because the controller
        // gated on update); that contract is now retired. Tasks.edit is NOT
        // sufficient by itself — see test_tasks_edit_alone_is_insufficient.
        $task = $this->makeTask($this->orgA, $this->projectA);

        $actor = $this->makeUser($this->orgA);
        $this->grantEngineCapability($actor, Capability::TASKS_ASSIGN);

        $assignee = $this->makeUser($this->orgA);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/assign", [
                'assigned_to' => $assignee->id,
            ])
            ->assertOk()
            ->assertJsonPath('task.assignee.id', $assignee->id);
    }

    public function test_tasks_edit_alone_is_insufficient_for_assignment(): void
    {
        // Phase 2 contract: TASKS_EDIT is no longer sufficient for assigning.
        // An edit-only user can mutate a task they own but cannot delegate
        // ownership of it. The dedicated contract test lives in
        // TaskAssignAuthorizationTest::test_actor_with_only_tasks_edit_capability_is_forbidden.
        $task = $this->makeTask($this->orgA, $this->projectA);

        $actor = $this->makeUser($this->orgA);
        $this->grantEngineCapability($actor, Capability::TASKS_EDIT);

        $assignee = $this->makeUser($this->orgA);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/unified-tasks/{$task->id}/assign", [
                'assigned_to' => $assignee->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('tasks', [
            'id' => $task->id,
            'assigned_to' => $assignee->id,
        ]);
    }

    public function test_super_admin_can_assign_any_org_task(): void
    {
        $foreignTask = $this->makeTask($this->orgB);

        $superAdmin = $this->makeUser($this->orgA, 'super_admin');

        $sameOrgAssignee = $this->makeUser($this->orgB);

        // super_admin's TaskPolicy::before() returns true unconditionally —
        // bypasses the org-floor and the engine. Controller still enforces
        // the IDOR floor on assigned_to (must belong to the task's org), so
        // the assignee must be same-org as the task.
        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/unified-tasks/{$foreignTask->id}/assign", [
                'assigned_to' => $sameOrgAssignee->id,
            ])
            ->assertOk()
            ->assertJsonPath('task.assignee.id', $sameOrgAssignee->id);
    }
}
