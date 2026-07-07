<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Requests\DeleteRoleRequest;
use App\Modules\Core\Http\Requests\StoreRoleRequest;
use App\Modules\Core\Http\Requests\UpdateRoleRequest;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\AssignableRoleKey;
use App\Modules\Core\Support\UserRoleAssignmentGuard;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

/**
 * RoleController - إدارة الأدوار والصلاحيات (System Roles)
 *
 * ملاحظة: فقط super_admin يمكنه إدارة الأدوار
 *
 * Phase 4b (ADR-UNIFIED-ROLE-ACCESS): scoped_role_definitions is the SINGLE
 * source for role definitions. CRUD operates on the definition id — no Spatie
 * Role is created/updated/deleted for a definition. The thin Spatie compat set
 * (super_admin/admin/viewer) is kept only for ASSIGNMENT (applyRoleAssignment).
 */
class RoleController extends Controller
{
    /**
     * List org-scope role definitions (the single-source definition list).
     */
    public function index(): JsonResponse
    {
        $orgScopeTypeId = $this->getOrgScopeTypeId();

        $defs = $orgScopeTypeId === null
            ? collect()
            : ScopedRoleDefinition::where('scope_type_id', $orgScopeTypeId)
                ->where('is_active', true)
                ->ordered()
                ->get();

        $userCounts = $this->orgRoleUserCounts();

        $roles = $defs->map(function (ScopedRoleDefinition $def) use ($userCounts) {
            return $this->definitionData($def, [
                'permissions_count' => count($def->permissions ?? []),
                'users_count' => $userCounts[$def->role_key] ?? 0,
            ]);
        })->values();

        return response()->json([
            'data' => $roles,
            'meta' => [
                'total' => $roles->count(),
            ],
        ]);
    }

    /**
     * Show a single role definition (bound by definition id).
     */
    public function show(ScopedRoleDefinition $roleDefinition): JsonResponse
    {
        return response()->json([
            'data' => $this->definitionData($roleDefinition, [
                'guard_name' => 'web',
                'users' => $this->usersForRole($roleDefinition->role_key, $roleDefinition->scope_type),
                'updated_at' => $roleDefinition->updated_at,
            ]),
        ]);
    }

