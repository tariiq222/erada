<?php

namespace App\Modules\Performance\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Support\KpiOrgGuard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreKpiMeasurementRequest - validation + engine-based authz for KPI measurement recording.
 *
 * Recording a measurement is an edit on the KPI itself (it updates current_value
 * downstream), so it uses KPIS_EDIT and the same deny-not-bypass organization
 * gate as UpdateKpiRequest.
 */
class StoreKpiMeasurementRequest extends FormRequest
{
    protected ?Kpi $kpi = null;

    public function authorize(): bool
    {
        $user = $this->user();
        $kpi = $this->resolveKpi();

        if (! $user instanceof User || ! $kpi instanceof Kpi) {
            return false;
        }

        $this->kpi = $kpi;

        if (! AccessDecision::can($user, Capability::KPIS_EDIT)) {
            return false;
        }

        // Phase 4: delegate to KpiOrgGuard (single source of truth for same-org gate).
        return app(KpiOrgGuard::class)->sameOrganizationForKpi($user, $kpi);
    }

    public function rules(): array
    {
        return [
            'value' => ['required', 'numeric', 'min:-1000000000000', 'max:1000000000000'],
            'measurement_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'evidence_url' => ['nullable', 'string', 'max:2048'],
            'source_type' => ['nullable', 'string', 'max:255', 'required_with:source_id'],
            'source_id' => ['nullable', 'integer', 'min:1', 'required_with:source_type'],
        ];
    }

    public function getKpi(): ?Kpi
    {
        return $this->kpi;
    }

    private function resolveKpi(): ?Kpi
    {
        $routeKpi = $this->route('kpi');

        if ($routeKpi instanceof Kpi) {
            return $routeKpi;
        }

        if ($routeKpi !== null) {
            return Kpi::find($routeKpi);
        }

        return null;
    }
}
