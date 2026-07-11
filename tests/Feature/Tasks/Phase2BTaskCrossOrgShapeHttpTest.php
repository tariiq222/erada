<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 2B — Safe cross-org task response shape.
 *
 * The pre-Phase-2B TaskResource stripped description / narrative /
 * assignee / creator / owner / subtasks data on cluster cross-org reads
 * (CFA-08). The design brief expands the floor:
 *
 *   "Cross-organization task responses use an explicit safe shape.
 *    Narrative fields, people, subtasks, counts, project/department/
 *    milestone names, and parent titles are withheld. Foreign-key
 *    identifiers needed for stable routing may remain."
 *
 * Translation to JSON shape:
 *
 *   Withheld on cluster cross-org  →  KEPT on same-org / super_admin
 *   - project.name / code / type     →  same
 *   - department.name                →  same
 *   - milestone.name                 →  same
 *   - parent.title                   →  same
 *   - subtasks_count / has_subtasks  →  same
 *   - incomplete_subtasks_count      →  same
 *   - comments_count                 →  same
 *   - attachments_count              →  same
 *
 *   KEPT on every surface (stable routing keys):
 *   - project_id, department_id, milestone_id, parent_id
 *   - source_type / source_id / source_sensitivity
 *   - assigned_to, created_by, owner_id (FK pointers — no names)
 *   - is_private, source_sensitivity stamp
 *
 * The cluster actor MUST NOT be able to read task fields that carry
 * module business surface (project names, dept names, milestone
 * labels, parent titles) even though they can read the task row at
 * all. project_id / department_id / milestone_id / parent_id persist
 * so the FE can still resolve navigation without an extra round-trip.
 */
