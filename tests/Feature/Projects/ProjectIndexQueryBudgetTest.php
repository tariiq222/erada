<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\Projects\Models\Project;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ProjectIndexQueryBudgetTest — proves GET /api/projects does not N+1 when the
 * ProjectResource resolves per-record `abilities` through AccessDecision.
 *
 * Why a department manager (not super_admin): super_admin short-circuits
 * AccessDecision::can() at layer 1, so it never walks the scope chain — the
 * existing PerformanceBaselineTest uses super_admin and therefore cannot catch
 * this N+1. A dept_manager is a non-super-admin whose abilities are granted
 * through the scope chain, so resolving the abilities map for every row forces
 * Project::scopeParent() to resolve the department for each project. Without the
 * engine identity-map fix that is one department fetch per project (N+1); with
 * it (and the controller eager-loading the full department) it collapses to one.
 */
class ProjectIndexQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Memoized count observed after the fix is 14 (constant, independent of the
     * project count): the per-record department fetch collapses to the single
     * department already eager-loaded for the list. Budget 15 leaves a 1-query
     * margin while still catching a regression — re-introducing a per-record
     * scopeParent fetch over 10 projects pushes the count to 24, past this ceiling.
     */
    private const INDEX_QUERY_BUDGET = 15;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(ScopedDepartmentRolesSeeder::class);
    }

    public function test_index_stays_within_query_budget_for_ten_projects(): void
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

        // Ten projects in one department: the manager sees them all through the
        // department-scoped role, and each row's abilities walk the scope chain.
        for ($i = 0; $i < 10; $i++) {
            Project::factory()->create([
                'organization_id' => $org->id,
                'department_id' => $dept->id,
                'created_by' => null,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($mgr->fresh(), 'sanctum')
            ->getJson('/api/projects?per_page=15');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);
        $this->assertCount(10, $response->json('data'), 'All 10 projects must be returned.');
        // Sanity: the abilities map is actually being resolved per record.
        $this->assertTrue((bool) $response->json('data.0.abilities.view'));

        $this->assertLessThanOrEqual(
            self::INDEX_QUERY_BUDGET,
            count($queries),
            sprintf(
                "Project index exceeded the query budget (%d > %d) — likely a per-record scopeParent N+1.\nQueries:\n%s",
                count($queries),
                self::INDEX_QUERY_BUDGET,
                implode("\n", array_column($queries, 'query'))
            )
        );
    }
}
