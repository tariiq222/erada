<?php

namespace Tests\Feature\Surveys\Isolation;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * DataImportIsolationTest - Phase 6: org isolation of /api/data-imports.
 *
 * GET /api/data-imports is open (no permission:view middleware at the route
 * level — the controller applies scope-or-deny inline). DataImportController::
 * index filters with whereHas('response.survey', organization_id = actor's
 * org), and authorizeImportRequest() in show() enforces a per-record org
 * floor (403 on cross-org or null-org actor).
 */
class DataImportIsolationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeImportInOrg(Organization $org): DataImportRequest
    {
        $creator = User::factory()->create(['organization_id' => $org->id]);
        $survey = Survey::factory()->create([
            'organization_id' => $org->id,
            'created_by' => $creator->id,
        ]);
        $respondent = User::factory()->create(['organization_id' => $org->id]);
        $response = SurveyResponse::factory()->create([
            'survey_id' => $survey->id,
            'respondent_id' => $respondent->id,
        ]);

        return DataImportRequest::factory()->create([
            'response_id' => $response->id,
        ]);
    }

    public function test_org_a_user_lists_only_org_a_data_imports(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $importA1 = $this->makeImportInOrg($orgA);
        $importA2 = $this->makeImportInOrg($orgA);
        $this->makeImportInOrg($orgB);
        $this->makeImportInOrg($orgB);
        $this->makeImportInOrg($orgB);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::SURVEYS_REVIEW_RESPONSES);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/data-imports');

        $response->assertStatus(200);
        $body = $response->json();
        // ResourceCollection with paginate() puts items in `data` and the count
        // in `meta.total`. DataImportController::index adds `success: true`.
        $this->assertSame(2, $body['meta']['total']);
        $this->assertCount(2, $body['data']);
        foreach ($body['data'] as $row) {
            $import = DataImportRequest::find($row['id']);
            $this->assertSame($orgA->id, $import?->response?->survey?->organization_id);
        }
    }

    public function test_org_a_user_filtering_by_org_b_survey_id_returns_empty(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyB = Survey::factory()->create(['organization_id' => $orgB->id]);
        $this->makeImportInOrg($orgA);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::SURVEYS_REVIEW_RESPONSES);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/data-imports?survey_id={$surveyB->id}");

        // Scope-or-deny: the user sees 0 rows for a survey_id they don't own.
        // Documented: 200 with total=0 (the index doesn't 403 on foreign survey_id,
        // it just returns zero matching rows).
        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(0, $body['meta']['total']);
        $this->assertCount(0, $body['data']);
    }

    public function test_org_a_user_cannot_show_org_b_data_import(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $importB = $this->makeImportInOrg($orgB);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::SURVEYS_REVIEW_RESPONSES);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/data-imports/{$importB->id}");

        // authorizeImportRequest() floors cross-org show.
        $response->assertStatus(403);
    }

    public function test_org_a_user_can_show_org_a_data_import(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $importA = $this->makeImportInOrg($orgA);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::SURVEYS_REVIEW_RESPONSES);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/data-imports/{$importA->id}");

        $response->assertStatus(200);
    }

    public function test_super_admin_can_show_any_data_import(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $importB = $this->makeImportInOrg($orgB);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/data-imports/{$importB->id}");

        $response->assertStatus(200);
    }

    public function test_null_org_user_lists_no_data_imports(): void
    {
        $orgA = Organization::factory()->create();
        $this->makeImportInOrg($orgA);
        $this->makeImportInOrg($orgA);

        $actor = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::SURVEYS_REVIEW_RESPONSES);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/data-imports');

        // Scope-or-deny for null-org: where organization_id = null ⇒ 0 rows.
        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(0, $body['meta']['total']);
        $this->assertCount(0, $body['data']);
    }
}
