<?php

namespace Tests\Feature\Surveys\ClusterTree;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\ResponseStatus;
use App\Modules\Surveys\Enums\SurveyStatus;
use App\Modules\Surveys\Enums\SurveyType;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Models\SurveySection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeSurveyStatsTest - Phase CFA-10: HTTP-level aggregate stats
 * widening via the cluster_tree pair (SURVEYS_VIEW + CLUSTER_TREE_VIEW).
 *
 * Verifies the full HTTP path:
 *   1. Cluster actor with both grants sees aggregate rows for cluster + descendants.
 *   2. Cluster actor without CLUSTER_TREE_VIEW is denied.
 *   3. Cluster actor without SURVEYS_VIEW is denied.
 *   4. Sibling cluster actor is denied.
 *   5. Child actor cannot see parent cluster survey stats via widening.
 *   6. super_admin can call the endpoint on any survey.
 *   7. Unauthenticated request is denied.
 *   8. Response shape is aggregate-only (response_count / completion_rate /
 *      aggregate_score per organization) — no raw response rows, no PII fields.
 */
class ClusterTreeSurveyStatsTest extends TestCase
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
            'title' => 'CFA-10 survey '.$org->id,
            'code' => 'CFA10-'.$org->id,
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

    private function makeResponse(
        Survey $survey,
        string $status,
        ?int $completionTime = null,
        ?Organization $respondentOrganization = null,
    ): SurveyResponse {
        return SurveyResponse::create([
            'survey_id' => $survey->id,
            'respondent_id' => User::factory()->create([
                'organization_id' => $respondentOrganization?->id ?? $survey->organization_id,
            ])->id,
            'respondent_name' => null,
            'respondent_email' => null,
            'status' => $status,
            'submitted_at' => $status === ResponseStatus::Submitted->value ? now() : null,
            'completion_time' => $completionTime,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // positive — both grants widen
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_actor_with_both_grants_sees_descendant_aggregate_rows(): void
    {
        // Plant responses on the cluster's own survey and the hospital's own survey.
        // Each survey aggregates ONLY against itself (survey_id is fixed); the
        // cross-survey aggregate is via descendant organizations whose surveys
        // carry the same code — which we don't create here.
        //
        // For this test we exercise the aggregate path on the cluster survey:
        // 2 submitted + 1 invalid ⇒ 3 total, completion_rate=66.67%, mean=180.
        $this->makeResponse($this->clusterSurvey, ResponseStatus::Submitted->value, 120);
        $this->makeResponse($this->clusterSurvey, ResponseStatus::Submitted->value, 240);
        $this->makeResponse($this->clusterSurvey, ResponseStatus::Invalid->value);

        $user = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-stats")
            ->assertStatus(200);

        $body = $response->json();

        $this->assertSame($this->clusterSurvey->id, $body['survey_id']);
        $this->assertSame($this->cluster->id, $body['cluster_root_org_id']);
        $this->assertIsArray($body['aggregates']);
        $this->assertNotEmpty($body['aggregates']);

        // Aggregate row for the cluster org must carry response_count/completion_rate.
        $clusterRow = collect($body['aggregates'])->firstWhere('organization_id', $this->cluster->id);
        $this->assertNotNull($clusterRow);
        $this->assertSame(3, $clusterRow['response_count']);
        $this->assertSame(2, $clusterRow['submitted_count']);
        $this->assertEqualsWithDelta(66.67, $clusterRow['completion_rate'], 0.1);
        $this->assertEqualsWithDelta(180.0, $clusterRow['aggregate_score'], 0.1);
    }

    public function test_cluster_actor_with_both_grants_sees_descendant_org_in_aggregates(): void
    {
        // Plant a response on the hospital's own survey so the descendant
        // org appears in the descendant aggregate (same survey.id is required
        // for the aggregate row; we plant a response on the cluster survey
        // with hospital as the org via a respondent but the survey belongs
        // to cluster — this is just to make sure the descendant org does
        // NOT appear if it has zero responses on the same survey_id).
        //
        // The cluster widening lists cluster + hospital even if the hospital
        // has zero rows on the same survey_id; this test verifies the row
        // shape is consistent for both.
        $user = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-stats")
            ->assertStatus(200);

        $body = $response->json();
        $orgIds = array_column($body['aggregates'], 'organization_id');

        // Cluster widening surfaces descendant org even with zero responses.
        $this->assertContains($this->cluster->id, $orgIds);
        $this->assertContains($this->hospital->id, $orgIds);
        // Sibling / unrelated orgs MUST NOT appear.
        $this->assertNotContains($this->otherOrg->id, $orgIds);
    }

    public function test_aggregate_rows_are_attributed_to_the_respondent_organization(): void
    {
        $this->makeResponse($this->clusterSurvey, ResponseStatus::Submitted->value, 120, $this->hospital);
        $this->makeResponse($this->clusterSurvey, ResponseStatus::Submitted->value, 240, $this->hospital);

        $user = User::factory()->create(['organization_id' => $this->cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($user, [Capability::SURVEYS_VIEW, Capability::CLUSTER_TREE_VIEW]);

        $rows = $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-stats")
            ->assertOk()
            ->json('aggregates');

        $clusterRow = collect($rows)->firstWhere('organization_id', $this->cluster->id);
        $hospitalRow = collect($rows)->firstWhere('organization_id', $this->hospital->id);

        $this->assertSame(0, $clusterRow['response_count']);
        $this->assertSame(2, $hospitalRow['response_count']);
        $this->assertSame(2, $hospitalRow['submitted_count']);
    }

    public function test_cluster_export_uses_export_pair_not_the_stats_pair(): void
    {
        $this->makeResponse($this->clusterSurvey, ResponseStatus::Submitted->value, 60, $this->hospital);
        $actor = User::factory()->create(['organization_id' => $this->cluster->id, 'is_active' => true]);
        $this->grantEngineCapability($actor, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_EXPORT,
        ]);

        $sameOrgExport = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-export?format=csv")
            ->assertOk();
        // Phase 3B — endpoint is direct download; read the body, not
        // the storage layer.
        $sameOrgContent = $sameOrgExport->getContent();
        $this->assertStringNotContainsString((string) $this->hospital->id, $sameOrgContent);

        $this->grantEngineCapability($actor, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_EXPORT,
            Capability::CLUSTER_TREE_EXPORT,
        ]);
        $clusterExport = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-export?format=csv")
            ->assertOk();
        $clusterContent = $clusterExport->getContent();
        $this->assertStringContainsString((string) $this->hospital->id, $clusterContent);
    }

    // ──────────────────────────────────────────────────────────────
    // negative — missing one grant ⇒ 403
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_actor_without_cluster_tree_view_is_denied_on_descendant_survey(): void
    {
        // Build a survey that lives in the hospital so we exercise the
        // cross-org path; without cluster_tree the actor is denied.
        $user = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::SURVEYS_VIEW);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->hospitalSurvey->id}/cluster-stats")
            ->assertStatus(403);
    }

    public function test_cluster_actor_without_surveys_view_is_denied_on_descendant_survey(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::CLUSTER_TREE_VIEW);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->hospitalSurvey->id}/cluster-stats")
            ->assertStatus(403);
    }

    // ──────────────────────────────────────────────────────────────
    // negative — sibling / child isolation
    // ──────────────────────────────────────────────────────────────

    public function test_sibling_cluster_actor_is_denied_on_unrelated_descendant_survey(): void
    {
        $siblingCluster = Organization::factory()->cluster()->create(['name' => 'sibling']);
        $user = User::factory()->create([
            'organization_id' => $siblingCluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->hospitalSurvey->id}/cluster-stats")
            ->assertStatus(403);
    }

    public function test_child_actor_cannot_see_parent_cluster_survey_stats_via_widening(): void
    {
        $childUser = User::factory()->create([
            'organization_id' => $this->hospital->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($childUser, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->actingAs($childUser, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-stats")
            ->assertStatus(403);
    }

    // ──────────────────────────────────────────────────────────────
    // floors — super_admin / null / unauthenticated
    // ──────────────────────────────────────────────────────────────

    public function test_super_admin_can_call_cluster_stats_on_any_survey(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-stats")
            ->assertStatus(200);
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-stats")
            ->assertStatus(401);
    }

    public function test_null_org_user_is_denied_even_with_grants(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->hospitalSurvey->id}/cluster-stats")
            ->assertStatus(403);
    }

    // ──────────────────────────────────────────────────────────────
    // CFA-00 stop condition — response shape is aggregate-only
    // ──────────────────────────────────────────────────────────────

    public function test_response_shape_carries_no_raw_response_rows(): void
    {
        $this->makeResponse($this->clusterSurvey, ResponseStatus::Submitted->value, 60);

        $user = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-stats")
            ->assertStatus(200);

        $body = $response->json();

        // The aggregates payload must NOT contain any raw response shape.
        $forbiddenKeys = ['responses', 'answers', 'response_id', 'respondent_id', 'respondent'];
        foreach ($body['aggregates'] as $row) {
            foreach ($forbiddenKeys as $key) {
                $this->assertArrayNotHasKey($key, $row, "aggregate row must NOT carry '{$key}'");
            }

            // Only the aggregate keys are allowed.
            $this->assertArrayHasKey('organization_id', $row);
            $this->assertArrayHasKey('organization_name', $row);
            $this->assertArrayHasKey('response_count', $row);
            $this->assertArrayHasKey('completion_rate', $row);
            $this->assertArrayHasKey('aggregate_score', $row);
        }
    }
}
