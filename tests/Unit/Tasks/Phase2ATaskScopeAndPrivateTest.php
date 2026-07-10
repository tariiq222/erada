<?php

namespace Tests\Unit\Tasks;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Models\Organization;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2A — Task::scopeOrganizationId() + isSensitive() contract.
 *
 * Two bugs in the pre-Phase-2A code, both directly named in the design:
 *
 *   1. scopeOrganizationId() ignored the direct `tasks.organization_id`
 *      column. Source-only tasks (Recommendation / MeetingResolution /
 *      Risk / Kpi / Milestone / OVR) have no project_id and no
 *      department_id, so scopeOrganizationId() returned null for them —
 *      but the cluster widening scopeVisibleTo() filter was reading
 *      tasks.organization_id directly. The per-record AuthZ decision
 *      (which uses scopeOrganizationId()) said "null scope, deny" while
 *      the SQL filter (which read the column directly) said "visible".
 *      That contradiction is the source-only path bug Phase 2 closes.
 *
 *   2. isSensitive() did not honor the `is_private` floor. The design
 *      is explicit: private non-personal tasks do not widen through
 *      cluster access. Pre-Phase-2A, a cluster actor with TASKS_VIEW +
 *      CLUSTER_TREE_VIEW on actor.org could show a child hospital's
 *      is_private=true non-personal task because neither the sensitive
 *      gate nor the project/department list branch treated is_private
 *      as a confidentiality signal.
 *
 * These tests pin the per-record contract; HTTP-level coverage lives in
 * the feature folder.
 */
class Phase2ATaskScopeAndPrivateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Phase 2A — flush the engine's identity-map caches. The
        // AccessDecision static caches persist across tests in the same
        // process while RefreshDatabase only wipes the rows. A test that
        // creates Project N where N was previously cached for a
        // different model class can silently return the wrong model.
        AccessDecision::flushCache();
    }

    // ──────────────────────────────────────────────────────────────
    // scopeOrganizationId() — direct column first, fall back to parent
    // ──────────────────────────────────────────────────────────────

    public function test_scope_organization_id_returns_direct_column_for_source_only_task(): void
    {
        // A Recommendation-sourced task has no project_id and no
        // department_id — its only org identifier is the direct
        // tasks.organization_id column stamped at create time. Pre-Phase-2A
        // this returned null because scopeOrganizationId() never read
        // the column. The cluster widening filter in scopeVisibleTo()
        // was reading tasks.organization_id directly, so the per-record
        // AuthZ decision (null ⇒ deny) contradicted the SQL filter
        // (column value ⇒ visible).
        $childOrg = Organization::factory()->cluster()->create();
        $task = new Task;
        $task->forceFill([
            'organization_id' => $childOrg->id,
            'type' => TaskType::PROJECT->value,
            // Source-only: no project_id, no department_id.
            'source_type' => 'Recommendation',
            'source_id' => 1,
        ]);
        $task->id = 999;
        $task->exists = true;

        $this->assertSame((int) $childOrg->id, $task->scopeOrganizationId());
    }

    public function test_scope_organization_id_falls_back_to_project_for_legacy_project_task(): void
    {
        // Regression: project_id-based tasks still derive scope from
        // the project. The Phase 2A change must not break this path.
        //
        // NB: ProjectFactory's cascade-coherent ponytail auto-creates a
        // fresh Organization for the project. To assert the exact
        // organization_id mapping, we use DB::table directly so the
        // factory's department cascade doesn't re-stamp the project to
        // a different org than the one we explicitly set.
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $projectId = \DB::table('projects')->insertGetId([
            'name' => 'phase2a_project_fallback',
            'description' => null,
            'status' => 'planning',
            'priority' => 'medium',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
            'budget' => 10000,
            'progress' => 0,
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $task = new Task;
        $task->forceFill([
            'project_id' => $projectId,
            'type' => TaskType::PROJECT->value,
        ]);
        $task->id = 1;
        $task->exists = true;

        $this->assertSame((int) $org->id, $task->scopeOrganizationId());
    }

    public function test_scope_organization_id_falls_back_to_department_for_department_task(): void
    {
        // Same direct-insert trick for the dept-task fallback path.
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        $task = new Task;
        $task->forceFill([
            'department_id' => $dept->id,
            'type' => TaskType::DEPARTMENT->value,
        ]);
        $task->id = 1;
        $task->exists = true;

        $this->assertSame((int) $org->id, $task->scopeOrganizationId());
    }

    // ──────────────────────────────────────────────────────────────
    // isSensitive() — extend to honor the private non-personal floor
    // ──────────────────────────────────────────────────────────────

    public function test_is_sensitive_true_for_private_non_personal_task(): void
    {
        // A non-personal task with is_private=true triggers the
        // sensitive gate. That makes the per-record TaskPolicy deny
        // cluster actors regardless of TASKS_VIEW + CLUSTER_TREE_VIEW.
        $task = new Task;
        $task->forceFill([
            'type' => TaskType::PROJECT->value,
            'is_private' => true,
            'source_type' => null,
            'source_sensitivity' => null,
        ]);
        $task->id = 1;
        $task->exists = true;

        $this->assertTrue($task->isSensitive());
    }

    public function test_is_sensitive_false_for_private_personal_task(): void
    {
        // Personal tasks are owner-only by construction. Their privacy
        // is the owner floor, not the cluster sensitive floor — a
        // private personal task is NOT sensitive.
        $task = new Task;
        $task->forceFill([
            'type' => TaskType::PERSONAL->value,
            'is_private' => true,
        ]);
        $task->id = 1;
        $task->exists = true;

        $this->assertFalse($task->isSensitive());
    }

    public function test_is_sensitive_false_for_public_non_personal_task(): void
    {
        // Regression: a non-personal task with is_private=false and no
        // confidential source stamp is NOT sensitive.
        $task = new Task;
        $task->forceFill([
            'type' => TaskType::PROJECT->value,
            'is_private' => false,
            'source_sensitivity' => null,
        ]);
        $task->id = 1;
        $task->exists = true;

        $this->assertFalse($task->isSensitive());
    }

    public function test_is_sensitive_true_for_ovr_confidential_source_stamp(): void
    {
        // Regression: the source_sensitivity='confidential' stamp is
        // still authoritative for OVR-derived tasks. A cluster actor
        // without OVR_CONFIDENTIAL still gets a deny.
        $task = new Task;
        $task->forceFill([
            'type' => TaskType::PROJECT->value,
            'is_private' => false,
            'source_type' => 'IncidentReport',
            'source_sensitivity' => 'confidential',
        ]);
        $task->id = 1;
        $task->exists = true;

        $this->assertTrue($task->isSensitive());
    }
}
