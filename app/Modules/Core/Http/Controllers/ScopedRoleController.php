<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Requests\AssignDepartmentRoleRequest;
use App\Modules\Core\Http\Requests\AssignProjectRoleRequest;
use App\Modules\Core\Http\Requests\RemoveDepartmentRoleRequest;
use App\Modules\Core\Http\Requests\UpdateProjectRoleRequest;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ScopedRoleController - إدارة الأدوار السياقية (المشاريع والأقسام)
 */
class ScopedRoleController extends Controller
{
    // ==================== أدوار المشاريع ====================

    /**
     * عرض أعضاء المشروع مع أدوارهم
     */
    public function projectMembers(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $members = ScopedRole::where('scope_type', ScopedRole::SCOPE_PROJECT)
            ->where('scope_id', $project->id)
            ->active()
            ->with('user:id,name,email,job_title')
            ->get()
            ->map(function ($scopedRole) {
                return [
                    'id' => $scopedRole->id,
                    'user' => $scopedRole->user,
                    'role' => $scopedRole->role,
                    'role_display' => $scopedRole->display_name,
                    'expires_at' => $scopedRole->expires_at,
                    'granted_by' => $scopedRole->granted_by,
                    'created_at' => $scopedRole->created_at,
                ];
            });

        return response()->json([
            'data' => $members,
            'available_roles' => ScopedRole::getProjectRoles(),
        ]);
    }

    /**
     * تعيين دور لمستخدم في مشروع
     */
    public function assignProjectRole(AssignProjectRoleRequest $request, Project $project): JsonResponse
    {
        // Authz + BOLA/IDOR + manager-escalation guard all live inside
        // AssignProjectRoleRequest.
        $validated = $request->validated();

        $user = User::findOrFail($validated['user_id']);

        // تعيين الدور
        $scopedRole = $user->assignProjectRole(
            $project,
            $validated['role'],
            auth()->id(),
            isset($validated['expires_at']) ? new \DateTime($validated['expires_at']) : null
        );

        // تسجيل في سجل الأنشطة
        ActivityLog::logRoleAssigned(
            $user->id,
            $validated['role'],
            ScopedRole::SCOPE_PROJECT,
            $project->id,
            auth()->id(),
            "تعيين دور في مشروع: {$project->name}"
        );

        return response()->json([
            'message' => 'تم تعيين الدور بنجاح',
            'data' => [
                'id' => $scopedRole->id,
                'user_id' => $user->id,
                'role' => $scopedRole->role,
                'role_display' => $scopedRole->display_name,
                'project_id' => $project->id,
            ],
        ], 201);
    }

