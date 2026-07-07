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
 * SurveyUpdateIsolationTest - Phase 6: منع payload tampering عند تحديث Survey.
 *
 * PATCH /api/surveys/{survey} flows through UpdateSurveyRequest which:
 *   - authorize() returns false for cross-org via AccessDecision (SURVEYS_EDIT).
 *   - rules() do NOT include `organization_id`, so a tampered organization_id is
 *     silently stripped by the validated() gate.
 *   - withValidator() enforces canEdit() (draft + not locked) - via the model.
 *
 * Mirrors MeetingStorePayloadTamperingTest.
 */
class SurveyUpdateIsolationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_org_a_user_can_patch_org_a_survey(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyA = Survey::factory()->create([
            'organization_id' => $orgA->id,
            'title' => 'Original Title',
        ]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        // Routes mounted under engine_capability:surveys.view ⇒ group floor.
        // Patch route adds engine_capability:surveys.edit on top. Grant both.
        $this->grantEngineCapability($actor, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_EDIT,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/surveys/{$surveyA->id}", [
                'title' => 'Updated Title',
            ]);

        $response->assertStatus(200);
        $this->assertSame('Updated Title', $surveyA->fresh()->title);
    }

    public function test_org_a_user_cannot_patch_org_b_survey(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyB = Survey::factory()->create([
            'organization_id' => $orgB->id,
            'title' => 'Org B Title',
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
            ->patchJson("/api/surveys/{$surveyB->id}", [
                'title' => 'Tampered Title',
            ]);

        // Engine cross-org floor in UpdateSurveyRequest::authorize().
        $response->assertStatus(403);
        // Surviving title is the original; nothing mutated through the gate.
        $this->assertSame('Org B Title', $surveyB->fresh()->title);
    }

    public function test_organization_id_in_payload_is_silently_stripped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyA = Survey::factory()->create([
            'organization_id' => $orgA->id,
            'title' => 'Original',
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
            ->patchJson("/api/surveys/{$surveyA->id}", [
                'title' => 'Patched Title',
                'organization_id' => $orgB->id, // tampering attempt
            ]);

        // organization_id is not in rules() so Laravel strips it from the
        // validated() set. The patched title is applied under the survey's
        // original org A. No org-B survey row exists.
        $response->assertStatus(200);
        $this->assertSame('Patched Title', $surveyA->fresh()->title);
        $this->assertSame($orgA->id, $surveyA->fresh()->organization_id);
        $this->assertDatabaseMissing('surveys', [
            'id' => $surveyA->id,
            'organization_id' => $orgB->id,
        ]);
    }

    public function test_null_org_user_cannot_patch_survey(): void
    {
        $orgA = Organization::factory()->create();
        $surveyA = Survey::factory()->create([
            'organization_id' => $orgA->id,
            'title' => 'Original',
        ]);

        $actor = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::SURVEYS_EDIT);

        $response = $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/surveys/{$surveyA->id}", [
                'title' => 'Tampered',
            ]);

        // Phase 6 floor: null-org actor fails engine precheck.
        $response->assertStatus(403);
        $this->assertSame('Original', $surveyA->fresh()->title);
    }

    public function test_super_admin_can_patch_any_survey(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyB = Survey::factory()->create([
            'organization_id' => $orgB->id,
            'title' => 'Org B Original',
        ]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/surveys/{$surveyB->id}", [
                'title' => 'Super Admin Update',
            ]);

        $response->assertStatus(200);
        $this->assertSame('Super Admin Update', $surveyB->fresh()->title);
    }
}
