<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TaskIndexQueryBudgetTest — proves GET /api/unified-tasks does not N+1 when the
 * TaskResource resolves per-record `abilities` through AccessDecision.
 *
 * A Task rolls up to its project, then to the project's department (Task ->
 * project -> department). Resolving abilities per row walks that chain, so an
 * unmemoized scopeParent() issues one project fetch + one department fetch per
 * task (N+1). The engine identity map (and eager-loading the full project +
 * department in the index) collapses each distinct ancestor to a single fetch.
 *
 * Visibility + viewAny: the user is the department's manager (dept_manager
 * scoped role via DepartmentCapacityRole) which grants TASKS_* through the scope
 * chain, and additionally holds the org-scoped pmo_coordinator role so the
 * org-level viewAny gate (target=null, organization scope) passes. Neither role
 * is is_admin_role, so per-record abilities are resolved by walking the chain
 * (no org-functional shortcut) — exactly the path the N+1 lives on.
 */
class TaskIndexQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    // Memoized count observed after the fix is 17 (constant, independent of the
    // task count): the per-record project/department fetches collapse to a single
    // fetch per distinct ancestor via the engine identity map. Budget 18 leaves a
    // 1-query margin while still catching a regression — re-introducing a
    // per-record scopeParent fetch over 10 tasks pushes the count to ~76, far past
    // this ceiling.
    private const INDEX_QUERY_BUDGET = 18;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ScopedDepartmentRolesSeeder::class);
    }

    public function test_index_stays_within_query_budget_for_ten_tasks(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);

        DepartmentCapacityRole::create([
            'department_id' => $dept->id,
            'capacity' => 'manager',
            'role_key' => 'dept_manager',
        ]);

        $mgr = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $dept->update(['manager_id' => $mgr->id]);
        app(ScopedDepartmentRoleSyncService::class)->syncUser($mgr->fresh());
        // Org-scoped canonical viewer makes the org-level viewAny gate explicit;
        // the synchronized department-manager assignment still governs scope.
        $this->grantCanonicalViewer($mgr);

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        for ($i = 0; $i < 10; $i++) {
            Task::factory()->create([
                'type' => TaskType::PROJECT->value,
                'project_id' => $project->id,
                'department_id' => $dept->id,
                'assigned_to' => null,
                'created_by' => null,
                'owner_id' => null,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($mgr->fresh(), 'sanctum')
            ->getJson('/api/unified-tasks?per_page=15');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);
        $this->assertCount(10, $response->json('data'), 'All 10 tasks must be returned.');
        $this->assertTrue((bool) $response->json('data.0.abilities.view'));

        $this->assertLessThanOrEqual(
            self::INDEX_QUERY_BUDGET,
            count($queries),
            sprintf(
                "Task index exceeded the query budget (%d > %d) — likely a per-record scopeParent N+1.\nQueries:\n%s",
                count($queries),
                self::INDEX_QUERY_BUDGET,
                implode("\n", array_column($queries, 'query'))
            )
        );
    }
}
