<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\HR\Models\Department;
use App\Modules\Performance\Http\Requests\DestroyKpiRequest;
use App\Modules\Performance\Http\Requests\ImportKpiRequest;
use App\Modules\Performance\Http\Requests\StoreKpiRequest;
use App\Modules\Performance\Http\Requests\UpdateKpiRequest;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Policies\KpiPolicy;
use App\Modules\Performance\Scopes\UserKpiScope;
use App\Modules\Performance\Services\KpiImportExportService;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KpiController extends Controller
{
    use HasOrganizationScope;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePerformance('view');

        $kpis = $this->filteredKpiQuery($request)
            ->with(['owner:id,name', 'creator:id,name'])
            ->paginate(min($request->integer('per_page', 15), 100));

        return response()->json($kpis);
    }

    public function export(Request $request, KpiImportExportService $service, string $format): StreamedResponse
    {
        $this->authorizePerformance('export');

        // CFA-02: scope widening for the export pair (KPIS_EXPORT + CLUSTER_TREE_EXPORT).
        // Reuses filteredKpiQuery so all search/status/category filters still apply,
        // but passes purpose='export' to UserKpiScope so the export-specific widening
        // (and not the view-pair widening) applies. Strict per CFA-00: a user with only
        // KPIS_VIEW + CLUSTER_TREE_VIEW (read-only cluster access) can read descendants
        // via index/show/list, but cannot export them — they see only their own org in
        // the streamed CSV/XLSX.
        $query = $this->filteredKpiQuery($request, 'export');
        $filename = 'performance-kpis-'.now()->toDateString().'.'.$format;

        return match ($format) {
            'csv' => $service->streamCsv($query, $filename),
            'xlsx' => $service->streamXlsx($query, $filename),
            default => abort(404),
        };
    }

    public function import(ImportKpiRequest $request, KpiImportExportService $service): JsonResponse
    {
        // Authz (KPIS_MANAGE) + payload validation owned by ImportKpiRequest.
        $validated = $request->validated();

        $organizationId = $this->organizationIdForWrite($validated['organization_id'] ?? null);
        $summary = $service->import($request->file('file'), $organizationId, $request->user()?->id);

        return response()->json($summary);
    }

    public function store(StoreKpiRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $organizationId = $this->organizationIdForWrite($validated['organization_id'] ?? null);
        $departmentIds = $this->validatedDepartmentIds($validated['department_ids'] ?? [], $organizationId);
        unset($validated['organization_id'], $validated['department_ids']);

        $validated['created_by'] = auth()->id();
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['frequency'] = $validated['frequency'] ?? 'monthly';
        $validated['direction'] = $validated['direction'] ?? Kpi::DIRECTION_INCREASE;
        // M-10: seed current_value from the baseline; it is never client-supplied
        // and changes only through KpiMeasurement afterwards.
        $validated['current_value'] = $validated['baseline'] ?? 0;

        $kpi = new Kpi($validated);
        $kpi->forceFill(['organization_id' => $organizationId])->save();
        $this->syncDepartmentLinks($kpi, $departmentIds);

        return response()->json([
            'message' => 'تم إنشاء مؤشر الأداء بنجاح',
            'kpi' => $kpi->load(['owner:id,name', 'creator:id,name']),
        ], 201);
    }

    public function show(Kpi $kpi): JsonResponse
    {
        $this->authorizePerformance('view');

        // Phase 9-D-D1a: replaced assertSameOrganization($kpi) (strict equality)
        // with KpiPolicy::view(), which allows cluster_tree widening (KPIS_VIEW +
        // CLUSTER_TREE_VIEW ⇒ reading KPIs in descendant orgs).
        if (! app(KpiPolicy::class)->view(auth()->user(), $kpi)) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        $kpi->load([
            'owner:id,name',
            'creator:id,name',
            'measurements' => fn ($query) => $query->with('recorder:id,name')->limit(12),
            'links' => fn ($query) => $query->with('linkable')->latest()->limit(12),
        ]);

        return response()->json($kpi);
    }

    public function update(UpdateKpiRequest $request, Kpi $kpi): JsonResponse
    {
        $validated = $request->validated();
        $hasDepartmentIds = array_key_exists('department_ids', $validated);
        $departmentIds = [];

        if ($hasDepartmentIds) {
            if ($kpi->organization_id === null) {
                throw ValidationException::withMessages([
                    'department_ids' => 'يجب أن يكون مؤشر الأداء مرتبطاً بمؤسسة قبل ربط الإدارات',
                ]);
            }

            $departmentIds = $this->validatedDepartmentIds($validated['department_ids'] ?? [], (int) $kpi->organization_id);
        }

        unset($validated['organization_id'], $validated['department_ids']);

        $kpi->update($validated);

        if ($hasDepartmentIds) {
            $this->syncDepartmentLinks($kpi, $departmentIds);
        }

        return response()->json([
            'message' => 'تم تحديث مؤشر الأداء بنجاح',
            'kpi' => $kpi->fresh()->load(['owner:id,name', 'creator:id,name']),
        ]);
    }

    public function destroy(DestroyKpiRequest $request, Kpi $kpi): JsonResponse
    {
        $this->authorizePerformance('delete');
        $this->assertSameOrganization($kpi);

        $kpi->delete();

        return response()->json([
            'message' => 'تم حذف مؤشر الأداء بنجاح',
        ]);
    }

    /**
     * Engine-based gate: maps a controller-level ability to the matching
     * Capability constant. Authorization is decided by the unified AuthZ
     * engine (AccessDecision::can), not by a flat Spatie permission name.
     *
     * CFA-02: 'export' ability accepts EITHER Capability::KPIS_EXPORT (the new
     * canonical export capability, paired with CLUSTER_TREE_EXPORT for the
     * cluster rescue branch) OR Capability::KPIS_VIEW (backward-compat for
     * existing users who only hold KPIS_VIEW). This preserves the pre-CFA-02
     * same-org export behavior while introducing the new export capability
     * for cluster widening.
     */
    protected function authorizePerformance(string $ability = 'view'): void
    {
        $user = auth()->user();

        if ($ability === 'export') {
            // CFA-02: KPIS_EXPORT (new canonical, gates cross-org export) OR
            // KPIS_VIEW (backward-compat for same-org export only).
            if (! AccessDecision::can($user, Capability::KPIS_EXPORT)
                && ! AccessDecision::can($user, Capability::KPIS_VIEW)) {
                abort(403, 'ليس لديك صلاحية الوصول');
            }

            return;
        }

        $capability = match ($ability) {
            'create', 'update', 'delete' => Capability::KPIS_MANAGE,
            default => Capability::KPIS_VIEW,
        };

        if (! AccessDecision::can($user, $capability)) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }
    }

    protected function scopeToCurrentOrganization($query, ?string $purpose = null): void
    {
        $user = auth()->user();

        if ($user?->isSuperAdmin()) {
            return;
        }

        if ($user?->organization_id === null) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        // Phase 4 + CFA-02: delegate to UserKpiScope (single source of truth
        // for KPI org floor). The optional $purpose controls which cluster_tree
        // widening pair applies — null uses the 9-D-D1a read pair;
        // 'export' uses the CFA-02 export pair.
        app(UserKpiScope::class)->applyToKpis($query, $user, $purpose);
    }

    /**
     * @param  string|null  $purpose  Optional purpose forwarded to
     *                                UserKpiScope::applyToKpis for export widening.
     *                                null ⇒ read pair (default).
     *                                'export' ⇒ CFA-02 export pair.
     * @return Builder<Kpi>
     */
    private function filteredKpiQuery(Request $request, ?string $purpose = null): Builder
    {
        $query = Kpi::query();
        $this->scopeToCurrentOrganization($query, $purpose);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('order')->orderBy('created_at', 'desc');
    }

    protected function organizationIdForWrite(?int $requestedOrganizationId = null): int
    {
        $user = auth()->user();

        if ($user?->isSuperAdmin()) {
            $organizationId = $requestedOrganizationId ?? $user->organization_id;

            if ($organizationId === null) {
                abort(422, 'يجب تحديد المنظمة لمؤشر الأداء');
            }

            return (int) $organizationId;
        }

        if ($user?->organization_id === null) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        return (int) $user->organization_id;
    }

    private function orgScopedUserRule()
    {
        $user = auth()->user();
        $rule = Rule::exists('users', 'id');

        if ($user?->isSuperAdmin()) {
            return $rule;
        }

        if ($user?->organization_id === null) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        return $rule->where('organization_id', $user->organization_id);
    }

    private function rules(Request $request, ?Kpi $kpi = null): array
    {
        $user = $request->user();
        $requiresOrganization = $kpi === null
            && $user?->isSuperAdmin()
            && $user->organization_id === null;

        $codeRule = Rule::unique('kpis', 'code');

        if ($kpi !== null) {
            $codeRule->ignore($kpi->id);
        }

        return [
            'organization_id' => [$requiresOrganization ? 'required' : 'nullable', 'integer', Rule::exists('organizations', 'id')],
            'code' => ['nullable', 'string', 'max:40', $codeRule],
            'name' => $kpi === null
                ? ['required', 'string', 'max:255']
                : ['sometimes', 'required', 'string', 'max:255'],
            'description' => 'nullable|string',
            'measurement_method' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:100',
            'baseline' => 'nullable|numeric|min:-1000000000000|max:1000000000000',
            'target' => 'nullable|numeric|min:-1000000000000|max:1000000000000',
            'current_value' => 'nullable|numeric|min:-1000000000000|max:1000000000000',
            'unit' => 'nullable|string|max:50',
            'frequency' => ['nullable', Rule::in(array_keys(Kpi::FREQUENCY_LABELS))],
            'direction' => ['nullable', Rule::in(array_keys(Kpi::DIRECTION_LABELS))],
            'status' => 'nullable|in:active,inactive,archived',
            'owner_id' => ['nullable', $this->orgScopedUserRule()],
            'order' => 'nullable|integer|min:0|max:65535',
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }

    private function validatedDepartmentIds(array $departmentIds, int $organizationId): array
    {
        $ids = array_values(array_unique(array_map('intval', $departmentIds)));

        if ($ids === []) {
            return [];
        }

        $validCount = Department::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->count();

        if ($validCount !== count($ids)) {
            throw ValidationException::withMessages([
                'department_ids' => 'يجب اختيار إدارات من نفس مؤسسة مؤشر الأداء',
            ]);
        }

        return $ids;
    }

    private function syncDepartmentLinks(Kpi $kpi, array $departmentIds): void
    {
        $query = KpiLink::query()
            ->where('kpi_id', $kpi->id)
            ->where('linkable_type', Department::class);

        if ($departmentIds === []) {
            $query->delete();

            return;
        }

        $query->whereNotIn('linkable_id', $departmentIds)->delete();

        foreach ($departmentIds as $departmentId) {
            $link = KpiLink::withTrashed()->firstOrNew([
                'kpi_id' => $kpi->id,
                'linkable_type' => Department::class,
                'linkable_id' => $departmentId,
            ]);

            if ($link->exists && $link->trashed()) {
                $link->restore();
            }

            $link->fill([
                'relationship_type' => 'department',
                'weight' => null,
                'notes' => null,
                'created_by' => $link->exists ? $link->created_by : auth()->id(),
            ]);
            $link->forceFill([
                'organization_id' => $kpi->organization_id,
            ])->save();
        }
    }
}
