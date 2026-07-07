<?php

namespace Tests\Feature\Api\Surveys;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Enums\SurveyStatus;
use App\Modules\Surveys\Enums\SurveyType;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Models\SurveySection;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Wave 1 Task 1.8: GET /api/surveys/{survey}/export — PII scope + authz.
 *
 *   1. Apply T-G: org-A reviewer's export contains org-A survey data and
 *      does NOT include org-B survey data.
 *   2. A user without `view_survey_responses` → 403; unauthenticated → 401.
 *
 * M-04: the unrouted `downloadExport()` path-traversal sink and the
 * `download_url` it was advertised through were removed. The export endpoint
 * now returns metadata only; no server-side file-download path remains.
 */
class SurveyExportAuthzTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);

        Storage::fake('local');
    }

    private function makeUser(Organization $org, Department $dept, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function makeSurvey(Organization $org, Department $dept): Survey
    {
        $survey = Survey::create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'title' => 'Wave 1 Survey '.$org->id,
            'title_ar' => 'استبيان',
            'code' => 'WAVE1-'.$org->id,
            'type' => SurveyType::Periodic,
            'status' => SurveyStatus::Published,
            'is_anonymous' => false,
            'created_by' => $this->makeUser($org, $dept)->id,
        ]);

        $section = SurveySection::create([
            'survey_id' => $survey->id,
            'title' => 'Section A',
            'sort_order' => 1,
        ]);

        SurveyField::create([
            'survey_id' => $survey->id,
            'section_id' => $section->id,
            'name' => 'name',
            'label' => 'Name',
            'field_key' => 'name',
            'type' => 'text',
            'is_required' => true,
            'sort_order' => 1,
        ]);

        return $survey;
    }

    private function makeResponse(Survey $survey, User $respondent): SurveyResponse
    {
        // SurveyResponse.respondent_name is encrypted at rest (cast:
        // 'encrypted'); the encrypted base64 blows past varchar(255) when
        // faker generates long names. We null the encrypted fields here —
        // they aren't required for the export counts we assert on. If a
        // future test needs them, use a short literal name.
        return SurveyResponse::create([
            'survey_id' => $survey->id,
            'respondent_id' => $respondent->id,
            'respondent_name' => null,
            'respondent_email' => null,
            'status' => 'submitted',
        ]);
    }

    // ========== Authz ==========

    public function test_export_requires_authentication(): void
    {
        $survey = $this->makeSurvey($this->orgA, $this->deptA);

        $this->getJson("/api/surveys/{$survey->id}/export")->assertStatus(401);
    }

    public function test_export_denies_without_view_survey_responses_capability(): void
    {
        $survey = $this->makeSurvey($this->orgA, $this->deptA);
        $viewer = $this->makeUser($this->orgA, $this->deptA, 'viewer');

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/surveys/{$survey->id}/export")
            ->assertStatus(403);
    }

    public function test_export_succeeds_for_admin_with_view_survey_responses(): void
    {
        $survey = $this->makeSurvey($this->orgA, $this->deptA);
        $admin = $this->makeUser($this->orgA, $this->deptA, 'admin');
        // admin role grants VIEW_SURVEY_RESPONSES (Permission::VIEW_SURVEY_RESPONSES)
        // via RolesAndPermissionsSeeder.

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/surveys/{$survey->id}/export")
            ->assertStatus(200)
            ->assertJsonStructure(['message', 'filename', 'format', 'responses_count'])
            ->assertJsonMissingPath('download_url');
    }

    public function test_no_export_download_route_is_registered(): void
    {
        // M-04: the path-traversal download sink must not resolve to any route.
        $survey = $this->makeSurvey($this->orgA, $this->deptA);
        $admin = $this->makeUser($this->orgA, $this->deptA, 'admin');

        $this->actingAs($admin, 'sanctum')
            ->get("/api/surveys/{$survey->id}/export/download?file=../../.env")
            ->assertStatus(404);
    }

    // ========== Org scope (T-G) ==========

    public function test_export_metadata_responses_count_is_org_scoped(): void
    {
        $surveyA = $this->makeSurvey($this->orgA, $this->deptA);
        $surveyB = $this->makeSurvey($this->orgB, $this->deptB);

        // 2 responses on orgA survey, 1 on orgB survey
        $this->makeResponse($surveyA, $this->makeUser($this->orgA, $this->deptA));
        $this->makeResponse($surveyA, $this->makeUser($this->orgA, $this->deptA));
        $this->makeResponse($surveyB, $this->makeUser($this->orgB, $this->deptB));

        $orgAAdmin = $this->makeUser($this->orgA, $this->deptA, 'admin');

        $responseA = $this->actingAs($orgAAdmin, 'sanctum')
            ->getJson("/api/surveys/{$surveyA->id}/export")
            ->assertStatus(200);

        $this->assertSame(2, $responseA->json('responses_count'));

        // orgA admin must not be able to call the export endpoint with
        // an orgB survey id — the controller's authorizeSurvey() must
        // enforce org floor.
        $this->actingAs($orgAAdmin, 'sanctum')
            ->getJson("/api/surveys/{$surveyB->id}/export")
            ->assertStatus(403);
    }

    public function test_super_admin_can_export_across_orgs(): void
    {
        $surveyB = $this->makeSurvey($this->orgB, $this->deptB);
        $this->makeResponse($surveyB, $this->makeUser($this->orgB, $this->deptB));

        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/surveys/{$surveyB->id}/export")
            ->assertStatus(200)
            ->assertJsonPath('responses_count', 1);
    }
}
