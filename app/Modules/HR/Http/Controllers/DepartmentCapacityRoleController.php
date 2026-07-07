<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\HR\Http\Requests\UpdateDepartmentCapacityRoleRequest;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages the per-department capacity-role policy (member/manager) that drives
 * the automatic scoped-role sync. The backend is the sole decision source; the
 * UI only reads `available` and writes the policy, never the sync logic.
 */
class DepartmentCapacityRoleController extends Controller
{
    use HasOrganizationScope;

    /**
     * Department-independent list of assignable capacity roles, used by the
     * create form before a department exists. The list does not depend on any
     * single department, so no department binding is required.
     */
    public function available(Request $request): JsonResponse
    {
        return response()->json([
            'available' => $this->availableDefinitions(),
        ]);
    }

    public function show(Request $request, Department $department): JsonResponse
    {
        if (! $this->sharesOrganization($request->user(), $department->organization_id)) {
            return response()->json(['message' => 'غير مصرح بالوصول إلى هذا القسم'], 403);
        }

        $policies = DepartmentCapacityRole::where('department_id', $department->id)->get();

        return response()->json([
            'member_role_keys' => $policies->where('capacity', DepartmentCapacityRole::CAPACITY_MEMBER)
                ->pluck('role_key')->values(),
            'manager_role_keys' => $policies->where('capacity', DepartmentCapacityRole::CAPACITY_MANAGER)
                ->pluck('role_key')->values(),
            'available' => $this->availableDefinitions(),
        ]);
    }

    public function update(UpdateDepartmentCapacityRoleRequest $request, Department $department): JsonResponse
    {
        $validated = $request->validated();

        $members = collect($validated['member_role_keys'] ?? [])->unique()->values();
        $managers = collect($validated['manager_role_keys'] ?? [])->unique()->values();

        // Batch write with the observer muted, then sync ONCE (Review Round 2 point 3).
        DepartmentCapacityRole::withoutEvents(function () use ($department, $members, $managers) {
            DepartmentCapacityRole::where('department_id', $department->id)->delete();

            foreach ($members as $key) {
                DepartmentCapacityRole::create([
                    'department_id' => $department->id,
                    'capacity' => DepartmentCapacityRole::CAPACITY_MEMBER,
                    'role_key' => $key,
                ]);
            }

            foreach ($managers as $key) {
                DepartmentCapacityRole::create([
                    'department_id' => $department->id,
                    'capacity' => DepartmentCapacityRole::CAPACITY_MANAGER,
                    'role_key' => $key,
                ]);
            }
        });

        app(ScopedDepartmentRoleSyncService::class)->syncDepartment($department);

        return response()->json(['message' => 'تم تحديث أدوار القسم']);
    }

    /**
     * Department-scoped and organization-scoped active definitions a department
     * may assign (a department can hold its own dept roles or a cross-cutting
     * org role such as quality_manager).
     *
     * @return array<int, array{role_key: string, label: string, scope: ?string}>
     */
    protected function availableDefinitions(): array
    {
        return ScopedRoleDefinition::query()
            ->whereHas('scopeType', fn ($q) => $q->whereIn('key', ['department', 'organization']))
            ->where('is_active', true)
            ->with('scopeType:id,key')
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($d) => [
                'role_key' => $d->role_key,
                'label' => $d->label_ar ?: $d->role_key,
                'scope' => $d->scopeType?->key,
            ])->all();
    }
}
