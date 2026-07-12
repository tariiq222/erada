<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\HR\Http\Requests\UpdateDepartmentCapacityRoleRequest;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages the per-department capacity-role policy (member/manager) that drives
 * the automatic canonical-assignment sync. The backend is the sole decision source; the
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
     * Department-scoped active canonical roles that may be materialized as
     * automatic department assignments.
     *
     * @return array<int, array{role_id: int, role_key: string, name: string, label: string, scope: string, capabilities: list<string>}>
     */
    protected function availableDefinitions(): array
    {
        return AuthorizationRole::query()
            ->where('scope_type', 'department')
            ->where('is_active', true)
            ->with('permissions.resource')
            ->orderBy('name')
            ->get()
            ->map(fn (AuthorizationRole $role) => [
                'role_id' => $role->id,
                'role_key' => $role->name,
                'name' => $role->name,
                'label' => $role->label_ar ?: $role->label,
                'scope' => $role->scope_type,
                'capabilities' => $this->capabilitiesFor($role),
            ])->all();
    }

    /** @return list<string> */
    private function capabilitiesFor(AuthorizationRole $role): array
    {
        return $role->permissions->map(function (AuthorizationRolePermission $permission): ?string {
            return collect(Capability::all())->first(function (string $capability) use ($permission): bool {
                $mapping = CapabilityToAuthorizationRolePermission::map($capability);

                return $mapping !== null
                    && $mapping['resource'] === $permission->resource?->key
                    && $mapping['action'] === $permission->action;
            });
        })->filter()->unique()->sort()->values()->all();
    }
}
