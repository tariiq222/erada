<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Models\KpiMeasurement;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdcaCheckTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function improvementProjectWithTwoKpis(array $overrides = []): array
    {
        $project = Project::factory()->create(array_merge([
            'type' => 'improvement',
            'current_pdca_phase' => 'do',
        ], $overrides));

        $manager = User::factory()->create(['organization_id' => $project->organization_id]);
        $manager->assignRole('super_admin');

        foreach (range(1, 2) as $i) {
            $kpi = Kpi::factory()->create(['organization_id' => $project->organization_id]);
            (new KpiLink)->forceFill([
                'organization_id' => $project->organization_id,
                'kpi_id' => $kpi->id,
                'linkable_type' => Project::class,
                'linkable_id' => $project->id,
                'relationship_type' => 'primary',
                'weight' => 1,
                'created_by' => $project->created_by,
            ])->save();
        }

        return [$manager, $project];
    }

    public function test_first_check_transition_creates_measurement_rows_for_each_kpi(): void
    {
        [$manager, $project] = $this->improvementProjectWithTwoKpis(['current_pdca_phase' => 'plan']);

        $this->actingAs($manager)
            ->patchJson("/api/projects/{$project->id}/pdca-phase", ['phase' => 'do'], ['X-Skip-Csrf' => '1'])
            ->assertOk();

        $measurements = $project->kpis->map(fn ($kpi) => [
            'kpi_id' => $kpi->id,
            'value' => 75,
            'measurement_date' => '2026-06-20',
        ])->all();

        $this->actingAs($manager)
            ->patchJson("/api/projects/{$project->id}/pdca-phase", [
                'phase' => 'check',
                'measurements' => $measurements,
            ], ['X-Skip-Csrf' => '1'])
            ->assertOk();

        $this->assertSame('check', $project->fresh()->current_pdca_phase);
        $this->assertSame(2, KpiMeasurement::count());
    }

    public function test_replay_check_transition_updates_existing_rows_without_duplicates(): void
    {
        [$manager, $project] = $this->improvementProjectWithTwoKpis(['current_pdca_phase' => 'plan']);

        $this->actingAs($manager)
            ->patchJson("/api/projects/{$project->id}/pdca-phase", ['phase' => 'do'], ['X-Skip-Csrf' => '1'])
            ->assertOk();

        $measurements = $project->kpis->map(fn ($kpi) => [
            'kpi_id' => $kpi->id,
            'value' => 75,
            'measurement_date' => '2026-06-20',
        ])->all();

        $this->actingAs($manager)
            ->patchJson("/api/projects/{$project->id}/pdca-phase", [
                'phase' => 'check',
                'measurements' => $measurements,
            ], ['X-Skip-Csrf' => '1'])
            ->assertOk();

        $countAfterFirst = KpiMeasurement::count();
        $this->assertSame(2, $countAfterFirst);

        $project->update(['current_pdca_phase' => 'do']);

        $this->actingAs($manager)
            ->patchJson("/api/projects/{$project->id}/pdca-phase", [
                'phase' => 'check',
                'measurements' => $measurements,
            ], ['X-Skip-Csrf' => '1'])
            ->assertOk();

        $this->assertSame($countAfterFirst, KpiMeasurement::count());
    }

    public function test_check_transition_validation_failure_rolls_back_inserted_measurements(): void
    {
        [$manager, $project] = $this->improvementProjectWithTwoKpis(['current_pdca_phase' => 'plan']);

        $this->actingAs($manager)
            ->patchJson("/api/projects/{$project->id}/pdca-phase", ['phase' => 'do'], ['X-Skip-Csrf' => '1'])
            ->assertOk();

        $onlyKpi = $project->kpis->first();
        $partialMeasurements = [
            ['kpi_id' => $onlyKpi->id, 'value' => 80, 'measurement_date' => '2026-06-20'],
        ];

        $this->actingAs($manager)
            ->patchJson("/api/projects/{$project->id}/pdca-phase", [
                'phase' => 'check',
                'measurements' => $partialMeasurements,
            ], ['X-Skip-Csrf' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements']);

        $this->assertSame(0, KpiMeasurement::count());
        $this->assertSame('do', $project->fresh()->current_pdca_phase);
    }
}
