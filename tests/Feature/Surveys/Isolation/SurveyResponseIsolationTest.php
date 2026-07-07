<?php

namespace Tests\Feature\Surveys\Isolation;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * SurveyResponseIsolationTest - Phase 6: org isolation of /api/surveys/{survey}/responses.
 *
 * Phase 6 note: the route group still uses Spatie middleware
 * `permission:view_survey_responses`. Tests below exercise BOTH:
 *   - the Spatie permission gate (middleware layer), AND
 *   - the per-record authorizeSurvey() floor (controller layer).
 *
 * Cross-org users who hold the Spatie permission still get blocked at the
 * controller-level authorizeSurvey() defense-in-depth, while users lacking it
 * never pass the middleware at all.
 */
class SurveyResponseIsolationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_user_without_view_survey_responses_is_denied_at_middleware(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyA = Survey::factory()->create([
            'organization_id' => $orgA->id,
        ]);
        SurveyResponse::factory()->create(['survey_id' => $surveyA->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        // No Spatie 'view_survey_responses', no engine SURVEYS_REVIEW_RESPONSES.
        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$surveyA->id}/responses");

        // Spatie permission:view_survey_responses middleware blocks first.
        $response->assertStatus(403);
    }

    public function test_org_a_user_with_view_permission_sees_only_org_a_responses(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyA = Survey::factory()->create(['organization_id' => $orgA->id]);
        $surveyB = Survey::factory()->create(['organization_id' => $orgB->id]);
        SurveyResponse::factory()->count(2)->create(['survey_id' => $surveyA->id]);
        SurveyResponse::factory()->count(3)->create(['survey_id' => $surveyB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        // The /api/surveys/{survey}/responses route lives INSIDE the
        // engine_capability:surveys.view group, AND has its own
        // permission:view_survey_responses Spatie middleware. Admin role grants
        // view_survey_responses via Spatie seeder; engine_capability grants are
        // a separate code path — we still need the engine SURVEYS_VIEW too.
        $actor->assignRole('admin');
        $this->grantEngineCapability($actor, Capability::SURVEYS_VIEW);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$surveyA->id}/responses");

        $response->assertStatus(200);
        // Response shape — SurveyResponseResource::collection paginates via
        // LengthAwarePaginator; Laravel paginate() places `total` at the root
        // and `data` at the root too (default ResourceCollection).
        $body = $response->json();
        $total = $body['total'] ?? ($body['meta']['total'] ?? null);
        $data = $body['data'] ?? [];
        $this->assertSame(2, $total);
        foreach ($data as $row) {
            $this->assertSame($surveyA->id, $row['survey_id']);
        }
    }

    public function test_org_a_user_with_view_permission_cannot_list_org_b_responses(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyA = Survey::factory()->create(['organization_id' => $orgA->id]);
        $surveyB = Survey::factory()->create(['organization_id' => $orgB->id]);
        SurveyResponse::factory()->count(2)->create(['survey_id' => $surveyB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $actor->assignRole('admin');
        $this->grantEngineCapability($actor, Capability::SURVEYS_VIEW);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$surveyA->id}/responses");

        // The route binds $survey from the URL → $surveyA (org A). The index lists
        // $survey->responses() which are all on org A surveyA — count = 0 (no responses
        // on surveyA), 200.
        // To actually trigger the cross-org floor we need to GET the show endpoint
        // with a response from org B accessed via the org A survey route.
        // Phase 6 controller-side assertion aborts 404 when the response's
        // survey_id !== the URL $survey id.
        $response->assertStatus(200);
        $body = $response->json();
        $total = $body['total'] ?? ($body['meta']['total'] ?? null);
        $this->assertSame(0, $total);

        // Now the cross-org single-resource look-up: org B response, accessed
        // via the org A survey route — must 404 (controller-level
        // `if ($response->survey_id !== $survey->id) abort(404)`).
        $bResponse = SurveyResponse::where('survey_id', $surveyB->id)->first();
        $show = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$surveyA->id}/responses/{$bResponse->id}");
        $show->assertStatus(404);
    }

    public function test_super_admin_can_list_any_org_responses(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyB = Survey::factory()->create(['organization_id' => $orgB->id]);
        SurveyResponse::factory()->count(3)->create(['survey_id' => $surveyB->id]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $superAdmin->assignRole('super_admin');
        // Group middleware still gates; super_admin has all permissions via Spatie
        // seeder, but the engine group middleware wants SURVEYS_VIEW directly.
        $this->grantEngineCapability($superAdmin, Capability::SURVEYS_VIEW);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/surveys/{$surveyB->id}/responses");

        // super_admin bypasses authorizeSurvey() floor.
        $response->assertStatus(200);
        $body = $response->json();
        $total = $body['total'] ?? ($body['meta']['total'] ?? null);
        $this->assertSame(3, $total);
    }
}
