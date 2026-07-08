<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\HR\Models\Department;
use App\Modules\Performance\Http\Requests\DestroyKpiLinkRequest;
use App\Modules\Performance\Http\Requests\StoreKpiLinkRequest;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Policies\KpiPolicy;
use App\Modules\Performance\Scopes\UserKpiScope;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Traits\HasOrganizationScope;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Models\Review;
use App\Modules\Strategy\Models\StrategicObjective;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KpiLinkController extends Controller
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

        $links = $kpi->links()
            ->with(['creator:id,name', 'linkable'])
            ->latest()
            ->paginate(min($request->integer('per_page', 15), 100));

        return response()->json($links);
    }

    public function store(StoreKpiLinkRequest $request, Kpi $kpi): JsonResponse
    {
        $validated = $request->validated();
        $linkableClass = $request->getLinkableClass();
        $linkable = $request->getLinkable();

        if (! $linkable) {
            return response()->json([
                'message' => 'العنصر المرتبط غير موجود',
            ], 422);
        }

        $link = KpiLink::firstOrNew([
            'kpi_id' => $kpi->id,
            'linkable_type' => $linkableClass,
            'linkable_id' => $linkable->getKey(),
        ]);

        $link->fill([
            'relationship_type' => $validated['relationship_type'] ?? 'related',
            'weight' => $validated['weight'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $link->exists ? $link->created_by : auth()->id(),
        ]);
        $link->forceFill([
            'organization_id' => $kpi->organization_id ?? ($linkable->organization_id ?? null),
        ])->save();

        return response()->json([
            'message' => $link->wasRecentlyCreated ? 'تم ربط مؤشر الأداء بنجاح' : 'تم تحديث ربط مؤشر الأداء بنجاح',
            'link' => $link->load(['creator:id,name', 'linkable']),
        ], $link->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(DestroyKpiLinkRequest $request, Kpi $kpi, KpiLink $link): JsonResponse
    {
        $this->authorizePerformance('update');
        $this->assertSameOrganization($kpi);

        if ($link->kpi_id !== $kpi->id) {
            abort(404);
        }

        $this->assertSameOrganization($link);
        $link->delete();

        return response()->json([
            'message' => 'تم حذف ربط مؤشر الأداء بنجاح',
        ]);
    }

    public function contextKpis(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorizePerformance('view');

        $linkableClass = $this->resolveContextType($type);
        $linkable = $linkableClass::find($id);

        if (! $linkable) {
            return response()->json([
                'message' => 'العنصر المرتبط غير موجود',
            ], 404);
        }

        $this->assertSameOrganization($linkable);

        $query = Kpi::query()
            ->with(['owner:id,name', 'links' => function ($query) use ($linkableClass, $id) {
                $query->where('linkable_type', $linkableClass)
                    ->where('linkable_id', $id);
            }])
            ->whereHas('links', function ($query) use ($linkableClass, $id) {
                $query->where('linkable_type', $linkableClass)
                    ->where('linkable_id', $id);
            });

        $this->scopeToCurrentOrganization($query);

        return response()->json($query->orderBy('order')->paginate(min($request->integer('per_page', 15), 100)));
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

    private function scopeToCurrentOrganization($query): void
    {
        $user = auth()->user();

        if ($user?->isSuperAdmin()) {
            return;
        }

        if ($user?->organization_id === null) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        // Phase 4: delegate to UserKpiScope (single source of truth for KPI org floor).
        app(UserKpiScope::class)->applyToKpis($query, $user);
    }

    private function resolveContextType(string $type): string
    {
        return $this->contextTypes()[$type] ?? abort(422, 'نوع العنصر غير صالح');
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private function contextTypes(): array
    {
        return [
            'project' => Project::class,
            'program' => Program::class,
            'objective' => StrategicObjective::class,
            'review' => Review::class,
            'department' => Department::class,
        ];
    }

    private function assertCompatibleOrganization(Kpi $kpi, Model $linkable): void
    {
        $linkableOrganizationId = $linkable->organization_id ?? null;

        if ($kpi->organization_id !== null
            && $linkableOrganizationId !== null
            && $kpi->organization_id !== $linkableOrganizationId) {
            abort(422, 'يجب أن يكون مؤشر الأداء والعنصر المرتبط ضمن نفس المؤسسة');
        }
    }
}
