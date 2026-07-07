<?php

use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('project_kpis')) {
            return;
        }

        DB::table('project_kpis')->orderBy('id')->each(function ($row) {
            $project = Project::withTrashed()->find($row->project_id);
            if (! $project) {
                return;
            }

            $kpi = (new Kpi)->forceFill([
                'organization_id' => $project->organization_id,
                'name' => $row->indicator,
                'description' => null,
                'measurement_method' => $row->measurement_method,
                'category' => 'project',
                'baseline' => $row->baseline,
                'target' => $row->target,
                'current_value' => $row->current_value ?? $row->baseline ?? 0,
                'unit' => null,
                'frequency' => 'monthly',
                'direction' => 'increase',
                'status' => 'active',
                'owner_id' => $project->created_by,
                'created_by' => $project->created_by,
                'order' => $row->order ?? 0,
            ]);
            $kpi->save();

            (new KpiLink)->forceFill([
                'organization_id' => $project->organization_id,
                'kpi_id' => $kpi->id,
                'linkable_type' => Project::class,
                'linkable_id' => $project->id,
                'relationship_type' => 'primary',
                'weight' => 1,
                'created_by' => $project->created_by,
            ])->save();
        });
    }

    public function down(): void
    {
        // One-way data migration. project_kpis rows are not recreated.
    }
};
