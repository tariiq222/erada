<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Contracts\AuthorizationAssignmentActorGuard;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Data\AssignmentWrite;
use App\Modules\Core\Authorization\Data\RoleAssignmentWrite;
use App\Modules\Core\Authorization\Exceptions\AuthorizationAssignmentDenied;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Services\AuthorizationAssignmentService;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Http\Requests\AssignCanonicalRolesRequest;
use App\Modules\Core\Http\Requests\DeleteRoleRequest;
use App\Modules\Core\Http\Requests\StoreRoleRequest;
use App\Modules\Core\Http\Requests\UpdateRoleRequest;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Canonical role catalog and assignments. */
class RoleController extends Controller
{
    public function __construct(
        private readonly AuthorizationAssignmentActorGuard $assignmentActorGuard,
    ) {}

    public function index(): JsonResponse
    {
        $roles = AuthorizationRole::query()
            ->with(['permissions.resource'])
            ->withCount(['assignments as users_count' => fn ($query) => $query
                ->where(fn ($active) => $active->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->select(DB::raw('count(distinct user_id)'))])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (AuthorizationRole $role) => $this->roleData($role));

        return response()->json(['data' => $roles, 'meta' => ['total' => $roles->count()]]);
    }

    public function show(AuthorizationRole $roleDefinition): JsonResponse
    {
        $roleDefinition->load(['permissions.resource', 'assignments.user:id,name,email']);
        $roleDefinition->setAttribute('users_count', $roleDefinition->assignments
            ->filter(fn ($assignment) => $assignment->expires_at === null || $assignment->expires_at->isFuture())
            ->pluck('user_id')->unique()->count());

        return response()->json(['data' => $this->roleData($roleDefinition, true)]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $capabilities = $this->validatedCapabilities($validated);

        $role = DB::transaction(function () use ($validated, $capabilities, $request): AuthorizationRole {
            $label = $validated['label'] ?? $validated['label_ar'] ?? $validated['label_en'] ?? $validated['name'];
            $role = AuthorizationRole::query()->create([
                'name' => $validated['name'],
                'label' => $label,
                'label_ar' => $validated['label_ar'] ?? $label,
                'label_en' => $validated['label_en'] ?? $label,
                'scope_type' => $validated['scope_type'] ?? 'organization',
                'is_admin_role' => false,
                'is_system' => false,
                'is_active' => true,
            ]);
            $this->syncCapabilities($role, $capabilities, $validated['reach'] ?? []);
            $this->writeAudit('role_created', $role, null, $capabilities, $request);

            return $role;
        });

        return response()->json(['message' => 'تم إنشاء الدور بنجاح', 'data' => $this->roleData($role->load('permissions.resource'))], 201);
    }

    public function update(UpdateRoleRequest $request, AuthorizationRole $roleDefinition): JsonResponse
    {
        if ($this->isSystemRole($roleDefinition)) {
            return response()->json(['message' => 'لا يمكن تعديل الدور الأساسي'], 403);
        }

        $validated = $request->validated();
        if (($validated['is_active'] ?? true) === false) {
            return $this->disableRole($request, $roleDefinition);
        }

        $oldCapabilities = $this->capabilitiesFor($roleDefinition->load('permissions.resource'));
        $capabilities = $this->validatedCapabilities($validated, $oldCapabilities);

        DB::transaction(function () use ($validated, $capabilities, $oldCapabilities, $roleDefinition, $request): void {
            $lockedRole = AuthorizationRole::query()->whereKey($roleDefinition->id)->lockForUpdate()->firstOrFail();
            $newScopeType = $validated['scope_type'] ?? $lockedRole->scope_type;
            if ($newScopeType !== $lockedRole->scope_type) {
                $incompatibleAssignment = AuthorizationRoleAssignment::query()
                    ->where('authorization_role_id', $lockedRole->id)
                    ->where('scope_type', '!=', $newScopeType)
                    ->lockForUpdate()
                    ->first();

                if ($incompatibleAssignment !== null) {
                    throw ValidationException::withMessages([
                        'scope_type' => 'Role scope cannot change while incompatible assignments exist.',
                    ]);
                }
            }

            $roleDefinition->update(array_filter([
                'name' => $validated['name'] ?? null,
                'label' => $validated['label'] ?? $validated['label_ar'] ?? null,
                'label_ar' => $validated['label_ar'] ?? null,
                'label_en' => $validated['label_en'] ?? null,
                'scope_type' => $validated['scope_type'] ?? null,
            ], fn ($value) => $value !== null));

            if ($this->hasCapabilityPayload($validated) || array_key_exists('reach', $validated)) {
                $this->syncCapabilities($roleDefinition, $capabilities, $validated['reach'] ?? $this->reachFor($roleDefinition));
            }
            $this->writeAudit('role_updated', $roleDefinition, $oldCapabilities, $capabilities, $request);
        });

        return response()->json(['message' => 'تم تحديث الدور بنجاح', 'data' => $this->roleData($roleDefinition->refresh()->load('permissions.resource'))]);
    }

    public function destroy(DeleteRoleRequest $request, AuthorizationRole $roleDefinition): JsonResponse
    {
        if ($this->isSystemRole($roleDefinition)) {
            return response()->json(['message' => 'لا يمكن حذف الدور الأساسي'], 403);
        }

        return $this->disableRole($request, $roleDefinition);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => collect(Capability::all())->sort()->values()]);
    }

    public function abilities(): JsonResponse
    {
        $groups = collect(Capability::all())->groupBy(fn (string $capability) => explode('.', $capability, 2)[0])
            ->map(fn ($capabilities, $module) => [
                'key' => $module,
                'label' => $module,
                'abilities' => $capabilities->sort()->values()->map(fn ($capability) => ['id' => $capability, 'label' => $capability])->all(),
            ])->values();

        return response()->json(['data' => ['groups' => $groups]]);
    }

    public function scopeOptions(): JsonResponse
    {
        $scopes = collect(AssignmentScope::catalog())
            ->map(fn (array $scope) => ['key' => $scope['key'], 'label' => $scope['label_ar']])
            ->values();

        return response()->json(['scopes' => $scopes]);
    }

    public function assignToUser(AssignCanonicalRolesRequest $request, AuthorizationAssignmentService $assignmentService): JsonResponse
    {
        $validated = $request->validated();
        /** @var User $actor */
        $actor = $request->user();
        $subject = User::query()->findOrFail($validated['user_id']);
        $roles = AuthorizationRole::query()->where('is_active', true)
            ->whereKey(collect($validated['assignments'])->pluck('role_id')->all())->get()->keyBy('id');
        $writes = collect($validated['assignments'])->map(function (array $payload) use ($roles): RoleAssignmentWrite {
            $role = $roles->get((int) $payload['role_id']);
            abort_if($role === null, 422, 'الدور المطلوب غير موجود أو غير نشط.');

            return new RoleAssignmentWrite($role, new AssignmentWrite(
                new AssignmentScope($payload['scope_type'], $payload['scope_id'] ?? null, (bool) ($payload['inherit_to_children'] ?? false)),
                isset($payload['expires_at']) ? CarbonImmutable::parse($payload['expires_at']) : null,
            ));
        })->values()->all();

        try {
            DB::transaction(function () use ($actor, $assignmentService, $request, $subject, $writes): void {
                User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
                $old = $this->canonicalAssignmentsFor($subject);
                $assignmentService->syncManual($actor, $subject, $writes);
                $new = $this->canonicalAssignmentsFor($subject);
                ActivityLog::logSystemRoleAssigned($subject->id, collect($new)->pluck('role_name')->filter()->values()->all(), $actor->id, json_encode([
                    'old_assignments' => $old, 'new_assignments' => $new, 'request_id' => $request->header('X-Request-Id'),
                ]));
            });
        } catch (AuthorizationAssignmentDenied $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                return response()->json(['message' => 'يتعارض الطلب مع تفويض موجود.'], 409);
            }
            throw $exception;
        }

        return response()->json(['message' => 'تم تعيين الأدوار والنطاقات بنجاح', 'data' => [
            'user_id' => $subject->id, 'assignments' => $this->canonicalAssignmentsFor($subject),
        ]]);
    }

    private function disableRole(Request $request, AuthorizationRole $role): JsonResponse
    {
        $assignmentCount = $role->assignments()->count();
        $replacementId = $request->integer('reassign_to_role_id') ?: null;
        if ($assignmentCount > 0 && $replacementId === null) {
            return response()->json(['message' => 'تعطيل الدور المسند يتطلب reassign_to_role_id صريحاً.', 'users_count' => $role->assignments()->distinct()->count('user_id')], 422);
        }

        $replacement = $replacementId ? AuthorizationRole::query()->where('is_active', true)->find($replacementId) : null;
        if ($replacementId && ($replacement === null || $replacement->is($role))) {
            return response()->json(['message' => 'دور إعادة الإسناد غير صالح.'], 422);
        }

        $old = $this->capabilitiesFor($role->load('permissions.resource'));
        /** @var User $actor */
        $actor = $request->user();

        try {
            DB::transaction(function () use ($actor, $role, $replacement, $old, $request): void {
                $lockedRoles = AuthorizationRole::query()
                    ->whereKey(array_filter([$role->id, $replacement?->id]))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                /** @var AuthorizationRole $lockedRole */
                $lockedRole = $lockedRoles->get($role->id) ?? throw new AuthorizationAssignmentDenied('The role no longer exists.');
                $lockedReplacement = $replacement === null ? null : $lockedRoles->get($replacement->id);

                if ($lockedReplacement !== null && ! $lockedReplacement->is_active) {
                    throw new AuthorizationAssignmentDenied('Inactive roles cannot receive reassigned grants.');
                }

                $assignments = AuthorizationRoleAssignment::query()
                    ->where('authorization_role_id', $lockedRole->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($assignments->isNotEmpty() && $lockedReplacement === null) {
                    throw new AuthorizationAssignmentDenied('Assigned roles require an explicit replacement.');
                }

                if ($lockedReplacement !== null) {
                    User::query()->whereKey($assignments->pluck('user_id')->unique()->sort()->values())
                        ->orderBy('id')->lockForUpdate()->get();

                    foreach ($assignments as $assignment) {
                        $subject = User::query()->find($assignment->user_id);
                        $scope = new AssignmentScope(
                            (string) $assignment->scope_type,
                            $assignment->scope_id === null ? null : (int) $assignment->scope_id,
                            (bool) $assignment->inherit_to_children,
                        );

                        if ($subject === null || ! $this->assignmentActorGuard->allows($actor, $subject, $lockedReplacement, $scope)) {
                            throw new AuthorizationAssignmentDenied('The actor cannot reassign this canonical grant.');
                        }

                        $duplicate = AuthorizationRoleAssignment::query()
                            ->where('authorization_role_id', $lockedReplacement->id)
                            ->where('user_id', $assignment->user_id)
                            ->where('scope_type', $assignment->scope_type)
                            ->where(function ($query) use ($assignment): void {
                                $assignment->scope_id === null
                                    ? $query->whereNull('scope_id')
                                    : $query->where('scope_id', $assignment->scope_id);
                            })->lockForUpdate()->exists();

                        if ($duplicate) {
                            throw new AuthorizationAssignmentDenied('A replacement assignment already exists for this scope.');
                        }
                    }

                    AuthorizationRoleAssignment::query()
                        ->whereKey($assignments->pluck('id'))
                        ->update(['authorization_role_id' => $lockedReplacement->id, 'updated_at' => now()]);
                    DB::afterCommit(static fn () => AccessDecision::flushCache());
                }

                $lockedRole->update(['is_active' => false]);
                $this->writeAudit('role_disabled', $lockedRole, $old, null, $request);
            }, 3);
        } catch (AuthorizationAssignmentDenied $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return response()->json(['message' => 'تم تعطيل الدور بنجاح']);
    }

    /** @return array<string, mixed> */
    private function roleData(AuthorizationRole $role, bool $includeUsers = false): array
    {
        $data = [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->label,
            'display_name' => $role->label_ar ?: $role->label,
            'label_ar' => $role->label_ar,
            'label_en' => $role->label_en,
            'scope_type' => $role->scope_type,
            'permissions' => $this->capabilitiesFor($role),
            'capabilities' => $this->capabilitiesFor($role),
            'reach' => $this->reachFor($role),
            'is_system' => $this->isSystemRole($role),
            'is_admin_role' => (bool) $role->is_admin_role,
            'is_active' => (bool) $role->is_active,
            'users_count' => (int) ($role->users_count ?? 0),
        ];
        if ($includeUsers) {
            $data['users'] = $role->assignments->filter(fn ($assignment) => $assignment->expires_at === null || $assignment->expires_at->isFuture())
                ->pluck('user')->filter()->unique('id')->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])->values();
        }

        return $data;
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

    /** @return array<string, string> */
    private function reachFor(AuthorizationRole $role): array
    {
        return $role->permissions->pluck('reach')->filter()->reduce(fn (array $carry, array $reach) => array_replace($carry, $reach), []);
    }

    /** @param list<string> $capabilities */
    private function syncCapabilities(AuthorizationRole $role, array $capabilities, array $reach): void
    {
        $rows = collect($capabilities)->map(function (string $capability) use ($role, $reach): array {
            $mapping = CapabilityToAuthorizationRolePermission::map($capability);
            abort_if($mapping === null, 422, "القدرة غير معروفة: {$capability}");
            $resource = AuthorizationResource::query()->firstOrCreate(['key' => $mapping['resource']], ['label' => class_basename($mapping['resource'])]);

            return ['authorization_role_id' => $role->id, 'authorization_resource_id' => $resource->id, 'action' => $mapping['action'], 'reach' => $reach === [] ? null : json_encode($reach)];
        })->all();
        AuthorizationRolePermission::query()->where('authorization_role_id', $role->id)->delete();
        if ($rows !== []) {
            DB::table('authorization_role_permissions')->insert($rows);
        }
    }

    /** @return list<string> */
    private function validatedCapabilities(array $validated, array $default = []): array
    {
        return array_values(array_unique($validated['capabilities'] ?? $default));
    }

    private function hasCapabilityPayload(array $validated): bool
    {
        return array_key_exists('capabilities', $validated);
    }

    private function isSystemRole(AuthorizationRole $role): bool
    {
        return (bool) $role->is_system || in_array($role->name, ['super_admin', 'admin', 'viewer'], true);
    }

    /** @return list<array<string, mixed>> */
    private function canonicalAssignmentsFor(User $user): array
    {
        return AuthorizationRoleAssignment::query()->with('role:id,name,label')->where('user_id', $user->id)->orderBy('id')->get()
            ->map(fn (AuthorizationRoleAssignment $assignment) => [
                'id' => $assignment->id, 'role_id' => $assignment->authorization_role_id, 'role_name' => $assignment->role?->name,
                'scope_type' => $assignment->scope_type, 'scope_id' => $assignment->scope_id, 'organization_id' => $assignment->organization_id,
                'inherit_to_children' => $assignment->inherit_to_children, 'expires_at' => $assignment->expires_at?->toIso8601String(),
                'source' => $assignment->source, 'granted_by' => $assignment->granted_by,
            ])->all();
    }

    private function writeAudit(string $event, AuthorizationRole $role, ?array $old, ?array $new, Request $request): void
    {
        DB::table('authorization_assignment_audits')->insert([
            'event' => $event, 'actor_id' => $request->user()?->id, 'target_user_id' => null,
            'scope_type' => $role->scope_type, 'scope_id' => null, 'role' => $role->name,
            'old_value' => $old === null ? null : json_encode($old), 'new_value' => $new === null ? null : json_encode($new),
            'reason' => 'canonical role mutation', 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'created_at' => now(),
        ]);
    }
}
