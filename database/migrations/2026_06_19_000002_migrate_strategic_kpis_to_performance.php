<?php

use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Models\KpiMeasurement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function mapTrend(?string $trend): string
    {
        return match ($trend) {
            'down_good' => 'decrease',
            'stable' => 'maintain',
            default => 'increase',
        };
    }

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('strategic_kpis')) {
            return;
        }

        DB::table('strategic_kpis')->orderBy('id')->each(function ($row) {
            $orgId = $row->organization_id;
            if (! $orgId && $row->measurable_type) {
                $measurable = $row->measurable_type::find($row->measurable_id);
                $orgId = $measurable?->organization_id;
            }
            if (! $orgId) {
                return;
            }

            $kpi = (new Kpi)->forceFill([
                'organization_id' => $orgId,
                'code' => $row->code,
                'name' => $row->name,
                'description' => $row->description ?? null,
                'measurement_method' => $row->measurement_method ?? null,
                'category' => 'strategic',
                'baseline' => $row->baseline,
                'target' => $row->target,
                'current_value' => $row->current_value ?? $row->baseline ?? 0,
                'unit' => $row->unit,
                'frequency' => $row->frequency ?? 'quarterly',
                'direction' => $this->mapTrend($row->trend ?? null),
                'status' => 'active',
                'owner_id' => $row->owner_id,
                'created_by' => $row->owner_id,
                'order' => $row->order ?? 0,
            ]);
            $kpi->save();

            (new KpiLink)->forceFill([
                'organization_id' => $orgId,
                'kpi_id' => $kpi->id,
                'linkable_type' => $row->measurable_type,
                'linkable_id' => $row->measurable_id,
                'relationship_type' => 'primary',
                'weight' => 1,
                'created_by' => $row->owner_id,
            ])->save();

            DB::table('strategic_kpi_measurements')
                ->where('kpi_id', $row->id)
                ->orderBy('id')
                ->each(function ($m) use ($kpi, $orgId) {
                    (new KpiMeasurement)->forceFill([
                        'organization_id' => $orgId,
                        'kpi_id' => $kpi->id,
                        'value' => $m->value,
                        'measurement_date' => $m->measurement_date,
                        'notes' => $m->notes,
                        'recorded_by' => $m->recorded_by,
                    ])->save();
                });
        });
    }

    public function down(): void
    {
        // One-way data migration.
    }
};
