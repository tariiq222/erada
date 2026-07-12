<?php

namespace Tests\Feature\Surveys;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyResponsePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithReviewCap(Organization $organization): User
    {
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $this->assignCanonicalRole(
            $user,
            'viewer',
            scopeId: $organization->id,
            capabilities: [Capability::SURVEYS_REVIEW_RESPONSES],
        );

        return $user;
    }

    public function test_review_returns_true_for_same_org_with_engine_capability(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->makeUserWithReviewCap($organization);
        $survey = Survey::factory()->create(['organization_id' => $organization->id]);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);

        $this->assertTrue($user->can('review', $response));
    }

    public function test_review_returns_false_when_user_lacks_capability(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $survey = Survey::factory()->create(['organization_id' => $organization->id]);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);

        $this->assertFalse($user->can('review', $response));
    }

    public function test_review_returns_false_for_cross_org_user(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $user = $this->makeUserWithReviewCap($organizationA);
        $survey = Survey::factory()->create(['organization_id' => $organizationB->id]);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);

        $this->assertFalse($user->can('review', $response));
    }

    public function test_super_admin_bypasses_org_check(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $superAdmin = User::factory()->create(['organization_id' => $organizationA->id]);
        $this->grantCanonicalSuperAdmin($superAdmin);
        $survey = Survey::factory()->create(['organization_id' => $organizationB->id]);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);

        $this->assertTrue($superAdmin->can('review', $response));
    }
}
