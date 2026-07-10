<?php

namespace Tests\Unit\Tasks\Models;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeTaskSensitivelyScopedTest — Phase CFA-08 (CRITICAL INVARIANT).
 *
 * Pins the contract that a Task implements SensitivelyScoped correctly:
 *   - Task::isSensitive() returns true when source_sensitivity='confidential'.
 *   - OVR-sourced tasks inherit sensitivity through the copied task stamp;
 *     tasks.source_id cannot reference the OVR UUID primary key.
 *   - Task::isSensitive() returns false for normal tasks (no source
 *     sensitivity, non-confidential OVR source, no source at all,
 *     personal tasks).
 *   - Task::mayAccessSensitive() honors the engine's structural floor:
 *     super_admin, the task creator/owner/assignee, and a user with an
 *     OVR_CONFIDENTIAL scoped role are granted;
 *     everyone else (including a cluster actor with ONLY cluster grants)
 *     is denied.
 *
 * These invariants back the engine's cluster rescue branch
 * (AccessDecision::clusterTreeRescueApplies) — a sensitive target
 * short-circuits the cluster rescue, preventing cluster actors from
 * reaching confidential OVR-sourced tasks via the path 2 of the
 * TaskPolicy::view() / changeStatus() cluster rescue.
 */
class ClusterTreeTaskSensitivelyScopedTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private Organization $org;

    private Project $project;

    private Department $dept;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $this->project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
        ]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id, 'is_active' => true]);

    }

    private function makeNormalTask(): Task
    {
        return Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
        ]);
    }

    // ============================================================
    // isSensitive() — return values
    // ============================================================

    public function test_normal_task_is_not_sensitive(): void
    {
        $task = $this->makeNormalTask();

        $this->assertFalse($task->isSensitive());
    }

    public function test_task_with_source_sensitivity_confidential_is_sensitive(): void
    {
        // source_sensitivity='confidential' alone is enough — even with
        // a non-confidential OVR source, the per-row stamp wins.
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => 'confidential',
        ]);

        $this->assertTrue($task->isSensitive());
    }

    public function test_task_with_normal_source_sensitivity_is_not_sensitive(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => 'normal',
        ]);

        $this->assertFalse($task->isSensitive());
    }

    public function test_task_sourced_from_confidential_ovr_incident_is_sensitive(): void
    {
        // schema workaround: IncidentReport.id is UUID, tasks.source_id is
        // bigint — direct FK is not expressible. The per-row stamp is the
        // authoritative signal here; the SQL source-row resolution at the
        // scopeVisibleTo layer is tested separately (see
        // ClusterTreeOvrSourcedTaskForbiddenTest).
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'source_type' => 'IncidentReport',
            'source_id' => 0,
            'source_sensitivity' => 'confidential',
        ]);

        $this->assertTrue($task->isSensitive());
    }

    public function test_task_sourced_from_non_confidential_ovr_incident_is_not_sensitive(): void
    {
        // Schema workaround: IncidentReport.id is UUID, tasks.source_id
        // is bigint — the per-row stamp is the authoritative signal.
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'source_type' => 'IncidentReport',
            'source_id' => 0,
            'source_sensitivity' => 'normal',
        ]);

        $this->assertFalse($task->isSensitive());
    }

    public function test_personal_task_with_source_sensitivity_confidential_is_no_t_sensitive(): void
    {
        // Per CFA-08 contract: personal tasks are NEVER sensitive. Their
        // owner floor is the only gate; nothing confidential to inherit.
        $task = Task::factory()->create([
            'type' => 'personal',
            'owner_id' => $this->owner->id,
            'created_by' => $this->owner->id,
            'project_id' => null,
            'department_id' => null,
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => 'confidential',
        ]);

        $this->assertFalse($task->isSensitive());
    }

    // ============================================================
    // mayAccessSensitive() — return value (hook contract)
    // ============================================================

    public function test_may_access_sensitive_returns_false_for_regular_user(): void
    {
        // The hook is the braces; the belt is the structural floor.
        // Returning false here means the engine will still consult the
        // floor (created_by / owner_id match or scoped-role confidential
        // grant). This test pins the hook-level contract only — a
        // stranger without need-to-know is denied at the hook.
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'created_by' => $this->owner->id,
            'source_type' => 'IncidentReport',
            'source_id' => 0,
            'source_sensitivity' => 'confidential',
        ]);

        $stranger = User::factory()->create(['organization_id' => $this->org->id, 'is_active' => true]);
        $this->assertFalse($task->mayAccessSensitive($stranger));
    }

    public function test_may_access_sensitive_returns_true_for_super_admin(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => 'confidential',
        ]);

        $superAdmin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $superAdmin->assignRole('super_admin');

        $this->assertTrue($task->mayAccessSensitive($superAdmin));
    }

    public function test_may_access_sensitive_returns_true_for_task_creator(): void
    {
        // created_by floor — the owner/creator has need-to-know access.
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'created_by' => $this->owner->id,
            'source_type' => 'IncidentReport',
            'source_id' => 0,
            'source_sensitivity' => 'confidential',
        ]);

        $this->assertTrue($task->mayAccessSensitive($this->owner));
    }

    public function test_may_access_sensitive_uses_the_task_creator_when_ovr_source_is_stamped(): void
    {
        // OVR source IDs cannot be resolved through tasks.source_id because
        // the column is bigint while OVR uses UUIDs. The task's copied stamp
        // is the confidentiality source of truth; task-level ownership is the
        // available structural need-to-know path.
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'created_by' => $this->owner->id,
            'owner_id' => $this->owner->id,
            'source_type' => 'IncidentReport',
            'source_id' => 0,
            'source_sensitivity' => 'confidential',
        ]);

        // The task-row's created_by floor grants need-to-know access.
        $this->assertTrue($task->mayAccessSensitive($this->owner));
    }

    public function test_may_access_sensitive_returns_true_for_ovr_confidential_scoped_role(): void
    {
        // The OVR_CONFIDENTIAL scoped role is the only path through the
        // permission system that opens sensitive OVR-derived tasks.
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'source_type' => 'IncidentReport',
            'source_id' => 0,
            'source_sensitivity' => 'confidential',
        ]);

        $clearedUser = User::factory()->create(['organization_id' => $this->org->id, 'is_active' => true]);
        $this->grantEngineCapability($clearedUser, Capability::OVR_CONFIDENTIAL);

        $this->assertTrue($task->mayAccessSensitive($clearedUser));
    }

    public function test_may_access_sensitive_returns_false_for_cluster_user_with_only_cluster_grants(): void
    {
        // CRITICAL: cluster widening grants (CLUSTER_TREE_VIEW/MANAGE/EXPORT)
        // do NOT grant sensitive access. A cluster user with the cluster pair
        // only — without OVR_CONFIDENTIAL — must be denied at the hook.
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => 'confidential',
        ]);

        $clusterUser = User::factory()->create(['organization_id' => $this->org->id, 'is_active' => true]);
        $this->grantEngineCapability($clusterUser, [
            Capability::CLUSTER_TREE_VIEW,
            Capability::CLUSTER_TREE_MANAGE,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        $this->assertFalse($task->mayAccessSensitive($clusterUser));
    }
}
