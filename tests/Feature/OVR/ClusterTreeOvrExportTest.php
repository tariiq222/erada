<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Models\IncidentReport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class ClusterTreeOvrExportTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cluster_export_uses_every_report_status_and_excludes_raw_data(): void
    {
        [$cluster, $hospital, $sibling] = $this->makeClusterTree();
        $actor = $this->makeActor($cluster, [
            Capability::OVR_EXPORT,
            Capability::CLUSTER_TREE_EXPORT,
        ]);

        $this->makeReport($cluster, ReportStatus::New);
        $this->makeReport($hospital, ReportStatus::Draft);
        $this->makeReport($hospital, ReportStatus::UnderReview, false, 'PATIENT-PRIVATE-001');
        $this->makeReport($hospital, ReportStatus::PendingInfo, true);
        $this->makeReport($sibling, ReportStatus::Closed);

        $response = $this->actingAs($actor, 'sanctum')
            ->get('/api/ovr/incidents/cluster-export?format=csv')
            ->assertOk();

        $content = $response->streamedContent();
        $rows = $this->csvRows($content);
        $header = array_shift($rows);

        $this->assertSame([
            'organization_id',
            'organization_name',
            'total',
            'draft',
            'new',
            'under_review',
            'pending_info',
            'in_progress',
            'resolved',
            'closed',
            'rejected',
            'archived',
            'low',
            'medium',
            'high',
            'critical',
        ], $header);

        $byOrg = collect($rows)
            ->mapWithKeys(fn (array $row) => [(int) $row[0] => array_combine($header, $row)]);

        $this->assertSame('1', $byOrg[$cluster->id]['new']);
        $this->assertSame('1', $byOrg[$hospital->id]['draft']);
        $this->assertSame('1', $byOrg[$hospital->id]['under_review']);
        $this->assertSame('0', $byOrg[$hospital->id]['pending_info']);
        $this->assertArrayNotHasKey($sibling->id, $byOrg);
        $this->assertStringNotContainsString('PATIENT-PRIVATE-001', $content);
    }

    public function test_cluster_export_stays_same_org_without_cluster_tree_export(): void
    {
        [$cluster, $hospital] = $this->makeClusterTree();
        $actor = $this->makeActor($cluster, Capability::OVR_EXPORT);

        $this->makeReport($cluster, ReportStatus::New);
        $this->makeReport($hospital, ReportStatus::New);

        $response = $this->actingAs($actor, 'sanctum')
            ->get('/api/ovr/incidents/cluster-export?format=csv')
            ->assertOk();

        $rows = $this->csvRows($response->streamedContent());
        array_shift($rows);

        $this->assertCount(1, $rows);
        $this->assertSame((string) $cluster->id, $rows[0][0]);
    }

    public function test_stats_pair_cannot_export_cluster_aggregates(): void
    {
        [$cluster] = $this->makeClusterTree();
        $actor = $this->makeActor($cluster, [
            Capability::OVR_VIEW_STATISTICS,
            Capability::CLUSTER_TREE_VIEW,
        ]);

        $this->actingAs($actor, 'sanctum')
            ->get('/api/ovr/incidents/cluster-export?format=csv')
            ->assertForbidden();
    }

    /**
     * @return list<array<int, string|null>>
     */
    private function csvRows(string $content): array
    {
        return array_values(array_map(
            fn (string $line) => str_getcsv($line),
            array_filter(preg_split('/\r\n|\r|\n/', ltrim($content, "\xEF\xBB\xBF")))
        ));
    }

    /**
     * @param  Capability|list<Capability>  $capabilities
     */
    private function makeActor(Organization $organization, string|array $capabilities): User
    {
        $actor = User::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($actor, $capabilities);

        return $actor;
    }

    private function makeReport(
        Organization $organization,
        ReportStatus $status,
        bool $confidential = false,
        ?string $patientFileNumber = null,
    ): IncidentReport {
        return IncidentReport::factory()->create([
            'organization_id' => $organization->id,
            'status' => $status,
            'is_confidential' => $confidential,
            'patient_file_number' => $patientFileNumber,
        ]);
    }

    /**
     * @return array{0: Organization, 1: Organization, 2: Organization}
     */
    private function makeClusterTree(): array
    {
        $cluster = Organization::factory()->cluster()->create(['name' => 'Export Cluster']);
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create(['name' => 'Export Hospital']);
        $sibling = Organization::factory()->create(['name' => 'Export Sibling']);

        return [$cluster, $hospital, $sibling];
    }
}
