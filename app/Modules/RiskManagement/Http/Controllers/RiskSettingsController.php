<?php

namespace App\Modules\RiskManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Http\Requests\DestroyImpactTypeRequest;
use App\Modules\RiskManagement\Http\Requests\DestroyRiskTypeRequest;
use App\Modules\RiskManagement\Http\Requests\StoreImpactTypeRequest;
use App\Modules\RiskManagement\Http\Requests\StoreRiskTypeRequest;
use App\Modules\RiskManagement\Http\Requests\UpdateGoverningDepartmentRequest;
use App\Modules\RiskManagement\Http\Requests\UpdateImpactTypeRequest;
use App\Modules\RiskManagement\Http\Requests\UpdateRiskTypeRequest;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskImpactType;
use App\Modules\RiskManagement\Models\RiskSetting;
use App\Modules\RiskManagement\Models\RiskType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RiskSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Engine-based gate (Wave 3 task 4): Capability::RISKS_EDIT, not the
        // legacy Spatie 'edit_risks' permission. super_admin still bypasses
        // because the engine grants super_admin every capability.
        if ($user === null || ! AccessDecision::can($user, Capability::RISKS_EDIT)) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }

        return response()->json([
            'data' => [
                'risk_types' => RiskType::query()
                    ->orderBy('sort_order')
                    ->orderBy('label')
                    ->get()
                    ->map(fn (RiskType $riskType) => $this->riskTypePayload($riskType))
                    ->all(),
                'impact_types' => RiskImpactType::query()
                    ->orderBy('sort_order')
                    ->orderBy('value')
                    ->get()
                    ->map(fn (RiskImpactType $impactType) => $this->impactTypePayload($impactType))
                    ->all(),
            ],
        ]);
    }

    public function storeRiskType(StoreRiskTypeRequest $request): JsonResponse
    {
        $riskType = RiskType::create($request->validated());

        return response()->json([
            'message' => 'تم إنشاء نوع الخطر بنجاح',
            'data' => $this->riskTypePayload($riskType),
        ], 201);
    }

    public function updateRiskType(UpdateRiskTypeRequest $request, RiskType $riskType): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['value']) && $data['value'] !== $riskType->value && $this->riskTypeIsUsed($riskType->value)) {
            throw ValidationException::withMessages([
                'value' => 'لا يمكن تغيير قيمة نوع خطر مستخدم في سجلات قائمة',
            ]);
        }

        $riskType->update($data);

        return response()->json([
            'message' => 'تم تحديث نوع الخطر بنجاح',
            'data' => $this->riskTypePayload($riskType->fresh()),
        ]);
    }

    public function destroyRiskType(DestroyRiskTypeRequest $request, RiskType $riskType): JsonResponse
    {
        if ($this->riskTypeIsUsed($riskType->value)) {
            throw ValidationException::withMessages([
                'risk_type' => 'لا يمكن حذف نوع خطر مستخدم في سجلات قائمة',
            ]);
        }

        $riskType->delete();

        return response()->json([
            'message' => 'تم حذف نوع الخطر بنجاح',
        ]);
    }

    public function updateImpactType(UpdateImpactTypeRequest $request, RiskImpactType $impactType): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['value']) && $data['value'] !== $impactType->value && $this->impactTypeIsUsed($impactType->value)) {
            throw ValidationException::withMessages([
                'value' => 'لا يمكن تغيير قيمة نوع أثر مستخدم في سجلات قائمة',
            ]);
        }

        $impactType->update($data);

        return response()->json([
            'message' => 'تم تحديث نوع الأثر بنجاح',
            'data' => $this->impactTypePayload($impactType->fresh()),
        ]);
    }

    public function storeImpactType(StoreImpactTypeRequest $request): JsonResponse
    {
        $impactType = RiskImpactType::create($request->validated());

        return response()->json([
            'message' => 'تم إنشاء نوع الأثر بنجاح',
            'data' => $this->impactTypePayload($impactType),
        ], 201);
    }

    public function destroyImpactType(DestroyImpactTypeRequest $request, RiskImpactType $impactType): JsonResponse
    {
        if ($this->impactTypeIsUsed($impactType->value)) {
            throw ValidationException::withMessages([
                'impact_type' => 'لا يمكن حذف نوع أثر مستخدم في سجلات قائمة',
            ]);
        }

        $impactType->delete();

        return response()->json([
            'message' => 'تم حذف نوع الأثر بنجاح',
        ]);
    }

    /**
     * Read the configured governing department for risks plus the selectable
     * departments. Admin-gated (super_admin / manage_organization).
     */
    public function getGoverningDepartment(Request $request): JsonResponse
    {
        $user = $request->user();

        // Engine-based gate (Wave 3 task 4): Capability::SETTINGS_MANAGE, not
        // the legacy Spatie 'manage_organization' permission. super_admin
        // still bypasses via the engine.
        if ($user === null || ! AccessDecision::can($user, Capability::SETTINGS_MANAGE)) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }

        $departments = Department::query()
            ->active()
            ->forOrganization($user->isSuperAdmin() ? null : $user->organization_id)
            ->select('id', 'name', 'code', 'level')
            ->orderBy('level')
            ->orderBy('name')
            ->get()
            ->map(fn ($dept) => [
                'id' => $dept->id,
                'name' => $dept->name,
                'code' => $dept->code,
                'level' => $dept->level,
                'level_name' => $dept->getLevelNameAttribute(),
            ]);

        return response()->json([
            'department_id' => RiskSetting::getGoverningDepartmentId(),
            'departments' => $departments,
        ]);
    }

    /**
     * Update (or clear) the governing department for risks. Admin-gated.
     */
    public function updateGoverningDepartment(UpdateGoverningDepartmentRequest $request): JsonResponse
    {
        $departmentId = $request->validated()['department_id'] ?? null;

        RiskSetting::setGoverningDepartmentId($departmentId !== null ? (int) $departmentId : null);

        return response()->json([
            'message' => 'تم تحديث القسم المُشرِف على المخاطر بنجاح',
            'department_id' => RiskSetting::getGoverningDepartmentId(),
        ]);
    }

    private function riskTypeIsUsed(string $value): bool
    {
        return Risk::withTrashed()->where('type', $value)->exists();
    }

    private function impactTypeIsUsed(string $value): bool
    {
        return Risk::withTrashed()
            ->whereNotNull('impact_details')
            ->whereRaw('impact_details::jsonb @> ?::jsonb', [json_encode([['type' => $value]])])
            ->exists();
    }

    private function riskTypePayload(RiskType $riskType): array
    {
        return [
            'id' => $riskType->id,
            'value' => $riskType->value,
            'label' => $riskType->label,
            'is_active' => $riskType->is_active,
            'sort_order' => $riskType->sort_order,
        ];
    }

    private function impactTypePayload(RiskImpactType $impactType): array
    {
        return [
            'id' => $impactType->id,
            'value' => $impactType->value,
            'label' => $impactType->label,
            'is_active' => $impactType->is_active,
            'sort_order' => $impactType->sort_order,
        ];
    }
}
