<?php

namespace App\Modules\RiskManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Enums\RiskActionType;
use App\Modules\RiskManagement\Enums\RiskResponseType;
use App\Modules\RiskManagement\Enums\RiskStatus;
use App\Modules\RiskManagement\Enums\RiskType;
use App\Modules\RiskManagement\Http\Requests\ChangeRiskStatusRequest;
use App\Modules\RiskManagement\Http\Requests\DestroyRiskRequest;
use App\Modules\RiskManagement\Http\Requests\StoreRiskRequest;
use App\Modules\RiskManagement\Http\Requests\UpdateRiskRequest;
use App\Modules\RiskManagement\Http\Resources\RiskResource;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\RiskManagement\Scopes\UserRiskScope;
use App\Modules\RiskManagement\Services\RiskAuthorizationService;
use App\Modules\RiskManagement\Services\RiskLifecycleService;
use App\Modules\RiskManagement\Services\RiskScoreCalculator;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class RiskController extends Controller
{
    use HasOrganizationScope;

    public function __construct(
        protected RiskLifecycleService $lifecycle
    ) {}

    /**
     * Engine-based gate: maps a controller-level ability to the matching
     * Capability constant. Authorization is decided by the unified AuthZ
     * engine (AccessDecision::can), not by a flat Spatie permission name.
     */
    protected function authorizeRisk(string $ability = 'view'): void
    {
        $user = auth()->user();
        $capability = match ($ability) {
            'create' => Capability::RISKS_CREATE,
            'update' => Capability::RISKS_EDIT,
            'delete' => Capability::RISKS_DELETE,
            'reassess' => Capability::RISKS_REASSESS,
            'changeStatus' => Capability::RISKS_CHANGE_STATUS,
            'reports' => Capability::RISKS_VIEW_REPORTS,
            default => Capability::RISKS_VIEW,
        };

        if (! AccessDecision::can($user, $capability)) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }
    }

    private function scopedUserRule(): Exists
    {
        $rule = Rule::exists('users', 'id');
        $user = auth()->user();

        if ($user?->isSuperAdmin()) {
            return $rule;
        }

        if ($user?->organization_id === null) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        return $rule->where('organization_id', $user->organization_id);
    }

    private function orgFilter(): callable
    {
        $user = auth()->user();

        if ($user?->isSuperAdmin()) {
            return fn ($q) => $q;
        }

        if ($user?->organization_id === null) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        return fn ($q) => $q->where('organization_id', $user->organization_id);
    }

    public function index(Request $request): JsonResponse
    {
        // Engine-aware list gate: anyone the engine grants risk visibility to
        // (department-scoped, governing, or org-level). UserRiskScope below then
        // narrows the rows. The flat-permission gate would wrongly exclude
        // department-scoped members.
        if (! app(RiskAuthorizationService::class)->canViewAny($request->user())) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }

        // department is eager-loaded in full (not id,name) so AccessDecision can
        // reuse the loaded relation for the scope chain instead of issuing a
        // per-record fetch; the resource still surfaces only id/name from it.
        $query = Risk::query()
            ->with(['department', 'owner:id,name', 'creator:id,name'])
            ->withCount('actions');

        // Per-record visibility (department subtree / governing / direct relation).
        // Replaces the previous org-only filter that over-fetched every risk.
        (new UserRiskScope)->apply($query, $request->user());

        if ($request->filled('level')) {
            $query->where('current_level', $request->string('level'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        if ($request->filled('owner_id')) {
            $query->where('owner_id', $request->integer('owner_id'));
        }

        if ($request->filled('score_min')) {
            $query->where('current_score', '>=', $request->integer('score_min'));
        }

        if ($request->filled('score_max')) {
            $query->where('current_score', '<=', $request->integer('score_max'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $risks = $query->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page') ?: 15, 100));

        return response()->json($risks->through(fn ($r) => (new RiskResource($r))->resolve()));
    }

    public function create(): JsonResponse
    {
        if (! app(RiskAuthorizationService::class)->canCreateAny(auth()->user())) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }

        $scoreScale = collect(range(1, 5))->map(fn (int $n) => [
            'value' => (string) $n,
            'label' => $n.' — '.$this->scoreLabel($n),
        ])->all();

        return response()->json([
            'data' => [
                'types' => collect(RiskType::cases())->map(fn (RiskType $t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                ])->all(),
                'response_types' => collect(RiskResponseType::cases())->map(fn (RiskResponseType $r) => [
                    'value' => $r->value,
                    'label' => $r->label(),
                ])->all(),
                'likelihood_scale' => $scoreScale,
                'impact_scale' => $scoreScale,
                'statuses' => collect(RiskStatus::cases())->map(fn (RiskStatus $s) => [
                    'value' => $s->value,
                    'label' => $s->label(),
                ])->all(),
                'default_status' => RiskStatus::Open->value,
            ],
        ]);
    }

    /**
     * Departments the current user may target when creating a risk. Powers the
     * create-form department picker; the store() path enforces the same scope.
     */
    public function creatableDepartments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return response()->json(['message' => 'المستخدم لا ينتمي لمؤسسة'], 403);
        }

        $allowedIds = app(RiskAuthorizationService::class)->creatableDepartmentIds($user);

        $query = Department::query()
            ->active()
            ->forOrganization($user->isSuperAdmin() ? null : $user->organization_id)
            ->select('id', 'name', 'code', 'parent_id', 'level')
            ->orderBy('level')
            ->orderBy('name');

        if ($allowedIds !== null) {
            $query->whereIn('id', $allowedIds === [] ? [-1] : $allowedIds);
        }

        $departments = $query->get()->map(fn ($dept) => [
            'id' => $dept->id,
            'name' => $dept->name,
            'code' => $dept->code,
            'parent_id' => $dept->parent_id,
            'level' => $dept->level,
            'level_name' => $dept->getLevelNameAttribute(),
        ]);

        return response()->json(['all' => $departments]);
    }

    private function scoreLabel(int $n): string
    {
        return match ($n) {
            1 => 'منخفض جداً',
            2 => 'منخفض',
            3 => 'متوسط',
            4 => 'مرتفع',
            default => 'مرتفع جداً',
        };
    }

    public function store(StoreRiskRequest $request): JsonResponse
    {
        // Authorization (incl. target-department scope) is enforced by
        // StoreRiskRequest::authorize via RiskAuthorizationService::canCreate.
        $data = $request->validated();
        $user = $request->user();

        // Default the risk to the creator's own department when none is supplied
        // (keeps a department creator's risk inside their visibility subtree).
        if (empty($data['department_id']) && $user->department_id) {
            $data['department_id'] = $user->department_id;
        }

        $riskOrgId = $user->isSuperAdmin()
            ? ($data['organization_id'] ?? $user->organization_id)
            : $user->organization_id;

        if ($riskOrgId === null && ! $user->isSuperAdmin()) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        $initial = app(RiskScoreCalculator::class)
            ->calculate((int) $data['initial_likelihood'], (int) $data['initial_impact']);

        $riskableType = null;
        if (! empty($data['riskable_type']) && ! empty($data['riskable_id'])) {
            $riskableType = $request->resolveRiskableClass($data['riskable_type']);
        }

        $risk = DB::transaction(function () use ($data, $riskOrgId, $user, $initial, $riskableType) {
            $risk = new Risk;
            $risk->forceFill([
                'organization_id' => $riskOrgId,
                'title' => $data['title'],
                'discovery_date' => $data['discovery_date'],
                'type' => $data['type'],
                'department_id' => $data['department_id'] ?? null,
                'description' => $data['description'] ?? null,
                'consequences' => $data['consequences'] ?? null,
                'initial_likelihood' => $data['initial_likelihood'],
                'initial_impact' => $data['initial_impact'],
                'current_likelihood' => $data['initial_likelihood'],
                'current_impact' => $data['initial_impact'],
                'current_score' => $initial['score'],
                'current_level' => $initial['level']->value,
                'status' => RiskStatus::Open->value,
                'owner_id' => $data['owner_id'] ?? $user->id,
                'stakeholder_ids' => $data['stakeholder_ids'] ?? null,
                'preventive_measures' => $data['preventive_measures'] ?? null,
                'target_close_date' => $data['target_close_date'] ?? null,
                'response_type' => $data['response_type'] ?? RiskResponseType::Mitigate->value,
                'riskable_type' => $riskableType,
                'riskable_id' => $riskableType ? $data['riskable_id'] : null,
                'created_by' => $user->id,
            ])->save();
            $risk = Risk::find($risk->id);

            if (! empty($data['actions'])) {
                foreach ($data['actions'] as $item) {
                    if (empty($item['title'])) {
                        continue;
                    }
                    RiskAction::create([
                        'risk_id' => $risk->id,
                        'organization_id' => $riskOrgId,
                        'title' => $item['title'],
                        'type' => RiskActionType::Preventive->value,
                        'owner_id' => $item['owner_id'] ?? null,
                        'due_date' => $item['due_date'] ?? null,
                        'status' => RiskActionStatus::Pending->value,
                    ]);
                }
            }

            return $risk;
        });

        return response()->json([
            'message' => 'تم تسجيل الخطر بنجاح',
            'data' => new RiskResource($risk->load(['department:id,name', 'owner:id,name', 'creator:id,name'])),
        ], 201);
    }

    public function show(Risk $risk): JsonResponse
    {
        // Phase CFA-05 — Policy-driven two-path view covers same-org AND
        // cluster_tree rescue. Replacing the inline assertSameOrganization()
        // call so cluster rescue on RISKS_VIEW + CLUSTER_TREE_VIEW can fire
        // (assertSameOrganization would otherwise cancel the rescue by
        // re-asserting strict equality). Engine still gates null-org actors
        // inside AccessDecision::can(), so cross-tenant reads remain blocked.
        $this->authorize('view', $risk);

        $risk->load([
            'department:id,name',
            'owner:id,name',
            'creator:id,name',
            'riskable',
            'assessments.assessor:id,name',
            'actions.owner:id,name',
            'actions.updates.user:id,name',
            'statusChanges.changer:id,name',
        ]);

        return response()->json([
            'data' => new RiskResource($risk),
        ]);
    }

    public function update(UpdateRiskRequest $request, Risk $risk): JsonResponse
    {
        $this->authorizeRisk('update');
        $this->assertSameOrganization($risk);

        $data = $request->validated();

        if (! empty($data['riskable_type']) && ! empty($data['riskable_id'])) {
            $class = $request->resolveRiskableClass($data['riskable_type']);
            $data['riskable_type'] = $class;
        } elseif (array_key_exists('riskable_type', $data) && empty($data['riskable_type'])) {
            $data['riskable_type'] = null;
            $data['riskable_id'] = null;
        }

        $risk->update($data);

        return response()->json([
            'message' => 'تم تحديث الخطر بنجاح',
            'data' => new RiskResource($risk->fresh()->load(['department:id,name', 'owner:id,name'])),
        ]);
    }

    public function destroy(DestroyRiskRequest $request, Risk $risk): JsonResponse
    {
        $this->authorizeRisk('delete');
        $this->assertSameOrganization($risk);

        $risk->delete();

        return response()->json(['message' => 'تم حذف الخطر بنجاح']);
    }

    public function changeStatus(ChangeRiskStatusRequest $request, Risk $risk): JsonResponse
    {
        // Phase CFA-05 — Policy-driven two-path changeStatus (cluster rescue
        // via RISKS_CHANGE_STATUS + CLUSTER_TREE_MANAGE). Replacing the prior
        // authorizeRisk('changeStatus') (flat) + assertSameOrganization pair
        // so the cluster widening takes effect; the policy already enforces
        // same-org via the engine when no cluster grants are held.
        $this->authorize('changeStatus', $risk);

        $change = $this->lifecycle->changeStatus(
            $risk,
            RiskStatus::from($request->string('to_status')),
            $request->user(),
            $request->input('reason')
        );

        return response()->json([
            'message' => 'تم تغيير حالة الخطر بنجاح',
            'data' => $change,
        ], 201);
    }

    public function statusHistory(Risk $risk): JsonResponse
    {
        $this->authorizeRisk('view');
        $this->assertSameOrganization($risk);

        return response()->json($risk->statusChanges()->with('changer:id,name')->get());
    }
}
