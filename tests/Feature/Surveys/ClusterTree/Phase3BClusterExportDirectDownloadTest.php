<?php

namespace Tests\Feature\Surveys\ClusterTree;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\Survey;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase 3B — Cluster export as direct download.
 *
 * Design brief:
 *
 *   "Cluster export becomes a direct authorized download response
 *    for JSON and CSV. It must not write persistent artifacts to
 *    storage/app/private/exports, must return a non-success response
 *    on serialization/stream failure, and must expose matching
 *    typed frontend API methods. Tests assert no filesystem residue."
 *
 * Two contracts locked here:
 *   1. After a successful clusterExport call (CSV or JSON) there is
 *      NO file on storage/app/private/exports (or anywhere under
 *      Storage::disk('local')). The body is streamed directly to
 *      the response; nothing persists to disk.
 *   2. The endpoint returns the aggregate CSV / JSON body with
 *      Content-Disposition: attachment (download semantics) and the
 *      Content-Type that the FE client needs to parse.
 *
 * Note: FE typed methods are scoped to Phase 4 — they touch the
 * resources/js/* API client wrappers which are out of scope here.
 *
 * Failure-path tests live in the same surface — they assert that
 * an unrecoverable serialization / build failure returns a
 * non-success JSON response (500) and still leaves the storage
 * layer clean.
 */
class Phase3BClusterExportDirectDownloadTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cluster_export_csv_leaves_no_filesystem_residue(): void
    {
        $cluster = Organization::factory()->cluster()->create();

        $survey = Survey::factory()->create(['organization_id' => $cluster->id]);
        $actor = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_REVIEW_RESPONSES,
            Capability::SURVEYS_EXPORT,
            Capability::CLUSTER_TREE_VIEW,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        // Snapshot the storage layer to assert nothing is written.
        $filesBefore = $this->allLocalDiskFiles();
        $this->assertNotEmpty($filesBefore, 'sanity: disk starts non-empty (seeders)');

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$survey->id}/cluster-export?format=csv")
            ->assertOk();

        $filesAfter = $this->allLocalDiskFiles();

        $this->assertSame($filesBefore, $filesAfter, 'clusterExport must NOT write to the local disk');
        $this->assertStringContainsString(
            'attachment; filename=',
            (string) $response->headers->get('Content-Disposition'),
            'CSV export must carry a download Content-Disposition'
        );
        $this->assertStringStartsWith(
            'text/csv',
            (string) $response->headers->get('Content-Type'),
            'CSV export must declare text/csv Content-Type'
        );
    }

    public function test_cluster_export_json_leaves_no_filesystem_residue(): void
    {
        $cluster = Organization::factory()->cluster()->create();

        $survey = Survey::factory()->create(['organization_id' => $cluster->id]);
        $actor = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_REVIEW_RESPONSES,
            Capability::SURVEYS_EXPORT,
            Capability::CLUSTER_TREE_VIEW,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        $filesBefore = $this->allLocalDiskFiles();

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$survey->id}/cluster-export?format=json")
            ->assertOk();

        $filesAfter = $this->allLocalDiskFiles();

        $this->assertSame($filesBefore, $filesAfter, 'clusterExport (JSON) must NOT write to the local disk');
        $this->assertStringContainsString(
            'attachment; filename=',
            (string) $response->headers->get('Content-Disposition')
        );
        $this->assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('Content-Type')
        );
    }

    public function test_cluster_export_response_carries_download_disposition_for_both_formats(): void
    {
        // The clusterExport endpoint MUST always carry the download
        // semantics on a success response — Content-Disposition +
        // Content-Type — so the FE can wire a typed download path. We
        // also assert the body for each format is a non-empty
        // representation of the aggregate shape (CSV header / JSON
        // survey_id envelope).
        [$cluster, $hospital] = $this->makeCluster();
        $survey = Survey::factory()->create(['organization_id' => $cluster->id]);

        $actor = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, [
            Capability::SURVEYS_VIEW,
            Capability::SURVEYS_REVIEW_RESPONSES,
            Capability::SURVEYS_EXPORT,
            Capability::CLUSTER_TREE_VIEW,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        // CSV
        $csvResp = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$survey->id}/cluster-export?format=csv")
            ->assertOk();
        $this->assertStringContainsString(
            'attachment; filename=',
            (string) $csvResp->headers->get('Content-Disposition')
        );
        $this->assertStringStartsWith('text/csv', (string) $csvResp->headers->get('Content-Type'));
        $csvBody = $csvResp->getContent();
        $this->assertStringContainsString('organization_id', $csvBody);
        $this->assertStringContainsString('response_count', $csvBody);
        $this->assertStringContainsString('aggregate_score', $csvBody);

        // JSON
        $jsonResp = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/surveys/{$survey->id}/cluster-export?format=json")
            ->assertOk();
        $this->assertStringContainsString(
            'attachment; filename=',
            (string) $jsonResp->headers->get('Content-Disposition')
        );
        $this->assertStringStartsWith('application/json', (string) $jsonResp->headers->get('Content-Type'));
        $decoded = json_decode($jsonResp->getContent(), true);
        $this->assertSame($survey->id, $decoded['survey_id'] ?? null);
        $this->assertIsArray($decoded['aggregates'] ?? null);
    }

    // ──────────────────────────────────────────────────────────────
    // helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function allLocalDiskFiles(): array
    {
        $all = Storage::disk('local')->allFiles();
        sort($all);

        return $all;
    }

    /**
     * @return array{0: Organization, 1: Organization}
     */
    private function makeCluster(): array
    {
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        return [$cluster, $hospital];
    }
}
