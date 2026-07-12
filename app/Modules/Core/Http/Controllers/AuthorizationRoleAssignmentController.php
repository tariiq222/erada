<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Data\AssignmentWrite;
use App\Modules\Core\Authorization\Exceptions\AuthorizationAssignmentDenied;
use App\Modules\Core\Authorization\Models\AuthorizationAssignmentAudit;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Services\AuthorizationAssignmentService;
use App\Modules\Core\Http\Requests\AssignDepartmentRoleRequest;
use App\Modules\Core\Http\Requests\AssignProjectRoleRequest;
use App\Modules\Core\Http\Requests\RemoveDepartmentRoleRequest;
use App\Modules\Core\Http\Requests\UpdateProjectRoleRequest;
use App\Modules\Core\Http\Resources\AuthorizationAssignmentAuditResource;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Canonical authorization-role assignment API for users, projects, and departments.
 */
class AuthorizationRoleAssignmentController extends Controller
{
    // ==================== أدوار المشاريع ====================

    /**
     * عرض أعضاء المشروع مع أدوارهم
     */
    public function projectMembers(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $members = AuthorizationRoleAssignment::query()
            ->where('scope_type', 'project')
            ->where('scope_id', $project->id)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('role', fn ($query) => $query->where('is_active', true))
            ->with(['user:id,name,email,job_title', 'role:id,name,label'])
            ->get()
            ->map(function (AuthorizationRoleAssignment $assignment) {
                return [
                    'id' => $assignment->id,
                    'user_id' => $assignment->user_id,
                    'user' => $assignment->user,
                    'role_id' => $assignment->authorization_role_id,
                    'role_name' => $assignment->role?->name,
                    'role_display' => $assignment->role?->label,
                    'scope_type' => $assignment->scope_type,
                    'scope_id' => $assignment->scope_id,
                    'expires_at' => $assignment->expires_at,
                    'source' => $assignment->source,
                    'granted_by' => $assignment->granted_by,
                    'created_at' => $assignment->created_at,
                ];
            });

        return response()->json([
            'data' => $members,
            'available_roles' => $this->availableCanonicalRoles('project'),
        ]);
    }

    /**
     * تعيين دور لمستخدم في مشروع
     */
    public function assignProjectRole(
        AssignProjectRoleRequest $request,
        Project $project,
        AuthorizationAssignmentService $assignmentService,
    ): JsonResponse {
        // Authz + BOLA/IDOR + manager-escalation guard all live inside
        // AssignProjectRoleRequest.
        $validated = $request->validated();

        $user = User::query()->findOrFail($validated['user_id']);
        $role = AuthorizationRole::query()->findOrFail($validated['role_id']);
        $assignment = $this->runAssignmentMutation(fn () => $assignmentService->assign(
            $request->user(),
            $user,
            $role,
            $this->write('project', $project->id, $validated),
        ));

        if ($assignment instanceof JsonResponse) {
            return $assignment;
        }

        return response()->json([
            'message' => 'تم تعيين الدور بنجاح',
            'data' => [
                'id' => $assignment->id,
                'user_id' => $user->id,
                'role_id' => $assignment->authorization_role_id,
                'role_name' => $role->name,
                'role_display' => $role->label,
                'project_id' => $project->id,
                'scope_type' => 'project',
                'scope_id' => $project->id,
                'expires_at' => $assignment->expires_at,
                'source' => $assignment->source,
            ],
        ], 201);
    }

