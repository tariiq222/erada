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
 * SurveyShowIsolationTest - Phase 6: cross-org GET /api/surveys/{survey} is denied.
 *
 * GET /api/surveys/{survey} is gated by ViewSurveyRequest::authorize() which
 * delegates to AccessDecision::can($user, Capability::SURVEYS_VIEW, $survey).
 * The engine evaluates the same-org floor against Survey::scopeOrganizationId();
 * a non-super_admin user from another org is denied at the FormRequest layer.
 *
 * Mirrors MeetingIndexIsolationTest pattern per route.
 */
class SurveyShowIsolationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_org_a_user_can_show_org_a_survey(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyA = Survey::factory()->create([
            'organization_id' => $orgA->id,
            'title' => 'Org A Survey',
        ]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::SURVEYS_VIEW);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$surveyA->id}");

        $response->assertStatus(200);
        // SurveyController::show returns new SurveyResource($survey) at root,
        // not nested under 'data'. Read the id directly.
        $body = $response->json();
        $id = $body['data']['id'] ?? $body['id'] ?? null;
        $this->assertSame($surveyA->id, $id);
    }

    public function test_org_a_user_cannot_show_org_b_survey(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyB = Survey::factory()->create([
            'organization_id' => $orgB->id,
            'title' => 'Org B Survey',
        ]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $this->grantEngineCapability($actor, Capability::SURVEYS_VIEW);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$surveyB->id}");

        // Engine floor: same-org false ⇒ ViewSurveyRequest::authorize() returns false.
        $response->assertStatus(403);
    }

    public function test_super_admin_can_show_any_survey(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyB = Survey::factory()->create([
            'organization_id' => $orgB->id,
            'title' => 'Org B Survey',
        ]);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/surveys/{$surveyB->id}");

        $response->assertStatus(200);
        $body = $response->json();
        $id = $body['data']['id'] ?? $body['id'] ?? null;
        $this->assertSame($surveyB->id, $id);
    }

    public function test_null_org_user_cannot_show_survey(): void
    {
        $orgA = Organization::factory()->create();
        $surveyA = Survey::factory()->create([
            'organization_id' => $orgA->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, Capability::SURVEYS_VIEW);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$surveyA->id}");

        // null-org floor on record-scoped methods.
        $response->assertStatus(403);
    }

    public function test_user_without_view_capability_cannot_show_survey(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $surveyA = Survey::factory()->create([
            'organization_id' => $orgA->id,
        ]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$surveyA->id}");

        $response->assertStatus(403);
    }
}
