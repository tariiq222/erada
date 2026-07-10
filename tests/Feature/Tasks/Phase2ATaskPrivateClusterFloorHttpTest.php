<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 2A — Private non-personal floor for cluster widening.
 *
 * The pre-Phase-2A behavior allowed a cluster actor with TASKS_VIEW +
 * CLUSTER_TREE_VIEW to read a child org's is_private=true non-personal
 * task via the cluster rescue branch. The design brief is explicit:
 *
 *   "private non-personal tasks do not widen through cluster access
 *    unless an existing explicit need-to-know rule grants access"
 *
 * Both the per-record policy gate AND the LIST scope must enforce that.
 * The policy gate is wired by making Task::isSensitive() return true for
 * private non-personal rows (closing the per-record path). The list
 * scope honors the floor in its cluster widening branches (closing the
 * SQL path).
 *
 * Same-org access for private tasks still flows through the existing
 * assignee/owner/creator/role predicates — a same-org user who happens
 * to hold the project role, for instance, still sees private project
 * tasks in their own org (no regression there).
 */
class Phase2ATaskPrivateClusterFloorHttpTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cluster_actor_show_child_org_private_non_personal_task_is_forbidden(): void
    {
        // A cluster_auditor with TASKS_VIEW + CLUSTER_TREE_VIEW on the
        // cluster cannot SHOW a child hospital's is_private=true
        // non-personal task. The per-record TaskPolicy::view consults
        // Task::isSensitive() (Phase 2A — was extended to honor
        // is_private for non-personal rows) and denies via the
        // sensitive gate, which the cluster actor does NOT satisfy
        // (they are not the creator, owner, or assignee, and they
        // hold no OVR_CONFIDENTIAL capability).
        [$cluster, $hospital] = $this->makeClusterTree();

        $clusterUser = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($clusterUser, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        // Build the project + dept at known ids via DB::table so the
        // organization cascade the ProjectFactory ponytail wires does
        // not surprise the test (the cascade can re-stamp the project
        // to an auto-created org that breaks the cluster ancestor
        // walk assumption).
        $hospitalDeptId = \DB::table('departments')->insertGetId([
            'name' => 'phase2a_dept_private',
            'organization_id' => $hospital->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $hospitalProjectId = \DB::table('projects')->insertGetId([
            'name' => 'phase2a_project_private',
            'description' => null,
            'status' => 'planning',
            'priority' => 'medium',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
            'budget' => 10000,
            'progress' => 0,
            'organization_id' => $hospital->id,
            'department_id' => $hospitalDeptId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $privateTaskId = \DB::table('tasks')->insertGetId([
            'title' => 'phase2a_private_task',
            'type' => 'project',
            'is_private' => true,
            'status' => 'todo',
            'priority' => 'medium',
            'progress' => 0,
            'project_id' => $hospitalProjectId,
            'organization_id' => $hospital->id,
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($clusterUser, 'sanctum')
            ->getJson("/api/unified-tasks/{$privateTaskId}")
            ->assertStatus(403);
    }

    public function test_cluster_actor_show_child_org_public_non_personal_task_is_allowed(): void
    {
        // Sanity — a child org's PUBLIC non-personal task remains
        // cluster-visible. The is_private floor must not over-extend
        // to non-private rows.
        [$cluster, $hospital] = $this->makeClusterTree();

        $clusterUser = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($clusterUser, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $hospitalDeptId = \DB::table('departments')->insertGetId([
            'name' => 'phase2a_dept_public',
            'organization_id' => $hospital->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $hospitalProjectId = \DB::table('projects')->insertGetId([
            'name' => 'phase2a_project_public',
            'description' => null,
            'status' => 'planning',
            'priority' => 'medium',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
            'budget' => 10000,
            'progress' => 0,
            'organization_id' => $hospital->id,
            'department_id' => $hospitalDeptId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $publicTaskId = \DB::table('tasks')->insertGetId([
            'title' => 'phase2a_public_task',
            'type' => 'project',
            'is_private' => false,
            'status' => 'todo',
            'priority' => 'medium',
            'progress' => 0,
            'project_id' => $hospitalProjectId,
            'organization_id' => $hospital->id,
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($clusterUser, 'sanctum')
            ->getJson("/api/unified-tasks/{$publicTaskId}")
            ->assertOk();
    }

    public function test_cluster_actor_list_excludes_child_org_private_non_personal_tasks(): void
    {
        // The cluster_auditor listing the cluster's tasks sees the
        // child hospital's public tasks but NOT the child hospital's
        // private tasks. The scope filter must add an is_private floor
        // to its cluster-widening branches; same-org private task
        // access through the user's existing role / direct-relation
        // predicates is unaffected.
        [$cluster, $hospital] = $this->makeClusterTree();

        $clusterUser = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($clusterUser, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        // Direct DB inserts — the cluster ancestor walk depends on
        // task.organization_id being literally the child hospital's id.
        $hospitalDeptId = \DB::table('departments')->insertGetId([
            'name' => 'phase2a_dept_list',
            'organization_id' => $hospital->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $hospitalProjectId = \DB::table('projects')->insertGetId([
            'name' => 'phase2a_project_list',
            'description' => null,
            'status' => 'planning',
            'priority' => 'medium',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
            'budget' => 10000,
            'progress' => 0,
            'organization_id' => $hospital->id,
            'department_id' => $hospitalDeptId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $publicTaskId = \DB::table('tasks')->insertGetId([
            'title' => 'public_child_task',
            'type' => 'project',
            'is_private' => false,
            'status' => 'todo',
            'priority' => 'medium',
            'progress' => 0,
            'project_id' => $hospitalProjectId,
            'organization_id' => $hospital->id,
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $privateTaskId = \DB::table('tasks')->insertGetId([
            'title' => 'private_child_task',
            'type' => 'project',
            'is_private' => true,
            'status' => 'todo',
            'priority' => 'medium',
            'progress' => 0,
            'project_id' => $hospitalProjectId,
            'organization_id' => $hospital->id,
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($clusterUser, 'sanctum')
            ->getJson('/api/unified-tasks');

        $response->assertOk();

        $rows = collect($response->json('data'))
            ->keyBy('id')
            ->all();

        $this->assertArrayHasKey($publicTaskId, $rows, 'public child task must remain cluster-visible');
        $this->assertArrayNotHasKey(
            $privateTaskId,
            $rows,
            'private non-personal child task must NOT widen to cluster actor via the list filter'
        );
    }

    public function test_same_org_user_with_direct_relation_still_sees_private_task(): void
    {
        // Regression: a same-org user who is the ASSIGNEE of a
        // private project task still sees it. The is_private cluster
        // floor must not block same-org access through assignee /
        // owner / creator / role predicates.
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::TASKS_VIEW, 'organization', $org->id);

        $projectId = \DB::table('projects')->insertGetId([
            'name' => 'phase2a_same_org_project',
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

        $taskId = \DB::table('tasks')->insertGetId([
            'title' => 'phase2a_same_org_private',
            'type' => 'project',
            'is_private' => true,
            'status' => 'todo',
            'priority' => 'medium',
            'progress' => 0,
            'project_id' => $projectId,
            'organization_id' => $org->id,
            'assigned_to' => $user->id,
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/unified-tasks/{$taskId}")
            ->assertOk();
    }

    // ──────────────────────────────────────────────────────────────
    // helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: Organization, 1: Organization}
     */
    private function makeClusterTree(string $clusterName = 'cluster2a', string $hospitalName = 'hospital2a'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);

        return [$cluster, $hospital];
    }
}