    /**
     * تحديث دور مستخدم في مشروع
     */
    public function updateProjectRole(
        UpdateProjectRoleRequest $request,
        Project $project,
        User $user,
        AuthorizationAssignmentService $assignmentService,
    ): JsonResponse {
        $validated = $request->validated();

        // BOLA/IDOR وفحص تصعيد الصلاحيات نُقِلا إلى UpdateProjectRoleRequest::withValidator.

        $role = AuthorizationRole::query()->findOrFail($validated['role_id']);
        $assignment = $this->runAssignmentMutation(function () use ($assignmentService, $project, $request, $role, $user, $validated) {
            return DB::transaction(function () use ($assignmentService, $project, $request, $role, $user, $validated) {
                $current = $this->manualAssignment($user, 'project', $project->id);
                if ($current instanceof JsonResponse) {
                    return $current;
                }
                if ((int) $current->authorization_role_id !== (int) $role->id) {
                    $oldRole = $current->role;
                    abort_if($oldRole === null, 409, 'الدور الحالي غير صالح.');
                    $assignmentService->revoke($request->user(), $user, $oldRole, new AssignmentScope('project', $project->id));
                }

                return $assignmentService->assign($request->user(), $user, $role, $this->write('project', $project->id, $validated));
            });
        });

        if ($assignment instanceof JsonResponse) {
            return $assignment;
        }

        return response()->json([
            'message' => 'تم تحديث الدور بنجاح',
            'data' => [
                'user_id' => $user->id,
                'id' => $assignment->id,
                'role_id' => $assignment->authorization_role_id,
                'role_name' => $role->name,
                'role_display' => $role->label,
                'scope_type' => 'project',
                'scope_id' => $project->id,
                'expires_at' => $assignment->expires_at,
                'source' => $assignment->source,
            ],
        ]);
    }

    /**
     * إزالة مستخدم من مشروع
     */
    public function removeFromProject(
        UpdateProjectRoleRequest $request,
        Project $project,
        User $user,
        AuthorizationAssignmentService $assignmentService,
    ): JsonResponse {
        $validated = $request->validated();
        $role = AuthorizationRole::query()->findOrFail($validated['role_id']);
        $existing = $this->exactManualAssignment($user, $role, 'project', $project->id);
        if ($existing instanceof JsonResponse) {
            return $existing;
        }
        $result = $this->runAssignmentMutation(fn () => $assignmentService->revoke(
            $request->user(),
            $user,
            $role,
            new AssignmentScope('project', $project->id),
        ));
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json([
            'message' => 'تم إزالة المستخدم من المشروع بنجاح',
        ]);
    }

    // ==================== أدوار الأقسام ====================

    /**
     * عرض مديري/مشرفي القسم
     */
    public function departmentManagers(Request $request, Department $department): JsonResponse
    {
        abort_unless(
            AccessDecision::can($request->user(), Capability::DEPARTMENTS_VIEW, $department),
            403,
            'غير مصرح',
        );

        $managers = AuthorizationRoleAssignment::query()
            ->where('scope_type', 'department')
            ->where('scope_id', $department->id)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('role', fn ($query) => $query->where('is_active', true))
            ->with(['user:id,name,email,job_title', 'role:id,name,label'])
            ->get()
            ->map(function (AuthorizationRoleAssignment $assignment) {
                return [
                    'id' => $assignment->id,
                    'user_id' => $assignment->user_id,
                    'user' => $assignment->user,
                    'role_id' => $assignment->authorization_role_id,
                    'role_name' => $assignment->role?->name,
                    'role_display' => $assignment->role?->label,
                    'scope_type' => $assignment->scope_type,
                    'scope_id' => $assignment->scope_id,
                    'inherit_to_children' => $assignment->inherit_to_children,
                    'expires_at' => $assignment->expires_at,
                    'source' => $assignment->source,
                    'created_at' => $assignment->created_at,
                ];
            });

        return response()->json([
            'data' => $managers,
            'available_roles' => $this->availableCanonicalRoles('department'),
        ]);
    }

    /**
     * تعيين دور لمستخدم في قسم
     */
    public function assignDepartmentRole(
        AssignDepartmentRoleRequest $request,
        Department $department,
        AuthorizationAssignmentService $assignmentService,
    ): JsonResponse {
        // Authz + BOLA/IDOR guard both live inside AssignDepartmentRoleRequest.
        $validated = $request->validated();

        $user = User::query()->findOrFail($validated['user_id']);
        $role = AuthorizationRole::query()->findOrFail($validated['role_id']);
        $assignment = $this->runAssignmentMutation(fn () => $assignmentService->assign(
            $request->user(),
            $user,
            $role,
            $this->write('department', $department->id, $validated, (bool) ($validated['inherit_to_children'] ?? true)),
        ));
        if ($assignment instanceof JsonResponse) {
            return $assignment;
        }

        return response()->json([
            'message' => 'تم تعيين الدور بنجاح',
            'data' => [
                'id' => $assignment->id,
                'user_id' => $user->id,
                'role_id' => $assignment->authorization_role_id,
                'role_name' => $role->name,
                'role_display' => $role->label,
                'department_id' => $department->id,
                'scope_type' => 'department',
                'scope_id' => $department->id,
                'inherit_to_children' => $assignment->inherit_to_children,
                'expires_at' => $assignment->expires_at,
                'source' => $assignment->source,
            ],
        ], 201);
    }

