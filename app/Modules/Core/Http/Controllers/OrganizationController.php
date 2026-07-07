<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Requests\DestroyOrganizationRequest;
use App\Modules\Core\Http\Requests\StoreOrganizationRequest;
use App\Modules\Core\Http\Requests\UpdateOrganizationRequest;
use App\Modules\Core\Models\Organization;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! AccessDecision::can($request->user(), Capability::CORE_VIEW_ORGANIZATIONS)) {
            abort(403, 'غير مصرح بعرض المؤسسات');
        }

        $query = Organization::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('is_active', $status === 'active');
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        $organizations = $query->orderBy('name')
            ->paginate($perPage)
            ->through(fn ($org) => $this->transform($org));

        return response()->json([
            'data' => $organizations->items(),
            'meta' => [
                'current_page' => $organizations->currentPage(),
                'last_page' => $organizations->lastPage(),
                'per_page' => $organizations->perPage(),
                'total' => $organizations->total(),
            ],
        ]);
    }

    public function show(Organization $organization): JsonResponse
    {
        if (! AccessDecision::can(request()->user(), Capability::CORE_VIEW_ORGANIZATIONS)) {
            abort(403, 'غير مصرح بعرض المؤسسات');
        }

        $organization->loadCount(['users', 'projects']);

        return response()->json([
            'data' => $this->transform($organization, withCounts: true),
        ]);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['created_by'] = auth()->id();
        $validated['is_active'] = $validated['is_active'] ?? true;

        $org = Organization::create($validated);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => ActivityLog::ACTION_CREATED,
            'description' => "إنشاء مؤسسة: {$org->name}",
            'loggable_type' => Organization::class,
            'loggable_id' => $org->id,
            'new_values' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'تم إنشاء المؤسسة بنجاح',
            'data' => $this->transform($org),
        ], 201);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $validated = $request->validated();

        $oldValues = $organization->only(array_keys($validated));
        $organization->update($validated);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => ActivityLog::ACTION_UPDATED,
            'description' => "تحديث مؤسسة: {$organization->name}",
            'loggable_type' => Organization::class,
            'loggable_id' => $organization->id,
            'old_values' => $oldValues,
            'new_values' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'تم تحديث المؤسسة بنجاح',
            'data' => $this->transform($organization),
        ]);
    }

    public function destroy(DestroyOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $usersCount = $organization->users()->count();
        if ($usersCount > 0) {
            return response()->json([
                'message' => "لا يمكن حذف مؤسسة مرتبطة بـ {$usersCount} مستخدم. قم بإزالة المستخدمين أولاً.",
                'users_count' => $usersCount,
            ], 422);
        }

        $name = $organization->name;
        $organization->delete();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => ActivityLog::ACTION_DELETED,
            'description' => "حذف مؤسسة: {$name}",
            'loggable_type' => Organization::class,
            'loggable_id' => $organization->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'تم حذف المؤسسة بنجاح',
        ]);
    }

    private function transform(Organization $org, bool $withCounts = false): array
    {
        $data = [
            'id' => $org->id,
            'name' => $org->name,
            'code' => $org->code,
            'description' => $org->description,
            'email' => $org->email,
            'phone' => $org->phone,
            'address' => $org->address,
            'website' => $org->website,
            'logo' => $org->logo,
            'is_active' => (bool) $org->is_active,
            'created_at' => $org->created_at?->toIso8601String(),
            'updated_at' => $org->updated_at?->toIso8601String(),
        ];

        if ($withCounts) {
            $data['users_count'] = $org->users_count ?? 0;
            $data['projects_count'] = $org->projects_count ?? 0;
        }

        return $data;
    }
}