    /**
     * تحديث دور مستخدم في مشروع
     */
    public function updateProjectRole(UpdateProjectRoleRequest $request, Project $project, User $user): JsonResponse
    {
        $validated = $request->validated();

        // BOLA/IDOR وفحص تصعيد الصلاحيات نُقِلا إلى UpdateProjectRoleRequest::withValidator.

        $oldRole = $user->roleInProject($project);

        // تحديث الدور
        $scopedRole = $user->assignProjectRole(
            $project,
            $validated['role'],
            auth()->id(),
            isset($validated['expires_at']) ? new \DateTime($validated['expires_at']) : null
        );

        // تسجيل في سجل الأنشطة
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => ActivityLog::ACTION_ROLE_UPDATED,
            'description' => "تحديث دور من {$oldRole} إلى {$validated['role']}",
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
            'target_user_id' => $user->id,
            'scope_type' => ScopedRole::SCOPE_PROJECT,
            'scope_id' => $project->id,
            'role' => $validated['role'],
            // اشتقاق organization_id من scope_type='project' عبر Resolver (source 4).
            'organization_id' => app(\App\Modules\Shared\Services\ActivityLogOrganizationResolver::class)
                ->resolveForScope(ScopedRole::SCOPE_PROJECT, $project->id),
            'old_values' => ['role' => $oldRole],
            'new_values' => ['role' => $validated['role']],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'تم تحديث الدور بنجاح',
            'data' => [
                'user_id' => $user->id,
                'role' => $scopedRole->role,
                'role_display' => $scopedRole->display_name,
            ],
        ]);
    }

    /**
     * إزالة مستخدم من مشروع
     */
    public function removeFromProject(Project $project, User $user): JsonResponse
    {
        $this->authorize('assignProjectRoles', $project);

        // التحقق من أن المستخدم المستهدف في نفس مؤسسة المشروع (BOLA/IDOR)
        if (! auth()->user()->isSuperAdmin()
            && $user->organization_id !== $project->organization_id) {
            abort(403, 'لا يمكن إزالة دور لمستخدم من مؤسسة أخرى');
        }

        $oldRole = $user->roleInProject($project);

        if (! $oldRole) {
            return response()->json([
                'message' => 'المستخدم ليس لديه دور في هذا المشروع',
            ], 404);
        }

        // إزالة الدور
        $user->revokeProjectRole($project);

        // تسجيل في سجل الأنشطة
        ActivityLog::logRoleRevoked(
            $user->id,
            $oldRole,
            ScopedRole::SCOPE_PROJECT,
            $project->id,
            auth()->id(),
            "إزالة من مشروع: {$project->name}"
        );

        return response()->json([
            'message' => 'تم إزالة المستخدم من المشروع بنجاح',
        ]);
    }

    // ==================== أدوار الأقسام ====================

    /**
     * عرض مديري/مشرفي القسم
     */
    public function departmentManagers(Department $department): JsonResponse
    {
        // التحقق من الوصول
        if (! auth()->user()->canAccessDepartment($department)) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $managers = ScopedRole::where('scope_type', ScopedRole::SCOPE_DEPARTMENT)
            ->where('scope_id', $department->id)
            ->active()
            ->with('user:id,name,email,job_title')
            ->get()
            ->map(function ($scopedRole) {
                return [
                    'id' => $scopedRole->id,
                    'user' => $scopedRole->user,
                    'role' => $scopedRole->role,
                    'role_display' => $scopedRole->display_name,
                    'inherit_to_children' => $scopedRole->inherit_to_children,
                    'expires_at' => $scopedRole->expires_at,
                    'created_at' => $scopedRole->created_at,
                ];
            });

        return response()->json([
            'data' => $managers,
            'available_roles' => ScopedRole::getDepartmentRoles(),
        ]);
    }

    /**
     * تعيين دور لمستخدم في قسم
     */
    public function assignDepartmentRole(AssignDepartmentRoleRequest $request, Department $department): JsonResponse
    {
        // Authz + BOLA/IDOR guard both live inside AssignDepartmentRoleRequest.
        $validated = $request->validated();

        $user = User::findOrFail($validated['user_id']);

        // تعيين الدور
        $scopedRole = $user->assignDepartmentRole(
            $department,
            $validated['role'],
            auth()->id(),
            $validated['inherit_to_children'] ?? true,
            isset($validated['expires_at']) ? new \DateTime($validated['expires_at']) : null
        );

        // تسجيل في سجل الأنشطة
        ActivityLog::logRoleAssigned(
            $user->id,
            $validated['role'],
            ScopedRole::SCOPE_DEPARTMENT,
            $department->id,
            auth()->id(),
            "تعيين دور في قسم: {$department->name}"
        );

        return response()->json([
            'message' => 'تم تعيين الدور بنجاح',
            'data' => [
                'id' => $scopedRole->id,
                'user_id' => $user->id,
                'role' => $scopedRole->role,
                'role_display' => $scopedRole->display_name,
                'department_id' => $department->id,
                'inherit_to_children' => $scopedRole->inherit_to_children,
            ],
        ], 201);
    }

    /**
     * إزالة دور مستخدم من قسم
     */
    public function removeFromDepartment(RemoveDepartmentRoleRequest $request, Department $department, User $user): JsonResponse
    {
        // Authz + "row exists" guard both live inside RemoveDepartmentRoleRequest.
        $validated = $request->validated();

        $row = $user->scopedRoles()
            ->where('scope_type', ScopedRole::SCOPE_DEPARTMENT)
            ->where('scope_id', $department->id)
            ->where('role', $validated['role'])
            ->first();

        // A manual role that the capacity policy STILL expects is downgraded to
        // an auto grant instead of being deleted; otherwise it is removed.
        if (app(ScopedDepartmentRoleSyncService::class)
            ->isExpectedAutoRole($user, $department->id, $validated['role'])) {
            $row->update(['source' => 'auto']);
        } else {
            $row->delete();
        }

        // تسجيل في سجل الأنشطة
        ActivityLog::logRoleRevoked(
            $user->id,
            $validated['role'],
            ScopedRole::SCOPE_DEPARTMENT,
            $department->id,
            auth()->id(),
            "إزالة من قسم: {$department->name}"
        );

        return response()->json([
            'message' => 'تم تحديث دور المستخدم',
        ]);
    }

    // ==================== عام ====================

    /**
     * عرض جميع الأدوار السياقية لمستخدم
     */
    public function userScopedRoles(User $user): JsonResponse
    {
        $currentUser = auth()->user();

        // التحقق من الصلاحية
        if (! $currentUser->isSuperAdmin() && $currentUser->id !== $user->id) {
            if (! $currentUser->can('view_users')) {
                return response()->json(['message' => 'غير مصرح'], 403);
            }

            // ✅ عزل المؤسسة: لا يمكن عرض أدوار مستخدم من مؤسسة أخرى
            if ($user->organization_id !== $currentUser->organization_id) {
                return response()->json(['message' => 'لا يمكن عرض أدوار مستخدم من مؤسسة أخرى'], 403);
            }
        }

        $roles = $user->getAllScopedRolesForDisplay();

        // إضافة تفاصيل المشاريع والأقسام
        $projectIds = collect($roles['projects'])->pluck('scope_id');
        $projects = Project::whereIn('id', $projectIds)->pluck('name', 'id');

        $departmentIds = collect($roles['departments'])->pluck('scope_id');
        $departments = Department::whereIn('id', $departmentIds)->pluck('name', 'id');

        // دمج الأسماء
        $roles['projects'] = collect($roles['projects'])->map(function ($role) use ($projects) {
            $role['scope_name'] = $projects[$role['scope_id']] ?? 'غير معروف';

            return $role;
        })->toArray();

        $roles['departments'] = collect($roles['departments'])->map(function ($role) use ($departments) {
            $role['scope_name'] = $departments[$role['scope_id']] ?? 'غير معروف';

            return $role;
        })->toArray();

        return response()->json([
            'data' => $roles,
        ]);
    }

    /**
     * Access summary — a read-only "why does this user have access" view
     * (ADR-UNIFIED-ROLE-ACCESS, Phase 6). Lists the user's org functional roles
     * plus every scoped assignment with its source (auto = from department
     * membership, manual = explicitly granted), scope target name, and reach cap.
     */
    public function accessSummary(User $user): JsonResponse
    {
        $currentUser = auth()->user();

        if (! $currentUser->isSuperAdmin() && $currentUser->id !== $user->id) {
            if (! $currentUser->can('view_users') || $user->organization_id !== $currentUser->organization_id) {
                return response()->json(['message' => 'غير مصرح'], 403);
            }
        }

        $scopedRoles = $user->activeScopedRoles()->with('roleDefinition')->get();

        // Resolve scope target names in bulk (no N+1).
        $deptNames = Department::whereIn('id', $scopedRoles->where('scope_type', ScopedRole::SCOPE_DEPARTMENT)->pluck('scope_id'))
            ->pluck('name', 'id');
        $projectNames = Project::whereIn('id', $scopedRoles->where('scope_type', ScopedRole::SCOPE_PROJECT)->pluck('scope_id'))
            ->pluck('name', 'id');

        $scoped = $scopedRoles->map(function (ScopedRole $role) use ($deptNames, $projectNames) {
            $scopeName = match ($role->scope_type) {
                ScopedRole::SCOPE_DEPARTMENT => $deptNames[$role->scope_id] ?? null,
                ScopedRole::SCOPE_PROJECT => $projectNames[$role->scope_id] ?? null,
                ScopedRole::SCOPE_ORGANIZATION => 'المؤسسة',
                default => null,
            };

            return [
                'role' => $role->role,
                'label' => $role->roleDefinition?->getLabel() ?? $role->role,
                'scope_type' => $role->scope_type,
                'scope_id' => $role->scope_id,
                'scope_name' => $scopeName,
                'source' => $role->source, // 'auto' (department membership) | 'manual'
                'reach' => $role->roleDefinition?->reach ?? (object) [],
            ];
        })->values();

        return response()->json([
            'data' => [
                'functional_roles' => $user->getRoleNames()->values(),
                'scoped' => $scoped,
            ],
        ]);
    }

    /**
     * سجل تغييرات الصلاحيات
     */
    public function auditLogs(Request $request): JsonResponse
    {
        if (! AccessDecision::can($request->user(), Capability::AUDIT_VIEW)) {
            abort(403, 'غير مصرح بعرض سجل تغييرات الصلاحيات');
        }

        $actor = $request->user();

        $query = ActivityLog::with(['user:id,name', 'targetUser:id,name'])
            ->permissionEvents()
            ->orderBy('created_at', 'desc');

        // عزل المؤسسة عبر UserActivityLogScope (فلتر موحّد على activity_logs.organization_id)
        app(\App\Modules\Shared\Scopes\UserActivityLogScope::class)->apply($query, $actor);

        // فلاتر
        if ($request->has('event') || $request->has('action')) {
            $query->where('action', $request->event ?? $request->action);
        }

        if ($request->has('user_id')) {
            $query->where('target_user_id', $request->user_id);
        }

        if ($request->has('scope_type')) {
            $query->where('scope_type', $request->scope_type);
        }

        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('created_at', '<=', $request->to_date);
        }

        $logs = $query->paginate(min((int) $request->input('per_page', 50), 100));

        return response()->json([
            'data' => \App\Modules\Shared\Http\Resources\ActivityLogResource::collection($logs->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