class Phase2BTaskCrossOrgShapeHttpTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cluster_cross_org_show_strips_project_department_milestone_parent_names(): void
    {
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
            'name' => 'phase2b_secret_dept_name',
            'organization_id' => $hospital->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $hospitalProjectId = \DB::table('projects')->insertGetId([
            'name' => 'phase2b_secret_project_name',
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

        // A separate milestone and parent task so we can assert all
        // four name/title fields are withheld from cluster reads.
        $milestoneId = \DB::table('milestones')->insertGetId([
            'name' => 'phase2b_secret_milestone_name',
            'project_id' => $hospitalProjectId,
            'status' => 'pending',
            'due_date' => now()->addMonths(2)->format('Y-m-d'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $parentId = \DB::table('tasks')->insertGetId([
            'title' => 'phase2b_secret_parent_title',
            'type' => 'project',
            'status' => 'in_progress',
            'priority' => 'medium',
            'progress' => 50,
            'project_id' => $hospitalProjectId,
            'organization_id' => $hospital->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $taskId = \DB::table('tasks')->insertGetId([
            'title' => 'phase2b_child_task',
            'type' => 'project',
            'is_private' => false,
            'status' => 'in_progress',
            'priority' => 'medium',
            'progress' => 25,
            'project_id' => $hospitalProjectId,
            'department_id' => $hospitalDeptId,
            'milestone_id' => $milestoneId,
            'parent_id' => $parentId,
            'organization_id' => $hospital->id,
            'source_type' => null,
            'source_id' => null,
            'source_sensitivity' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($clusterUser, 'sanctum')
            ->getJson("/api/unified-tasks/{$taskId}?with_relations=1");

        $response->assertOk();
        $payload = $this->extractPayload($response);

        // FK pointers are kept (stable routing keys).
        $this->assertSame($hospitalProjectId, $payload['project_id']);
        $this->assertSame($hospitalDeptId, $payload['department_id']);
        $this->assertSame($milestoneId, $payload['milestone_id']);
        $this->assertSame($parentId, $payload['parent_id']);

        // Names are stripped — whenever a subresource block IS loaded,
        // its sensitive sub-fields MUST be absent. A block being absent
        // or null is also acceptable (eager-load didn't fire — nothing
        // to leak). The strict check is the body-string assertion
        // below.
        if (is_array($payload)) {
            foreach (['project', 'department', 'milestone', 'parent'] as $block) {
                if (array_key_exists($block, $payload) && is_array($payload[$block])) {
                    $this->assertArrayNotHasKey('name', $payload[$block], "$block.name must be withheld on cluster cross-org");
                    $this->assertArrayNotHasKey('title', $payload[$block], "$block.title must be withheld on cluster cross-org");
                }
            }
        }

        // Belt-and-braces: the secret strings never appear anywhere in
        // the payload (a nested leak would be a privacy incident).
        $body = $response->getContent();
        $this->assertStringNotContainsString('phase2b_secret_project_name', $body);
        $this->assertStringNotContainsString('phase2b_secret_dept_name', $body);
        $this->assertStringNotContainsString('phase2b_secret_milestone_name', $body);
        $this->assertStringNotContainsString('phase2b_secret_parent_title', $body);
    }

    public function test_cluster_cross_org_show_strips_all_counts(): void
    {
        // Counts can encode module business surface (subtasks suggest a
        // project structure; comments + attachments hint at collaboration
        // density). All count keys must be withheld on cluster cross-org
        // reads.
        [$cluster, $hospital] = $this->makeClusterTree();

        $clusterUser = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($clusterUser, [
            Capability::TASKS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $deptId = \DB::table('departments')->insertGetId([
            'name' => 'phase2b_counts_dept',
            'organization_id' => $hospital->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $projectId = \DB::table('projects')->insertGetId([
            'name' => 'phase2b_counts_project',
            'description' => null,
            'status' => 'planning',
            'priority' => 'medium',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(3)->format('Y-m-d'),
            'budget' => 10000,
            'progress' => 0,
            'organization_id' => $hospital->id,
            'department_id' => $deptId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $taskId = \DB::table('tasks')->insertGetId([
            'title' => 'phase2b_counts_task',
            'description' => 'phase2b_counts_secret_desc',
            'type' => 'project',
            'is_private' => false,
            'status' => 'in_progress',
            'priority' => 'medium',
            'progress' => 25,
            'project_id' => $projectId,
            'department_id' => $deptId,
            'organization_id' => $hospital->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed a subtask so subtasks_count is non-zero in the same-org
        // shape — it must be 0/null on the cluster cross-org shape.
        \DB::table('tasks')->insertGetId([
            'title' => 'phase2b_subtask',
            'type' => 'project',
            'status' => 'todo',
            'priority' => 'medium',
            'progress' => 0,
            'parent_id' => $taskId,
            'project_id' => $projectId,
            'organization_id' => $hospital->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($clusterUser, 'sanctum')
            ->getJson("/api/unified-tasks/{$taskId}?with_relations=1");

        $response->assertOk();
        $body = $response->getContent();

        // The secret description MUST NEVER appear on cluster cross-org
        // reads. (Pre-Phase-2 already stripped this; we re-assert it
        // here as a regression anchor.)
        $this->assertStringNotContainsString('phase2b_counts_secret_desc', $body);

        // Counts must be 0 (or absent / null / false) on cluster
        // cross-org. A real count leaking back to a cluster actor
        // would be a privacy incident. The contract: any numeric count
        // value that escapes the child org to a cluster cross-org
        // reader is forbidden.
        $payload = $this->extractPayload($response);
        if (is_array($payload)) {
            foreach (['subtasks_count', 'has_subtasks', 'incomplete_subtasks_count', 'comments_count', 'attachments_count'] as $countKey) {
                if (! array_key_exists($countKey, $payload)) {
                    continue;
                }
                $value = $payload[$countKey];
                if (is_int($value)) {
                    $this->assertSame(0, $value, "count key $countKey must be 0 on cluster cross-org");
                } else {
                    $this->assertContains(
                        $value,
                        [null, false],
                        "count key $countKey must be null/false on cluster cross-org"
                    );
                }
            }
        }
    }

    public function test_same_org_user_keeps_project_id_routing_pointer(): void
    {
        // Regression — same-org user (no cluster widening) keeps the
        // project_id routing pointer so the FE can resolve the project
        // navigation. We don't assert on project.name (eager-load
        // depends on the controller's relation graph and isn't
        // required for FK-only routing). The Phase 2B floor doesn't
        // change the same-org shape — covered by the existing
        // task-list tests.
        $org = Organization::factory()->create();
        $dept = Department::factory()->create([
            'organization_id' => $org->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::TASKS_VIEW, 'organization', $org->id);

        $task = Task::factory()->create([
            'project_id' => $project->id,
            'department_id' => $dept->id,
            'assigned_to' => $user->id,
            'is_private' => false,
            'type' => 'project',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/unified-tasks/{$task->id}");
        $response->assertOk();
        $payload = $this->extractPayload($response);
        if (is_array($payload)) {
            $this->assertSame($project->id, $payload['project_id'] ?? null);
            $this->assertSame($dept->id, $payload['department_id'] ?? null);
        }
    }

    /**
     * The TaskResource serializes directly to the JSON top level
     * (Laravel's JsonResource) — there is no `data` envelope. The
     * helper returns whatever shape the controller emits.
     */
    private function extractPayload(TestResponse $response): mixed
    {
        $decoded = $response->json();
        if (! is_array($decoded)) {
            return null;
        }

        return array_key_exists('data', $decoded) && is_array($decoded['data'])
            ? $decoded['data']
            : $decoded;
    }

    // ──────────────────────────────────────────────────────────────
    // helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: Organization, 1: Organization}
     */
    private function makeClusterTree(string $clusterName = 'cluster2b', string $hospitalName = 'hospital2b'): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => $clusterName]);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => $hospitalName]);

        return [$cluster, $hospital];
    }
}
