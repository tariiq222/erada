<?php

namespace Tests\Feature\Surveys\Isolation;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * SurveyInvitationIsolationTest - Phase 6: منع حقن department_id / user_id
 * من مؤسسة أخرى عند POST /api/surveys/{survey}/invitations.
 *
 * The FormRequest rules() apply Exists-with-org-scope on department_id and
 * user_id: a cross-org id fails the rule and returns 422. Authorize() uses
 * AccessDecision::can($user, Capability::SURVEYS_EDIT, $survey) so cross-org
 * access to the parent survey already floors at 403.
 *
 * Mirrors MeetingStorePayloadTamperingTest for the payload pattern.
 */
class SurveyInvitationIsolationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_org_a_can_create_invitation_with_org_a_dept_and_user(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $userA = User::factory()->create(['organization_id' => $orgA->id, 'department_id' => $deptA->id]);
        $surveyA = Survey::factory()->create([
            'organization_id' => $orgA->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        // Group middleware engine_capability:surveys.view + route-level surveys.edit.
        $this->grantEngineCapability($actor, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_EDIT,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/surveys/{$surveyA->id}/invitations", [
                'email' => 'invitee@example.test',
                'name' => 'Invitee',
                'department_id' => $deptA->id,
                'user_id' => $userA->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('survey_invitations', [
            'survey_id' => $surveyA->id,
            'department_id' => $deptA->id,
            'user_id' => $userA->id,
        ]);
    }

    public function test_org_a_invitation_with_foreign_department_id_is_rejected(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $surveyA = Survey::factory()->create([
            'organization_id' => $orgA->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_EDIT,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/surveys/{$surveyA->id}/invitations", [
                'email' => 'invitee@example.test',
                'department_id' => $deptB->id, // cross-org
            ]);

        // orgScopedDepartmentRule fails the Exists rule ⇒ 422.
        $response->assertStatus(422);
        $this->assertDatabaseMissing('survey_invitations', [
            'survey_id' => $surveyA->id,
            'department_id' => $deptB->id,
        ]);
    }

    public function test_org_a_invitation_with_foreign_user_id_is_rejected(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $surveyA = Survey::factory()->create([
            'organization_id' => $orgA->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_EDIT,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/surveys/{$surveyA->id}/invitations", [
                'email' => 'invitee@example.test',
                'user_id' => $userB->id, // cross-org
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('survey_invitations', [
            'survey_id' => $surveyA->id,
            'user_id' => $userB->id,
        ]);
    }

    public function test_bulk_create_with_one_foreign_row_is_rejected(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);
        $surveyA = Survey::factory()->create([
            'organization_id' => $orgA->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_EDIT,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/surveys/{$surveyA->id}/invitations/bulk", [
                'invitations' => [
                    [
                        'email' => 'valid@example.test',
                        'department_id' => $deptA->id,
                    ],
                    [
                        'email' => 'foreign@example.test',
                        'department_id' => $deptB->id, // cross-org row
                    ],
                ],
            ]);

        // Bulk array validation fails on the foreign department_id rule.
        $response->assertStatus(422);
        $this->assertDatabaseCount('survey_invitations', 0);
    }

    public function test_org_a_user_cannot_create_invitation_on_org_b_survey(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyB = Survey::factory()->create([
            'organization_id' => $orgB->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_EDIT,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/surveys/{$surveyB->id}/invitations", [
                'email' => 'invitee@example.test',
            ]);

        // Engine precheck floors cross-org POST at authorize().
        $response->assertStatus(403);
        $this->assertDatabaseCount('survey_invitations', 0);
    }
}
