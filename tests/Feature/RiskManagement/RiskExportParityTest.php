<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Models\Risk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 5 export-parity gate: GET /api/risk-management/export/pdf must share
 * the exact same orgFilter() as the list and CSV endpoints.
 *
 * Actual behavior (RiskDashboardController::exportPdf): an org-A admin does
 * NOT get a 403 — the export succeeds (200) but the query is scoped with
 * `where organization_id = <org A>`, so the org-B risk can never reach the
 * rendered PDF. The 403 branch of orgFilter() only fires for a
 * non-super-admin whose own organization_id is null. Both branches are
 * asserted here, plus a CSV anchor proving content-level parity and a
 * super-admin run proving the unfiltered branch.
 *
 * Wave 3 task 8: legacy Spatie view_risks / view_risk_reports grant paths
 * removed; the engine (Capability::RISKS_VIEW + RISKS_VIEW_REPORTS) is the
 * only authz source.
 */
class RiskExportParityTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private const ORG_A_CODE = 'RSK-2026-7001';

    private const ORG_B_CODE = 'RSK-2026-7002';

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $department;

    protected User $orgAAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->department = Department::factory()->create();

        $this->orgAAdmin = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // Engine grants (Wave 3 task 8): replaces legacy Spatie view_risks +
        // view_risk_reports. Both are needed because the PDF export uses
        // RISKS_VIEW_REPORTS and the CSV/list path uses RISKS_VIEW.
        $this->grantEngineCapability($this->orgAAdmin, Capability::RISKS_VIEW);
        $this->grantEngineCapability($this->orgAAdmin, Capability::RISKS_VIEW_REPORTS);

        $this->makeRisk($this->orgA->id, self::ORG_A_CODE, 'خطر مؤسسة أ');
        $this->makeRisk($this->orgB->id, self::ORG_B_CODE, 'خطر مؤسسة ب');
    }

    private function makeRisk(int $organizationId, string $code, string $title): Risk
    {
        $risk = new Risk;
        $risk->forceFill([
            'code' => $code,
            'organization_id' => $organizationId,
            'title' => $title,
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
            'owner_id' => $this->orgAAdmin->id,
            'response_type' => 'mitigate',
            'created_by' => $this->orgAAdmin->id,
        ])->save();

        return Risk::find($risk->id);
    }

    /**
     * Org-A admin PDF export: NOT 403 — a 200 export whose risks query is
     * bound to org A, so the org-B risk is excluded by the same orgFilter()
     * used by list/CSV.
     */
    public function test_org_admin_pdf_export_is_org_scoped_not_403(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->get('/api/risk-management/export/pdf');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Documented actual behavior: filtered export, not a blanket 403.
        $response->assertStatus(200);
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent(), 'PDF export must return a rendered PDF document.');

        $riskQueries = array_values(array_filter(
            $queries,
            fn (array $q) => str_contains($q['query'], '"risks"')
        ));

        $this->assertNotEmpty($riskQueries, 'PDF export must query the risks table.');

        foreach ($riskQueries as $q) {
            $this->assertStringContainsString(
                '"organization_id"',
                $q['query'],
                "PDF export query is missing the org constraint:\n{$q['query']}"
            );
            $this->assertContains(
                $this->orgA->id,
                $q['bindings'],
                "PDF export query is not bound to the admin's organization:\n{$q['query']}"
            );
        }
    }

    /**
     * Parity anchor: the CSV endpoint (whose content is inspectable) applies
     * the identical filter — org-A code present, org-B code absent.
     */
    public function test_csv_export_applies_the_same_org_filter(): void
    {
        $response = $this->actingAs($this->orgAAdmin, 'sanctum')
            ->get('/api/risk-management/export/csv');

        $response->assertStatus(200);

        $csv = $response->streamedContent();
        $this->assertStringContainsString(self::ORG_A_CODE, $csv, 'CSV must include the org-A risk.');
        $this->assertStringNotContainsString(self::ORG_B_CODE, $csv, 'CSV must exclude the org-B risk.');
    }

    /**
     * The only 403 branch of orgFilter(): a non-super-admin with a null
     * organization_id is denied on PDF exactly as on list/CSV/dashboard.
     */
    public function test_null_org_user_gets_403_on_pdf_export(): void
    {
        $nullOrgUser = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // No engine grant on purpose: a non-super-admin null-org user is
        // denied by orgFilter() before the engine grant is even checked.
        // orgFilter() aborts 403 because $user->organization_id is null and
        // they're not super_admin — the engine path inherits that gate.
        $this->actingAs($nullOrgUser, 'sanctum')
            ->get('/api/risk-management/export/pdf')
            ->assertStatus(403);
    }

    /**
     * Super-admin branch: the PDF query runs without any organization_id
     * constraint (cross-org export is super-admin-only).
     */
    public function test_super_admin_pdf_export_is_unfiltered(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->get('/api/risk-management/export/pdf');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);

        $riskQueries = array_values(array_filter(
            $queries,
            fn (array $q) => str_contains($q['query'], 'from "risks"')
        ));

        $this->assertNotEmpty($riskQueries, 'Super-admin PDF export must query the risks table.');

        foreach ($riskQueries as $q) {
            $this->assertStringNotContainsString(
                '"organization_id" =',
                $q['query'],
                "Super-admin PDF export must not be org-constrained:\n{$q['query']}"
            );
        }
    }
}
