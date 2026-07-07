<?php

namespace Tests\Unit\Surveys\Scopes;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyInvitation;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Scopes\UserSurveyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UserSurveyScopeTest - Phase 6.A: verify the single source of truth for the
 * Surveys org-isolation floor across all four query variants.
 *
 * Pins the four canonical isolation cases for every method:
 *   - super_admin ⇒ no filter (sees everything).
 *   - normal user ⇒ strict organization_id match on the row (Survey) or its
 *     parent Survey (SurveyResponse, SurveyInvitation) or grandparent
 *     Survey via response (DataImportRequest).
 *   - null-org user ⇒ zero rows (1 = 0 floor).
 *
 * For the walking relations (Responses, Invitations, ImportRequests) the
 * assertion still goes through `count()` on a fully-hydrated query.
 */
class UserSurveyScopeTest extends TestCase
{
    use RefreshDatabase;

    private UserSurveyScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserSurveyScope;
    }

    private function makeUser(?Organization $org, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'is_active' => true,
        ]);

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function makeSurvey(Organization $org): Survey
    {
        return Survey::factory()->create([
            'organization_id' => $org->id,
            'created_by' => User::factory()->create(['organization_id' => $org->id])->id,
        ]);
    }

    // ========== applyToSurveys ==========

    public function test_super_admin_sees_all_surveys(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $this->makeSurvey($orgA);
        $this->makeSurvey($orgA);
        $this->makeSurvey($orgB);
        $this->makeSurvey($orgB);
        $this->makeSurvey($orgB);

        $super = $this->makeUser(null, 'super_admin');

        $query = Survey::query();
        $this->scope->applyToSurveys($query, $super);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_org_a_user_sees_only_org_a_surveys(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $this->makeSurvey($orgA);
        $this->makeSurvey($orgA);
        $this->makeSurvey($orgB);
        $this->makeSurvey($orgB);
        $this->makeSurvey($orgB);

        $user = $this->makeUser($orgA);

        $query = Survey::query();
        $this->scope->applyToSurveys($query, $user);

        $this->assertSame(2, (clone $query)->count());
    }

    public function test_null_org_user_sees_no_surveys(): void
    {
        $orgA = Organization::factory()->create();
        $this->makeSurvey($orgA);
        $this->makeSurvey($orgA);

        $orphan = $this->makeUser(null);

        $query = Survey::query();
        $this->scope->applyToSurveys($query, $orphan);

        $this->assertSame(0, (clone $query)->count());
    }

    public function test_org_b_user_sees_no_org_a_surveys(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $this->makeSurvey($orgA);
        $this->makeSurvey($orgB);

        $outsider = $this->makeUser($orgB);

        $query = Survey::query();
        $this->scope->applyToSurveys($query, $outsider);

        $this->assertSame(1, (clone $query)->count());
        $this->assertSame($orgB->id, (clone $query)->first()->organization_id);
    }

    // ========== applyToSurveyResponses — walks survey parent ==========

    public function test_super_admin_sees_all_survey_responses(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $surveyA = $this->makeSurvey($orgA);
        $surveyB = $this->makeSurvey($orgB);
        SurveyResponse::factory()->count(2)->create(['survey_id' => $surveyA->id]);
        SurveyResponse::factory()->count(3)->create(['survey_id' => $surveyB->id]);

        $super = $this->makeUser(null, 'super_admin');

        $query = SurveyResponse::query();
        $this->scope->applyToSurveyResponses($query, $super);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_org_a_user_sees_only_org_a_responses(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $surveyA = $this->makeSurvey($orgA);
        $surveyB = $this->makeSurvey($orgB);
        SurveyResponse::factory()->count(2)->create(['survey_id' => $surveyA->id]);
        SurveyResponse::factory()->count(3)->create(['survey_id' => $surveyB->id]);

        $user = $this->makeUser($orgA);

        $query = SurveyResponse::query();
        $this->scope->applyToSurveyResponses($query, $user);

        $this->assertSame(2, (clone $query)->count());
    }

    public function test_null_org_user_sees_no_survey_responses(): void
    {
        $orgA = Organization::factory()->create();
        $surveyA = $this->makeSurvey($orgA);
        SurveyResponse::factory()->count(2)->create(['survey_id' => $surveyA->id]);

        $orphan = $this->makeUser(null);

        $query = SurveyResponse::query();
        $this->scope->applyToSurveyResponses($query, $orphan);

        $this->assertSame(0, (clone $query)->count());
    }

    // ========== applyToSurveyInvitations — walks survey parent ==========

    private function createInvitations(Survey $survey, int $count, string $emailPrefix): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $survey->invitations()->create([
                'email' => "{$emailPrefix}-{$i}@example.test",
            ]);
        }
    }

    public function test_super_admin_sees_all_survey_invitations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $surveyA = $this->makeSurvey($orgA);
        $surveyB = $this->makeSurvey($orgB);
        $this->createInvitations($surveyA, 2, 'a');
        $this->createInvitations($surveyB, 3, 'b');

        $super = $this->makeUser(null, 'super_admin');

        $query = SurveyInvitation::query();
        $this->scope->applyToSurveyInvitations($query, $super);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_org_a_user_sees_only_org_a_invitations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $surveyA = $this->makeSurvey($orgA);
        $surveyB = $this->makeSurvey($orgB);
        $this->createInvitations($surveyA, 2, 'a');
        $this->createInvitations($surveyB, 3, 'b');

        $user = $this->makeUser($orgA);

        $query = SurveyInvitation::query();
        $this->scope->applyToSurveyInvitations($query, $user);

        $this->assertSame(2, (clone $query)->count());
    }

    public function test_null_org_user_sees_no_survey_invitations(): void
    {
        $orgA = Organization::factory()->create();
        $surveyA = $this->makeSurvey($orgA);
        $this->createInvitations($surveyA, 2, 'a');

        $orphan = $this->makeUser(null);

        $query = SurveyInvitation::query();
        $this->scope->applyToSurveyInvitations($query, $orphan);

        $this->assertSame(0, (clone $query)->count());
    }

    // ========== applyToDataImportRequests — walks response -> survey parent ==========

    public function test_super_admin_sees_all_data_import_requests(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $surveyA = $this->makeSurvey($orgA);
        $surveyB = $this->makeSurvey($orgB);
        $responseA1 = SurveyResponse::factory()->create(['survey_id' => $surveyA->id]);
        $responseA2 = SurveyResponse::factory()->create(['survey_id' => $surveyA->id]);
        $responseB1 = SurveyResponse::factory()->create(['survey_id' => $surveyB->id]);
        $responseB2 = SurveyResponse::factory()->create(['survey_id' => $surveyB->id]);
        $responseB3 = SurveyResponse::factory()->create(['survey_id' => $surveyB->id]);
        DataImportRequest::factory()->count(1)->create(['response_id' => $responseA1->id]);
        DataImportRequest::factory()->count(1)->create(['response_id' => $responseA2->id]);
        DataImportRequest::factory()->count(1)->create(['response_id' => $responseB1->id]);
        DataImportRequest::factory()->count(1)->create(['response_id' => $responseB2->id]);
        DataImportRequest::factory()->count(1)->create(['response_id' => $responseB3->id]);

        $super = $this->makeUser(null, 'super_admin');

        $query = DataImportRequest::query();
        $this->scope->applyToDataImportRequests($query, $super);

        $this->assertSame(5, (clone $query)->count());
    }

    public function test_org_a_user_sees_only_org_a_data_import_requests(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $surveyA = $this->makeSurvey($orgA);
        $surveyB = $this->makeSurvey($orgB);
        $responseA1 = SurveyResponse::factory()->create(['survey_id' => $surveyA->id]);
        $responseA2 = SurveyResponse::factory()->create(['survey_id' => $surveyA->id]);
        $responseB1 = SurveyResponse::factory()->create(['survey_id' => $surveyB->id]);
        $responseB2 = SurveyResponse::factory()->create(['survey_id' => $surveyB->id]);
        $responseB3 = SurveyResponse::factory()->create(['survey_id' => $surveyB->id]);
        DataImportRequest::factory()->count(1)->create(['response_id' => $responseA1->id]);
        DataImportRequest::factory()->count(1)->create(['response_id' => $responseA2->id]);
        DataImportRequest::factory()->count(1)->create(['response_id' => $responseB1->id]);
        DataImportRequest::factory()->count(1)->create(['response_id' => $responseB2->id]);
        DataImportRequest::factory()->count(1)->create(['response_id' => $responseB3->id]);

        $user = $this->makeUser($orgA);

        $query = DataImportRequest::query();
        $this->scope->applyToDataImportRequests($query, $user);

        $this->assertSame(2, (clone $query)->count());
    }

    public function test_null_org_user_sees_no_data_import_requests(): void
    {
        $orgA = Organization::factory()->create();
        $surveyA = $this->makeSurvey($orgA);
        $response = SurveyResponse::factory()->create(['survey_id' => $surveyA->id]);
        DataImportRequest::factory()->count(2)->create(['response_id' => $response->id]);

        $orphan = $this->makeUser(null);

        $query = DataImportRequest::query();
        $this->scope->applyToDataImportRequests($query, $orphan);

        $this->assertSame(0, (clone $query)->count());
    }
}