    /**
     * Create a role DEFINITION (scoped_role_definitions only — no Spatie role).
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        // Authorization is enforced by the route middleware: role:super_admin.

        $validated = $request->validated();

        // super_admin is a pure system role and cannot be (re)defined here.
        if ($validated['name'] === 'super_admin') {
            return response()->json([
                'message' => 'لا يمكن إنشاء دور باسم super_admin',
            ], 403);
        }

        $scopeKey = $validated['scope_type'] ?? 'organization';
        $scopeTypeId = ScopeType::findByKey($scopeKey)?->id;

        if ($scopeTypeId === null) {
            return response()->json([
                'message' => 'النطاق غير معروف',
            ], 422);
        }

        $roleKey = $validated['name'];
        $capabilities = $validated['permissions_capabilities'] ?? [];

        $def = DB::transaction(function () use ($validated, $scopeKey, $scopeTypeId, $roleKey, $capabilities, $request) {
            $now = now();

            // updateOrInsert keyed on (scope, role_key): a fresh name inserts; a
            // previously soft-deleted name is reactivated in place (its unique
            // (name, scope_type) row already exists, so a plain insert would fail).
            // ponytail: reactivation overwrites created_at — acceptable for a rare re-create.
            DB::table('scoped_role_definitions')->updateOrInsert(
                ['scope_type_id' => $scopeTypeId, 'role_key' => $roleKey],
                [
                    // legacy NOT NULL columns (not in $fillable)
                    'name' => $scopeKey.'.'.$roleKey,
                    'display_name' => $validated['label_ar'] ?? $roleKey,
                    'scope_type' => $scopeKey,
                    // unified columns
                    'label_ar' => $validated['label_ar'] ?? $roleKey,
                    'label_en' => $validated['label_en'] ?? $roleKey,
                    'permissions' => json_encode($capabilities),
                    'reach' => ! empty($validated['reach']) ? json_encode($validated['reach']) : null,
                    'is_admin_role' => false,
                    'is_active' => true,
                    'sort_order' => 100,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            ScopedRoleDefinition::clearCache();

            $def = ScopedRoleDefinition::where('scope_type_id', $scopeTypeId)
                ->where('role_key', $roleKey)
                ->first();

            $this->writePermissionAudit('role_created', $roleKey, null, $capabilities, $request, $scopeKey);

            return $def;
        });

        $this->logDefinitionActivity('created', $def, null, [
            'name' => $roleKey,
            'permissions' => $capabilities,
        ], $request);

        return response()->json([
            'message' => 'تم إنشاء الدور بنجاح',
            'data' => [
                'id' => $def->id,
                'name' => $def->role_key,
                'scope_type' => $scopeKey,
                'permissions' => $def->permissions ?? [],
            ],
        ], 201);
    }

    /**
     * Update a role DEFINITION (bound by definition id — no Spatie writes).
     */
    public function update(UpdateRoleRequest $request, ScopedRoleDefinition $roleDefinition): JsonResponse
    {
        // Authorization is enforced by the route middleware: role:super_admin.

        $validated = $request->validated();
        $def = $roleDefinition;
        $roleKey = $def->role_key;
        $scopeKey = $def->scope_type;

        // System (compat-set) definitions may not be renamed.
        if ($this->isSystemRole($roleKey)
            && array_key_exists('name', $validated)
            && $validated['name'] !== $roleKey) {
            return response()->json([
                'message' => 'لا يمكن تغيير اسم الدور الأساسي',
            ], 403);
        }

        $oldCapabilities = $def->permissions ?? [];

        // System definitions are read-only (capabilities owned by seeders/migrations).
        if (! $this->isSystemRole($roleKey)) {
            DB::transaction(function () use ($validated, $def, $scopeKey, $oldCapabilities, $request) {
                $updateData = [];

                if (isset($validated['name'])) {
                    $updateData['role_key'] = $validated['name'];
                    $updateData['name'] = $scopeKey.'.'.$validated['name'];
                }
                if (isset($validated['label_ar'])) {
                    $updateData['label_ar'] = $validated['label_ar'];
                    $updateData['display_name'] = $validated['label_ar'];
                }
                if (isset($validated['label_en'])) {
                    $updateData['label_en'] = $validated['label_en'];
                }
                if (array_key_exists('permissions_capabilities', $validated)
                    && $validated['permissions_capabilities'] !== null) {
                    $updateData['permissions'] = $validated['permissions_capabilities'];
                }
                if (array_key_exists('reach', $validated)) {
                    $updateData['reach'] = ! empty($validated['reach']) ? $validated['reach'] : null;
                }

                if (! empty($updateData)) {
                    $def->update($updateData); // model 'saved' hook clears caches
                }

                $newCapabilities = $validated['permissions_capabilities'] ?? $oldCapabilities;
                $this->writePermissionAudit('role_updated', $def->role_key, $oldCapabilities, $newCapabilities, $request, $scopeKey);
            });

            $def->refresh();
        }

        $this->logDefinitionActivity('updated', $def, [
            'name' => $roleKey,
            'permissions' => $oldCapabilities,
        ], [
            'name' => $def->role_key,
            'permissions' => $def->permissions ?? [],
        ], $request);

        return response()->json([
            'message' => 'تم تحديث الدور بنجاح',
            'data' => [
                'id' => $def->id,
                'name' => $def->role_key,
                'permissions' => $def->permissions ?? [],
            ],
        ]);
    }

    /**
     * Delete a role DEFINITION — soft-disable (is_active=false), bound by def id.
     */
    public function destroy(DeleteRoleRequest $request, ScopedRoleDefinition $roleDefinition): JsonResponse
    {
        // Authorization is enforced by the route middleware (and DeleteRoleRequest).

        $def = $roleDefinition;
        $roleKey = $def->role_key;

        // System (compat-set) definitions cannot be deleted.
        if ($this->isSystemRole($roleKey)) {
            return response()->json([
                'message' => 'لا يمكن حذف الدور الأساسي',
            ], 403);
        }

        // Cannot delete a role still assigned to users (scoped assignments).
        $usersCount = $this->usersCountForRole($roleKey, $def->scope_type);
        if ($usersCount > 0) {
            return response()->json([
                'message' => 'لا يمكن حذف دور مرتبط بمستخدمين. قم بإزالة المستخدمين أولاً.',
                'users_count' => $usersCount,
            ], 422);
        }

        $capabilities = $def->permissions ?? [];

        DB::transaction(function () use ($def, $roleKey, $capabilities) {
            $def->update(['is_active' => false]); // soft-disable; model hook clears caches

            $this->writePermissionAudit('role_deleted', $roleKey, $capabilities, null, request(), $def->scope_type);
        });

        $this->logDefinitionActivity('deleted', $def, [
            'name' => $roleKey,
            'permissions' => $capabilities,
        ], null, $request);

        return response()->json([
            'message' => 'تم حذف الدور بنجاح',
        ]);
    }

