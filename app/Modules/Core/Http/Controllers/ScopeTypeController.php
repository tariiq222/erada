<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Requests\DestroyScopeTypeRequest;
use App\Modules\Core\Http\Requests\StoreScopeTypeRequest;
use App\Modules\Core\Http\Requests\UpdateScopeTypeRequest;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScopeTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! AccessDecision::can($request->user(), Capability::CORE_VIEW_ORGANIZATIONS)) {
            abort(403, 'غير مصرح بعرض أنواع النطاق');
        }

        $query = ScopeType::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('key', 'ilike', "%{$search}%")
                    ->orWhere('label_ar', 'ilike', "%{$search}%")
                    ->orWhere('label_en', 'ilike', "%{$search}%");
            });
        }

        $perPage = min((int) $request->query('per_page', 50), 200);

        $types = $query->orderBy('sort_order')
            ->orderBy('key')
            ->paginate($perPage)
            ->through(fn ($type) => $this->transform($type));

        return response()->json([
            'data' => $types->items(),
            'meta' => [
                'current_page' => $types->currentPage(),
                'last_page' => $types->lastPage(),
                'per_page' => $types->perPage(),
                'total' => $types->total(),
            ],
        ]);
    }

    public function show(ScopeType $scopeType): JsonResponse
    {
        if (! AccessDecision::can(request()->user(), Capability::CORE_VIEW_ORGANIZATIONS)) {
            abort(403, 'غير مصرح بعرض أنواع النطاق');
        }

        return response()->json([
            'data' => $this->transform($scopeType, detailed: true),
        ]);
    }

    public function store(StoreScopeTypeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        $type = ScopeType::create($validated);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => ActivityLog::ACTION_CREATED,
            'description' => "إنشاء نوع نطاق: {$type->key}",
            'loggable_type' => ScopeType::class,
            'loggable_id' => $type->id,
            'new_values' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'تم إنشاء نوع النطاق بنجاح',
            'data' => $this->transform($type),
        ], 201);
    }

    public function update(UpdateScopeTypeRequest $request, ScopeType $scopeType): JsonResponse
    {
        $validated = $request->validated();

        $oldValues = $scopeType->only(array_keys($validated));
        $scopeType->update($validated);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => ActivityLog::ACTION_UPDATED,
            'description' => "تحديث نوع نطاق: {$scopeType->key}",
            'loggable_type' => ScopeType::class,
            'loggable_id' => $scopeType->id,
            'old_values' => $oldValues,
            'new_values' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'تم تحديث نوع النطاق بنجاح',
            'data' => $this->transform($scopeType),
        ]);
    }

    public function destroy(DestroyScopeTypeRequest $request, ScopeType $scopeType): JsonResponse
    {
        $key = $scopeType->key;
        $scopeType->delete();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => ActivityLog::ACTION_DELETED,
            'description' => "حذف نوع نطاق: {$key}",
            'loggable_type' => ScopeType::class,
            'loggable_id' => $scopeType->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'تم حذف نوع النطاق بنجاح',
        ]);
    }

    private function transform(ScopeType $type, bool $detailed = false): array
    {
        $data = [
            'id' => $type->id,
            'key' => $type->key,
            'label_ar' => $type->label_ar,
            'label_en' => $type->label_en,
            'icon' => $type->icon,
            'color' => $type->color,
            'sort_order' => $type->sort_order ?? 0,
            'is_active' => (bool) $type->is_active,
        ];

        if ($detailed) {
            $data['model_class'] = $type->model_class;
            $data['description_ar'] = $type->description_ar;
            $data['description_en'] = $type->description_en;
            $data['created_at'] = $type->created_at?->toIso8601String();
            $data['updated_at'] = $type->updated_at?->toIso8601String();
        }

        return $data;
    }
}
