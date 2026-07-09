<?php

namespace App\Modules\Strategy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Traits\HasOrganizationScope;
use App\Modules\Strategy\Http\Requests\DeleteBlockerRequest;
use App\Modules\Strategy\Http\Requests\EscalateBlockerRequest;
use App\Modules\Strategy\Http\Requests\ListBlockersRequest;
use App\Modules\Strategy\Http\Requests\ResolveBlockerRequest;
use App\Modules\Strategy\Http\Requests\StoreBlockerRequest;
use App\Modules\Strategy\Http\Requests\UpdateBlockerRequest;
use App\Modules\Strategy\Http\Requests\ViewBlockerRequest;
use App\Modules\Strategy\Models\Blocker;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Scopes\UserStrategyScope;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\JsonResponse;

class BlockerController extends Controller
{
    use HasOrganizationScope;

    /**
     * Verify the actor's strategy authorization.
     */
    protected function authorizeStrategy(string $ability = 'view'): void
    {
        $user = auth()->user();

        if ($user?->isSuperAdmin()) {
            return;
        }

        $capability = match ($ability) {
            'create' => Capability::STRATEGY_CREATE,
            'update' => Capability::STRATEGY_EDIT,
            'delete' => Capability::STRATEGY_DELETE,
            default => Capability::STRATEGY_VIEW,
        };

        if (! $user || ! AccessDecision::can($user, $capability)) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }
    }

    /**
     * Display a listing of blockers.
     */
    public function index(ListBlockersRequest $request): JsonResponse
    {
        // Authz (STRATEGY_VIEW) owned by ListBlockersRequest.
        // Phase 9-D-D1b: cluster_tree read widening via UserStrategyScope.

        $query = Blocker::query()
            ->with(['reporter:id,name', 'assignee:id,name']);

        $user = auth()->user();
        if (! $user) {
            abort(401);
        }

        app(UserStrategyScope::class)->applyToBlockers($query, $user);

        // Filter by blockable type
        if ($request->has('type') && $request->has('id')) {
            $query->where('blockable_type', $this->getModelClass($request->type))
                ->where('blockable_id', $request->id);
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filter by severity
        if ($request->has('severity') && $request->severity) {
            $query->where('severity', $request->severity);
        }

        // Only open blockers
        if ($request->boolean('open_only')) {
            $query->open();
        }

        // Only overdue blockers
        if ($request->boolean('overdue_only')) {
            $query->overdue();
        }

        $blockers = $query->orderByRaw("CASE status WHEN 'escalated' THEN 1 WHEN 'open' THEN 2 WHEN 'in_progress' THEN 3 WHEN 'resolved' THEN 4 ELSE 5 END")
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->paginate(min((int) $request->get('per_page', 15), 100));

        // Add computed fields
        $blockers->getCollection()->transform(function ($blocker) {
            $blocker->severity_label = $blocker->severity_label;
            $blocker->status_label = $blocker->status_label;
            $blocker->is_overdue = $blocker->is_overdue;
            $blocker->days_overdue = $blocker->days_overdue;

            return $blocker;
        });

        return response()->json($blockers);
    }

    /**
     * Store a newly created blocker.
     */
    public function store(StoreBlockerRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Verify the blockable exists
        $modelClass = $this->getModelClass($validated['blockable_type']);
        $blockable = $modelClass::find($validated['blockable_id']);

        if (! $blockable) {
            return response()->json([
                'message' => 'العنصر المرتبط غير موجود',
            ], 422);
        }

        $this->assertSameOrganization($blockable);

        // Org-isolation floor: a blocker with a null organization_id becomes
        // invisible to every org-scoped query, so refuse to save it instead
        // of creating an orphan. Validation-style 422.
        if ($blockable->organization_id === null) {
            return response()->json([
                'message' => 'لا يمكن تسجيل تعثر على عنصر غير مرتبط بمؤسسة',
                'errors' => ['blockable_id' => ['العنصر المرتبط يجب أن يكون مرتبطًا بمؤسسة']],
            ], 422);
        }

        $validated['blockable_type'] = $modelClass;
        $validated['reported_by'] = auth()->id();
        $validated['status'] = 'open';
        $validated['severity'] = $validated['severity'] ?? 'medium';

        $blocker = Blocker::create($validated);
        $blocker->forceFill(['organization_id' => $blockable->organization_id])->save();

        return response()->json([
            'message' => 'تم تسجيل التعثر بنجاح',
            'blocker' => $blocker->load(['reporter:id,name', 'assignee:id,name']),
        ], 201);
    }

    /**
     * Display the specified blocker.
     */
    public function show(ViewBlockerRequest $request, Blocker $blocker): JsonResponse
    {
        // Authz (STRATEGY_VIEW on blocker) + org-isolation floor owned by
        // ViewBlockerRequest.

        $blocker->load(['reporter:id,name', 'assignee:id,name', 'blockable']);

        $blocker->severity_label = $blocker->severity_label;
        $blocker->status_label = $blocker->status_label;
        $blocker->is_overdue = $blocker->is_overdue;
        $blocker->days_overdue = $blocker->days_overdue;

        return response()->json($blocker);
    }

    /**
     * Update the specified blocker.
     */
    public function update(UpdateBlockerRequest $request, Blocker $blocker): JsonResponse
    {
        $this->assertSameOrganization($blocker);

        $validated = $request->validated();

        $blocker->update($validated);

        return response()->json([
            'message' => 'تم تحديث التعثر بنجاح',
            'blocker' => $blocker->fresh()->load(['reporter:id,name', 'assignee:id,name']),
        ]);
    }

    /**
     * Remove the specified blocker.
     */
    public function destroy(DeleteBlockerRequest $request, Blocker $blocker): JsonResponse
    {
        // Authz (STRATEGY_DELETE on blocker) + org-isolation floor owned by
        // DeleteBlockerRequest.

        $blocker->delete();

        return response()->json([
            'message' => 'تم حذف التعثر بنجاح',
        ]);
    }

    /**
     * Resolve a blocker.
     */
    public function resolve(ResolveBlockerRequest $request, Blocker $blocker): JsonResponse
    {
        // Authz (STRATEGY_EDIT on blocker) + org-isolation floor + `resolution`
        // validation all owned by ResolveBlockerRequest.
        $validated = $request->validated();

        $blocker->resolve($validated['resolution']);

        return response()->json([
            'message' => 'تم حل التعثر بنجاح',
            'blocker' => $blocker->fresh(),
        ]);
    }

    /**
     * Escalate a blocker.
     */
    public function escalate(EscalateBlockerRequest $request, Blocker $blocker): JsonResponse
    {
        // Authz (STRATEGY_EDIT on blocker) + org-isolation floor owned by
        // EscalateBlockerRequest. Mirrors resolve() so both status-transition
        // endpoints pass through the same target-bound engine check.
        $blocker->escalate();

        return response()->json([
            'message' => 'تم تصعيد التعثر بنجاح',
            'blocker' => $blocker->fresh(),
        ]);
    }

    /**
     * Get the model class for a blockable type.
     */
    private function getModelClass(string $type): string
    {
        return match ($type) {
            'program' => Program::class,
            'project' => Project::class,
            'task' => Task::class,
            default => throw new \InvalidArgumentException('Invalid blockable entity type'),
        };
    }
}