    /**
     * عرض جميع الصلاحيات المتاحة (نموذج المصفوفة القديم — يبقى للتوافق الخلفي).
     */
    public function permissions(): JsonResponse
    {
        $scoped = $this->scopedResources();
        $flat = $this->flatGroups($this->usedScopedPermissions($scoped));

        return response()->json([
            'data' => [
                'scoped' => $scoped,
                'flat' => $flat,
            ],
        ]);
    }

    /**
     * سجل القدرات الموحّد لبناء دور — المصدر الوحيد لواجهة إنشاء/تعديل الدور.
     *
     * كل مجموعة تحمل `store` يحدد أين تُكتب القدرة عند الحفظ:
     *  - engine: قدرة المحرّك (module.action من Capability) → scoped_role_definitions.permissions
     *  - flat:   صلاحية Spatie مسطّحة (لموديولات لم تُهاجَر للمحرّك بعد) → جدول permissions
     *
     * الموديولات الستة المحكومة بالمحرّك (projects/tasks/risks/ovr/strategy/departments)
     * تُعرض كقدرات بلا نطاق؛ النطاق يُحسم عند إسناد الدور، لا في تعريفه.
     */
    public function abilities(): JsonResponse
    {
        $engine = array_map(
            fn ($g) => $g + ['store' => 'engine'],
            $this->engineCapabilityGroups()
        );

        // Normalize flat groups ({permissions:[{name,display_name}]}) into the
        // shared ability shape ({abilities:[{id,label}]}) the builder consumes.
        $flat = array_map(fn ($g) => [
            'key' => $g['key'],
            'label' => $g['label'],
            'store' => 'flat',
            'abilities' => array_map(
                fn ($p) => ['id' => $p['name'], 'label' => $p['display_name']],
                $g['permissions']
            ),
        ], $this->flatGroups($this->engineFlatPermissionNames()));

        return response()->json([
            'data' => ['groups' => array_values(array_merge($engine, $flat))],
        ]);
    }

    /**
     * تعيين أدوار لمستخدم
     */
    public function assignToUser(Request $request): JsonResponse
    {
        if (! AccessDecision::can($request->user(), Capability::CORE_ASSIGN_ROLES)) {
            abort(403, 'غير مصرح بتعيين الأدوار');
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'roles' => 'required|array',
            'roles.*' => ['string', new AssignableRoleKey],
        ]);

        $user = User::findOrFail($validated['user_id']);
        $currentUser = auth()->user();

        // Phase 3: UserRoleAssignmentGuard is the single defense-in-depth layer
        // for cross-org + escalation checks. It supersedes the two inline
        // blocks that used to live here (super_admin escalation + cross-org).
        // The Guard never mutates the payload — it throws 403 or passes.
        app(UserRoleAssignmentGuard::class)->assertCanAssign($currentUser, $user, $validated['roles']);

        $oldRoles = $user->roles->pluck('name')->toArray();
        $this->applyRoleAssignment($user, $validated['roles']);

        // تسجيل في سجل الأنشطة
        ActivityLog::logSystemRoleAssigned(
            $user->id,
            $validated['roles'],
            $currentUser->id,
            'تغيير الأدوار من: '.implode(', ', $oldRoles)
        );

