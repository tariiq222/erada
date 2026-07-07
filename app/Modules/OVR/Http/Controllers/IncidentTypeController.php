<?php

namespace App\Modules\OVR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\OVR\Http\Requests\DestroyIncidentTypeRequest;
use App\Modules\OVR\Http\Requests\StoreIncidentTypeRequest;
use App\Modules\OVR\Http\Requests\StoreReportableTypeRequest;
use App\Modules\OVR\Http\Requests\UpdateIncidentTypeRequest;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // دفاع عميق: فحص الصلاحية في الـ Controller أيضاً
        $user = $request->user();
        if (! AccessDecision::can($user, Capability::OVR_VIEW)) {
            return response()->json(['message' => __('ovr.api.view_incident_types_forbidden')], 403);
        }

        $types = IncidentType::query()
            ->where('is_active', true)
            ->forOrganization($request->user()->organization_id)
            ->with(['reportableTypes' => function ($q) use ($request) {
                $q->forOrganization($request->user()->organization_id);
            }])
            ->orderBy('name_ar')
            ->get();

        return response()->json([
            'data' => $types,
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        // دفاع عميق: فحص الصلاحية في الـ Controller أيضاً
        $user = $request->user();
        if (! AccessDecision::can($user, Capability::OVR_VIEW)) {
            return response()->json(['message' => __('ovr.api.view_incident_types_forbidden')], 403);
        }

        $types = IncidentType::query()
            ->where('is_active', true)
            ->forOrganization($request->user()->organization_id)
            ->select('id', 'name', 'name_ar')
            ->orderBy('name_ar')
            ->get();

        return response()->json($types);
    }

    public function store(StoreIncidentTypeRequest $request): JsonResponse
    {
        // Authorization is handled by StoreIncidentTypeRequest::authorize()
        // (OVR_MANAGE_TYPES via engine).
        $type = IncidentType::create($request->only(['name', 'name_ar', 'is_active']));

        // Audit log: إنشاء نوع حادثة
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'incident_type_created',
            'description' => __('ovr.api.activity.incident_type_created', ['name' => $type->name]),
            'loggable_type' => IncidentType::class,
            'loggable_id' => $type->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => __('ovr.api.incident_type_created'),
            'data' => $type,
        ], 201);
    }

    public function update(UpdateIncidentTypeRequest $request, IncidentType $type): JsonResponse
    {
        // Authorization is handled by UpdateIncidentTypeRequest::authorize()
        // (OVR_MANAGE_TYPES via engine).

        $oldValues = $type->only(['name', 'name_ar', 'is_active']);
        $type->update($request->only(['name', 'name_ar', 'is_active']));

        // Audit log: تحديث نوع حادثة
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'incident_type_updated',
            'description' => __('ovr.api.activity.incident_type_updated', ['name' => $type->name]),
            'loggable_type' => IncidentType::class,
            'loggable_id' => $type->id,
            'old_values' => $oldValues,
            'new_values' => $type->only(['name', 'name_ar', 'is_active']),
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => __('ovr.api.incident_type_updated'),
            'data' => $type,
        ]);
    }

    public function destroy(DestroyIncidentTypeRequest $request, IncidentType $type): JsonResponse
    {
        // Authorization is handled by DestroyIncidentTypeRequest::authorize()
        // (OVR_MANAGE_TYPES via engine).

        // حفظ المعلومات قبل الحذف للتسجيل
        $typeId = $type->id;
        $typeName = $type->name;
        $typeData = $type->only(['name', 'name_ar', 'is_active']);

        $type->delete();

        // Audit log: حذف نوع حادثة
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'incident_type_deleted',
            'description' => __('ovr.api.activity.incident_type_deleted', ['name' => $typeName]),
            'loggable_type' => IncidentType::class,
            'loggable_id' => $typeId,
            'old_values' => $typeData,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => __('ovr.api.incident_type_deleted'),
        ]);
    }

    public function storeReportableType(StoreReportableTypeRequest $request, IncidentType $type): JsonResponse
    {
        // Authorization is handled by StoreReportableTypeRequest::authorize()
        // (OVR_MANAGE_TYPES via engine).

        $reportable = $type->reportableTypes()->create($request->only(['name', 'name_ar']));

        // Audit log: إنشاء نوع فرعي
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'incident_reportable_type_created',
            'description' => __('ovr.api.activity.incident_reportable_type_created', ['name' => $reportable->name, 'parent' => $type->name]),
            'loggable_type' => IncidentType::class,
            'loggable_id' => $type->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => __('ovr.api.incident_reportable_type_created'),
            'data' => $reportable,
        ], 201);
    }
}
