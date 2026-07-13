<?php

namespace Tests\Feature\Api\Surveys;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class SurveyResponseControllerTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected Survey $survey;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);
        $this->department = $this->deptA;
        $this->user = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);
        $this->survey = Survey::factory()->published()->create([
            'organization_id' => $this->orgA->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function makeResponse(array $overrides = []): SurveyResponse
    {
        return SurveyResponse::factory()->create(array_merge([
            'survey_id' => $this->survey->id,
            'respondent_id' => $this->user->id,
            'status' => 'submitted',
            'submitted_at' => now(),
            'respondent_name' => null,
            'respondent_email' => null,
        ], $overrides));
    }

    private function makeCrossOrgActor(): User
    {
        $actor = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($actor, 'admin');
        $this->grantEngineCapability($actor, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_REVIEW_RESPONSES,
        ]);

        return $actor;
    }

    // ========== index ==========

    public function test_can_list_survey_responses(): void
    {
        $this->makeResponse();
        $this->makeResponse();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/surveys/{$this->survey->id}/responses");

        $response->assertStatus(200);
    }

    public function test_unauthenticated_cannot_list_responses(): void
    {
        $response = $this->getJson("/api/surveys/{$this->survey->id}/responses");

        $response->assertStatus(401);
    }

    // ========== show ==========

    public function test_can_view_response(): void
    {
        $surveyResponse = $this->makeResponse();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/surveys/{$this->survey->id}/responses/{$surveyResponse->id}");

        $response->assertStatus(200);
    }

    // ========== flag ==========

    public function test_can_flag_response(): void
    {
        $surveyResponse = $this->makeResponse();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$surveyResponse->id}/flag", [
                'notes' => 'هذه الإجابة مشكوك فيها',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('survey_responses', [
            'id' => $surveyResponse->id,
            'status' => 'flagged',
        ]);
    }

    public function test_flag_requires_notes(): void
    {
        $surveyResponse = $this->makeResponse();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$surveyResponse->id}/flag", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    }

    // ========== review ==========

    public function test_can_review_response(): void
    {
        $surveyResponse = $this->makeResponse();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$surveyResponse->id}/review", [
                'status' => 'submitted',
                'notes' => 'تمت المراجعة والموافقة',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('survey_responses', [
            'id' => $surveyResponse->id,
            'reviewed_by' => $this->user->id,
        ]);
    }

    public function test_review_requires_status(): void
    {
        $surveyResponse = $this->makeResponse();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$surveyResponse->id}/review", [
                'notes' => 'ملاحظة',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_returns_404_for_nonexistent_response(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/surveys/{$this->survey->id}/responses/99999");

        $response->assertStatus(404);
    }

    // ========== Cross-org isolation (T-A applied to flag / review) ==========
    //
    // The flag/review routes are gated by Spatie's flat `view_survey_responses`
    // permission AND by SurveyResponsePolicy::review() which calls the engine
    // for Capability::SURVEYS_REVIEW_RESPONSES plus an org-match check. An actor
    // from orgA with the flat perm + the engine capability must still be blocked
    // from acting on a response whose survey belongs to orgB.

    public function test_cross_org_actor_with_review_capability_cannot_flag_foreign_response(): void
    {
        $actor = $this->makeCrossOrgActor();
        $foreignSurvey = Survey::factory()->published()->create([
            'organization_id' => $this->orgB->id,
        ]);
        $foreignResponse = SurveyResponse::factory()->create([
            'survey_id' => $foreignSurvey->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/surveys/{$foreignSurvey->id}/responses/{$foreignResponse->id}/flag", [
                'notes' => 'محاولة فلاغ من مؤسسة أخرى',
            ]);

        $this->assertContains(
            $response->status(),
            [403, 404],
            'org-A actor with view_survey_responses + review capability must not flag an org-B response'
        );
        // Status must not have been mutated by a denied call.
        $this->assertSame('submitted', $foreignResponse->fresh()->status->value);
    }

    public function test_cross_org_actor_with_review_capability_cannot_review_foreign_response(): void
    {
        $actor = $this->makeCrossOrgActor();
        $foreignSurvey = Survey::factory()->published()->create([
            'organization_id' => $this->orgB->id,
        ]);
        $foreignResponse = SurveyResponse::factory()->create([
            'survey_id' => $foreignSurvey->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/surveys/{$foreignSurvey->id}/responses/{$foreignResponse->id}/review", [
                'status' => 'submitted',
                'notes' => 'محاولة مراجعة من مؤسسة أخرى',
            ]);

        $this->assertContains(
            $response->status(),
            [403, 404],
            'org-A actor with view_survey_responses + review capability must not review an org-B response'
        );
        // Reviewer fields must not have been written.
        $fresh = $foreignResponse->fresh();
        $this->assertNull($fresh->reviewed_by);
        $this->assertNull($fresh->reviewed_at);
    }

    public function test_cross_org_actor_cannot_read_foreign_response_show(): void
    {
        $actor = $this->makeCrossOrgActor();
        $foreignSurvey = Survey::factory()->published()->create([
            'organization_id' => $this->orgB->id,
        ]);
        $foreignResponse = SurveyResponse::factory()->create([
            'survey_id' => $foreignSurvey->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$foreignSurvey->id}/responses/{$foreignResponse->id}");

        $this->assertContains(
            $response->status(),
            [403, 404],
            'org-A actor must not read an org-B response'
        );
    }

    public function test_cross_org_actor_cannot_list_foreign_survey_responses(): void
    {
        $actor = $this->makeCrossOrgActor();
        $foreignSurvey = Survey::factory()->published()->create([
            'organization_id' => $this->orgB->id,
        ]);
        SurveyResponse::factory()->create([
            'survey_id' => $foreignSurvey->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$foreignSurvey->id}/responses");

        $this->assertContains(
            $response->status(),
            [403, 404],
            'org-A actor must not list org-B survey responses'
        );
    }

    public function test_cross_org_actor_without_review_capability_is_403_on_flag(): void
    {
        // A viewer in orgA with only the engine SURVEYS_VIEW capability (no admin
        // is_admin_role short-circuit): the Spatie view_survey_responses flat
        // permission lets them past the route middleware, but the Policy's engine
        // check for SURVEYS_REVIEW_RESPONSES must fail → 403.
        $actor = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($actor, 'viewer');
        // Give viewer the flat view_survey_responses permission so they clear the
        // route middleware, then give them only SURVEYS_VIEW (not SURVEYS_REVIEW_RESPONSES).
        $this->grantEngineCapability($actor, Capability::SURVEYS_REVIEW_RESPONSES);
        $this->grantEngineCapability($actor, Capability::SURVEYS_VIEW);

        $response = $this->makeResponse();

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/surveys/{$this->survey->id}/responses/{$response->id}/flag", [
                'notes' => 'بدون صلاحية مراجعة',
            ])
            ->assertStatus(403);
    }
}