        return response()->json([
            'message' => 'تم تعيين الأدوار بنجاح',
            'data' => [
                'user_id' => $user->id,
                'roles' => $this->assignedRoleKeys($user),
            ],
        ]);
    }

    /**
     * The thin Spatie compat set (Phase 4, ADR-UNIFIED-ROLE-ACCESS). Only these
     * seeded roles keep a Spatie assignment:
     *  - super_admin: the `role:super_admin` middleware depends on it (Spatie only).
     *  - admin / viewer: kept as Spatie for their getRoleNames()-based /auth/me chips
     *    and legacy Spatie checks, AND mirrored to a scoped org role so the engine
     *    resolves them uniformly.
     * Every OTHER role is assigned ONLY as an org-scope scoped role — the engine
     * recognizes it via AccessDecision::orgFunctionalRoleKeysFor (Phase 4 decoupling).
     */
    private const COMPAT_SPATIE_ROLES = ['super_admin', 'admin', 'viewer'];

    /**
     * Apply a full set of org-functional role assignments for a user under the
     * Phase 4 model: Spatie only for the compat set, scoped org roles for the rest
     * (admin/viewer are mirrored to both). Behavior-preserving for seeded roles.
     *
     * @param  array<int, string>  $roles
     */
    public static function applyRoleAssignment(User $user, array $roles): void
    {
        $roles = array_values(array_unique($roles));

        // Spatie side: keep ONLY the compat-set roles the user was given.
        $user->syncRoles(array_values(array_intersect($roles, self::COMPAT_SPATIE_ROLES)));

        $orgId = $user->organization_id;
        if ($orgId === null) {
            // No org node to attach a scoped role to; the Spatie compat still applies.
            return;
        }

        // Scoped side: every assigned role EXCEPT super_admin becomes an org-scope
        // scoped role (admin/viewer are mirrored here in addition to Spatie).
        $scopedRoles = array_values(array_diff($roles, ['super_admin']));

        // Drop org-scope scoped roles no longer in the set, then (re)grant the rest.
        $user->activeScopedRoles()
            ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
            ->where('scope_id', $orgId)
            ->whereNotIn('role', $scopedRoles ?: ['__none__'])
            ->delete();

        AccessDecision::flushUserCache((int) $user->id);

        foreach ($scopedRoles as $role) {
            $exists = $user->scopedRoles()
                ->where('role', $role)
                ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
                ->where('scope_id', $orgId)
                ->exists();

            if (! $exists) {
                ScopedRole::create([
                    'user_id' => $user->id,
                    'role' => $role,
                    'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
                    'scope_id' => $orgId,
                    'inherit_to_children' => true,
                    'granted_by' => auth()->id(),
                ]);
            }
        }
    }

    /**
     * Scope options and per-scope role definitions — the source for the role editor pickers.
     */
    public function scopeOptions(): JsonResponse
    {
        $scopes = ScopeType::getAllActive()
            ->map(fn ($s) => ['key' => $s->key, 'label' => $s->label_ar ?: $s->key])
            ->values();

        $definitions = [];
        foreach ($scopes as $s) {
            $definitions[$s['key']] = collect(
                ScopedRoleDefinition::getRolesForType($s['key'])
            )->map(fn ($label, $key) => ['role_key' => $key, 'label' => $label])->values();
        }

        return response()->json([
            'scopes' => $scopes,
            'definitions' => $definitions,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function assignedRoleKeys(User $user): array
    {
        $spatieRoles = $user->getRoleNames()->all();
        $scopedRoles = $user->activeScopedRoles()
            ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
            ->when($user->organization_id !== null, fn ($query) => $query->where('scope_id', $user->organization_id))
            ->pluck('role')
            ->all();

        return array_values(array_unique(array_merge($spatieRoles, $scopedRoles)));
    }

    // ========== Private Helpers ==========

    /**
     * The API shape of a role definition, shared by index() and show().
     * `$extra` overrides/augments the base fields for the caller's context.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function definitionData(ScopedRoleDefinition $def, array $extra = []): array
    {
        $capabilities = $def->permissions ?? [];

        return array_merge([
            'id' => $def->id,
            'name' => $def->role_key,
            'display_name' => $def->label_ar ?: $this->getRoleDisplayName($def->role_key),
            'permissions' => $capabilities,
            'is_system' => $this->isSystemRole($def->role_key),
            'created_at' => $def->created_at,
            'scope_type' => $def->scope_type,
            'scoped_def_id' => $def->id,
            'label_ar' => $def->label_ar ?? $this->getRoleDisplayName($def->role_key),
            'label_en' => $def->label_en ?? $def->role_key,
            'capabilities' => $capabilities,
            'reach' => $def->reach ?: (object) [],
            'is_admin_role' => (bool) $def->is_admin_role,
        ], $extra);
    }

    /**
     * User counts per org-functional role_key, from org-scope scoped assignments.
     *
     * @return array<string, int>
     */
    private function orgRoleUserCounts(): array
    {
        return ScopedRole::query()
            ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
            ->selectRaw('role, COUNT(DISTINCT user_id) as c')
            ->groupBy('role')
            ->pluck('c', 'role')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * Number of distinct users holding a role at a given scope.
     */
    private function usersCountForRole(string $roleKey, string $scopeType): int
    {
        return ScopedRole::query()
            ->where('role', $roleKey)
            ->where('scope_type', $scopeType)
            ->distinct()
            ->count('user_id');
    }

    /**
     * Users holding a role at a given scope (id/name/email).
     *
     * @return array<int, array{id: int, name: string, email: string}>
     */
    private function usersForRole(string $roleKey, string $scopeType): array
    {
        $userIds = ScopedRole::query()
            ->where('role', $roleKey)
            ->where('scope_type', $scopeType)
            ->pluck('user_id')
            ->unique();

        if ($userIds->isEmpty()) {
            return [];
        }

        return User::whereIn('id', $userIds)
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->all();
    }

    /**
     * Record a role-definition change in the activity log.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function logDefinitionActivity(string $action, ScopedRoleDefinition $def, ?array $old, ?array $new, Request $request): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => "role definition {$action}: {$def->role_key}",
            'loggable_type' => ScopedRoleDefinition::class,
            'loggable_id' => $def->id,
            'role' => $def->role_key,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * جلب id لنوع سياق organization
     */
    private function getOrgScopeTypeId(): ?int
    {
        $st = ScopeType::findByKey('organization');

        return $st?->id;
    }

    /**
     * كتابة سجل في permission_audits
     */
    private function writePermissionAudit(
        string $event,
        string $roleName,
        ?array $oldValue,
        ?array $newValue,
        Request $request,
        string $scopeType = 'organization'
    ): void {
        try {
            DB::table('permission_audits')->insert([
                'event' => $event,
                'actor_id' => auth()->id(),
                'target_user_id' => null,
                'scope_type' => $scopeType,
                'scope_id' => null,
                'role' => $roleName,
                'old_value' => $oldValue !== null ? json_encode($oldValue) : null,
                'new_value' => $newValue !== null ? json_encode($newValue) : null,
                'reason' => 'dual-write from RoleController',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('RoleController::writePermissionAudit failed: '.$e->getMessage(), [
                'event' => $event,
                'role' => $roleName,
            ]);
        }
    }

    /**
     * A system role definition (compat set) — read-only: cannot be renamed or
     * deleted. Its capabilities are owned by seeders/migrations, not the UI.
     */
    private function isSystemRole(string $roleKey): bool
    {
        return in_array($roleKey, self::COMPAT_SPATIE_ROLES, true);
    }

    /**
     * اسم الدور للعرض
     */
    private function getRoleDisplayName(string $roleName): string
    {
        return match ($roleName) {
            'super_admin' => 'مدير النظام',
            'admin' => 'مدير إدارة',
            'viewer' => 'مشاهد',
            default => $roleName,
        };
    }

    /**
     * الموارد ذات سلّم النطاق (مورد × نطاق + إجراءات).
     */
    private function scopedResources(): array
    {
        $scopeLabels = ['own' => 'الخاصة بي', 'department' => 'إدارتي', 'all' => 'الكل'];

        $defs = [
            'projects' => [
                'label' => 'المشاريع',
                'view' => 'view_projects',
                'edit' => ['own' => 'edit_own_projects', 'department' => 'edit_department_projects', 'all' => 'edit_projects'],
                'create' => 'create_projects',
                'delete' => 'delete_projects',
            ],
            'tasks' => [
                'label' => 'المهام',
                'view' => 'view_tasks',
                'edit' => ['own' => 'edit_own_tasks', 'department' => 'edit_department_tasks', 'all' => 'edit_tasks'],
                'create' => 'create_tasks',
                'delete' => 'delete_tasks',
            ],
            'ovr' => [
                'label' => 'بلاغات الحوادث',
                'view' => 'ovr.view_all',
                'edit' => 'ovr.edit_all',
                'delete' => 'ovr.delete',
                'create' => 'ovr.create',
            ],
        ];

        $actionLabels = ['view' => 'عرض', 'edit' => 'تعديل', 'create' => 'إنشاء', 'delete' => 'حذف'];
        $out = [];

        foreach ($defs as $key => $def) {
            $actions = [];
            foreach (['view', 'edit', 'create', 'delete'] as $action) {
                if (! isset($def[$action])) {
                    continue;
                }
                $val = $def[$action];
                if (is_array($val)) {
                    $scopes = [];
                    foreach ($val as $scope => $perm) {
                        $scopes[] = ['key' => $scope, 'label' => $scopeLabels[$scope], 'permission' => $perm];
                    }
                    $actions[] = ['key' => $action, 'label' => $actionLabels[$action], 'scopes' => $scopes];
                } else {
                    $actions[] = ['key' => $action, 'label' => $actionLabels[$action], 'permission' => $val];
                }
            }
            $out[] = ['key' => $key, 'label' => $def['label'], 'actions' => $actions];
        }

        return $out;
    }

    /**
     * أسماء الصلاحيات المستهلكة داخل الموارد المقيّدة (لاستبعادها من المسطّحة).
     *
     * @param  array<int, mixed>  $scoped
     * @return array<int, string>
     */
    private function usedScopedPermissions(array $scoped): array
    {
        $used = [];
        foreach ($scoped as $resource) {
            foreach ($resource['actions'] as $action) {
                if (isset($action['permission'])) {
                    $used[] = $action['permission'];
                }
                foreach ($action['scopes'] ?? [] as $scope) {
                    $used[] = $scope['permission'];
                }
            }
        }

        return $used;
    }

    /**
     * موديولات محكومة بالمحرّك — تُعرض كقدرات engine، وتُستبعد صلاحياتها المسطّحة.
     *
     * @var array<int, string>
     */
    private const ENGINE_MODULES = ['projects', 'tasks', 'departments', 'strategy', 'risks', 'ovr'];

    /**
     * مجموعات قدرات المحرّك (module.action) من سجل Capability، مجمّعة بالموديول.
     * النطاق غير مُرمّز هنا — يُحسم عند الإسناد.
     *
     * @return array<int, array{key: string, label: string, abilities: array<int, array{id: string, label: string}>}>
     */
    private function engineCapabilityGroups(): array
    {
        $moduleLabels = [
            'projects' => 'المشاريع',
            'tasks' => 'المهام',
            'departments' => 'الأقسام',
            'strategy' => 'التخطيط التنفيذي',
            'risks' => 'المخاطر',
            'ovr' => 'بلاغات الحوادث',
        ];

        $actionLabels = [
            'view' => 'عرض', 'create' => 'إنشاء', 'edit' => 'تعديل', 'delete' => 'حذف',
            'manage_members' => 'إدارة الأعضاء', 'assign_roles' => 'تعيين الأدوار',
            'change_status' => 'تغيير الحالة', 'close' => 'إغلاق', 'complete' => 'إكمال',
            'assign' => 'تعيين', 'manage_priority' => 'إدارة الأولوية', 'assign_owner' => 'تعيين المالك',
            'manage_projects' => 'إدارة المشاريع المرتبطة', 'reassess' => 'إعادة تقييم',
            'view_reports' => 'عرض التقارير', 'investigate' => 'التحقيق', 'view_all' => 'عرض الكل',
            'confidential' => 'عرض السري', 'comment' => 'التعليق',
            'view_internal_comments' => 'عرض التعليقات الداخلية', 'export' => 'تصدير',
            'view_statistics' => 'عرض الإحصاءات',
        ];

        $grouped = [];
        foreach (Capability::all() as $cap) {
            [$module, $action] = array_pad(explode('.', $cap, 2), 2, '');
            if (! in_array($module, self::ENGINE_MODULES, true)) {
                continue;
            }
            $grouped[$module][] = ['id' => $cap, 'label' => $actionLabels[$action] ?? $action];
        }

        $out = [];
        foreach (self::ENGINE_MODULES as $module) {
            if (empty($grouped[$module])) {
                continue;
            }
            $out[] = [
                'key' => $module,
                'label' => $moduleLabels[$module] ?? $module,
                'abilities' => $grouped[$module],
            ];
        }

        return $out;
    }

    /**
     * أسماء صلاحيات Spatie المسطّحة العائدة لموديولات المحرّك — تُستبعد من المجموعات
     * المسطّحة و"أخرى" حتى لا تتكرر مع قدرات المحرّك.
     *
     * @return array<int, string>
     */
    private function engineFlatPermissionNames(): array
    {
        return array_merge(
            $this->usedScopedPermissions($this->scopedResources()),
            ['view_departments', 'create_departments', 'edit_departments', 'delete_departments'],
            ['view_strategy', 'create_strategy', 'edit_strategy', 'delete_strategy'],
            ['ovr.change_status', 'ovr.assign', 'ovr.comment', 'ovr.view_confidential', 'ovr.view_internal_comments', 'ovr.export', 'ovr.view_statistics'],
        );
    }

    /**
     * المجموعات المسطّحة (toggle on/off) لموديولات لم تُهاجَر للمحرّك بعد.
     *
     * ملاحظة: موديولات المحرّك (projects/tasks/risks/ovr/strategy/departments)
     * أُزيلت من هنا — تُعرض كقدرات engine في abilities()، لا كصلاحيات Spatie.
     *
     * @param  array<int, string>  $exclude
     */
    private function flatGroups(array $exclude): array
    {
        $groups = [
            'organizations' => ['label' => 'الجهات', 'names' => ['view_organizations', 'create_organizations', 'edit_organizations', 'delete_organizations']],
            'users' => ['label' => 'المستخدمين', 'names' => ['view_users', 'create_users', 'edit_users', 'delete_users']],
            'roles' => ['label' => 'الأدوار', 'names' => ['view_roles', 'create_roles', 'edit_roles', 'delete_roles', 'assign_roles']],
            'dashboard' => ['label' => 'لوحة التحكم', 'names' => ['view_dashboard']],
            'reports' => ['label' => 'التقارير', 'names' => ['view_reports', 'export_reports']],
            'audit' => ['label' => 'سجل التغييرات', 'names' => ['view_audit_logs', 'export_audit_logs']],
            'attachments' => ['label' => 'المرفقات', 'names' => ['upload_attachments', 'download_attachments', 'delete_attachments']],
            'comments' => ['label' => 'التعليقات', 'names' => ['create_comments', 'edit_comments', 'delete_comments', 'edit_any_comment', 'delete_any_comment']],
            'meetings' => ['label' => 'الاجتماعات', 'names' => ['meetings.view', 'meetings.create', 'meetings.edit', 'meetings.delete', 'meetings.record_decisions']],
            'surveys' => ['label' => 'الاستبيانات', 'names' => ['view_survey_responses', 'review_survey_responses', 'review_data_imports']],
        ];

        $all = Permission::pluck('name')->all();
        $excludeSet = array_flip($exclude);
        $assigned = $exclude;
        $out = [];

        foreach ($groups as $key => $group) {
            $perms = [];
            foreach ($group['names'] as $name) {
                if (isset($excludeSet[$name]) || ! in_array($name, $all, true)) {
                    continue;
                }
                $perms[] = ['name' => $name, 'display_name' => $this->getPermissionDisplayName($name)];
                $assigned[] = $name;
            }
            if ($perms !== []) {
                $out[] = ['key' => $key, 'label' => $group['label'], 'permissions' => $perms];
            }
        }

        // شمولية: أي صلاحية في الـ enum لم تُصنَّف تظهر تحت "أخرى".
        $leftover = array_values(array_diff($all, $assigned));
        if ($leftover !== []) {
            $out[] = [
                'key' => 'other',
                'label' => 'أخرى',
                'permissions' => array_map(fn ($name) => [
                    'name' => $name,
                    'display_name' => $this->getPermissionDisplayName($name),
                ], $leftover),
            ];
        }

        return $out;
    }

    /**
     * اسم الصلاحية للعرض
     */
    private function getPermissionDisplayName(string $permission): string
    {
        $map = [
            'view_organizations' => 'عرض الجهات',
            'create_organizations' => 'إنشاء جهات',
            'edit_organizations' => 'تعديل الجهات',
            'delete_organizations' => 'حذف الجهات',
            'view_users' => 'عرض المستخدمين',
            'create_users' => 'إنشاء مستخدمين',
            'edit_users' => 'تعديل المستخدمين',
            'delete_users' => 'حذف المستخدمين',
            'view_dashboard' => 'عرض لوحة التحكم',
            'view_projects' => 'عرض جميع المشاريع',
            'create_projects' => 'إنشاء مشاريع',
            'edit_projects' => 'تعديل جميع المشاريع',
            'edit_department_projects' => 'تعديل مشاريع إدارتي',
            'edit_own_projects' => 'تعديل مشاريعي',
            'delete_projects' => 'حذف المشاريع',
            'view_tasks' => 'عرض جميع المهام',
            'create_tasks' => 'إنشاء مهام',
            'edit_tasks' => 'تعديل جميع المهام',
            'edit_department_tasks' => 'تعديل مهام إدارتي',
            'edit_own_tasks' => 'تعديل مهامي',
            'delete_tasks' => 'حذف المهام',
            'view_roles' => 'عرض الأدوار',
            'create_roles' => 'إنشاء أدوار',
            'edit_roles' => 'تعديل الأدوار',
            'delete_roles' => 'حذف الأدوار',
            'assign_roles' => 'تعيين الأدوار',
            'view_reports' => 'عرض التقارير',
            'export_reports' => 'تصدير التقارير',
            'upload_attachments' => 'رفع مرفقات',
            'download_attachments' => 'تنزيل مرفقات',
            'delete_attachments' => 'حذف مرفقات',
            'create_comments' => 'إنشاء تعليقات',
            'edit_comments' => 'تعديل التعليقات',
            'delete_comments' => 'حذف التعليقات',
            'edit_any_comment' => 'تعديل أي تعليق',
            'delete_any_comment' => 'حذف أي تعليق',
            'view_audit_logs' => 'عرض سجل التغييرات',
            'export_audit_logs' => 'تصدير سجل التغييرات',
            'view_strategy' => 'عرض التخطيط التنفيذي',
            'create_strategy' => 'إنشاء التخطيط التنفيذي',
            'edit_strategy' => 'تعديل التخطيط التنفيذي',
            'delete_strategy' => 'حذف التخطيط التنفيذي',
            'view_survey_responses' => 'عرض ردود الاستبيانات',
            'review_survey_responses' => 'مراجعة ردود الاستبيانات',
            'review_data_imports' => 'مراجعة استيراد البيانات',
            'view_departments' => 'عرض الأقسام',
            'create_departments' => 'إنشاء أقسام',
            'edit_departments' => 'تعديل الأقسام',
            'delete_departments' => 'حذف الأقسام',
            'ovr.view_all' => 'عرض جميع البلاغات',
            'ovr.view_confidential' => 'عرض البلاغات السرية',
            'ovr.create' => 'إنشاء بلاغ حادث',
            'ovr.edit_all' => 'تعديل جميع البلاغات',
            'ovr.change_status' => 'تغيير حالة البلاغ',
            'ovr.assign' => 'تعيين البلاغات',
            'ovr.comment' => 'التعليق على البلاغات',
            'ovr.view_internal_comments' => 'عرض التعليقات الداخلية',
            'ovr.export' => 'تصدير بلاغات الحوادث',
            'ovr.view_statistics' => 'عرض إحصاءات الحوادث',
            // Phase 9: legacy kebab Meetings strings (view-meetings,
            // manage-meetings, record-decisions) were removed from
            // CapabilityAlias and Permission; only canonical dotted
            // capabilities drive the role-catalog UI now.
            'meetings.view' => 'عرض الاجتماعات',
            'meetings.create' => 'إنشاء الاجتماعات',
            'meetings.edit' => 'تعديل الاجتماعات',
            'meetings.delete' => 'حذف الاجتماعات',
            'meetings.record_decisions' => 'تسجيل القرارات والتوصيات',
        ];

        return $map[$permission] ?? $permission;
    }

    /**
     * اسم الفئة للعرض
     */
    private function getCategoryDisplayName(string $category): string
    {
        return match ($category) {
            'organizations' => 'الجهات',
            'organization' => 'إدارة المنظمة',
            'users' => 'المستخدمين',
            'projects' => 'المشاريع',
            'tasks' => 'المهام',
            'stakeholders' => 'أصحاب المصلحة',
            'roles' => 'الأدوار',
            'reports' => 'التقارير',
            'settings' => 'الإعدادات',
            'attachments' => 'المرفقات',
            'comments' => 'التعليقات',
            'audit' => 'سجل التغييرات',
            'strategy' => 'التخطيط التنفيذي',
            'surveys' => 'الاستبيانات',
            'survey' => 'ردود الاستبيانات',
            'data' => 'استيراد البيانات',
            'departments' => 'الأقسام',
            'hr' => 'الموارد البشرية',
            'ovr' => 'بلاغات الحوادث',
            'own' => 'صلاحياتي الخاصة',
            'department' => 'صلاحيات القسم',
            'all' => 'صلاحيات شاملة',
            'any' => 'أي تعليق',
            'status' => 'الحالة',
            'internal' => 'التعليقات الداخلية',
            'types' => 'أنواع الحوادث',
            'statistics' => 'الإحصاءات',
            'categories' => 'التصنيفات',
            'risk' => 'تقارير وحالة المخاطر',
            'risks' => 'المخاطر',
            'other' => 'أخرى',
            default => $category,
        };
    }
}
