<?php

namespace App\Modules\RiskManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Http\Requests\DestroyRiskActionRequest;
use App\Modules\RiskManagement\Http\Requests\StoreRiskActionRequest;
use App\Modules\RiskManagement\Http\Requests\StoreRiskActionUpdateRequest;
use App\Modules\RiskManagement\Http\Requests\UpdateRiskActionRequest;
use App\Modules\RiskManagement\Http\Resources\RiskActionResource;
use App\Modules\RiskManagement\Http\Resources\RiskActionUpdateResource;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RiskActionController extends Controller
{
    use HasOrganizationScope;

    /**
     * Engine-based gate for action-level operations. create/update share the
     * RISKS_EDIT capability (creating an action mutates a risk the same as
     * editing it), delete uses RISKS_DELETE, everything else is RISKS_VIEW.
     */
    private function authorizeAction(string $ability): void
    {
        $user = auth()->user();
        $capability = match ($ability) {
            'create', 'update' => Capability::RISKS_EDIT,
            'delete' => Capability::RISKS_DELETE,
            default => Capability::RISKS_VIEW,
        };

        if (! AccessDecision::can($user, $capability)) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }
    }

    public function show(RiskAction $action): JsonResponse
    {
        $this->authorizeAction('view');
        $this->assertSameOrganization($action);

        return response()->json([
            'data' => new RiskActionResource(
                $action->load(['owner:id,name', 'updates.user:id,name'])
            ),
        ]);
    }

    public function store(StoreRiskActionRequest $request, Risk $risk): JsonResponse
    {
        $this->authorizeAction('create');
        $this->assertSameOrganization($risk);

        $data = $request->validated();
        $user = $request->user();

        $action = RiskAction::create([
            'risk_id' => $risk->id,
            'organization_id' => $risk->organization_id,
            'title' => $data['title'],
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'owner_id' => $data['owner_id'] ?? $user->id,
            'due_date' => $data['due_date'] ?? null,
            'status' => $data['status'] ?? RiskActionStatus::Pending->value,
        ]);

        return response()->json([
            'message' => 'تم إنشاء الإجراء بنجاح',
            'data' => new RiskActionResource($action->load('owner:id,name')),
        ], 201);
    }

    public function update(UpdateRiskActionRequest $request, RiskAction $action): JsonResponse
    {
        $this->authorizeAction('update');
        $this->assertSameOrganization($action);

        $action->update($request->validated());

        return response()->json([
            'message' => 'تم تحديث الإجراء بنجاح',
            'data' => new RiskActionResource($action->fresh()->load('owner:id,name')),
        ]);
    }

    public function destroy(DestroyRiskActionRequest $request, RiskAction $action): JsonResponse
    {
        $this->authorizeAction('delete');
        $this->assertSameOrganization($action);

        $action->delete();

        return response()->json(['message' => 'تم حذف الإجراء بنجاح']);
    }

    public function addUpdate(StoreRiskActionUpdateRequest $request, RiskAction $action): JsonResponse
    {
        $this->authorizeAction('update');
        $this->assertSameOrganization($action);

        $data = $request->validated();
        $user = $request->user();

        return DB::transaction(function () use ($action, $data, $user) {
            $update = $action->updates()->create([
                'organization_id' => $action->organization_id,
                'user_id' => $user->id,
                'progress_pct' => $data['progress_pct'] ?? null,
                'status' => $data['status'] ?? null,
                'notes' => $data['notes'],
            ]);

            if (! empty($data['progress_pct'])) {
                $action->forceFill(['progress_pct' => $data['progress_pct']])->save();
            }

            if (! empty($data['status'])) {
                $action->forceFill(['status' => $data['status']])->save();
            }

            return response()->json([
                'message' => 'تم تسجيل التحديث بنجاح',
                'data' => new RiskActionUpdateResource($update->load('user:id,name')),
            ], 201);
        });
    }

    public function listUpdates(RiskAction $action): JsonResponse
    {
        $this->authorizeAction('view');
        $this->assertSameOrganization($action);

        return response()->json($action->updates()->with('user:id,name')->get());
    }
}
