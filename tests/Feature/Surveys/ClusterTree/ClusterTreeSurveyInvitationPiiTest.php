<?php

namespace Tests\Feature\Surveys\ClusterTree;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\SurveyStatus;
use App\Modules\Surveys\Enums\SurveyType;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyInvitation;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Models\SurveySection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ClusterTreeSurveyInvitationPiiTest - Phase CFA-10 REGRESSION.
 *
 * Pins the critical CFA-00 stop conditions for respondent identity:
 *
 *   - respondent_email  must NEVER appear in any cluster aggregate payload.
 *   - respondent_phone  must NEVER appear in any cluster aggregate payload.
 *   - survey_invitations.email  must NEVER appear in any cluster aggregate
 *     payload (the invitation list endpoint and the aggregate endpoints).
 *
 * The aggregate endpoints widen cross-org (cluster ancestor ⇒ descendants),
 * so a leak here would expose respondent identity across organizations.
 * The tracking token URL for invitations is PUBLIC and is intentionally
 * NOT widened by this change — it stays on the unauthenticated
 * `/api/surveys/public/*` routes. This test asserts the cluster endpoints
 * never touch tracking token URLs either.
 */
class ClusterTreeSurveyInvitationPiiTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private Organization $cluster;

    private Organization $hospital;

    private Survey $clusterSurvey;

    private Survey $hospitalSurvey;

    /** @var list<string> */
    private array $forbiddenEmails = [];

    /** @var list<string> */
    private array $forbiddenPhones = [];

    /** @var list<string> */
    private array $forbiddenInvitationTokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cluster = Organization::factory()->cluster()->create(['name' => 'cluster']);
        $this->hospital = Organization::factory()->hospital()
            ->childOf($this->cluster)
            ->create(['name' => 'hospital']);

        $this->clusterSurvey = $this->makeSurvey($this->cluster);
        $this->hospitalSurvey = $this->makeSurvey($this->hospital);

        // Plant a response + invitation in the hospital survey with PII
        // markers we can grep for in the cluster aggregate payload.
        $this->forbiddenEmails = [
            'secret-respondent-'.$this->hospital->id.'@example.test',
            'invitee-'.$this->hospital->id.'@example.test',
        ];
        $this->forbiddenPhones = [
            '+966500000'.$this->hospital->id,
        ];

        $this->makeResponseWithPii($this->hospitalSurvey);
        $this->makeInvitationWithPii($this->hospitalSurvey);
    }

    private function makeSurvey(Organization $org): Survey
    {
        $survey = Survey::create([
            'organization_id' => $org->id,
            'title' => 'CFA-10 PII survey '.$org->id,
            'code' => 'CFA10-PII-'.$org->id,
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

    private function makeResponseWithPii(Survey $survey): SurveyResponse
    {
        return SurveyResponse::create([
            'survey_id' => $survey->id,
            'respondent_id' => User::factory()->create(['organization_id' => $survey->organization_id])->id,
            // Use a sentinel string we can grep for.
            'respondent_name' => 'CFA10-secret-respondent-name-'.$survey->organization_id,
            'respondent_email' => $this->forbiddenEmails[0],
            'respondent_phone' => $this->forbiddenPhones[0],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    }

    private function makeInvitationWithPii(Survey $survey): SurveyInvitation
    {
        $invitation = SurveyInvitation::create([
            'survey_id' => $survey->id,
            'email' => $this->forbiddenEmails[1],
            'name' => 'CFA10-secret-invitee-'.$survey->organization_id,
            'status' => 'active',
        ]);

        $this->forbiddenInvitationTokens[] = $invitation->token;

        return $invitation;
    }

    private function makeWidenedClusterUser(): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_REVIEW_RESPONSES,
            Capability::SURVEYS_EXPORT,
            Capability::CLUSTER_TREE_VIEW,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        return $user;
    }

    // ──────────────────────────────────────────────────────────────
    // CFA-00 stop conditions — cluster-stats payload
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_stats_payload_never_contains_respondent_email(): void
    {
        $user = $this->makeWidenedClusterUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-stats")
            ->assertStatus(200);

        $serialized = json_encode($response->json());

        foreach ($this->forbiddenEmails as $email) {
            $this->assertStringNotContainsString(
                $email,
                $serialized,
                "cluster-stats payload must NEVER include respondent/invitation email '{$email}'"
            );
        }
    }

    public function test_cluster_stats_payload_never_contains_respondent_phone(): void
    {
        $user = $this->makeWidenedClusterUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-stats")
            ->assertStatus(200);

        $serialized = json_encode($response->json());

        foreach ($this->forbiddenPhones as $phone) {
            $this->assertStringNotContainsString(
                $phone,
                $serialized,
                "cluster-stats payload must NEVER include respondent phone '{$phone}'"
            );
        }
    }

    public function test_cluster_stats_payload_never_contains_invitation_token(): void
    {
        $user = $this->makeWidenedClusterUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-stats")
            ->assertStatus(200);

        $serialized = json_encode($response->json());

        foreach ($this->forbiddenInvitationTokens as $token) {
            $this->assertStringNotContainsString(
                $token,
                $serialized,
                "cluster-stats payload must NEVER include invitation tracking token '{$token}'"
            );
        }
    }

    // ──────────────────────────────────────────────────────────────
    // CFA-00 stop conditions — cluster-export payload
    // ──────────────────────────────────────────────────────────────

    public function test_cluster_export_json_payload_never_contains_respondent_pii(): void
    {
        $user = $this->makeWidenedClusterUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-export?format=json")
            ->assertStatus(200);

        $serialized = json_encode($response->json());

        foreach ($this->forbiddenEmails as $email) {
            $this->assertStringNotContainsString(
                $email,
                $serialized,
                "cluster-export JSON payload must NEVER include email '{$email}'"
            );
        }
        foreach ($this->forbiddenPhones as $phone) {
            $this->assertStringNotContainsString(
                $phone,
                $serialized,
                "cluster-export JSON payload must NEVER include phone '{$phone}'"
            );
        }
        foreach ($this->forbiddenInvitationTokens as $token) {
            $this->assertStringNotContainsString(
                $token,
                $serialized,
                "cluster-export JSON payload must NEVER include invitation token '{$token}'"
            );
        }
    }

    public function test_cluster_export_csv_payload_never_contains_respondent_pii(): void
    {
        $user = $this->makeWidenedClusterUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->clusterSurvey->id}/cluster-export?format=csv")
            ->assertStatus(200);

        // Phase 3B — endpoint is direct download; read the streamed
        // body, not the storage layer (Phase 3B is the contract that
        // no storage/app/private/exports residue survives the call).
        $contents = $response->getContent();
        $this->assertIsString($contents);

        foreach ($this->forbiddenEmails as $email) {
            $this->assertStringNotContainsString(
                $email,
                $contents,
                "cluster-export CSV must NEVER include email '{$email}'"
            );
        }
        foreach ($this->forbiddenPhones as $phone) {
            $this->assertStringNotContainsString(
                $phone,
                $contents,
                "cluster-export CSV must NEVER include phone '{$phone}'"
            );
        }
        foreach ($this->forbiddenInvitationTokens as $token) {
            $this->assertStringNotContainsString(
                $token,
                $contents,
                "cluster-export CSV must NEVER include invitation token '{$token}'"
            );
        }

        // Aggregate CSV header contract — only the six aggregate columns.
        $expectedHeader = 'organization_id,organization_name,response_count,submitted_count,completion_rate,aggregate_score';
        $this->assertStringContainsString(
            $expectedHeader,
            $contents,
            'cluster-export CSV must carry only the aggregate columns'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // CFA-00 stop conditions — invitation list endpoint stays strict
    // ──────────────────────────────────────────────────────────────

    public function test_invitation_list_endpoint_stays_strict_no_cross_org_widening(): void
    {
        // Even with all four cluster + export caps, the invitation list
        // endpoint (/api/surveys/{survey}/invitations) must NOT widen.
        // Cross-org read is denied via authorizeSurvey().
        $user = $this->makeWidenedClusterUser();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/surveys/{$this->hospitalSurvey->id}/invitations")
            ->assertStatus(403);
    }
}
