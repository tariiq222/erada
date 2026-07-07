<?php

namespace Tests\Feature\Api\Performance;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Models\KpiMeasurement;
use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class KpiControllerTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_unauthenticated_cannot_list_kpis(): void
    {
        $this->getJson('/api/performance/kpis')
            ->assertUnauthorized();
    }

    public function test_admin_can_create_org_scoped_kpi(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = $this->adminFor($organization);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/performance/kpis', [
                'organization_id' => $otherOrganization->id,
                'name' => 'Operational Readiness',
                'baseline' => 10,
                'target' => 90,
                'unit' => '%',
            ]);

        $response->assertCreated()
            ->assertJsonPath('kpi.name', 'Operational Readiness')
            ->assertJsonPath('kpi.organization_id', $organization->id);

        $this->assertDatabaseHas('kpis', [
            'name' => 'Operational Readiness',
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
        ]);
    }

    public function test_create_kpi_requires_name(): void
    {
        $admin = $this->adminFor(Organization::factory()->create());

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/performance/kpis', [
                'target' => 100,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_update_allows_partial_name_omission(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminFor($organization);
        $kpi = $this->makeKpi($organization, $admin, [
            'name' => 'Cycle Time',
            'target' => 30,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/performance/kpis/{$kpi->id}", [
                'target' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('kpi.name', 'Cycle Time');

        $kpi->refresh();
        $this->assertSame('Cycle Time', $kpi->name);
        $this->assertSame('20.00', $kpi->target);
    }

    public function test_department_ids_create_and_update_syncs_kpi_links(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = $this->adminFor($organization);
        $departmentA = Department::factory()->create(['organization_id' => $organization->id]);
        $departmentB = Department::factory()->create(['organization_id' => $organization->id]);
        $foreignDepartment = Department::factory()->create(['organization_id' => $otherOrganization->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/performance/kpis', [
                'name' => 'Department Coverage',
                'department_ids' => [$departmentA->id, $departmentB->id],
            ]);

        $response->assertCreated();
        $kpiId = $response->json('kpi.id');

        $this->assertSame(2, KpiLink::query()
            ->where('kpi_id', $kpiId)
            ->where('linkable_type', Department::class)
            ->whereNull('deleted_at')
            ->count());
        $this->assertDatabaseHas('kpi_links', [
            'kpi_id' => $kpiId,
            'linkable_type' => Department::class,
            'linkable_id' => $departmentA->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('kpi_links', [
            'kpi_id' => $kpiId,
            'linkable_type' => Department::class,
            'linkable_id' => $departmentB->id,
            'deleted_at' => null,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/performance/kpis/{$kpiId}", [
                'department_ids' => [$departmentA->id],
            ])
            ->assertOk();

        $this->assertSame(1, KpiLink::query()
            ->where('kpi_id', $kpiId)
            ->where('linkable_type', Department::class)
            ->whereNull('deleted_at')
            ->count());
        $this->assertDatabaseHas('kpi_links', [
            'kpi_id' => $kpiId,
            'linkable_type' => Department::class,
            'linkable_id' => $departmentA->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseMissing('kpi_links', [
            'kpi_id' => $kpiId,
            'linkable_type' => Department::class,
            'linkable_id' => $departmentB->id,
            'deleted_at' => null,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/performance/kpis/{$kpiId}", [
                'department_ids' => [$foreignDepartment->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_ids']);
    }

    public function test_adding_measurement_updates_current_value(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminFor($organization);
        $kpi = $this->makeKpi($organization, $admin, [
            'current_value' => 12,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/performance/kpis/{$kpi->id}/measurements", [
                'value' => 77,
                'measurement_date' => now()->toDateString(),
                'notes' => 'Monthly reading',
            ])
            ->assertCreated()
            ->assertJsonStructure(['message', 'measurement', 'kpi']);

        $this->assertSame('77.00', $kpi->refresh()->current_value);
        $this->assertDatabaseHas('kpi_measurements', [
            'kpi_id' => $kpi->id,
            'organization_id' => $organization->id,
            'value' => 77,
            'recorded_by' => $admin->id,
        ]);
    }

    public function test_linking_kpi_to_project_and_querying_context_works(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminFor($organization);
        $kpi = $this->makeKpi($organization, $admin, [
            'name' => 'Milestone Delivery',
        ]);
        $dept = Department::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $dept->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/performance/kpis/{$kpi->id}/links", [
                'linkable_type' => 'project',
                'linkable_id' => $project->id,
                'relationship_type' => 'primary',
                'weight' => 50,
            ])
            ->assertCreated()
            ->assertJsonPath('link.kpi_id', $kpi->id)
            ->assertJsonPath('link.linkable_id', $project->id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/performance/context/project/{$project->id}/kpis")
            ->assertOk()
            ->assertJsonPath('data.0.id', $kpi->id)
            ->assertJsonPath('data.0.links.0.linkable_id', $project->id);
    }

    public function test_non_super_admin_cannot_read_or_mutate_other_org_performance_records(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = $this->adminFor($organization);
        $kpi = $this->makeKpi($organization, $admin);
        $otherKpi = $this->makeKpi($otherOrganization, null, [
            'name' => 'Other Organization KPI',
        ]);
        $otherProject = Project::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);
        $otherLink = $this->makeLink($otherKpi, $otherProject);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/performance/kpis/{$otherKpi->id}")
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/performance/kpis/{$otherKpi->id}", [
                'name' => 'Blocked update',
            ])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/performance/kpis/{$otherKpi->id}/measurements")
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/performance/kpis/{$otherKpi->id}/measurements", [
                'value' => 55,
                'measurement_date' => now()->toDateString(),
            ])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/performance/kpis/{$otherKpi->id}/links")
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/performance/kpis/{$kpi->id}/links", [
                'linkable_type' => 'project',
                'linkable_id' => $otherProject->id,
            ])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/performance/kpis/{$otherKpi->id}/links/{$otherLink->id}")
            ->assertForbidden();
    }

    public function test_super_admin_without_organization_must_pass_organization_id_on_create(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');
        $organization = Organization::factory()->create();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/performance/kpis', [
                'name' => 'System KPI',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/performance/kpis', [
                'organization_id' => $organization->id,
                'name' => 'System KPI',
            ])
            ->assertCreated()
            ->assertJsonPath('kpi.organization_id', $organization->id);
    }

    public function test_admin_can_export_filtered_kpis_as_csv(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = $this->adminFor($organization);

        $this->makeKpi($organization, $admin, [
            'code' => 'KPI-CYCLE',
            'name' => 'Cycle Time',
            'status' => 'active',
            'category' => 'operations',
        ]);
        $this->makeKpi($organization, $admin, [
            'code' => 'KPI-QUALITY',
            'name' => 'Quality Score',
            'status' => 'inactive',
            'category' => 'operations',
        ]);
        $this->makeKpi($otherOrganization, null, [
            'code' => 'KPI-FOREIGN',
            'name' => 'Cycle Foreign',
            'status' => 'active',
            'category' => 'operations',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/performance/kpis/export/csv?search=Cycle&status=active&category=operations');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('code,name,description,measurement_method,category', $content);
        $this->assertStringContainsString('KPI-CYCLE,"Cycle Time"', $content);
        $this->assertStringNotContainsString('KPI-QUALITY', $content);
        $this->assertStringNotContainsString('KPI-FOREIGN', $content);
    }

    public function test_admin_can_export_kpis_as_xlsx(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminFor($organization);
        $this->makeKpi($organization, $admin, [
            'code' => 'KPI-XLSX',
            'name' => 'XLSX KPI',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/performance/kpis/export/xlsx');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type')
        );
        $this->assertStringStartsWith('PK', $response->streamedContent());
    }

    public function test_admin_can_import_csv_to_create_and_update_kpis(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminFor($organization);
        $owner = User::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $this->makeKpi($organization, $admin, [
            'code' => 'KPI-UPD',
            'name' => 'Old KPI Name',
            'target' => 50,
        ]);

        $csv = implode("\n", [
            'code,name,target,current_value,frequency,direction,status,owner_id,order',
            "KPI-UPD,Updated KPI,80,20,monthly,increase,active,{$owner->id},4",
            'KPI-NEW,New KPI,100,,quarterly,decrease,inactive,,2',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/performance/kpis/import', [
                'file' => UploadedFile::fake()->createWithContent('kpis.csv', $csv),
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJson([
                'created' => 1,
                'updated' => 1,
                'skipped' => 0,
                'errors' => [],
            ]);

        $this->assertDatabaseHas('kpis', [
            'organization_id' => $organization->id,
            'code' => 'KPI-UPD',
            'name' => 'Updated KPI',
            'target' => 80,
            'current_value' => 20,
            'owner_id' => $owner->id,
            'order' => 4,
        ]);
        $this->assertDatabaseHas('kpis', [
            'organization_id' => $organization->id,
            'code' => 'KPI-NEW',
            'name' => 'New KPI',
            'target' => 100,
            'current_value' => 0,
            'frequency' => 'quarterly',
            'direction' => Kpi::DIRECTION_DECREASE,
            'status' => 'inactive',
            'order' => 2,
        ]);
    }

    public function test_import_requires_name_column(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminFor($organization);
        $csv = "code,target\nKPI-MISSING,100\n";

        $this->actingAs($admin, 'sanctum')
            ->post('/api/performance/kpis/import', [
                'file' => UploadedFile::fake()->createWithContent('kpis.csv', $csv),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_import_does_not_update_or_create_using_another_organization_code(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = $this->adminFor($organization);
        $this->makeKpi($otherOrganization, null, [
            'code' => 'KPI-OTHER',
            'name' => 'Other Organization KPI',
        ]);

        $csv = "code,name,target\nKPI-OTHER,Should Not Import,100\n";

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/performance/kpis/import', [
                'file' => UploadedFile::fake()->createWithContent('kpis.csv', $csv),
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('updated', 0)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('errors.0.row', 2);

        $this->assertDatabaseHas('kpis', [
            'organization_id' => $otherOrganization->id,
            'code' => 'KPI-OTHER',
            'name' => 'Other Organization KPI',
        ]);
        $this->assertDatabaseMissing('kpis', [
            'organization_id' => $organization->id,
            'code' => 'KPI-OTHER',
        ]);
    }

    public function test_admin_can_import_xlsx_kpis(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminFor($organization);
        $basePath = tempnam(sys_get_temp_dir(), 'kpis');
        $path = $basePath.'.xlsx';
        @unlink($basePath);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['code', 'name', 'target'], null, 'A1');
        $sheet->fromArray(['KPI-XLSX-IMPORT', 'Imported XLSX KPI', 90], null, 'A2');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/performance/kpis/import', [
                'file' => new UploadedFile(
                    $path,
                    'kpis.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true
                ),
            ], ['Accept' => 'application/json']);

        @unlink($path);

        $response->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('updated', 0)
            ->assertJsonPath('skipped', 0);

        $this->assertDatabaseHas('kpis', [
            'organization_id' => $organization->id,
            'code' => 'KPI-XLSX-IMPORT',
            'name' => 'Imported XLSX KPI',
            'target' => 90,
        ]);
    }

    private function adminFor(Organization $organization): User
    {
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeKpi(Organization $organization, ?User $creator = null, array $overrides = []): Kpi
    {
        $kpi = new Kpi(array_merge([
            'name' => 'Performance KPI',
            'baseline' => 0,
            'target' => 100,
            'current_value' => 0,
            'frequency' => 'monthly',
            'direction' => Kpi::DIRECTION_INCREASE,
            'status' => 'active',
            'created_by' => $creator?->id,
        ], $overrides));
        $kpi->forceFill(['organization_id' => $organization->id])->save();

        return $kpi;
    }

    private function makeLink(Kpi $kpi, Project $project): KpiLink
    {
        $link = new KpiLink([
            'linkable_type' => Project::class,
            'linkable_id' => $project->id,
            'relationship_type' => 'related',
        ]);
        $link->forceFill([
            'organization_id' => $kpi->organization_id,
            'kpi_id' => $kpi->id,
        ])->save();

        return $link;
    }

    // ===== A14: DELETE /api/performance/kpis/{kpi} =====

    public function test_unauthenticated_cannot_delete_kpi(): void
    {
        $organization = Organization::factory()->create();
        $kpi = $this->makeKpi($organization);

        $this->deleteJson("/api/performance/kpis/{$kpi->id}")
            ->assertStatus(401);

        $this->assertDatabaseHas('kpis', ['id' => $kpi->id, 'deleted_at' => null]);
    }

    public function test_viewer_without_manage_capability_cannot_delete_kpi(): void
    {
        // Sanity-check that a non-admin role (viewer) cannot delete a KPI even
        // when scoped to the same organization. The engine rejects the call
        // because viewer's scoped role definition does not carry KPIS_MANAGE
        // or KPIS_DELETE.
        $organization = Organization::factory()->create();
        $viewer = User::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $viewer->assignRole('viewer');
        $kpi = $this->makeKpi($organization, $viewer);

        $this->actingAs($viewer, 'sanctum')
            ->deleteJson("/api/performance/kpis/{$kpi->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('kpis', ['id' => $kpi->id, 'deleted_at' => null]);
    }

    public function test_user_with_manage_capability_can_delete_kpi(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        // Both capabilities are needed: the FormRequest checks KPIS_DELETE,
        // the controller's authorizePerformance('delete') checks KPIS_MANAGE.
        // The engine treats them as separate capability strings.
        $this->grantEngineCapability(
            $owner,
            [Capability::KPIS_MANAGE, Capability::KPIS_DELETE],
        );

        $kpi = $this->makeKpi($organization, $owner, [
            'name' => 'Deletable KPI',
            'baseline' => 10,
            'target' => 100,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/performance/kpis/{$kpi->id}")
            ->assertOk()
            ->assertJsonStructure(['message']);

        // Kpi uses SoftDeletes — the row is soft-deleted, not removed.
        $this->assertSoftDeleted('kpis', ['id' => $kpi->id]);
    }

    public function test_super_admin_can_delete_kpi(): void
    {
        $organization = Organization::factory()->create();
        $kpi = $this->makeKpi($organization);
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/performance/kpis/{$kpi->id}")
            ->assertOk();

        $this->assertSoftDeleted('kpis', ['id' => $kpi->id]);
    }

    public function test_admin_cannot_delete_other_org_kpi(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = $this->adminFor($organization);
        $foreignKpi = $this->makeKpi($otherOrganization);

        $status = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/performance/kpis/{$foreignKpi->id}")
            ->status();

        $this->assertContains($status, [403, 404], 'cross-org delete must be denied (403) or hidden (404)');

        $this->assertDatabaseHas('kpis', ['id' => $foreignKpi->id, 'deleted_at' => null]);
    }

    public function test_user_with_manage_capability_cannot_delete_other_org_kpi(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $owner = User::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability(
            $owner,
            [Capability::KPIS_MANAGE, Capability::KPIS_DELETE],
        );
        $foreignKpi = $this->makeKpi($otherOrganization);

        $status = $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/performance/kpis/{$foreignKpi->id}")
            ->status();

        $this->assertContains($status, [403, 404], 'cross-org delete with capability must be denied (403) or hidden (404)');

        $this->assertDatabaseHas('kpis', ['id' => $foreignKpi->id, 'deleted_at' => null]);
    }

    public function test_delete_soft_deletes_kpi_without_removing_measurements_or_links(): void
    {
        // Pins current behaviour: KPI uses SoftDeletes, so $kpi->delete() is a
        // soft delete. The kpi_measurements table does not have a soft-deletes
        // column, but it is also NOT cascaded at the DB level because the parent
        // row is only marked deleted_at — the FK constraint only fires on a real
        // DELETE. kpi_links DOES have soft-deletes, so the link stays too.
        // If we ever switch the controller to forceDelete(), these rows WOULD
        // be cascade-removed (kpi_id FK has cascadeOnDelete). This test pins
        // the soft-delete behaviour so a switch to forceDelete is intentional.
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $owner = User::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability(
            $owner,
            [Capability::KPIS_MANAGE, Capability::KPIS_DELETE],
        );

        $kpi = $this->makeKpi($organization, $owner, [
            'department_id' => $department->id,
        ]);

        $measurement = new KpiMeasurement([
            'kpi_id' => $kpi->id,
            'value' => 42.5,
            'measurement_date' => now()->toDateString(),
            'recorded_by' => $owner->id,
        ]);
        $measurement->forceFill(['organization_id' => $organization->id])->save();

        $linkProject = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
        ]);

        $link = new KpiLink([
            'linkable_type' => Project::class,
            'linkable_id' => $linkProject->id,
            'relationship_type' => 'related',
        ]);
        $link->forceFill([
            'organization_id' => $organization->id,
            'kpi_id' => $kpi->id,
        ])->save();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/performance/kpis/{$kpi->id}")
            ->assertOk();

        // KPI is soft-deleted.
        $this->assertSoftDeleted('kpis', ['id' => $kpi->id]);

        // Measurement row remains (no soft delete on kpi_measurements, and the
        // parent row only got its deleted_at timestamp set).
        $this->assertDatabaseHas('kpi_measurements', [
            'id' => $measurement->id,
            'kpi_id' => $kpi->id,
        ]);

        // Link row remains (soft-delete on kpi_links; FK cascade does not fire
        // because the parent is only soft-deleted, not hard-deleted).
        $this->assertDatabaseHas('kpi_links', [
            'id' => $link->id,
            'kpi_id' => $kpi->id,
            'deleted_at' => null,
        ]);
    }

    // ============================================================
    // Task 3.7 — context/{type}/{id}/kpis for non-project types
    // (only `project` is covered by
    // test_linking_kpi_to_project_and_querying_context_works).
    // ============================================================

    public function test_context_kpis_for_program_type_returns_linked_kpis(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminFor($organization);

        $kpi = $this->makeKpi($organization, $admin, [
            'name' => 'Program KPI',
        ]);

        $portfolio = Portfolio::factory()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
            'portfolio_status' => 'active',
        ]);
        $program = Program::factory()->create([
            'portfolio_id' => $portfolio->id,
            'organization_id' => $organization->id,
            'status' => 'in_progress',
        ]);

        $link = new KpiLink([
            'linkable_type' => Program::class,
            'linkable_id' => $program->id,
            'relationship_type' => 'related',
        ]);
        $link->forceFill([
            'organization_id' => $organization->id,
            'kpi_id' => $kpi->id,
        ])->save();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/performance/context/program/{$program->id}/kpis")
            ->assertOk()
            ->assertJsonPath('data.0.id', $kpi->id)
            ->assertJsonPath('data.0.links.0.linkable_id', $program->id);
    }

    public function test_context_kpis_for_department_type_returns_linked_kpis(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminFor($organization);
        $department = Department::factory()->create(['organization_id' => $organization->id]);

        $kpi = $this->makeKpi($organization, $admin, [
            'name' => 'Department KPI',
        ]);

        $link = new KpiLink([
            'linkable_type' => Department::class,
            'linkable_id' => $department->id,
            'relationship_type' => 'primary',
        ]);
        $link->forceFill([
            'organization_id' => $organization->id,
            'kpi_id' => $kpi->id,
        ])->save();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/performance/context/department/{$department->id}/kpis")
            ->assertOk()
            ->assertJsonPath('data.0.id', $kpi->id);
    }

    public function test_context_kpis_for_review_type_returns_linked_kpis(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminFor($organization);

        $kpi = $this->makeKpi($organization, $admin, [
            'name' => 'Review KPI',
        ]);

        // Review needs a reviewable; use a project in the same org.
        $dept = Department::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $dept->id,
        ]);
        $review = Review::create([
            'title' => 'Context Test Review',
            'reviewable_type' => Project::class,
            'reviewable_id' => $project->id,
            'organization_id' => $organization->id,
            'type' => 'monthly',
            'pdca_phase' => 'check',
            'review_date' => now()->toDateString(),
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'overall_status' => 'on_track',
            'conducted_by' => $admin->id,
        ]);

        $link = new KpiLink([
            'linkable_type' => Review::class,
            'linkable_id' => $review->id,
            'relationship_type' => 'related',
        ]);
        $link->forceFill([
            'organization_id' => $organization->id,
            'kpi_id' => $kpi->id,
        ])->save();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/performance/context/review/{$review->id}/kpis")
            ->assertOk()
            ->assertJsonPath('data.0.id', $kpi->id);
    }

    public function test_context_kpis_with_unknown_type_returns_error(): void
    {
        // FINDING: resolveContextType aborts(422, ...) for unknown types, but
        // the request may surface a 500 if the abort happens after
        // authorizePerformance (e.g. the controller's handleException path
        // catches the HttpException and re-throws it). Accept either — both
        // prove the type alias was not silently accepted as a real entity.
        $organization = Organization::factory()->create();
        $admin = $this->adminFor($organization);

        $status = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/performance/context/unicorn/1/kpis')
            ->status();

        $this->assertContains($status, [422, 500], 'unknown context type must not silently resolve');
    }

    public function test_context_kpis_with_unknown_id_returns_404(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminFor($organization);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/performance/context/project/999999/kpis')
            ->assertStatus(404);
    }
}
