<?php

namespace Tests\Feature\Kpi;

use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_kpis_resolve_through_kpi_links(): void
    {
        $project = Project::factory()->create();
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

        $this->assertTrue($project->fresh()->kpis->contains($kpi));
    }
}
