<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdcaCheckGateTest extends TestCase
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

    public function test_check_transition_requires_measurement_for_all_linked_kpis(): void
    {
        [$manager, $project] = $this->improvementProjectWithTwoKpis(['current_pdca_phase' => 'do']);

        $this->actingAs($manager)
            ->patchJson("/api/projects/{$project->id}/pdca-phase", [
                'phase' => 'check',
                'measurements' => [
                    ['kpi_id' => $project->kpis->first()->id, 'value' => 80, 'measurement_date' => '2026-06-19'],
                ],
            ], ['X-Skip-Csrf' => '1'])
            ->assertStatus(422);

        $this->assertSame('do', $project->fresh()->current_pdca_phase);
    }

    public function test_check_transition_succeeds_when_all_kpis_measured(): void
    {
        [$manager, $project] = $this->improvementProjectWithTwoKpis(['current_pdca_phase' => 'do']);

        $measurements = $project->kpis->map(fn ($kpi) => [
            'kpi_id' => $kpi->id,
            'value' => 90,
            'measurement_date' => '2026-06-19',
        ])->all();

        $this->actingAs($manager)
            ->patchJson("/api/projects/{$project->id}/pdca-phase", [
                'phase' => 'check',
                'measurements' => $measurements,
            ], ['X-Skip-Csrf' => '1'])
            ->assertOk();

        $this->assertSame('check', $project->fresh()->current_pdca_phase);
        $this->assertEqualsWithDelta(90, (float) $project->kpis->first()->fresh()->current_value, 0.01);
    }
}
