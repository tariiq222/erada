<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\DeleteUserRequest;
use App\Modules\Core\Http\Requests\StoreUserRequest;
use App\Modules\Core\Http\Requests\UpdateUserRequest;
use App\Modules\Core\Http\Requests\ViewUserRequest;
use App\Modules\Core\Http\Resources\UserDirectoryResource;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\Core\Scopes\UserOrganizationScope;
use App\Modules\Core\Support\UserRoleAssignmentGuard;
use App\Modules\HR\Models\Department;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UserController extends Controller
{
    /**
     * Apply the caller's user-visibility scope via UserOrganizationScope.
     *
     * The single horizontal-org + dept-subtree filter lives in
     * App\Modules\Core\Scopes\UserOrganizationScope. This wrapper keeps the
     * call sites (index/stats/list) unchanged and lets every controller share
     * one source of truth for "what users can this actor see?".
     */
    private function applyUserVisibility(Builder $query, User $user): void
    {
        app(UserOrganizationScope::class)->applyToUsers($query, $user);
    }

    /**
     * معالجة الأخطاء غير المتوقعة
     */
    private function handleException(\Throwable $e, string $context): JsonResponse
    {
        // Let auth/validation/routing exceptions bubble up to the global handler
        // so clients receive the proper 401/403/404/422 status codes.
        if ($e instanceof AuthorizationException
            || $e instanceof AuthenticationException
            || $e instanceof ValidationException
            || $e instanceof ModelNotFoundException
            || $e instanceof HttpException
            || $e instanceof NotFoundHttpException
            || $e instanceof MethodNotAllowedHttpException) {
            throw $e;
        }

        $errorId = uniqid('usr_err_', true);
        Log::error("UserController error: {$context}", [
            'error_id' => $errorId,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'message' => 'حدث خطأ غير متوقع. الرجاء المحاولة لاحقاً.',
            'error_id' => $errorId,
        ], 500);
    }

    /**
     * عرض قائمة المستخدمين
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', User::class);

            $user = $request->user();
            $query = User::with(['department', 'creator:id,name', 'roles:id,name', 'activeScopedRoles']);

            $this->applyUserVisibility($query, $user);

            // البحث
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // تصفية بالحالة
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // تصفية بالقسم
            if ($request->has('department_id') && is_numeric($request->department_id)) {
                $query->where('department_id', (int) $request->department_id);
            }

            $users = $query->orderBy('name')->paginate(min((int) $request->get('per_page', 15), 100));

            // تحويل الـ roles إلى مفاتيح الأدوار الفعلية: Spatie compat + أدوار المؤسسة السياقية.
            $users->getCollection()->transform(function ($user) {
                $user->setAttribute('roles', $this->roleKeysForUser($user));
                $user->unsetRelation('roles');
                $user->unsetRelation('activeScopedRoles');

                return $user;
            });

            return response()->json($users);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'index');
        }
    }

    /**
     * إحصائيات المستخدمين
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', User::class);

            $user = $request->user();
            $baseQuery = User::query();

            $this->applyUserVisibility($baseQuery, $user);

            return response()->json([
                'total' => (clone $baseQuery)->count(),
                'active' => (clone $baseQuery)->where('is_active', true)->count(),
                'inactive' => (clone $baseQuery)->where('is_active', false)->count(),
                'admins' => (clone $baseQuery)
                    ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'super_admin']))
                    ->count(),
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'stats');
        }
    }

    /**
     * إنشاء مستخدم جديد
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $validated['password'] = Hash::make($validated['password']);
            $validated['created_by'] = $request->user()->id;
            $roles = $validated['roles'] ?? [];
            unset($validated['roles']);

            // فحص تصعيد الأدوار — canAssignRole عبر StoreUserRequest::withValidator
            // + UserRoleAssignmentGuard (defense-in-depth, Phase 3). الـ Guard وحده
            // يرفض super_admin مع 403 — لا حذف صامت هنا (Phase 3 v3 rule).

            // قفل المؤسسة
            $currentUser = $request->user();
            if (! $currentUser->isSuperAdmin()) {
                if ($currentUser->organization_id === null) {
                    return response()->json([
                        'message' => 'المستخدم لا ينتمي لمؤسسة',
                    ], 403);
                }
                $validated['organization_id'] = $currentUser->organization_id;
            } else {
                $validated['organization_id'] = $validated['organization_id'] ?? $currentUser->organization_id;
            }

            // السماح بتعيين حالة النشاط فقط للمسؤولين
            if (array_key_exists('is_active', $validated) && ! $request->user()->hasAnyRole(['super_admin', 'admin'])) {
                unset($validated['is_active']);
            }

            $user = User::create($validated);

            if (! empty($roles)) {
                // Phase 3: UserRoleAssignmentGuard is the single defense-in-depth
                // layer for cross-org + escalation checks. It validates:
                //   - super_admin in roles ⇒ only super_admin actor
                //   - cross-org target ⇒ 403
                //   - null-org actor/target ⇒ 403 (non-super_admin)
                //   - self-escalation ⇒ 403 for strictly-higher levels
                //   - AssignableRoleKey + RoleHierarchy matrix
                app(UserRoleAssignmentGuard::class)->assertCanAssign($currentUser, $user, $roles);

                // Phase 4 (ADR-UNIFIED-ROLE-ACCESS): route role assignment through
                // the single helper so the engine recognizes the roles via their
                // org-scope scoped assignment (Spatie kept only for the compat set).
                RoleController::applyRoleAssignment($user, $roles);
            }

            $user->load(['department']);
            $userData = $user->toArray();
            $userData['roles'] = $this->roleKeysForUser($user);

            return response()->json([
                'message' => 'تم إنشاء المستخدم بنجاح',
                'user' => $userData,
            ], 201);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'store');
        }
    }

    /**
     * عرض مستخدم محدد
     */
    public function show(ViewUserRequest $request, string $id): JsonResponse
    {
        try {
            $user = User::with(['department', 'creator:id,name', 'updater:id,name'])->findOrFail($id);

            // Phase CFA-07 (HIGH PII) — cluster limited directory widening.
            //
            // ViewUserRequest already ran the existing `view` policy which keeps
            // its strict same-org semantics and returns 403 for cross-org targets.
            // However, an actor holding USERS_VIEW + CLUSTER_TREE_VIEW on
            // actor.organization_id is admitted by viewDirectory() for cross-org
            // targets in the cluster tree. For those actors we MUST return the
            // sanitized directory shape — never the full UserResource shape
            // (which would leak password / tokens / 2FA / last_login_ip / etc).
            //
            // Same-org requests continue to receive the full shape unchanged;
            // only cross-org requests routed through the cluster widening get
            // the directory resource.
            //
            // The widening check mirrors UserPolicy::viewDirectory exactly (super_admin
            // bypass, ancestor walk, both capabilities required) so the policy
            // method and the controller sanitization cannot drift apart.
            $actor = $request->user();
            if ($actor instanceof User && $this->isCrossOrgClusterRead($actor, $user)) {
                return UserDirectoryResource::make($user)->response();
            }

            // تحويل الـ roles و permissions إلى مصفوفة أسماء
            $userData = $user->toArray();
            $userData['roles'] = $this->roleKeysForUser($user);
            $userData['permissions'] = $user->getAllPermissions()->pluck('name')->toArray();

            return response()->json($userData);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'show');
        }
    }

    /**
     * Cluster widening predicate for the show endpoint.
     *
     * Returns true iff the actor is admitted by UserPolicy::viewDirectory() AND
     * the target sits in a different organization than the actor (a directory
     * widening only kicks in cross-org — same-org reads keep the full shape).
     *
     * Phase CFA-07 strict contract:
     *   - super_admin: same-org shows continue through the regular path, no
     *     cluster-widening branch needed.
     *   - non-super_admin: identical to viewDirectory() then a same-org guard.
     */
    private function isCrossOrgClusterRead(User $actor, User $target): bool
    {
        if ($actor->isSuperAdmin()) {
            return false;
        }

        if ($actor->organization_id === null || $target->organization_id === null) {
            return false;
        }

        if ((int) $actor->organization_id === (int) $target->organization_id) {
            return false;
        }

        return $actor->can('viewDirectory', $target);
    }

    /**
     * تحديث مستخدم
     */
    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            $validated = $request->validated();

            if (! empty($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }

            $roles = $validated['roles'] ?? null;
            unset($validated['roles']);

            // فحص تصعيد الأدوار — canAssignRole عبر UpdateUserRequest::withValidator

            // منع تصعيد الصلاحيات: لا يمكن تعيين super_admin عبر API
            if ($roles !== null) {
                $roles = array_diff($roles, ['super_admin']);
            }

            // منع نقل المستخدم لمؤسسة أخرى
            $currentUser = $request->user();
            if (! $currentUser->isSuperAdmin()) {
                if (array_key_exists('organization_id', $validated)) {
                    unset($validated['organization_id']);
                }
            }

            // M-09: a non-admin cannot move their own department; and any
            // submitted department_id must belong to the target user's org.
            if (array_key_exists('department_id', $validated)) {
                $isAdmin = $currentUser->hasAnyRole(['super_admin', 'admin']);
                if (! $isAdmin && $currentUser->id === $user->id) {
                    unset($validated['department_id']);
                } elseif (! $currentUser->isSuperAdmin() && $validated['department_id'] !== null) {
                    $dept = Department::find($validated['department_id']);
                    if (! $dept || $dept->organization_id !== $user->organization_id) {
                        unset($validated['department_id']);
                    }
                }
            }

            // السماح بتعديل حالة النشاط فقط للمسؤولين
            if (array_key_exists('is_active', $validated) && ! $request->user()->hasAnyRole(['super_admin', 'admin'])) {
                unset($validated['is_active']);
            }

            $validated['updated_by'] = $request->user()->id;
            $user->update($validated);

            if ($roles !== null) {
                // Phase 3: UserRoleAssignmentGuard mirrors the same checks used in
                // store(). The role-escalation guard inside UpdateUserRequest
                // (withValidator) already returned a 422 for non-engine cases; the
                // Guard here is the second layer that also catches the org-isolation
                // and self-escalation paths the FormRequest can't see.
                app(UserRoleAssignmentGuard::class)->assertCanAssign($currentUser, $user, $roles);

                RoleController::applyRoleAssignment($user, $roles);
            }

            $user->load(['department']);
            $userData = $user->toArray();
            $userData['roles'] = $this->roleKeysForUser($user);

            return response()->json([
                'message' => 'تم تحديث المستخدم بنجاح',
                'user' => $userData,
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'update');
        }
    }

    /**
     * حذف مستخدم
     */
    public function destroy(DeleteUserRequest $request, string $id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            // Authz against the user's `delete` ability already enforced by
            // DeleteUserRequest.

            $user->delete();

            return response()->json([
                'message' => 'تم حذف المستخدم بنجاح',
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'destroy');
        }
    }

    /**
     * @return array<int, string>
     */
    private function roleKeysForUser(User $user): array
    {
        $spatieRoles = $user->relationLoaded('roles')
            ? $user->roles->pluck('name')->all()
            : $user->getRoleNames()->all();

        $scopedRoles = $user->relationLoaded('activeScopedRoles')
            ? $user->activeScopedRoles
                ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
                ->when($user->organization_id !== null, fn ($roles) => $roles->where('scope_id', $user->organization_id))
                ->pluck('role')
                ->all()
            : $user->activeScopedRoles()
                ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
                ->when($user->organization_id !== null, fn ($query) => $query->where('scope_id', $user->organization_id))
                ->pluck('role')
                ->all();

        return array_values(array_unique(array_merge($spatieRoles, $scopedRoles)));
    }

    /**
     * قائمة المستخدمين للاختيار (dropdown)
     */
    public function list(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', User::class);

            $user = $request->user();
            $query = User::select('id', 'name', 'email', 'job_title', 'department_id')
                ->where('is_active', true);

            $this->applyUserVisibility($query, $user);

            // فلترة بقسم واحد
            if ($request->has('department_id') && is_numeric($request->department_id)) {
                $query->where('department_id', (int) $request->department_id);
            }

            // فلترة بعدة أقسام
            if ($request->has('department_ids')) {
                $departmentIds = is_array($request->department_ids)
                    ? $request->department_ids
                    : explode(',', $request->department_ids);
                // فلترة القيم الرقمية فقط
                $departmentIds = array_filter($departmentIds, 'is_numeric');
                if (! empty($departmentIds)) {
                    $query->whereIn('department_id', array_map('intval', $departmentIds));
                }
            }

            return response()->json($query->orderBy('name')->get());
        } catch (\Throwable $e) {
            return $this->handleException($e, 'list');
        }
    }
}