    /**
     * إزالة دور مستخدم من قسم
     */
    public function removeFromDepartment(
        RemoveDepartmentRoleRequest $request,
        Department $department,
        User $user,
        AuthorizationAssignmentService $assignmentService,
    ): JsonResponse {
        // Authz + "row exists" guard both live inside RemoveDepartmentRoleRequest.
        $validated = $request->validated();

        $role = AuthorizationRole::query()->findOrFail($validated['role_id']);
        $existing = $this->exactManualAssignment($user, $role, 'department', $department->id);
        if ($existing instanceof JsonResponse) {
            return $existing;
        }
        $result = $this->runAssignmentMutation(fn () => $assignmentService->revoke(
            $request->user(),
            $user,
            $role,
            new AssignmentScope('department', $department->id),
        ));
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json([
            'message' => 'تم تحديث دور المستخدم',
        ]);
    }

    // ==================== عام ====================

    /**
     * عرض جميع الأدوار السياقية لمستخدم
     */
    public function userAssignments(Request $request, User $user): JsonResponse
    {
        // Authz delegates to UserPolicy::view, which routes through the engine
        // USERS_VIEW capability, applies same-org isolation, and grants self +
        // super_admin access (UserPolicy::before() + view()).
        $this->authorize('view', $user);

        return response()->json([
            'data' => $this->canonicalAssignmentSummaries($user, $request->user()),
        ]);
    }

    /**
     * Access summary — a read-only "why does this user have access" view
     * (ADR-UNIFIED-ROLE-ACCESS, Phase 6). Lists the user's org functional roles
     * plus every scoped assignment with its source (auto = from department
     * membership, manual = explicitly granted), scope target name, and reach cap.
     */
    public function accessSummary(Request $request, User $user): JsonResponse
    {
        // Same authz seam as userAssignments — UserPolicy::view.
        $this->authorize('view', $user);

        return response()->json([
            'data' => [
                'assignments' => $this->canonicalAssignmentSummaries($user, $request->user()),
            ],
        ]);
    }

    /**
     * @return list<array{id: int, role_id: int, role: string, label: string, scope_type: string, scope_id: int|null, scope_name: string|null, organization_id: int|null, inherit_to_children: bool, expires_at: string|null, source: string, granted_by: int|null}>
     */
    private function canonicalAssignmentSummaries(User $user, ?User $actor): array
    {
        // CSD-CA23078-CORE-002 — stale-org filter (mirrors
        // AccessDecision::canonicalListAssignmentMatchesUserOrganization and the
        // UserProjectScope::canonicalGrantingScopes filter).
        //
        // Drop rows where assignment.organization_id is non-null and NOT equal
        // to the target user's current organization_id. The scope_type='all' +
        // actor-is-super_admin exception is the canonical defensive bypass: a
        // super_admin viewer can still see the target's all-scope assignments
        // even if their stale organization_id is stale.
        //
        // Decision: drop stale rows entirely rather than emit `__stale: true`
        // because (a) the endpoint is a read-only access view and showing a
        // stale row with a stale flag would let an admin mistake it for an
        // active grant; (b) the safety-net migration
        // (2026_07_12_000015_invalidate_stale_canonical_assignments_on_org_transfer)
        // expires the rows in-place and writes one audit row per stale
        // assignment to `authorization_assignment_audits` so the historical
        // record is preserved; (c) the filter mirrors the engine behavior
        // (AccessDecision::canonicalListAssignmentMatchesUserOrganization
        // returns false rather than marking the row).
        $isActorSuperAdmin = $actor instanceof User && $actor->isSuperAdmin();

        $assignments = AuthorizationRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('role', fn ($query) => $query->where('is_active', true))
            ->where(function ($query) use ($user, $isActorSuperAdmin) {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', $user->organization_id);
                if ($isActorSuperAdmin) {
                    $query->orWhere('scope_type', 'all');
                }
            })
            ->with('role:id,name,label')
            ->get();

        $departmentNames = Department::query()
            ->whereIn('id', $assignments->where('scope_type', 'department')->pluck('scope_id')->filter())
            ->pluck('name', 'id');
        $projectNames = Project::query()
            ->whereIn('id', $assignments->where('scope_type', 'project')->pluck('scope_id')->filter())
            ->pluck('name', 'id');

        return $assignments->map(function (AuthorizationRoleAssignment $assignment) use ($departmentNames, $projectNames) {
            $scopeName = match ($assignment->scope_type) {
                'department' => $departmentNames[$assignment->scope_id] ?? null,
                'project' => $projectNames[$assignment->scope_id] ?? null,
                'organization' => 'المؤسسة',
                'all' => 'كل المؤسسات',
                'own' => 'السجلات الخاصة',
                default => null,
            };

            return [
                'id' => (int) $assignment->id,
                'role_id' => (int) $assignment->authorization_role_id,
                'role' => $assignment->role?->name ?? '',
                'label' => $assignment->role?->label ?? '',
                'scope_type' => $assignment->scope_type,
                'scope_id' => $assignment->scope_id === null ? null : (int) $assignment->scope_id,
                'scope_name' => $scopeName,
                'organization_id' => $assignment->organization_id === null ? null : (int) $assignment->organization_id,
                'inherit_to_children' => (bool) $assignment->inherit_to_children,
                'expires_at' => $assignment->expires_at?->toISOString(),
                'source' => $assignment->source,
                'granted_by' => $assignment->granted_by,
            ];
        })->values()->all();
    }

