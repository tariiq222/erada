<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Performance\Http\Requests\StoreKpiMeasurementRequest;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Policies\KpiPolicy;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KpiMeasurementController extends Controller
{
    use HasOrganizationScope;

    public function index(Request $request, Kpi $kpi): JsonResponse
    {
        $this->authorizePerformance('view');

        // Phase 9-D-D1a: cluster_tree-aware per-target check on the parent KPI.
        // Replaces the previous strict HasOrganizationScope::assertSameOrganization($kpi).
        if (! app(KpiPolicy::class)->view(auth()->user(), $kpi)) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        $query = $kpi->measurements()->with('recorder:id,name');

        if ($request->filled('from')) {
            $query->whereDate('measurement_date', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('measurement_date', '<=', $request->date('to'));
        }

        return response()->json($query->paginate(min($request->integer('per_page', 15), 100)));
    }

    public function store(StoreKpiMeasurementRequest $request, Kpi $kpi): JsonResponse
    {
        $validated = $request->validated();
        $validated['recorded_by'] = auth()->id();

        $measurement = $kpi->measurements()->make($validated);
        $measurement->forceFill(['organization_id' => $kpi->organization_id])->save();

        return response()->json([
            'message' => 'تم تسجيل القياس بنجاح',
            'measurement' => $measurement->load('recorder:id,name'),
            'kpi' => $kpi->fresh(),
        ], 201);
    }

    /**
     * Engine-based gate: maps a controller-level ability to the matching
     * Capability constant. Authorization is decided by the unified AuthZ
     * engine (AccessDecision::can), not by a flat Spatie permission name.
     */
    private function authorizePerformance(string $ability = 'view'): void
    {
        $user = auth()->user();
        $capability = match ($ability) {
            'update' => Capability::KPIS_MANAGE,
            default => Capability::KPIS_VIEW,
        };

        if (! AccessDecision::can($user, $capability)) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }
    }
}
