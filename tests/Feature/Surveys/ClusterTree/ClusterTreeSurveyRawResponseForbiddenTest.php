<?php

namespace Tests\Feature\Surveys\ClusterTree;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\SurveyStatus;
use App\Modules\Surveys\Enums\SurveyType;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Models\SurveySection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeSurveyRawResponseForbiddenTest - Phase CFA-10 REGRESSION.
 *
 * Pins the critical CFA-00 stop condition: even with the cluster_tree
 * grants (SURVEYS_VIEW + CLUSTER_TREE_VIEW + SURVEYS_EXPORT + CLUSTER_TREE_EXPORT),
 * the raw survey_responses endpoints MUST stay strict same-org and MUST
 * NEVER return cross-org rows.
 *
 * Endpoints under test:
 *   - GET /api/surveys/{survey}/responses       (raw list)
 *   - GET /api/surveys/{survey}/responses/{id}  (raw show)
 *   - GET /api/surveys/{survey}/export          (raw response export)
 *
 * The aggregate cluster endpoints (cluster-stats / cluster-export) DO widen
 * cross-org but only at the aggregate boundary — they MUST NOT surface
 * individual survey_responses rows in their payload.
 */
class ClusterTreeSurveyRawResponseForbiddenTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private Organization $cluster;

    private Organization $hospital;

    private Organization $otherOrg;

    private Survey $clusterSurvey;

    private Survey $hospitalSurvey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cluster = Organization::factory()->cluster()->create(['name' => 'cluster']);
        $this->hospital = Organization::factory()->hospital()
            ->childOf($this->cluster)
            ->create(['name' => 'hospital']);
        $this->otherOrg = Organization::factory()->create(['name' => 'unrelated']);

        $this->clusterSurvey = $this->makeSurvey($this->cluster);
        $this->hospitalSurvey = $this->makeSurvey($this->hospital);
    }

    private function makeSurvey(Organization $org): Survey
    {
        $survey = Survey::create([
            'organization_id' => $org->id,
            'title' => 'CFA-10 regression survey '.$org->id,
            'code' => 'CFA10-REG-'.$org->id,
            'type' => SurveyType::Periodic,
            'status' => SurveyStatus::Published,
            'created_by' => User::factory()->create(['organization_id' => $org->id])->id,
        ]);

        $section = SurveySection::create([
            'survey_id' => $survey->id,
            'title' => 'Section A',
            'sort_order' => 1,
        ]);

        SurveyField::create([
            'survey_id' => $survey->id,
            'section_id' => $section->id,
            'name' => 'rating',
            'label' => 'Rating',
            'field_key' => 'rating',
            'type' => 'rating',
            'is_required' => false,
            'sort_order' => 1,
        ]);

        return $survey;
    }

    private function makeResponse(Survey $survey): SurveyResponse
    {
        return SurveyResponse::create([
            'survey_id' => $survey->id,
            'respondent_id' => User::factory()->create(['organization_id' => $survey->organization_id])->id,
            'respondent_name' => null,
            'respondent_email' => null,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    }

    private function makeWidenedClusterUser(User $user): void
    {
        // All four cluster + export caps — the worst-case widening scenario.
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_REVIEW_RESPONSES,
            Capability::SURVEYS_EXPORT,
            Capability::CLUSTER_TREE_VIEW,
            Capability::CLUSTER_TREE_EXPORT,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // raw response list — NEVER returns cross-org rows
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_actor_with_all_cluster_grants_cannot_list_cross_org_responses(): void
    {
        $hospitalResponse = $this->makeResponse($this->hospitalSurvey);

        $user = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'is_active' => true,
        ]);
        $this->makeWidenedClusterUser($user);

        // Hit the raw list endpoint with the hospital's survey id.
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->hospitalSurvey->id}/responses");

        // The raw list endpoint's authorizeSurvey() must reject the
        // cluster ancestor. 403 (forbidden) — NOT 200 with a row payload.
        $response->assertStatus(403);

        $body = $response->json();
        if (is_array($body)) {
            $ids = collect($body)->flatten()->filter(fn ($v) => is_int($v))->all();
            $this->assertNotContains($hospitalResponse->id, $ids, 'raw response id must NEVER appear in cross-org payload');
        }
    }

    // ──────────────────────────────────────────────────────────────
    // raw response show — NEVER returns cross-org row
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_actor_with_all_cluster_grants_cannot_show_cross_org_response(): void
    {
        $hospitalResponse = $this->makeResponse($this->hospitalSurvey);

        $user = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'is_active' => true,
        ]);
        $this->makeWidenedClusterUser($user);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->hospitalSurvey->id}/responses/{$hospitalResponse->id}");

        $response->assertStatus(403);

        $body = $response->json();
        if (is_array($body)) {
            $this->assertNotEquals($hospitalResponse->id, $body['data']['id'] ?? null);
        }
    }

    // ──────────────────────────────────────────────────────────────
    // raw export — NEVER widens
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_actor_with_all_cluster_grants_cannot_raw_export_cross_org_survey(): void
    {
        $this->makeResponse($this->hospitalSurvey);

        $user = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'is_active' => true,
        ]);
        $this->makeWidenedClusterUser($user);

        // Hit the existing raw export endpoint with the hospital's survey id.
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->hospitalSurvey->id}/export")
            ->assertStatus(403);
    }

    // ──────────────────────────────────────────────────────────────
    // aggregate cluster endpoints — payload NEVER contains raw response rows
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_stats_payload_never_contains_raw_responses(): void
    {
        $hospitalResponse = $this->makeResponse($this->hospitalSurvey);

        $user = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'is_active' => true,
        ]);
        $this->makeWidenedClusterUser($user);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-stats")
            ->assertStatus(200);

        $body = $response->json();

        // Strict structural assertion: deserialize and verify the aggregates
        // payload does NOT carry any per-response keys. Avoids the brittle
        // substring-grep approach (a small response id like "1" collides
        // with response_count=1 etc.).
        $forbiddenKeys = ['id', 'response_id', 'respondent', 'respondent_id', 'respondent_email', 'respondent_phone', 'responses', 'answers', 'survey_id'];
        foreach (($body['aggregates'] ?? []) as $row) {
            foreach ($forbiddenKeys as $key) {
                $this->assertArrayNotHasKey($key, $row, "aggregate row must NOT carry '{$key}' (raw response leakage)");
            }

            // Only the aggregate columns are allowed.
            $allowed = ['organization_id', 'organization_name', 'response_count', 'submitted_count', 'completion_rate', 'aggregate_score'];
            foreach (array_keys($row) as $key) {
                $this->assertContains($key, $allowed, "aggregate row carries unexpected key '{$key}'");
            }
        }

        // Cross-check: the hospital response id must not appear as a value
        // in any aggregate row's numeric fields.
        foreach (($body['aggregates'] ?? []) as $row) {
            foreach (['organization_id', 'response_count', 'submitted_count', 'completion_rate', 'aggregate_score'] as $numericField) {
                $this->assertNotEquals(
                    $hospitalResponse->id,
                    $row[$numericField] ?? null,
                    "aggregate row's {$numericField} must NOT match the hospital response id"
                );
            }
        }
    }

    public function test_cluster_export_payload_never_contains_raw_responses(): void
    {
        $hospitalResponse = $this->makeResponse($this->hospitalSurvey);

        $user = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'is_active' => true,
        ]);
        $this->makeWidenedClusterUser($user);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-export?format=json")
            ->assertStatus(200);

        $body = $response->json();

        // Strict structural assertion: deserialize and verify the aggregates
        // payload does NOT carry any per-response keys. This avoids the
        // brittle substring-grep approach (a small response id like "1"
        // collides with response_count=1 etc.).
        $forbiddenKeys = ['id', 'response_id', 'respondent', 'respondent_id', 'respondent_email', 'respondent_phone', 'responses', 'answers', 'survey_id'];
        foreach (($body['aggregates'] ?? []) as $row) {
            foreach ($forbiddenKeys as $key) {
                $this->assertArrayNotHasKey($key, $row, "aggregate row must NOT carry '{$key}' (raw response leakage)");
            }

            // Only the aggregate columns are allowed.
            $allowed = ['organization_id', 'organization_name', 'response_count', 'submitted_count', 'completion_rate', 'aggregate_score'];
            foreach (array_keys($row) as $key) {
                $this->assertContains($key, $allowed, "aggregate row carries unexpected key '{$key}'");
            }
        }

        // Cross-check: the hospital response id must not appear as a value
        // in any aggregate row. Use a precise numeric comparison, not a
        // substring grep.
        foreach (($body['aggregates'] ?? []) as $row) {
            foreach (['organization_id', 'response_count', 'submitted_count', 'completion_rate', 'aggregate_score'] as $numericField) {
                $this->assertNotEquals(
                    $hospitalResponse->id,
                    $row[$numericField] ?? null,
                    "aggregate row's {$numericField} must NOT match the hospital response id"
                );
            }
        }
    }
}
