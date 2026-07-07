<?php

namespace Tests\Unit\Policies;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Policies\SurveyResponsePolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for SurveyResponsePolicy.
 *
 * The policy has a single method: review(User, SurveyResponse).
 * Rules:
 *   - Requires review_survey_responses permission
 *   - Requires both user.organization_id and survey.organization_id to be non-null
 *   - Requires they match (org isolation)
 *
 * Note: super_admin gets all permissions via seeder so they pass the permission
 * check; the org check only fails when organization_id is null on either side.
 */
class SurveyResponsePolicyTest extends TestCase
{
    use RefreshDatabase;

    private SurveyResponsePolicy $policy;

    private Organization $org;

    private Department $dept;

    private Survey $survey;

    private SurveyResponse $response;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->policy = new SurveyResponsePolicy;
        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $creator = User::factory()->create(['organization_id' => $this->org->id]);
        $this->survey = Survey::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $creator->id,
        ]);
        $respondent = User::factory()->create(['organization_id' => $this->org->id]);
        $this->response = SurveyResponse::factory()->create([
            'survey_id' => $this->survey->id,
            'respondent_id' => $respondent->id,
        ]);
    }

    private function makeUser(string $role, ?int $orgId = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $orgId ?? $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    // ========== Positive: admin with review permission, same org ==========

    public function test_admin_with_review_permission_can_review_same_org_response(): void
    {
        $admin = $this->makeUser('admin');
        // admin role has review_survey_responses permission from RolesAndPermissionsSeeder

        $this->assertTrue($this->policy->review($admin, $this->response));
    }

    public function test_super_admin_can_review_same_org_response(): void
    {
        $sa = $this->makeUser('super_admin');

        $this->assertTrue($this->policy->review($sa, $this->response));
    }

    // ========== Negative: viewer without permission ==========

    public function test_viewer_without_review_permission_cannot_review(): void
    {
        $viewer = $this->makeUser('viewer');
        // viewer role does not have review_survey_responses

        $this->assertFalse($this->policy->review($viewer, $this->response));
    }

    // ========== Negative: cross-org isolation ==========

    public function test_admin_from_other_org_cannot_review(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = $this->makeUser('admin', $orgB->id);

        // org B admin has review_survey_responses permission but different org
        $this->assertFalse($this->policy->review($outsider, $this->response));
    }

    // ========== Negative: null organization_id on user ==========

    public function test_null_org_user_cannot_review(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        $this->assertFalse($this->policy->review($user, $this->response));
    }

    // ========== Negative: survey has null organization_id ==========

    public function test_admin_cannot_review_response_with_null_survey_org(): void
    {
        $creator = User::factory()->create(['organization_id' => $this->org->id]);
        $nullOrgSurvey = Survey::factory()->create([
            'organization_id' => null,
            'created_by' => $creator->id,
        ]);
        $respondent = User::factory()->create(['organization_id' => $this->org->id]);
        $nullOrgResponse = SurveyResponse::factory()->create([
            'survey_id' => $nullOrgSurvey->id,
            'respondent_id' => $respondent->id,
        ]);

        $admin = $this->makeUser('admin');

        $this->assertFalse($this->policy->review($admin, $nullOrgResponse));
    }

    // ========== Edge: super_admin with null org cannot review null-org survey ==========

    public function test_super_admin_with_null_org_cannot_review_null_survey(): void
    {
        $sa = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $sa->assignRole('super_admin');

        $creator = User::factory()->create(['organization_id' => null]);
        $nullOrgSurvey = Survey::factory()->create([
            'organization_id' => null,
            'created_by' => $creator->id,
        ]);
        $respondent = User::factory()->create(['organization_id' => null]);
        $nullOrgResponse = SurveyResponse::factory()->create([
            'survey_id' => $nullOrgSurvey->id,
            'respondent_id' => $respondent->id,
        ]);

        // Policy checks: user.organization_id === null → false (explicit null guard)
        $this->assertFalse($this->policy->review($sa, $nullOrgResponse));
    }
}
