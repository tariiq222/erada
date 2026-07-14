<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Data\AssignmentWrite;
use App\Modules\Core\Authorization\Data\RoleAssignmentWrite;
use App\Modules\Core\Authorization\Exceptions\AuthorizationAssignmentDenied;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Services\AuthorizationAssignmentService;
use App\Modules\Core\Http\Requests\DeleteUserRequest;
use App\Modules\Core\Http\Requests\StoreUserRequest;
use App\Modules\Core\Http\Requests\UpdateUserRequest;
use App\Modules\Core\Http\Requests\ViewUserRequest;
use App\Modules\Core\Http\Resources\UserDirectoryResource;
use App\Modules\Core\Models\User;
use App\Modules\Core\Scopes\UserOrganizationScope;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Models\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            $query = User::with(['department', 'creator:id,name', 'activeCanonicalRoleAssignments.role:id,name,is_active']);

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

            // تحويل الأدوار إلى مفاتيح التعيينات القانونية والسياقية الفعلية.
            $users->getCollection()->transform(function ($user) {
                $user->setAttribute('roles', $this->roleKeysForUser($user));
                $user->unsetRelation('activeCanonicalRoleAssignments');

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
                    ->whereHas('canonicalRoleAssignments', function ($assignment) {
                        $assignment
                            ->where(function ($query) {
                                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                            })
                            ->where(function ($scope) {
                                $scope->where('scope_type', AuthorizationRoleAssignment::SCOPE_ALL)
                                    ->orWhere(function ($organization) {
                                        $organization
                                            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_ORGANIZATION)
                                            ->whereColumn('authorization_role_assignments.scope_id', 'users.organization_id');
                                    });
                            })
                            ->whereHas('role', fn ($role) => $role
                                ->where('is_active', true)
                                ->where('is_admin_role', true));
                    })
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
            $assignments = $validated['assignments'] ?? null;
            unset($validated['assignments']);

            // Canonical assignment authorization stays inside the same transaction
            // as user creation. The assignment service enforces actor, target,
            // role, scope, and privilege-escalation constraints before commit.

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
            if (array_key_exists('is_active', $validated) && ! $this->canManageUserLifecycle($request->user())) {
                unset($validated['is_active']);
            }

            $user = DB::transaction(function () use ($assignments, $currentUser, $validated): User {
                $user = User::create($validated);

                if ($assignments !== null) {
                    app(AuthorizationAssignmentService::class)->syncManual(
                        $currentUser,
                        $user,
                        $this->canonicalAssignmentWrites($assignments),
                    );
                }

                return $user;
            });

            $user->load(['department']);
            $userData = $user->toArray();
            $userData['roles'] = $this->roleKeysForUser($user);

            return response()->json([
                'message' => 'تم إنشاء المستخدم بنجاح',
                'user' => $userData,
            ], 201);
        } catch (AuthorizationAssignmentDenied $e) {
            return response()->json(['message' => $e->getMessage()], 403);
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
            $userData['permissions'] = $user->canonicalCapabilityNames();

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
            $currentUser = $request->user();

            // CSD-CA23078-CORE-008: Organization Super Admin target validation (UPDATE).
            $this->assertOrgSuperTargetIsMutable($currentUser, $user, $request, 'users.edit');

            $validated = $request->validated();

            if (! $currentUser->isSuperAdmin() && $currentUser->isOrganizationSuperAdmin()
                && (int) $user->id === (int) $currentUser->id
                && array_key_exists('organization_id', $request->all())
                && (int) $request->input('organization_id') !== (int) $currentUser->organization_id) {
                throw ValidationException::withMessages([
                    'organization_id' => ['لا يمكن للمسؤول العام للمؤسسة نقل نفسه لمؤسسة أخرى.'],
                ]);
            }

            if (! empty($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }

            $assignments = $validated['assignments'] ?? null;
            unset($validated['assignments']);

            // منع نقل المستخدم لمؤسسة أخرى
            if (! $currentUser->isSuperAdmin()) {
                if (array_key_exists('organization_id', $validated)) {
                    unset($validated['organization_id']);
                }
            }

            // M-09: a non-admin cannot move their own department; and any
            // submitted department_id must belong to the target user's org.
            if (array_key_exists('department_id', $validated)) {
                $isAdmin = $this->canManageUserLifecycle($currentUser);
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
            if (array_key_exists('is_active', $validated) && ! $this->canManageUserLifecycle($request->user())) {
                unset($validated['is_active']);
            }

            $validated['updated_by'] = $request->user()->id;
            DB::transaction(function () use ($assignments, $currentUser, $user, $validated): void {
                $user->update($validated);

                if ($assignments !== null) {
                    app(AuthorizationAssignmentService::class)->syncManual(
                        $currentUser,
                        $user,
                        $this->canonicalAssignmentWrites($assignments),
                    );
                }
            });

            $user->load(['department']);
            $userData = $user->toArray();
            $userData['roles'] = $this->roleKeysForUser($user);

            return response()->json([
                'message' => 'تم تحديث المستخدم بنجاح',
                'user' => $userData,
            ]);
        } catch (AuthorizationAssignmentDenied $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'update');
        }
    }

    /**
     * @param  list<array{role_id: int, scope_type: string, scope_id?: int|null, inherit_to_children?: bool, expires_at?: string|null}>  $assignments
     * @return list<RoleAssignmentWrite>
     */
    private function canonicalAssignmentWrites(array $assignments): array
    {
        $roles = AuthorizationRole::query()
            ->whereKey(collect($assignments)->pluck('role_id')->all())
            ->get()
            ->keyBy('id');

        return collect($assignments)->map(function (array $payload) use ($roles): RoleAssignmentWrite {
            $role = $roles->get((int) $payload['role_id']);
            if ($role === null) {
                throw ValidationException::withMessages([
                    'assignments' => ['الدور المطلوب غير موجود.'],
                ]);
            }

            return new RoleAssignmentWrite(
                $role,
                new AssignmentWrite(
                    new AssignmentScope(
                        $payload['scope_type'],
                        $payload['scope_id'] ?? null,
                        (bool) ($payload['inherit_to_children'] ?? false),
                    ),
                    isset($payload['expires_at']) ? CarbonImmutable::parse($payload['expires_at']) : null,
                ),
            );
        })->values()->all();
    }

    /**
     * حذف مستخدم
     */
    public function destroy(DeleteUserRequest $request, string $id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            // CSD-CA23078-CORE-008: Organization Super Admin target validation (DELETE).
            // Per user policy: OrgSuper cannot update OR delete
            // OrganizationSuperAdmin/PlatformSuperAdmin targets.
            $this->assertOrgSuperTargetIsMutable($request->user(), $user, $request, 'users.delete');

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
        return $user->canonicalRoleNames();
    }

    private function canManageUserLifecycle(User $user): bool
    {
        return $user->isSuperAdmin()
            || $user->isOrganizationSuperAdmin()
            || AccessDecision::canonicalTrace($user, Capability::USERS_MANAGE_ACCESS)['granted']
            || AccessDecision::can($user, Capability::USERS_ACTIVATE)
            || AccessDecision::can($user, Capability::USERS_DEACTIVATE);
    }

    /**
     * @throws ValidationException with 422 envelope when OrgSuper targets
     *                             a `super_admin` or `organization_super_admin` user.
     */
    private function assertOrgSuperTargetIsMutable(User $actor, User $target, Request $request, string $requestedCapability): void
    {
        if ($actor->isSuperAdmin() || ! $actor->isOrganizationSuperAdmin()) {
            return;
        }
        if ((int) $actor->id === (int) $target->id) {
            // Self-mutation: UPDATE-time organization_id check fires here too;
            // DELETE-time self-delete is enforced by UserPolicy::delete().
            return;
        }

        $protectedTarget = AuthorizationRoleAssignment::query()
            ->where('user_id', $target->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('role', fn ($role) => $role
                ->whereIn('name', ['super_admin', 'organization_super_admin'])
                ->where('is_active', true))
            ->exists();

        if (! $protectedTarget) {
            return;
        }

        ActivityLog::create([
            'user_id' => $actor->id,
            'action' => ActivityLog::ACTION_ACCESS_DENIED,
            'description' => "محاولة تعديل/حذف مستخدم محمي (super_admin/organization_super_admin): {$target->name}",
            'loggable_type' => User::class,
            'loggable_id' => $target->id,
            'metadata' => [
                'provenance' => 'organization_super_admin',
                'requested_capability' => $requestedCapability,
                'request_id' => $request->header('X-Request-Id'),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        throw ValidationException::withMessages([
            'user_id' => ['لا يمكن تعديل أو حذف مستخدم يحمل دور super_admin أو organization_super_admin.'],
        ]);
    }

    /**
     * قائمة المستخدمين للاختيار (dropdown)
     */
    public function list(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', User::class);

            $user = $request->user();
            $scope = app(UserOrganizationScope::class);
            $clusterDirectory = $scope->canViewClusterDirectory($user);
            $columns = $clusterDirectory
                ? UserDirectoryResource::WHITELISTED_KEYS
                : ['id', 'name', 'email', 'job_title', 'department_id'];
            $query = User::select($columns)->where('is_active', true);

            if ($clusterDirectory) {
                $scope->applyToUsersClusterDirectory($query, $user);
            } else {
                $this->applyUserVisibility($query, $user);
            }

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

            $users = $query->orderBy('name')->get();

            if ($clusterDirectory) {
                return response()->json(
                    UserDirectoryResource::collection($users)->resolve($request)
                );
            }

            return response()->json($users);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'list');
        }
    }
}
