<?php

namespace Tests\Unit\Surveys\Support;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Support\SurveyOrgGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * SurveyOrgGuardTest - Phase 6.A: استخراج organization_id من كل كيان
 * Surveys وفحص Same-Organization بنفس القواعد الموحّدة.
 *
 * Mirrors MeetingOrgGuardTest:
 *   - surveyOrgId / responseOrgId / invitationOrgId / importRequestOrgId.
 *   - sameOrganization: super_admin allowed، null-org denied، cross-org denied.
 *   - abortUnlessSameOrganization: throws AccessDeniedHttpException عند الرفض.
 */
class SurveyOrgGuardTest extends TestCase
{
    use RefreshDatabase;

    private SurveyOrgGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new SurveyOrgGuard;
    }

    private function makeSurvey(Organization $org): Survey
    {
        $creator = User::factory()->create(['organization_id' => $org->id]);

        return Survey::factory()->create([
            'organization_id' => $org->id,
            'created_by' => $creator->id,
        ]);
    }

    // ===== Org id extraction =====

    public function test_survey_org_id_returns_survey_org(): void
    {
        $org = Organization::factory()->create();
        $survey = $this->makeSurvey($org);

        $this->assertSame($org->id, $this->guard->surveyOrgId($survey));
    }

    public function test_survey_org_id_null_when_survey_null(): void
    {
        $this->assertNull($this->guard->surveyOrgId(null));
    }

    public function test_survey_org_id_null_when_survey_org_null(): void
    {
        $creator = User::factory()->create();
        $survey = Survey::factory()->create([
            'organization_id' => null,
            'created_by' => $creator->id,
        ]);

        $this->assertNull($this->guard->surveyOrgId($survey));
    }

    public function test_response_org_id_walks_survey_parent(): void
    {
        $org = Organization::factory()->create();
        $survey = $this->makeSurvey($org);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);

        $this->assertSame($org->id, $this->guard->responseOrgId($response));
    }

    public function test_response_org_id_null_when_response_null(): void
    {
        $this->assertNull($this->guard->responseOrgId(null));
    }

    public function test_response_org_id_null_when_survey_missing(): void
    {
        $response = new SurveyResponse;

        $this->assertNull($this->guard->responseOrgId($response));
    }

    public function test_invitation_org_id_walks_survey_parent(): void
    {
        $org = Organization::factory()->create();
        $survey = $this->makeSurvey($org);
        $invitation = $survey->invitations()->create([
            'email' => 'x@example.test',
        ]);

        $this->assertSame($org->id, $this->guard->invitationOrgId($invitation));
    }

    public function test_invitation_org_id_null_when_invitation_null(): void
    {
        $this->assertNull($this->guard->invitationOrgId(null));
    }

    public function test_import_request_org_id_walks_response_survey_grandparent(): void
    {
        $org = Organization::factory()->create();
        $survey = $this->makeSurvey($org);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);
        $import = DataImportRequest::factory()->create(['response_id' => $response->id]);

        $this->assertSame($org->id, $this->guard->importRequestOrgId($import));
    }

    public function test_import_request_org_id_null_when_request_null(): void
    {
        $this->assertNull($this->guard->importRequestOrgId(null));
    }

    public function test_import_request_org_id_null_when_response_missing(): void
    {
        $import = new DataImportRequest;

        $this->assertNull($this->guard->importRequestOrgId($import));
    }

    // ===== sameOrganization =====

    public function test_same_organization_super_admin_always_allowed(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => null]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $org = Organization::factory()->create();
        $this->assertTrue($this->guard->sameOrganization($superAdmin, $org->id));
        $this->assertTrue($this->guard->sameOrganization($superAdmin, null));
        $this->assertTrue($this->guard->sameOrganization($superAdmin, 99999));
    }

    public function test_same_organization_same_org_allowed(): void
    {
        $org = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $org->id]);

        $this->assertTrue($this->guard->sameOrganization($actor, $org->id));
    }

    public function test_same_organization_cross_org_denied(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $orgA->id]);

        $this->assertFalse($this->guard->sameOrganization($actor, $orgB->id));
    }

    public function test_same_organization_null_actor_org_denied(): void
    {
        $actor = User::factory()->create(['organization_id' => null]);
        $org = Organization::factory()->create();

        $this->assertFalse($this->guard->sameOrganization($actor, $org->id));
    }

    public function test_same_organization_null_target_org_denied(): void
    {
        $org = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $org->id]);

        $this->assertFalse($this->guard->sameOrganization($actor, null));
    }

    // ===== sameOrganizationForSurvey / Response / Invitation =====

    public function test_same_organization_for_survey_walks_target(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $surveyB = $this->makeSurvey($orgB);
        $actor = User::factory()->create(['organization_id' => $orgA->id]);

        $this->assertFalse($this->guard->sameOrganizationForSurvey($actor, $surveyB));
        $this->assertFalse($this->guard->sameOrganizationForSurvey($actor, null));
    }

    public function test_same_organization_for_response_walks_target(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $surveyB = $this->makeSurvey($orgB);
        $responseB = SurveyResponse::factory()->create(['survey_id' => $surveyB->id]);
        $actor = User::factory()->create(['organization_id' => $orgA->id]);

        $this->assertFalse($this->guard->sameOrganizationForResponse($actor, $responseB));
        $this->assertFalse($this->guard->sameOrganizationForResponse($actor, null));
    }

    public function test_same_organization_for_invitation_walks_target(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $surveyB = $this->makeSurvey($orgB);
        $invitationB = $surveyB->invitations()->create(['email' => 'y@example.test']);
        $actor = User::factory()->create(['organization_id' => $orgA->id]);

        $this->assertFalse($this->guard->sameOrganizationForInvitation($actor, $invitationB));
        $this->assertFalse($this->guard->sameOrganizationForInvitation($actor, null));
    }

    // ===== abortUnlessSameOrganization =====

    public function test_abort_unless_same_organization_throws_on_cross_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $orgA->id]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->guard->abortUnlessSameOrganization($actor, $orgB->id);
    }

    public function test_abort_unless_same_organization_passes_on_match(): void
    {
        $org = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $org->id]);

        $this->guard->abortUnlessSameOrganization($actor, $org->id);
        $this->assertTrue(true);
    }

    public function test_abort_unless_same_organization_passes_for_super_admin(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => null]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $this->guard->abortUnlessSameOrganization($superAdmin, 99999);
        $this->assertTrue(true);
    }

    public function test_abort_unless_same_organization_throws_for_null_actor_org(): void
    {
        $actor = User::factory()->create(['organization_id' => null]);
        $org = Organization::factory()->create();

        $this->expectException(AccessDeniedHttpException::class);
        $this->guard->abortUnlessSameOrganization($actor, $org->id);
    }
}
