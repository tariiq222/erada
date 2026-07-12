<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Models\User;
use App\Modules\HR\Http\Requests\UpdateDepartmentCapacityRoleRequest;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        // CSD-CA23078-HR-002 — privilege-escalation actor guard.
        //
        // The FormRequest's authorize() admits any same-org user with
        // `departments.edit`, but the policy it can persist is the source of
        // every later auto-grant — letting a non-super_admin departments.edit
        // actor configure manager capacity is a horizontal privilege escalation
        // (a target user is granted `dept_manager` without ever having been
        // assigned a role by anyone with `core.assign_roles`).
        //
        // Mirror the predicate in `CanonicalAuthorizationAssignmentActorGuard::
        // roleFitsActorAuthority`: for every (capacity_role_key, department)
        // being persisted, the actor must already be able to grant every
        // capability the role carries. Canonical super_admin is admitted
        // unconditionally; everyone else must pass the per-capability subset
        // check below.
        /** @var User|null $actor */
        $actor = $request->user();
        if ($actor === null) {
            return response()->json(['message' => 'Authentication required.'], 403);
        }

        $denial = $this->guardCapacityRolePayloadEscalation($actor, $department, $members, $managers);
        if ($denial !== null) {
            $this->auditRejectedCapacityRoleWrite($actor, $department, $members, $managers, $denial);

            return response()->json(['message' => $denial], 403);
        }

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

        app(ScopedDepartmentRoleSyncService::class)->syncDepartment($department, $actor);

        return response()->json(['message' => 'تم تحديث أدوار القسم']);
    }

    /**
     * CSD-CA23078-HR-002 — reject the capacity-role payload if the actor
     * cannot legitimately grant at least one of the resolved canonical roles
     * against this department. Returns the human-readable reason on denial;
     * `null` means the payload is admissible.
     *
     * Policy (mirrors `CanonicalAuthorizationAssignmentActorGuard::
     * roleFitsActorAuthority`): for every (capacity_role_key, department)
     * being persisted, the actor must already be able to grant every
     * capability the role carries. Super_admin is admitted unconditionally;
     * everyone else must pass the per-capability subset check below.
     *
     * @param  Collection<int, string>  $members
     * @param  Collection<int, string>  $managers
     */
    private function guardCapacityRolePayloadEscalation(
        User $actor,
        Department $department,
        $members,
        $managers,
    ): ?string {
        if ($actor->isSuperAdmin()) {
            return null;
        }

        $payloadKeys = $members->merge($managers)->unique()->values();
        $resolved = AuthorizationRole::query()
            ->whereIn('name', $payloadKeys->all() ?: ['__none__'])
            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_DEPARTMENT)
            ->where('is_active', true)
            ->with('permissions.resource')
            ->get()
            ->keyBy('name');

        foreach ($payloadKeys as $key) {
            $role = $resolved->get($key);
            if ($role === null) {
                // FormRequest validation already rejects unknown / inactive /
                // non-department-scoped roles, so reaching here is a logical
                // contradiction. Fail closed and surface it.
                return "Unknown or inactive canonical capacity role [{$key}].";
            }

            foreach ($this->capabilitiesFor($role) as $capability) {
                if (! AccessDecision::can($actor, $capability, $department)) {
                    return "The actor cannot configure a capacity role that grants [{$capability}] without holding it first.";
                }
            }
        }

        return null;
    }

    /**
     * Audit a rejected capacity-role write attempt (CSD-CA23078-HR-002). The
     * authorization_assignment_audits table is the canonical record for
     * privilege-escalation attempts; ActivityLog gives operators a
     * human-readable Arabic-language breadcrumb.
     *
     * @param  Collection<int, string>  $members
     * @param  Collection<int, string>  $managers
     */
    private function auditRejectedCapacityRoleWrite(
        User $actor,
        Department $department,
        $members,
        $managers,
        string $reason,
    ): void {
        $payload = [
            'department_id' => $department->id,
            'member_role_keys' => $members->values()->all(),
            'manager_role_keys' => $managers->values()->all(),
        ];

        DB::table('authorization_assignment_audits')->insert([
            'event' => 'canonical_assignment_capacity_role_write_rejected',
            'actor_id' => $actor->id,
            'target_user_id' => null,
            'scope_type' => 'department',
            'scope_id' => $department->id,
            'role' => null,
            'old_value' => null,
            'new_value' => json_encode($payload),
            'reason' => $reason,
            'ip_address' => request()?->ip(),
            'user_agent' => 'department-capacity-role-controller',
            'created_at' => now(),
        ]);

        ActivityLog::create([
            'user_id' => $actor->id,
            'action' => 'department_capacity_role_write_rejected',
            'description' => "رفض محاولة تعديل سياسة أدوار القسم: {$department->name}",
            'loggable_type' => Department::class,
            'loggable_id' => $department->id,
            'old_values' => null,
            'new_values' => $payload,
            'reason' => $reason,
        ]);
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