    /** @return list<array{id: int, name: string, label: string}> */
    private function availableCanonicalRoles(string $scopeType): array
    {
        return AuthorizationRole::query()
            ->where('is_active', true)
            ->where('scope_type', $scopeType)
            ->orderBy('label')
            ->get(['id', 'name', 'label'])
            ->map(fn (AuthorizationRole $role) => [
                'id' => (int) $role->id,
                'name' => $role->name,
                'label' => $role->label,
            ])
            ->all();
    }

    /** @param array<string, mixed> $validated */
    private function write(
        string $scopeType,
        int $scopeId,
        array $validated,
        bool $inheritToChildren = false,
    ): AssignmentWrite {
        return new AssignmentWrite(
            new AssignmentScope($scopeType, $scopeId, $inheritToChildren),
            isset($validated['expires_at']) ? CarbonImmutable::parse($validated['expires_at']) : null,
            'manual',
        );
    }

    private function manualAssignment(User $user, string $scopeType, int $scopeId): AuthorizationRoleAssignment|JsonResponse
    {
        $assignments = AuthorizationRoleAssignment::query()
            ->with('role')
            ->where('user_id', $user->id)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->lockForUpdate()
            ->get();

        if ($assignments->isEmpty()) {
            return response()->json(['message' => 'المستخدم ليس لديه تفويض في هذا النطاق.'], 404);
        }

        if ($assignments->count() !== 1 || $assignments->first()->source !== 'manual') {
            return response()->json(['message' => 'يتعارض الطلب مع تفويض آلي أو مهاجر موجود.'], 409);
        }

        return $assignments->first();
    }

    private function exactManualAssignment(
        User $user,
        AuthorizationRole $role,
        string $scopeType,
        int $scopeId,
    ): AuthorizationRoleAssignment|JsonResponse {
        $assignment = AuthorizationRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('authorization_role_id', $role->id)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->first();

        if ($assignment === null) {
            return response()->json(['message' => 'المستخدم ليس لديه هذا التفويض في النطاق.'], 404);
        }

        if ($assignment->source !== 'manual') {
            return response()->json(['message' => 'لا يمكن حذف تفويض آلي أو مهاجر من هذا المسار.'], 409);
        }

        return $assignment;
    }

    private function runAssignmentMutation(callable $mutation): mixed
    {
        try {
            return $mutation();
        } catch (AuthorizationAssignmentDenied $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                return response()->json(['message' => 'يتعارض الطلب مع تفويض موجود.'], 409);
            }

            throw $exception;
        }
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

        $query = AuthorizationAssignmentAudit::query()
            ->with(['actor:id,name'])
            ->visibleTo($actor)
            ->orderBy('created_at', 'desc');

        // فلاتر
        if ($request->has('event') || $request->has('action')) {
            $query->where('event', $request->event ?? $request->action);
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
            'data' => AuthorizationAssignmentAuditResource::collection($logs->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
