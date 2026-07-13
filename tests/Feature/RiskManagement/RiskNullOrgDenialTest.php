<?php

namespace Tests\Feature\RiskManagement;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Models\Risk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 5 null-org denial gate: a risk stored with `organization_id = null`
 * (a super-admin "global" record) must be invisible to every non-super-admin
 * org user on all three read surfaces — list, dashboard, CSV export — even
 * when that user holds the engine Capability::RISKS_VIEW + RISKS_VIEW_REPORTS.
 * A super-admin sees it everywhere.
 *
 * This locks the deny-not-bypass semantics of orgFilter(): the filter binds
 * to the user's organization_id, so `organization_id IS NULL` rows can never
 * match a non-super-admin query.
 *
 * Wave 3 task 8: legacy Spatie view_risks / view_risk_reports grant paths
 * removed; the engine is the only authz source.
 */
class RiskNullOrgDenialTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private const NULL_ORG_CODE = 'RSK-2026-9001';

    protected Organization $organization;

    protected Department $department;

    protected User $orgUser;

    protected User $superAdmin;

    protected Risk $nullOrgRisk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create();

        $this->orgUser = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // Engine grants (Wave 3 task 8): replaces legacy Spatie view_risks +
        // view_risk_reports. Both capabilities are granted org-scope so the
        // org user would normally see every risk in the org — but the null-org
        // risk is filtered by orgFilter() regardless of grants.
        // Grant both capabilities on one canonical role assignment so the fixture
        // mirrors the production permission graph at organization scope.
        $this->grantEngineCapability($this->orgUser, [
            Capability::RISKS_VIEW,
            Capability::RISKS_VIEW_REPORTS,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);

        $this->nullOrgRisk = new Risk;
        $this->nullOrgRisk->forceFill([
            'code' => self::NULL_ORG_CODE,
            'organization_id' => null,
            'title' => 'خطر عام بدون مؤسسة',
            'discovery_date' => '2026-06-01',
            'type' => 'operational',
            'department_id' => $this->department->id,
            'description' => 'سجل عام أنشأه مدير النظام',
            'initial_likelihood' => 3,
            'initial_impact' => 3,
            'current_likelihood' => 3,
            'current_impact' => 3,
            'current_score' => 9,
            'current_level' => 'critical',
            'status' => 'open',
            'owner_id' => $this->superAdmin->id,
            'response_type' => 'mitigate',
            'created_by' => $this->superAdmin->id,
        ])->save();
        $this->nullOrgRisk = Risk::find($this->nullOrgRisk->id);
    }

    public function test_org_user_list_excludes_null_org_risk(): void
    {
        $response = $this->actingAs($this->orgUser, 'sanctum')
            ->getJson('/api/risk-management/risks');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'), 'Null-org risks must never appear in an org user list.');
        $this->assertStringNotContainsString(self::NULL_ORG_CODE, $response->getContent());
    }

    public function test_org_user_dashboard_excludes_null_org_risk(): void
    {
        $response = $this->actingAs($this->orgUser, 'sanctum')
            ->getJson('/api/risk-management/dashboard');

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('totals.all'), 'Dashboard totals must not count null-org risks.');
        $this->assertSame(0, $response->json('totals.open'));
        $this->assertSame([], $response->json('top_risks'));
        $this->assertStringNotContainsString(self::NULL_ORG_CODE, $response->getContent());
    }

    public function test_org_user_csv_export_excludes_null_org_risk(): void
    {
        $response = $this->actingAs($this->orgUser, 'sanctum')
            ->get('/api/risk-management/export/csv');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('الرمز', $csv, 'CSV must still emit the header row.');
        $this->assertStringNotContainsString(self::NULL_ORG_CODE, $csv, 'CSV export must exclude null-org risks.');
    }

    public function test_super_admin_sees_null_org_risk_on_all_three_surfaces(): void
    {
        // 1. List
        $list = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/risk-management/risks');

        $list->assertStatus(200);
        $this->assertContains(
            self::NULL_ORG_CODE,
            array_column($list->json('data'), 'code'),
            'Super-admin list must include the null-org risk.'
        );

        // 2. Dashboard
        $dashboard = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/risk-management/dashboard');

        $dashboard->assertStatus(200);
        $this->assertSame(1, $dashboard->json('totals.all'), 'Super-admin dashboard must count the null-org risk.');

        // 3. CSV export
        $csv = $this->actingAs($this->superAdmin, 'sanctum')
            ->get('/api/risk-management/export/csv');

        $csv->assertStatus(200);
        $this->assertStringContainsString(
            self::NULL_ORG_CODE,
            $csv->streamedContent(),
            'Super-admin CSV export must include the null-org risk.'
        );
    }
}
