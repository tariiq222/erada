<?php

namespace Tests\Feature\Surveys\Isolation;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\SurveyStatus;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SurveySubmitIsolationTest - Phase 6: التأكد أنّ public survey endpoint
 * لا يكشف organization_id من هيكل الاستجابة.
 *
 * GET /api/surveys/public/{code} is open to anonymous callers (no auth:sanctum
 * middleware). PublicSurveyController::show returns SurveyPublicResource,
 * which (by design) excludes organization_id. This test pins that property at
 * the public surface so future refactors don't accidentally leak tenancy.
 *
 * The org-B public survey is also returned (is_public=true means any anonymous
 * fetcher gets it) — this is the documented behavior for short public links.
 */
class SurveySubmitIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makePublicActiveSurvey(Organization $org): Survey
    {
        $creator = User::factory()->create([
            'organization_id' => $org->id,
        ]);

        return Survey::factory()->create([
            'organization_id' => $org->id,
            'created_by' => $creator->id,
            'is_public' => true,
            'requires_auth' => false,
            'status' => SurveyStatus::Published,
            'accepting_responses' => true,
            'published_at' => now(),
        ]);
    }

    public function test_anonymous_can_fetch_org_a_public_survey(): void
    {
        $orgA = Organization::factory()->create();
        $surveyA = $this->makePublicActiveSurvey($orgA);

        $response = $this->getJson("/api/surveys/public/{$surveyA->code}");

        $response->assertStatus(200);
        $payload = $response->json('data') ?? [];
        $this->assertSame($surveyA->code, $payload['code'] ?? null);
        // Pin the org-isolation invariant: organization_id MUST NOT leak.
        $this->assertArrayNotHasKey('organization_id', $payload);
        // And no nested organization key on the resource envelope either.
        $this->assertArrayNotHasKey('organization_id', $response->json() ?? []);
        $this->assertArrayNotHasKey('organization', $payload);
    }

    public function test_anonymous_can_fetch_org_b_public_survey_too(): void
    {
        // Documented behavior: public + open surveys are reachable by code from
        // any caller (no auth, no org floor). The brief asked us to observe this.
        $orgB = Organization::factory()->create();
        $surveyB = $this->makePublicActiveSurvey($orgB);

        $response = $this->getJson("/api/surveys/public/{$surveyB->code}");

        $response->assertStatus(200);
        $payload = $response->json('data') ?? [];
        $this->assertSame($surveyB->code, $payload['code'] ?? null);
        $this->assertArrayNotHasKey('organization_id', $payload);
    }

    public function test_unpublished_public_survey_still_hides_organization_id(): void
    {
        $orgA = Organization::factory()->create();
        // Draft but is_public=true: the public gate checks isActive() at the
        // controller. Even so, the resource contract must not leak organization_id
        // if the survey ever does pass the gate.
        $creator = User::factory()->create([
            'organization_id' => $orgA->id,
        ]);
        $survey = Survey::factory()->create([
            'organization_id' => $orgA->id,
            'created_by' => $creator->id,
            'is_public' => true,
            'requires_auth' => false,
            'status' => SurveyStatus::Draft,
            'accepting_responses' => false,
        ]);

        $response = $this->getJson("/api/surveys/public/{$survey->code}");

        // Controller returns 403 because the draft is not active. The exact code
        // is incidental; what matters is the org never leaks.
        $this->assertContains(
            $response->status(),
            [403, 404],
            'unpublished survey must not return 200 to anonymous',
        );
        $this->assertArrayNotHasKey('organization_id', $response->json() ?? []);
    }

    public function test_unknown_code_returns_404_without_leak(): void
    {
        $response = $this->getJson('/api/surveys/public/NONEXISTENT-CODE-9999');

        $response->assertStatus(404);
        $this->assertArrayNotHasKey('organization_id', $response->json() ?? []);
    }
}
