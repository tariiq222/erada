<?php

namespace Tests\Feature\Surveys\ClusterTree;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\ResponseStatus;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 3A — Survey respondent_organization_id snapshot.
 *
 * The design brief:
 *
 *   "Add a new respondent-organization snapshot to survey responses.
 *    New responses capture the respondent organization at submission
 *    time. … Cluster aggregates group by the snapshot rather than
 *    the mutable users.organization_id relation."
 *
 * Two contracts locked here:
 *   1. On `creating`, SurveyResponse auto-stamps
 *      respondent_organization_id from the respondent's current org
 *      (or the survey's org for anonymous / deleted respondents).
 *      Re-asserted by injecting a respondent who is later moved to
 *      another org — the historical aggregate stays attributed to
 *      the stamp, not the live relation.
 *   2. The cluster aggregate on /api/surveys/{id}/cluster-stats
 *      groups by respondent_organization_id, NOT the live
 *      users.organization_id relation. A user who switches orgs
 *      after submission must NOT re-attribute their historical
 *      response into the destination org's cluster row.
 */
class Phase3ARespondentOrgSnapshotTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_submission_stamps_snapshot_from_live_respondent_org(): void
    {
        // Build the cluster + hospital tree, then submit a response
        // from a respondent in the hospital. The creating-boot must
        // resolve path 2 (live respondent org) and stamp hospital.id.
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();
        $survey = Survey::factory()->create([
            'organization_id' => $cluster->id,
            'status' => 'published',
        ]);
        $respondent = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);

        $response = SurveyResponse::create([
            'survey_id' => $survey->id,
            'respondent_id' => $respondent->id,
            'status' => ResponseStatus::Submitted->value,
            'submitted_at' => now(),
        ]);

        $this->assertSame((int) $hospital->id, (int) $response->respondent_organization_id);
    }

    public function test_submission_stamps_snapshot_when_respondent_has_no_org(): void
    {
        // Anonymous respondent (respondent_id = null) → fallback to
        // survey.org (path 3).
        $cluster = Organization::factory()->cluster()->create();
        $survey = Survey::factory()->create([
            'organization_id' => $cluster->id,
        ]);

        $response = SurveyResponse::create([
            'survey_id' => $survey->id,
            'respondent_id' => null,
            'status' => ResponseStatus::Submitted->value,
            'submitted_at' => now(),
        ]);

        $this->assertSame((int) $cluster->id, (int) $response->respondent_organization_id);
    }

    public function test_submission_stamps_snapshot_falls_back_to_survey_org_when_respondent_has_no_org(): void
    {
        // Path 2 falls through to path 3 when the respondent user
        // exists but has no organization_id (deleted org scenario —
        // the user row is still present but their org has been
        // detached). Snapshot must resolve to the survey's
        // organization.
        $cluster = Organization::factory()->cluster()->create();
        $survey = Survey::factory()->create([
            'organization_id' => $cluster->id,
        ]);
        $orglessUser = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);

        $response = SurveyResponse::create([
            'survey_id' => $survey->id,
            'respondent_id' => $orglessUser->id,
            'status' => ResponseStatus::Submitted->value,
            'submitted_at' => now(),
        ]);

        $this->assertSame(
            (int) $cluster->id,
            (int) $response->respondent_organization_id,
            'respondent has no org — snapshot must fall back to survey.org'
        );
    }

    public function test_cluster_aggregate_uses_snapshot_when_respondent_moves_org(): void
    {
        // The contract that motivated Phase 3A: a respondent who
        // switches orgs after submission must NOT re-attribute their
        // historical response into the new org's cluster row. The
        // aggregate reads the snapshot, not the live
        // users.organization_id relation.
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();
        $survey = Survey::factory()->create(['organization_id' => $cluster->id]);

        // Respondent originally lives in the cluster org.
        $respondent = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);

        $response = SurveyResponse::create([
            'survey_id' => $survey->id,
            'respondent_id' => $respondent->id,
            'status' => ResponseStatus::Submitted->value,
            'submitted_at' => now(),
        ]);

        $this->assertSame(
            (int) $cluster->id,
            (int) $response->respondent_organization_id,
            'snapshot must stamp the cluster org at submission time'
        );

        // Move the respondent to the hospital.
        $respondent->organization_id = $hospital->id;
        $respondent->save();

        // Re-fetch as the cluster actor for the cluster-stats read.
        $actor = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, [
            Capability::SURVEYS_VIEW,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $body = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$survey->id}/cluster-stats")
            ->assertOk()
            ->json();

        $clusterRow = collect($body['aggregates'])->firstWhere('organization_id', $cluster->id);
        $hospitalRow = collect($body['aggregates'])->firstWhere('organization_id', $hospital->id);

        $this->assertNotNull($clusterRow);
        $this->assertSame(1, $clusterRow['response_count'], 'snapshot stays attributed to the cluster org');

        if ($hospitalRow !== null) {
            $this->assertSame(
                0,
                $hospitalRow['response_count'],
                'snapshot must NOT re-attribute the response into the new org the user moved to'
            );
        }
    }
}
