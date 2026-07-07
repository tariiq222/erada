<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Models\Organization;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TaskSourceScopeTest — Phase 4 of the master AuthZ unification plan.
 *
 * Pins the new scopeParent() priority:
 *   1. source_type/source_id resolves to a ScopeAware parent
 *   2. project_id -> Project
 *   3. department_id -> Department
 *   4. personal floor (handled in TaskPolicy, NOT here)
 *
 * Scenarios covered:
 *   - task sourced from Risk              -> scopeParent is the Risk
 *   - task sourced from Recommendation    -> scopeParent is the Recommendation
 *   - task with project_id only           -> scopeParent is the Project
 *   - task with department_id only        -> scopeParent is the Department
 *   - task with neither                   -> scopeParent is null (personal floor)
 *   - source row missing / archived       -> falls through to project_id chain
 *
 * Confidential OVR and Risk actions live behind their own tests in the
 * owning modules (Phase 6 parity). This file only pins the engine's
 * scopeParent resolution; ability evaluation is the engine's job.
 */
class TaskSourceScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Department $deptA;

    private Project $projectA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->projectA = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
        ]);
    }

    private function makeProjectTask(?array $overrides = []): Task
    {
        return Task::factory()->create(array_merge([
            'type' => TaskType::PROJECT->value,
            'project_id' => $this->projectA->id,
            'department_id' => $this->deptA->id,
            'assigned_to' => null,
            'created_by' => null,
            'status' => TaskStatus::TODO->value,
        ], $overrides));
    }

    public function test_project_task_scope_parent_is_the_project(): void
    {
        $task = $this->makeProjectTask();

        // Phase 4 migration backfilled source_type=Project, source_id=project_id
        // for existing project tasks, so the new priority resolves to the
        // same Project as before. Pin that.
        $this->assertSame('Project', $task->source_type);
        $this->assertSame($this->projectA->id, $task->source_id);

        $parent = $task->scopeParent();
        $this->assertNotNull($parent);
        $this->assertInstanceOf(Project::class, $parent);
        $this->assertSame($this->projectA->id, $parent->id);
    }

    public function test_department_only_task_scope_parent_is_the_department(): void
    {
        $task = Task::factory()->create([
            'type' => TaskType::DEPARTMENT->value,
            'project_id' => null,
            'department_id' => $this->deptA->id,
            'source_type' => 'Department',
            'source_id' => $this->deptA->id,
            'status' => TaskStatus::TODO->value,
        ]);

        $parent = $task->scopeParent();
        $this->assertNotNull($parent);
        $this->assertInstanceOf(Department::class, $parent);
        $this->assertSame($this->deptA->id, $parent->id);
    }

    public function test_task_sourced_from_risk_inherits_risk_scope(): void
    {
        $risk = Risk::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
        ]);

        $task = $this->makeProjectTask([
            'project_id' => null,
            'source_type' => 'Risk',
            'source_id' => $risk->id,
            'source_sensitivity' => 'normal',
        ]);

        $parent = $task->scopeParent();
        $this->assertNotNull($parent);
        $this->assertInstanceOf(Risk::class, $parent);
        $this->assertSame($risk->id, $parent->id);
    }

    public function test_task_sourced_from_meeting_recommendation_inherits_recommendation_scope(): void
    {
        // Direction B (commit f98adef5): rulings now live on the unified
        // `recommendations` table. A task sourced from a Recommendation
        // (action_item or ruling — both kinds share the same scopeParent
        // walk) inherits that Recommendation's scope chain.
        $meeting = Meeting::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
        ]);

        $recommendation = Recommendation::factory()->ruling()->create([
            'meeting_id' => $meeting->id,
            'organization_id' => $this->orgA->id,
        ]);

        $task = $this->makeProjectTask([
            'project_id' => null,
            'source_type' => 'Recommendation',
            'source_id' => $recommendation->id,
            'source_sensitivity' => 'normal',
        ]);

        $parent = $task->scopeParent();
        $this->assertNotNull($parent);
        $this->assertInstanceOf(Recommendation::class, $parent);
        $this->assertSame($recommendation->id, $parent->id);
    }

    public function test_personal_task_scope_parent_is_null(): void
    {
        // Personal task: no source, no project, no department. The engine
        // returns null so TaskPolicy's personal-floor logic owns the
        // authorization decision.
        $task = Task::factory()->create([
            'type' => TaskType::PERSONAL->value,
            'project_id' => null,
            'department_id' => null,
            'source_type' => null,
            'source_id' => null,
            'status' => TaskStatus::TODO->value,
        ]);

        $this->assertNull($task->scopeParent());
    }

    public function test_source_priority_beats_project_when_both_present(): void
    {
        // Even when project_id is set, the polymorphic source wins. This
        // is the new behavior Phase 4 ships — a project-task can be re-
        // attached to a Risk or Recommendation without losing scope.
        $risk = Risk::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
        ]);

        $task = $this->makeProjectTask([
            'source_type' => 'Risk',
            'source_id' => $risk->id,
            'source_sensitivity' => 'normal',
        ]);

        $parent = $task->scopeParent();
        $this->assertNotNull($parent);
        $this->assertInstanceOf(Risk::class, $parent);
        $this->assertNotInstanceOf(Project::class, $parent);
    }

    public function test_source_priority_beats_department_when_both_present(): void
    {
        $risk = Risk::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
        ]);

        $task = $this->makeProjectTask([
            'project_id' => null,
            'source_type' => 'Risk',
            'source_id' => $risk->id,
            'source_sensitivity' => 'normal',
        ]);

        $parent = $task->scopeParent();
        $this->assertInstanceOf(Risk::class, $parent);
        $this->assertNotInstanceOf(Department::class, $parent);
    }

    public function test_unknown_source_type_falls_through_to_project(): void
    {
        // An unmapped source_type (legacy data, future extension) must not
        // crash the engine; it falls through to project_id.
        $task = $this->makeProjectTask([
            'source_type' => 'FutureSourceType',
            'source_id' => 999999,
        ]);

        $parent = $task->scopeParent();
        $this->assertInstanceOf(Project::class, $parent);
        $this->assertSame($this->projectA->id, $parent->id);
    }

    public function test_missing_source_row_falls_through_to_project(): void
    {
        // The polymorphic source row was deleted out from under the task.
        // The engine falls through to project_id rather than returning
        // null, so the task stays visible until ops resolves the
        // dangling source.
        $task = $this->makeProjectTask([
            'source_type' => 'Risk',
            'source_id' => 999999, // no row with this id exists
            'source_sensitivity' => 'normal',
        ]);

        $parent = $task->scopeParent();
        $this->assertInstanceOf(Project::class, $parent);
        $this->assertSame($this->projectA->id, $parent->id);
    }

    public function test_source_scope_parent_is_resolved_through_engine_cache(): void
    {
        // Multiple tasks sourced from the same parent must share one
        // engine lookup — the parent's identity-map cache collapses the
        // N+1 across the list. This test exercises the cache path
        // implicitly by resolving the same parent twice via different
        // task instances.
        $risk = Risk::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
        ]);

        $taskA = $this->makeProjectTask([
            'project_id' => null,
            'source_type' => 'Risk',
            'source_id' => $risk->id,
            'source_sensitivity' => 'normal',
        ]);
        $taskB = $this->makeProjectTask([
            'project_id' => null,
            'source_type' => 'Risk',
            'source_id' => $risk->id,
            'source_sensitivity' => 'normal',
        ]);

        // Flush the cache to make the resolution visible.
        AccessDecision::flushCache();

        $parentA = $taskA->scopeParent();
        $parentB = $taskB->scopeParent();

        $this->assertInstanceOf(Risk::class, $parentA);
        $this->assertInstanceOf(Risk::class, $parentB);
        $this->assertSame($risk->id, $parentA->id);
        $this->assertSame($risk->id, $parentB->id);

        // After the first resolution the engine has the parent in its
        // identity map; the second resolution MUST return the same
        // canonical instance (the engine's contract per the docs).
        $this->assertSame($parentA, $parentB);
    }

    public function test_user_organization_isolation_uses_source_parent_organization(): void
    {
        // A task sourced from a same-org Risk must surface the risk's
        // org as the parent organization; a cross-org risk source would
        // be rejected by the org-isolation layer in AccessDecision.
        $risk = Risk::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
        ]);

        $task = $this->makeProjectTask([
            'project_id' => null,
            'source_type' => 'Risk',
            'source_id' => $risk->id,
            'source_sensitivity' => 'normal',
        ]);

        $parent = $task->scopeParent();
        $this->assertNotNull($parent);
        $this->assertSame($this->orgA->id, $parent->scopeOrganizationId());
    }
}
