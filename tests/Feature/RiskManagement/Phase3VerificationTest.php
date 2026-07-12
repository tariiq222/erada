<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Models\Risk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 3 automated verification gates for the RiskManagement module:
 *
 *  1. Index query budget (eager loading, no N+1).
 *  2. Matrix endpoint runs exactly one aggregate query, no relation joins.
 *  3. Dashboard aggregates each run their own org-scoped query.
 *  4. CSV export streams (StreamedResponse) with 500+ rows.
 *
 * Factories for the module are Phase 5 scope, so rows are seeded with
 * explicit attributes. Authz is engine-only (Wave 3 task 8): the test user
 * is granted Capability::RISKS_VIEW + RISKS_VIEW_REPORTS via the engine
 * (GrantsEngineCapability trait).
 */
class Phase3VerificationTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    /**
     * Query budget for GET /api/risk-management/risks with 10 seeded risks.
     *
     * Observed breakdown (PostgreSQL, query log) — engine path (Wave 3 task 8):
     *   - 2 Spatie role lookups (model_has_roles + roles)
     *   - 1 engine scoped-roles lookup (model_has_scoped_roles)
     *   - 1 ScopedRoleDefinition lookup (the engine consults it per request)
     *   - 1 risk_settings read (governing-dept check inside UserRiskScope)
     *   - 1 paginator COUNT query
     *   - 1 main risks SELECT (withCount('actions') is an inline subquery)
     *   - 3 eager-load queries: departments, owners (users), creators (users)
     *
     * Total = 10. A per-risk N+1 with 10 risks would add >= 10 queries, so
     * holding the line at 10 proves the index eager-loads its relations.
     *
     * Legacy Spatie gate used the cache (3 fewer lookups: no scoped-roles,
     * no ScopedRoleDefinition, no risk_settings). The engine path costs one
     * extra round-trip per request in exchange for the contextual scope chain.
     */
    private const INDEX_QUERY_BUDGET = 10;

    protected Organization $organization;

    protected Department $department;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create();

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // Engine grants (Wave 3 task 8): replaces legacy Spatie view_risks +
        // view_risk_reports. RISKS_VIEW covers the list/dashboard/show paths;
        // RISKS_VIEW_REPORTS covers the matrix + CSV/PDF exports.
        // Grant both capabilities on one canonical role assignment so the fixture
        // mirrors the production permission graph at organization scope.
        $this->grantEngineCapability($this->user, [
            Capability::RISKS_VIEW,
            Capability::RISKS_VIEW_REPORTS,
        ]);
    }

    /**
     * Seed one risk with department/owner/creator set, via Model::create
     * (factories are Phase 5 scope).
     */
    private function makeRisk(array $override = []): Risk
    {
        $defaults = [
            'organization_id' => $this->organization->id,
            'title' => 'خطر تشغيلي للاختبار',
            'discovery_date' => '2026-06-01',
            'type' => 'operational',
            'department_id' => $this->department->id,
            'description' => 'وصف الخطر',
            'initial_likelihood' => 2,
            'initial_impact' => 2,
            'current_likelihood' => 2,
            'current_impact' => 2,
            'current_score' => 4,
            'current_level' => 'medium',
            'status' => 'open',
            'owner_id' => $this->user->id,
            'response_type' => 'mitigate',
            'created_by' => $this->user->id,
        ];

        $risk = new Risk;
        $risk->forceFill(array_merge($defaults, $override))->save();

        return Risk::find($risk->id);
    }

    /**
     * Gate 1 — Query budget: 10 risks listed with a constant number of
     * queries (eager loading, no per-risk N+1).
     */
    public function test_index_stays_within_query_budget_for_ten_risks(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->makeRisk(['title' => "خطر رقم {$i}"]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/risk-management/risks');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);
        $this->assertCount(10, $response->json('data'), 'All 10 seeded risks must be returned.');

        $this->assertLessThanOrEqual(
            self::INDEX_QUERY_BUDGET,
            count($queries),
            sprintf(
                "Index exceeded the query budget (%d > %d) — likely an N+1.\nQueries:\n%s",
                count($queries),
                self::INDEX_QUERY_BUDGET,
                implode("\n", array_column($queries, 'query'))
            )
        );
    }

    /**
     * Gate 2 — Matrix query shape: exactly one aggregate query against the
     * risks table, grouped by current_likelihood/current_impact, with no
     * relation joins in its SQL.
     */
    public function test_matrix_runs_single_grouped_aggregate_without_joins(): void
    {
        $this->makeRisk(['current_likelihood' => 1, 'current_impact' => 3]);
        $this->makeRisk(['current_likelihood' => 3, 'current_impact' => 3, 'current_score' => 9, 'current_level' => 'critical']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/risk-management/matrix');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);

        $riskQueries = array_values(array_filter(
            $queries,
            fn (array $q) => str_contains($q['query'], '"risks"')
        ));

        $this->assertCount(
            1,
            $riskQueries,
            "Matrix must hit the risks table with exactly one aggregate query.\nQueries:\n"
                .implode("\n", array_column($queries, 'query'))
        );

        $sql = strtolower($riskQueries[0]['query']);
        $this->assertStringContainsString('count(*)', $sql, 'Matrix query must aggregate with COUNT(*).');
        $this->assertStringContainsString(
            'group by "current_likelihood", "current_impact"',
            $sql,
            'Matrix query must group by current_likelihood and current_impact.'
        );
        $this->assertStringNotContainsString('join', $sql, 'Matrix query must not join any relation tables.');
    }

    /**
     * Gate 3 — Dashboard aggregates: each aggregate runs its own query and
     * every query touching the risks table carries the org constraint.
     */
    public function test_dashboard_aggregates_each_run_their_own_org_scoped_query(): void
    {
        $this->makeRisk();
        $this->makeRisk(['status' => 'treating', 'current_level' => 'high', 'current_score' => 6, 'current_likelihood' => 2, 'current_impact' => 3]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/risk-management/dashboard');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);

        $riskQueries = array_values(array_filter(
            $queries,
            fn (array $q) => str_contains($q['query'], '"risks"')
        ));

        // by_status, by_level, overdue actions (risks via EXISTS subquery),
        // top risks, totals.all, totals.open — one scoped query each.
        $this->assertCount(
            6,
            $riskQueries,
            "Dashboard must run one query per aggregate (6 touching risks).\nQueries:\n"
                .implode("\n", array_column($queries, 'query'))
        );

        foreach ($riskQueries as $q) {
            $this->assertStringContainsString(
                '"organization_id"',
                $q['query'],
                "Dashboard aggregate query is missing the org constraint:\n{$q['query']}"
            );
            $this->assertContains(
                $this->organization->id,
                $q['bindings'],
                "Dashboard aggregate query is not bound to the user's organization:\n{$q['query']}"
            );
        }
    }

    /**
     * Gate 4 — CSV streaming: 500+ risks export as a StreamedResponse
     * with status 200 (chunked, never loaded fully into memory).
     */
    public function test_csv_export_streams_with_five_hundred_risks(): void
    {
        $now = now();
        $rows = [];
        for ($i = 1; $i <= 510; $i++) {
            $rows[] = [
                'code' => sprintf('RSK-2026-%04d', $i),
                'organization_id' => $this->organization->id,
                'title' => "خطر مُصدَّر رقم {$i}",
                'discovery_date' => '2026-06-01',
                'type' => 'operational',
                'department_id' => $this->department->id,
                'initial_likelihood' => 2,
                'initial_impact' => 2,
                'current_likelihood' => 2,
                'current_impact' => 2,
                'current_score' => 4,
                'current_level' => 'medium',
                'status' => 'open',
                'owner_id' => $this->user->id,
                'response_type' => 'mitigate',
                'created_by' => $this->user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 255) as $chunk) {
            Risk::insert($chunk);
        }

        $this->assertSame(510, Risk::count());

        $response = $this->actingAs($this->user, 'sanctum')
            ->get('/api/risk-management/export/csv');

        $response->assertStatus(200);
        $this->assertInstanceOf(
            StreamedResponse::class,
            $response->baseResponse,
            'CSV export must return a StreamedResponse, not a buffered response.'
        );
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }
}
