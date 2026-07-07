<?php

namespace App\Modules\Projects\Services;

use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiMeasurement;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ProjectPhaseService - drives the sequential PDCA state machine for improvement
 * projects (FOCUS-PDCA methodology).
 *
 * State machine: plan -> do -> check -> act -> plan. Each transition is enforced
 * strictly; the `check` phase additionally requires measurements for every linked
 * KPI (the "Check KPI gate" per the PDCA design).
 */
class ProjectPhaseService
{
    private const ALLOWED_TRANSITIONS = [
        'plan' => 'do',
        'do' => 'check',
        'check' => 'act',
        'act' => 'plan',
    ];

    /**
     * Advance a project's PDCA phase.
     *
     * @param  array<string, mixed>  $input  validated payload (phase + optional measurements)
     * @param  int|null  $recordedBy  user id for measurement attribution
     *
     * @throws ValidationException on type mismatch, illegal transition, missing KPIs, or missing measurements
     */
    public function advance(Project $project, array $input, ?int $recordedBy = null): Project
    {
        if ($project->type !== 'improvement') {
            throw ValidationException::withMessages([
                'phase' => 'PDCA phases are available for improvement projects only',
            ]);
        }

        $current = $project->current_pdca_phase ?? 'plan';
        $next = $input['phase'];

        if ((self::ALLOWED_TRANSITIONS[$current] ?? null) !== $next) {
            throw ValidationException::withMessages([
                'phase' => 'Sequential transition only; cannot move from '.$current.' to '.$next,
            ]);
        }

        return DB::transaction(function () use ($project, $next, $input, $recordedBy) {
            if ($next === 'check') {
                $this->applyCheckGate($project, $input['measurements'] ?? [], $recordedBy);
            }

            $project->update(['current_pdca_phase' => $next]);

            return $project->fresh();
        });
    }

    /**
     * Enforce that every linked KPI has a measurement recorded in this batch, and
     * upsert the measurements onto KpiMeasurement rows scoped to this project.
     */
    private function applyCheckGate(Project $project, array $measurements, ?int $recordedBy): void
    {
        $linkedKpiIds = $project->kpis()->pluck('kpis.id')->map(fn ($id) => (int) $id)->all();

        if (empty($linkedKpiIds)) {
            throw ValidationException::withMessages([
                'measurements' => 'No KPIs linked to project',
            ]);
        }

        $measuredKpiIds = collect($measurements)
            ->pluck('kpi_id')
            ->map(fn ($kpiId) => (int) $kpiId)
            ->all();

        $missing = array_values(array_diff($linkedKpiIds, $measuredKpiIds));

        if (! empty($missing)) {
            throw ValidationException::withMessages([
                'measurements' => 'Missing measurements for kpi_ids: '.implode(',', $missing),
            ]);
        }

        foreach ($measurements as $m) {
            $kpi = Kpi::find($m['kpi_id']);
            if (! $kpi || ! in_array((int) $kpi->id, $linkedKpiIds, true)) {
                continue;
            }

            $existing = KpiMeasurement::query()
                ->where('kpi_id', $kpi->id)
                ->where('measurement_date', $m['measurement_date'])
                ->where('source_type', Project::class)
                ->where('source_id', $project->id)
                ->first();

            if ($existing) {
                $existing->update([
                    'value' => $m['value'],
                    'recorded_by' => $recordedBy,
                ]);
            } else {
                (new KpiMeasurement)->forceFill([
                    'organization_id' => $kpi->organization_id,
                    'kpi_id' => $kpi->id,
                    'value' => $m['value'],
                    'measurement_date' => $m['measurement_date'],
                    'source_type' => Project::class,
                    'source_id' => $project->id,
                    'recorded_by' => $recordedBy,
                ])->save();
            }
        }
    }
}
