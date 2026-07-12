<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Authorization\Contracts\OwnerEditable;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Authorization\Contracts\SensitivelyScoped;
use App\Modules\Core\Authorization\Models\AuthorizationRecordRule;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\ScopeAssignmentResolver;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * AccessDecision — محرّك قرار AuthZ الموحّد
 *
 * المدخل الرئيسي: can(User, capability, ?Model): bool
 *
 * جميع السياسات وحراس القدرات يفوّضون إليه بوصفه مصدر قرار التفويض الوحيد.
 *
 * خوارزمية القرار:
 *  1. super_admin ⇒ true دائماً
 *  2. عزل المؤسسة (D-02/D-04): إذا target ⇒ يجب أن يشارك المستخدم نفس المؤسسة
 *  3. قدرة الموقع الصاعدة: صعود سلسلة الأب من target حتى المؤسسة،
 *     فحص أدوار المستخدم النشطة على كل مستوى
 *  4. OR مع دور العنصر inline على target نفسه (أو الآباء بـ inherit_to_children=true)
 *  5. target=null ⇒ فحص أدوار على مستوى المؤسسة فقط
 */
class AccessDecision
{
    /**
     * Request-scoped memoization caches. The same authenticated user is evaluated
     * against many records (list endpoints resolve per-record abilities ~5x per
     * row), so re-reading their role assignments and the department scope chain for
     * every can() call is a pure N+1. These caches collapse that to one read per
     * distinct (user / node) within a process; they hold NO decision, only the raw
     * inputs, so semantics are unchanged.
     *
     * CRITICAL: every cache MUST be invalidated when roles, grants, or
     * the department tree change. flushCache()/flushUserCache() are the reset hooks
     * wired into authorization model events and the department observer. Over-flushing
     * is harmless (it only forfeits memoization); a stale grant is not.
     */

    /** @var array<string, Model|null> scopeParent() result keyed by child "class:id" */
    protected static array $scopeParentCache = [];

    /**
     * Identity map of hydrated scope-parent models keyed by "class:id". Many
     * sibling records share one ancestor (10 risks in one department), so once a
     * department is fetched as anyone's parent we reuse that single instance for
     * every other child resolving to the same node, collapsing the per-record
     * parent fetch to one query per distinct ancestor.
     *
     * @var array<string, Model|null>
     */
    protected static array $nodeIdentityCache = [];

    /** @var array<string, array<int, array{type: string, id: int}>> scope chain keyed by leaf "class:id" */
    protected static array $scopeChainCache = [];

    /** @var array<int, array<int, int>> descendant department ids keyed by department id */
    protected static array $descendantDeptCache = [];

    /**
     * @var array<int, Collection<int, AuthorizationRolePermission>> role permissions
     *                                                               for the user (joined through authorization_role_assignments), keyed by user id.
     */
    protected static array $rolePermissionsCache = [];

    /**
     * @var array<int, Collection<int, AuthorizationRoleAssignment>> admin-role assignments
     *                                                               for the user (assignments whose role carries is_admin_role=true), keyed by user id.
     *
     * Phase 2.1.4a admin shortcut support. The set is small (admins are
     * rare per user) so memoizing it per request collapses the admin
     * canonical admin check in `canonicalGrant` to one read per distinct user. Any
     * write to `authorization_role_assignments` or `authorization_roles`
     * invalidates the whole cache via the model hooks
     * (AccessDecision::flushCache()).
     */
    protected static array $adminAssignmentsCache = [];

    /**
     * @var array<string, Collection<int, AuthorizationRecordRule>> applicable record
     *                                                              rules for (user, resource key, action suffix) keyed by "<userId>|<resource>|<action>".
     */
    protected static array $applicableRecordRulesCache = [];

    /**
     * Drop every memoized input for every user/node. Safe to call at any time:
     * the next can() simply re-reads from the database.
     */
    public static function flushCache(): void
    {
        static::$scopeParentCache = [];
        static::$nodeIdentityCache = [];
        static::$scopeChainCache = [];
        static::$descendantDeptCache = [];
        static::$rolePermissionsCache = [];
        static::$adminAssignmentsCache = [];
        static::$applicableRecordRulesCache = [];
    }

    /**
     * Drop the memoized roles for a single user (and the cheap node caches, which
     * are user-independent but small). Used by assignment model events that know
     * exactly which user changed.
     */
    public static function flushUserCache(int $userId): void
    {
        unset(
            static::$rolePermissionsCache[$userId],
            static::$adminAssignmentsCache[$userId],
        );
        // Drop applicable-record-rules cache entries that belong to this user
        // (key prefix "<userId>|..."). Keeps the cross-user entries warm.
        foreach (array_keys(static::$applicableRecordRulesCache) as $key) {
            if (str_starts_with((string) $key, $userId.'|')) {
                unset(static::$applicableRecordRulesCache[$key]);
            }
        }
        // Node caches (scope parent / descendants) reflect the department tree,
        // not this user; a role change does not invalidate them.
    }

    /**
     * نقطة الدخول الرئيسية لقرار AuthZ.
     *
     * @param  User  $user  المستخدم الطالب للوصول
     * @param  string  $capability  القدرة المطلوبة (Capability::PROJECTS_EDIT ...)
     * @param  Model|null  $target  النموذج الهدف (Project، Task، Risk ...) أو null للقدرات العامة
     */
    public static function can(User $user, string $capability, ?Model $target = null): bool
    {
        return static::canonicalTrace($user, $capability, $target)['granted'];
    }

    public static function canonicalTrace(User $user, string $capability, ?Model $target = null): array
    {
        return static::normalizeTrace(static::evaluateCanonical($user, $capability, $target));
    }

    /**
     * Decision trace: the same control flow as can(), but it records WHICH layer
     * granted (or denied) and, for a positional grant, the matching role/scope.
     *
     * Layers (in evaluation order): super_admin, org_isolation_denied, owner_floor,
     * sensitive_allowed/sensitive_denied, org_functional_role, scope_chain,
     * inline_role, none.
     *
     * @return array{granted: bool, reason: string, layer: string|null, role: string|null, scope_type: string|null, scope_id: int|null}
     */
    public static function whyCan(User $user, string $capability, ?Model $target = null): array
    {
        return static::canonicalTrace($user, $capability, $target);
    }

    /**
     * Evaluate only the canonical authorization_* tables. The target is passed
     * through unchanged, including null, so target-free and target-bound
     * parity calls cannot accidentally compare different contexts.
     */
    protected static function evaluateCanonical(User $user, string $capability, ?Model $target = null): array
    {
        $adminBypass = static::canonicalAdminBypass($user, $capability, $target);
        if ($adminBypass !== null) {
            return $adminBypass;
        }

        if ($target === null && $user->organization_id === null) {
            return static::canonicalTraceRow(false, 'organization_required', null, null, null, null, 'non-super-admin user has no organization context');
        }

        $clusterTreeGrant = $target === null
            ? null
            : static::canonicalClusterTreeGrant($user, $capability, $target);
        if ($clusterTreeGrant !== null) {
            return static::canonicalTraceRow(
                true,
                'cluster_tree_rescue',
                $clusterTreeGrant['role_id'],
                $clusterTreeGrant['assignment_id'],
                $clusterTreeGrant['scope_type'],
                $clusterTreeGrant['scope_id'],
                'canonical cluster assignment grants the primitive to a descendant organization',
                $clusterTreeGrant['role'],
            );
        }

        if ($target !== null && ! static::sameOrganization($user, $target)) {
            return static::canonicalTraceRow(false, 'org_isolation_denied', null, null, null, null, 'target belongs to another organization');
        }

        if ($target !== null && static::canonicalOwnerFloorGrants($user, $capability, $target)) {
            return static::canonicalTraceRow(true, 'owner_floor', null, null, null, null, 'record ownership grants this capability');
        }

        if ($target instanceof SensitivelyScoped
            && $target->isSensitive()
            && ! static::canonicalSensitiveFloor($user, $target)) {
            return static::canonicalTraceRow(false, 'sensitive_denied', null, null, null, null, 'canonical sensitive floor denied the target');
        }

        $mapping = CapabilityToAuthorizationRolePermission::map($capability);
        if ($mapping === null) {
            return static::canonicalTraceRow(false, 'none', null, null, null, null, 'capability has no canonical resource mapping');
        }

        $resource = AuthorizationResource::query()->where('key', $mapping['resource'])->first();
        if ($resource === null) {
            return static::canonicalTraceRow(false, 'none', null, null, null, null, 'canonical resource is not registered');
        }

        $grant = static::canonicalGrant($user, (int) $resource->id, $mapping['action'], $capability, $target);
        if ($grant === null) {
            return static::canonicalTraceRow(false, 'none', null, null, null, null, 'no active canonical assignment grants this capability');
        }

        if ($target !== null && ! static::recordRulesAdmitTarget($user, $mapping['resource'], $mapping['action'], $target)) {
            return static::canonicalTraceRow(false, 'record_rule_denied', $grant['role_id'], $grant['assignment_id'], $grant['scope_type'], $grant['scope_id'], 'canonical record rule denied the target', $grant['role']);
        }

        return static::canonicalTraceRow(true, $grant['layer'], $grant['role_id'], $grant['assignment_id'], $grant['scope_type'], $grant['scope_id'], $grant['reason'], $grant['role']);
    }

    /**
     * Admit only explicit canonical cluster-tree primitives from an actor's
     * organization to one of its descendants. This is intentionally separate
     * from the normal organization scope resolver: it cannot widen ordinary
     * module capabilities, cannot cross to a sibling/ancestor, and never
     * bypasses the sensitive-record floor.
     *
     * @return array{role_id: int, assignment_id: int, scope_type: string, scope_id: int|null}|null
     */
    protected static function canonicalClusterTreeGrant(User $user, string $capability, Model $target): ?array
    {
        if (! in_array($capability, static::clusterTreePrimitiveCapabilities(), true)
            || $user->organization_id === null
            || static::sameOrganization($user, $target)
            || ($target instanceof SensitivelyScoped && $target->isSensitive())) {
            return null;
        }

        $targetOrgId = static::extractOrganizationId($target);
        if ($targetOrgId === null) {
            return null;
        }

        $targetOrg = Organization::query()->find($targetOrgId);
        $actorOrgId = (int) $user->organization_id;
        if (! $targetOrg instanceof Organization
            || ! in_array($actorOrgId, $targetOrg->ancestorIds(), true)) {
            return null;
        }

        $mapping = CapabilityToAuthorizationRolePermission::map($capability);
        if ($mapping === null) {
            return null;
        }

        $resourceId = AuthorizationResource::query()
            ->where('key', $mapping['resource'])
            ->value('id');
        if ($resourceId === null) {
            return null;
        }

        $roleIds = AuthorizationRolePermission::query()
            ->where('authorization_resource_id', $resourceId)
            ->where('action', $mapping['action'])
            ->pluck('authorization_role_id');
        if ($roleIds->isEmpty()) {
            return null;
        }

        $assignment = AuthorizationRoleAssignment::query()
            ->with('role')
            ->where('user_id', $user->id)
            ->whereIn('authorization_role_id', $roleIds)
            ->whereIn('scope_type', [
                AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            ])
            ->where('scope_id', $actorOrgId)
            ->where('inherit_to_children', true)
            ->get()
            ->first(fn (AuthorizationRoleAssignment $candidate) => static::canonicalRoleIsActive($candidate)
                && ($candidate->expires_at === null || ! $candidate->expires_at->isPast()));

        if (! $assignment instanceof AuthorizationRoleAssignment) {
            return null;
        }

        return [
            'role_id' => (int) $assignment->authorization_role_id,
            'role' => (string) $assignment->role->name,
            'assignment_id' => (int) $assignment->id,
            'scope_type' => (string) $assignment->scope_type,
            'scope_id' => $assignment->scope_id === null ? null : (int) $assignment->scope_id,
        ];
    }

    protected static function canonicalAdminBypass(User $user, string $capability, ?Model $target): ?array
    {
        if (static::requiresExplicitGrant($capability)) {
            return null;
        }

        foreach (static::loadAdminRoleAssignments($user) as $assignment) {
            if (! self::canonicalRoleIsActive($assignment)
                || $assignment->scope_type !== AuthorizationRoleAssignment::SCOPE_ALL
                || $assignment->role?->name !== 'super_admin'
                || ! static::canonicalAssignmentApplies($user, $assignment, $target)) {
                continue;
            }

            return static::canonicalTraceRow(
                true,
                'canonical_admin',
                (int) $assignment->authorization_role_id,
                (int) $assignment->id,
                (string) $assignment->scope_type,
                $assignment->scope_id === null ? null : (int) $assignment->scope_id,
                'granted by an applicable canonical admin assignment',
                (string) $assignment->role->name,
            );
        }

        return null;
    }

    /**
     * @return array{role_id: int, assignment_id: int, scope_type: string, scope_id: int|null, layer: string, reason: string}|null
     */
    protected static function canonicalGrant(User $user, int $resourceId, string $action, string $capability, ?Model $target): ?array
    {
        $permissions = static::loadAuthorizationRolePermissions($user)
            ->filter(fn ($permission) => (int) $permission->authorization_resource_id === $resourceId
                && (string) $permission->action === $action);

        $roleIds = $permissions->pluck('authorization_role_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        foreach (static::loadAdminRoleAssignments($user) as $assignment) {
            if (static::canonicalRoleIsActive($assignment)
                && ! static::requiresExplicitGrant($capability)
                && static::canonicalAssignmentApplies($user, $assignment, $target)) {
                return [
                    'role_id' => (int) $assignment->authorization_role_id,
                    'role' => (string) $assignment->role->name,
                    'assignment_id' => (int) $assignment->id,
                    'scope_type' => (string) $assignment->scope_type,
                    'scope_id' => $assignment->scope_id === null ? null : (int) $assignment->scope_id,
                    'layer' => 'canonical_assignment',
                    'reason' => 'granted by a canonical admin role assignment',
                ];
            }
        }

        if ($roleIds === []) {
            return null;
        }

        $assignments = AuthorizationRoleAssignment::query()
            ->where('user_id', $user->id)
            ->whereIn('authorization_role_id', $roleIds)
            ->get();

        foreach ($assignments as $assignment) {
            if (! static::canonicalRoleIsActive($assignment)) {
                continue;
            }

            if (! static::canonicalAssignmentApplies($user, $assignment, $target)) {
                continue;
            }

            $permission = $permissions->firstWhere('authorization_role_id', $assignment->authorization_role_id);
            if ($target !== null && $permission !== null
                && ! static::canonicalReachAdmits($user, $target, $permission, $capability)) {
                continue;
            }

            return [
                'role_id' => (int) $assignment->authorization_role_id,
                'role' => (string) $assignment->role->name,
                'assignment_id' => (int) $assignment->id,
                'scope_type' => (string) $assignment->scope_type,
                'scope_id' => $assignment->scope_id === null ? null : (int) $assignment->scope_id,
                'layer' => 'canonical_assignment',
                'reason' => 'granted by a canonical role assignment',
            ];
        }

        return null;
    }

    protected static function canonicalSensitiveFloor(User $user, Model $target): bool
    {
        if (static::isOwner($user, $target)) {
            return true;
        }

        foreach (['reporter_id', 'assigned_to', 'user_id'] as $attribute) {
            $value = $target->getAttribute($attribute);
            if ($value !== null && (int) $value === (int) $user->id) {
                return true;
            }
        }

        $mapping = CapabilityToAuthorizationRolePermission::map(Capability::OVR_CONFIDENTIAL);
        if ($mapping === null) {
            return false;
        }

        $resourceId = AuthorizationResource::query()
            ->where('key', $mapping['resource'])
            ->value('id');

        return $resourceId !== null
            && static::canonicalGrant(
                $user,
                (int) $resourceId,
                $mapping['action'],
                Capability::OVR_CONFIDENTIAL,
                $target,
            ) !== null;
    }

    /**
     * Ownership is a narrow canonical floor, never a substitute for a role.
     * It grants record viewing to the owner/reporter/assignee and grants edit
     * only to a true owner on models that explicitly opt into lifecycle-aware
     * owner editing.
     *
     * ORDERING INVARIANT — caller MUST invoke sameOrganization() before this
     * method (evaluateCanonical() does, at the top of the layer stack after
     * only super_admin and the cluster_tree rescue). If this floor were ever
     * evaluated BEFORE the org gate, a user who created a record in Org A and
     * was later moved to Org B would keep seeing and editing that record
     * purely via the created_by/owner_id columns — a cross-org IDOR. The
     * canonical owner floor grants visibility ONLY within the user's current
     * organization; the org gate is what enforces that scope.
     */
    protected static function canonicalOwnerFloorGrants(User $user, string $capability, Model $target): bool
    {
        $ownsRecord = static::isOwner($user, $target);
        $isRecordSubject = $ownsRecord;

        foreach (['reporter_id', 'assigned_to', 'user_id'] as $attribute) {
            $value = $target->getAttribute($attribute);
            if ($value !== null && (int) $value === (int) $user->id) {
                $isRecordSubject = true;
                break;
            }
        }

        if ($isRecordSubject && str_ends_with($capability, '.view')) {
            return true;
        }

        return $ownsRecord
            && str_ends_with($capability, '.edit')
            && $target instanceof OwnerEditable
            && $target->isOwnerEditable();
    }

    protected static function canonicalRoleIsActive(AuthorizationRoleAssignment $assignment): bool
    {
        $role = $assignment->role;

        return $role !== null
            && (string) $role->scope_type === (string) $assignment->scope_type
            && (! array_key_exists('is_active', $role->getAttributes())
                || (bool) $role->is_active);
    }

    protected static function canonicalAssignmentApplies(User $user, AuthorizationRoleAssignment $assignment, ?Model $target): bool
    {
        if ($assignment->expires_at !== null && $assignment->expires_at->isPast()) {
            return false;
        }

        return match ((string) $assignment->scope_type) {
            AuthorizationRoleAssignment::SCOPE_ALL => true,
            AuthorizationRoleAssignment::SCOPE_ORGANIZATION => $target === null
                ? $user->organization_id !== null
                    && (int) ($assignment->organization_id ?? $assignment->scope_id) === (int) $user->organization_id
                : ScopeAssignmentResolver::applies($assignment, $target),
            AuthorizationRoleAssignment::SCOPE_OWN => $target !== null && static::isOwner($user, $target),
            default => $target !== null && ScopeAssignmentResolver::applies($assignment, $target),
        };
    }

    /** @return array{granted: bool, layer: string, role_id: int|null, assignment_id: int|null, scope_type: string|null, scope_id: int|null, reason: string} */
    protected static function canonicalTraceRow(bool $granted, string $layer, ?int $roleId, ?int $assignmentId, ?string $scopeType, ?int $scopeId, string $reason, ?string $role = null): array
    {
        return [
            'granted' => $granted,
            'layer' => $layer,
            'role_id' => $roleId,
            'role' => $role,
            'assignment_id' => $assignmentId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'reason' => $reason,
        ];
    }

    /** @return array{granted: bool, layer: string, role: string|null, role_id: int|null, assignment_id: int|null, scope_type: string|null, scope_id: int|null, reason: string} */
    protected static function normalizeTrace(array $trace): array
    {
        return [
            'granted' => (bool) ($trace['granted'] ?? false),
            'layer' => (string) ($trace['layer'] ?? 'none'),
            'role' => isset($trace['role']) ? (string) $trace['role'] : null,
            'role_id' => isset($trace['role_id']) ? (int) $trace['role_id'] : null,
            'assignment_id' => isset($trace['assignment_id']) ? (int) $trace['assignment_id'] : null,
            'scope_type' => $trace['scope_type'] ?? null,
            'scope_id' => isset($trace['scope_id']) ? (int) $trace['scope_id'] : null,
            'reason' => (string) ($trace['reason'] ?? ''),
        ];
    }

    // ========================================================
    // عزل المؤسسة
    // ========================================================

    /**
     * هل يشارك المستخدم نفس مؤسسة الـ target؟
     * يصعد سلسلة الأب حتى أول نموذج يملك organization_id.
     * يفشل CLOSED: إذا تعذّر اشتقاق مؤسسة الهدف ⇒ نرفض (عزل المؤسسة لا يُتخطّى).
     * الأهداف بلا org شرعية (مثل المهام الشخصية) تُحكم بطبقة ملكية فوق المحرّك
     * في الـ Policy قبل الوصول إلى هنا.
     *
     * ORDERING INVARIANT — this gate MUST run BEFORE canonicalOwnerFloorGrants()
     * in evaluateCanonical(). It is the outermost engine-layer organization
     * check, sitting directly under the super_admin short-circuit and the
     * cluster_tree rescue. Owning a record (created_by / owner_id) does NOT
     * bypass it: the same comparison here is what stops a user moved to a
     * different org from continuing to see / edit records they previously
     * created in the old org. The owner floor grants visibility only within
     * the user's current organization — this gate is what enforces that.
     */
    protected static function sameOrganization(User $user, Model $target): bool
    {
        $targetOrgId = static::extractOrganizationId($target);

        // إذا لم يمكن اشتقاق المؤسسة من الـ target ⇒ نرفض (fail closed)
        if ($targetOrgId === null) {
            return false;
        }

        // مستخدم بلا مؤسسة لا يستطيع الوصول لأي target مؤسّسي
        if ($user->organization_id === null) {
            return false;
        }

        return (int) $user->organization_id === (int) $targetOrgId;
    }

    // ========================================================
    // Phase 9-D-B + CFA-01 — cluster_tree rescue (engine primitives)
    // ========================================================
    //
    // Three sibling primitives ride the SAME rescue branch:
    //   - Capability::CLUSTER_TREE_VIEW   — Phase 9-D-B (read)
    //   - Capability::CLUSTER_TREE_MANAGE  — Phase CFA-01 (governance writes)
    //   - Capability::CLUSTER_TREE_EXPORT  — Phase CFA-01 (row-level exports)
    //
    // Each is a CAPABILITY STRING, not a wildcard. The primitive:
    //   - fires ONLY for the three constants above (does NOT widen to
    //     users.view / projects.view / risks.view / meetings.view / ...);
    //   - does NOT bypass the sensitive floor (SensitivelyScoped + isSensitive);
    //   - requires a scoped role on user.organization_id (no is_admin_role
    //     shortcut, no inherit_to_children shortcut);
    //   - requires user.organization_id to be an ancestor of target.organization_id
    //     via the parent_id walk (depth cap 32, fail-closed on cycle);
    //   - does NOT widen list/index scopes (Phase 9-D-D / CFA-02..CFA-11 is
    //     what decides per-module widening on top of these primitives);
    //   - does NOT imply any module capability — the module capability MUST
    //     be held on actor.org in parallel (e.g. STRATEGY_CHANGE_STATUS +
    //     CLUSTER_TREE_MANAGE; never just one of them).
    //
    // The three primitives are distinguished in:
    //   - authorization_decision_audits (cluster_tree_rescue_layer_*
    //     discriminator coming via the trace reason);
    //   - the ActivityLog audit row;
    //   - the whyCan() return trace.
    //
    // Does NOT modify sameOrganization / extractOrganizationId /
    // buildScopeChain / ScopeAssignmentResolver / any OrgGuard / any
    // User*Scope / any module controller.

    /**
     * The set of capability strings that activate the cluster_tree rescue
     * branch. Sibling primitives — each fires through the SAME ancestor walk
     * + scoped-role check; the only difference is the audit-trail label.
     *
     * @return list<string>
     */
    protected static function clusterTreePrimitiveCapabilities(): array
    {
        return [
            Capability::CLUSTER_TREE_VIEW,
            Capability::CLUSTER_TREE_MANAGE,
            Capability::CLUSTER_TREE_EXPORT,
        ];
    }

    /**
     * استخراج organization_id من نموذج بصعود السلسلة.
     * يُعيد null إذا لم يوجد في أي مستوى.
     *
     * Mirror the visited-key / depth-cap guards `buildScopeChain` uses so a
     * circular ScopeAware chain cannot pin a request in unbounded recursion
     * (a leaf reporting itself as its own parent, or an A→B→A loop in a
     * pathological scope configuration, used to traverse the chain until
     * PHP exhausted its stack). The cap is a belt-and-braces layer over
     * the visited set: visited kills the loop on the second visit, depth
     * bounds an adversarial chain that grows linearly without repeating
     * a node (extremely unlikely against real data; kept for defense in
     * depth).
     */
    protected static function extractOrganizationId(Model $model, ?array $visited = null, int $depth = 0): ?int
    {
        // أولاً: إذا كان النموذج ScopeAware ويوفر الدالة مباشرة
        if ($model instanceof ScopeAware) {
            $orgId = $model->scopeOrganizationId();
            if ($orgId !== null) {
                return $orgId;
            }
        }

        // ثانياً: حقل مباشر
        if (isset($model->organization_id) && $model->organization_id !== null) {
            return (int) $model->organization_id;
        }

        // ثالثاً: صعود الأب عبر ScopeAware
        if ($model instanceof ScopeAware) {
            // Cycle guard mirrors buildScopeChain(): key by concrete node
            // identity (class:id) so two distinct models sharing the same
            // organization_id never alias as the same key.
            $visited ??= [];
            $key = get_class($model).':'.$model->getKey();

            if (isset($visited[$key])) {
                return null;
            }
            $visited[$key] = true;

            // Depth cap. 32 is well above any real org/department tree
            // observed in production (~5–7 levels) and stays below the
            // default xdebug nesting limit. Hitting it means either a
            // pathological fixture or adversarial data; either way, fail
            // closed (return null) and let the org gate deny.
            if ($depth >= 32) {
                return null;
            }

            $parent = static::safeParent($model);
            if ($parent !== null) {
                return static::extractOrganizationId($parent, $visited, $depth + 1);
            }
        }

        return null;
    }

    // ========================================================
    // Owner floor
    // ========================================================

    /**
     * Is the user the owner of the target? Checks created_by and owner_id.
     * Called only after the organization-isolation gate passes, so it never bypasses it.
     */
    protected static function isOwner(User $user, Model $target): bool
    {
        $createdBy = $target->getAttribute('created_by');
        $ownerId = $target->getAttribute('owner_id');

        return ($createdBy !== null && (int) $createdBy === (int) $user->id)
            || ($ownerId !== null && (int) $ownerId === (int) $user->id);
    }

    /**
     * Structural floor for the sensitive gate (`SensitivelyScoped +
     * isSensitive()`). Returns true when the user has an obviously
     * structural reason to access a sensitive record -- not relying on
     * `mayAccessSensitive()`'s implementation, which can itself be buggy
     * (mis-keyed Spatie role check, a column that was migrated away, or
     * a future module whose hook defaults to true before its own
     * permission table is wired). The floor is the belt; the hook is
     * the braces.
     *
     * Two admits -- ANY ONE is enough:
     *
     *   (a) created_by / owner_id / reporter_id / assigned_to / user_id
     *       matches the user. These are the universal "this record is
     *       about this user" columns each module uses -- OVR calls
     *       them reporter_id and assigned_to, generic audit logs use
     *       user_id, project-level ownership uses created_by and
     *       owner_id. The floor covers all of them so the additive
     *       (floor OR hook) pair grants a reporter / assignee
     *       consistently across modules even when the per-module
     *       `mayAccessSensitive()` is rewritten without one of those
     *       columns in mind.
     *
     *   (b) The user holds a scoped-role definition whose
     *       permissions[] contains Capability::OVR_CONFIDENTIAL, or the
     *       Capability::OVR_VIEW_CONFIDENTIAL transition alias.
     *
     * Admin-role status is intentionally NOT an admit here. Need-to-know
     * records require an explicit confidential grant unless the platform
     * super_admin bypass has already returned at the top of whyCan().
     */
    /**
     * Best-effort `organization_id` for the structural floor. Re-uses the
     * cycle-safe `extractOrganizationId()` and never throws. Null is a
     * legitimate answer (a sensitivescoped record that is intentionally
     * org-less); the caller treats null as "skip (b)/(c) bindings,
     * fall through to the rest of the decision".
     */
    // ========================================================
    // فحص الأدوار
    // ========================================================

    /**
     * بناء سلسلة المستويات من target حتى المؤسسة ثم فحص أدوار المستخدم.
     *
     * The single shared scope walk behind both can() and whyCan(). Returns the
     * first matching role descriptor (so the trace can name role/scope) or null
     * when nothing grants the capability.
     *
     * @return array{role: string, scope_type: string, scope_id: int|null, inline: bool}|null
     */
    /**
     * TASKS_ASSIGN is a project-local delegation grant. Some task sources roll
     * up through another ScopeAware parent, so check the direct project binding
     * explicitly without widening to the whole organization or sibling projects.
     *
     * @return array{role: string, scope_type: string, scope_id: int, inline: bool}|null
     */
    /**
     * Resolve a single role into a grant descriptor for a capability, applying the
     * per-capability reach cap (Phase 6). Returns null when the role does not grant
     * the capability, or grants it but the target lies outside the role's reach.
     * A target-free check (coarse gate) skips the reach test — list narrowing is
     * done by grantingScopes().
     *
     * @return array{role: string, scope_type: string, scope_id: int, inline: bool}|null
     */
    /**
     * The module segment of a capability (projects.edit -> projects).
     */
    protected static function moduleOf(string $capability): string
    {
        return str_contains($capability, '.') ? substr($capability, 0, strpos($capability, '.')) : $capability;
    }

    /**
     * The reach cap a role's definition places on a capability's module (Phase 6).
     */
    /**
     * Does the target fall within a role's reach cap (Phase 6)? Reach only ever
     * RESTRICTS: 'all' never narrows; 'own' requires ownership; 'department' narrows
     * an ORGANIZATION-scoped role to the user's own department subtree (a dept/project
     * scoped role is already constrained by its assignment, so 'department' is a no-op
     * there beyond the positional match that already selected it).
     */
    /**
     * Is the target owned by (its department in) one of the given departments?
     * Checks a direct department attribute first, then the ScopeAware scope chain.
     *
     * @param  array<int, int>  $deptIds
     */
    protected static function targetInDepartments(Model $target, array $deptIds): bool
    {
        if ($deptIds === []) {
            return false;
        }

        foreach (['department_id', 'reporter_department_id'] as $attr) {
            $value = $target->getAttribute($attr);
            if ($value !== null && in_array((int) $value, $deptIds, true)) {
                return true;
            }
        }

        if ($target instanceof ScopeAware) {
            foreach (static::buildScopeChain($target) as ['type' => $type, 'id' => $id]) {
                if ($type === 'department' && in_array((int) $id, $deptIds, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * بناء سلسلة المستويات من target حتى الجذر (المؤسسة).
     * كل عنصر: ['type' => string, 'id' => int]
     */
    protected static function buildScopeChain(ScopeAware $target): array
    {
        // The chain for a given leaf is stable within a request; resolving it once
        // and reusing it collapses the 5-abilities-per-record re-evaluation to a
        // single walk (and a single set of ancestor fetches) per record.
        $leafKey = get_class($target).':'.$target->getKey();

        if (isset(static::$scopeChainCache[$leafKey])) {
            return static::$scopeChainCache[$leafKey];
        }

        $chain = [];
        $current = $target;
        $visited = []; // منع الحلقات

        while ($current !== null) {
            // Cycle key MUST identify the concrete node, not its scope type. Two
            // distinct models can share one scopeTypeKey (e.g. Decision rolls up
            // under 'meeting'); keying by scopeTypeKey would collide with the
            // parent Meeting of the same id and truncate the chain prematurely.
            $key = get_class($current).':'.$current->getKey();

            if (isset($visited[$key])) {
                break; // حلقة — نتوقف
            }

            $visited[$key] = true;

            $chain[] = [
                'type' => $current->scopeTypeKey(),
                'id' => (int) $current->getKey(),
            ];

            // إذا كان النموذج يملك organization_id مباشرة ⇒ أضف المؤسسة للسلسلة
            $orgId = null;
            if (isset($current->organization_id) && $current->organization_id !== null) {
                $orgId = (int) $current->organization_id;
            }

            $parent = static::canonicalize(static::safeParent($current));

            // إذا وصلنا لأعلى السلسلة وعندنا org ⇒ أضف المؤسسة
            if ($parent === null && $orgId !== null) {
                $chain[] = [
                    'type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                    'id' => $orgId,
                ];
                break;
            }

            if ($parent instanceof ScopeAware) {
                $current = $parent;
            } else {
                // الأب ليس ScopeAware — توقّف بعد إضافة org إن وُجد
                if ($orgId !== null) {
                    $chain[] = [
                        'type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                        'id' => $orgId,
                    ];
                }
                break;
            }
        }

        return static::$scopeChainCache[$leafKey] = $chain;
    }

    /**
     * Return the canonical hydrated instance for a model, so every child that
     * resolves to the same ancestor shares one instance (and one fetch of that
     * ancestor's own parents). The first time a node is seen its instance is
     * registered; later equal nodes reuse it.
     */
    protected static function canonicalize(?Model $model): ?Model
    {
        if ($model === null) {
            return null;
        }

        $key = get_class($model).':'.$model->getKey();

        return static::$nodeIdentityCache[$key] ??= $model;
    }

    /**
     * Resolve a scope-parent model by class + id through the request identity map,
     * fetching it at most once per distinct ancestor. ScopeAware leaf models route
     * their parent lookup here (instead of a fresh `->first()`) so a list of N
     * records sharing one department triggers one department fetch, not N.
     *
     * Returns null for a null id. The fetched row carries all columns (no select
     * projection), so the engine still sees parent_id / path / organization_id and
     * the scope-chain semantics are byte-for-byte identical to a direct first().
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel|null
     */
    public static function resolveScopeParent(string $modelClass, ?int $id): ?Model
    {
        if ($id === null) {
            return null;
        }

        $key = $modelClass.':'.$id;

        if (array_key_exists($key, static::$nodeIdentityCache)) {
            return static::$nodeIdentityCache[$key];
        }

        return static::$nodeIdentityCache[$key] = $modelClass::query()->find($id);
    }

    /**
     * Columns the scope chain reads off a parent model. A relation eager-loaded
     * with a column projection (e.g. `department:id,name`) omits these, so reusing
     * it would silently break organization isolation / ancestor walking. We only
     * reuse a loaded relation when ALL of these attributes are present.
     */
    protected const SCOPE_PARENT_REQUIRED_COLUMNS = ['organization_id', 'parent_id', 'path'];

    /**
     * Is $model's $relation eager-loaded with every column the scope chain needs?
     * When true the caller may reuse the loaded relation instead of a fresh fetch.
     * The reused instance is also registered in the identity map so the engine and
     * the controller's eager-load resolve to one shared instance for the request.
     *
     * Presence is tested on the raw attribute array (array_key_exists), not the
     * value: a legitimately null parent_id/path on a fully-loaded row still counts
     * as loaded, while a column-projected partial load fails the check and is
     * correctly bypassed.
     */
    public static function scopeParentFullyLoaded(Model $model, string $relation): bool
    {
        if (! $model->relationLoaded($relation)) {
            return false;
        }

        $related = $model->getRelation($relation);

        if (! $related instanceof Model) {
            return false;
        }

        $attributes = $related->getAttributes();
        foreach (static::SCOPE_PARENT_REQUIRED_COLUMNS as $column) {
            if (! array_key_exists($column, $attributes)) {
                return false;
            }
        }

        // Share one canonical instance between the eager-load and the engine.
        $key = get_class($related).':'.$related->getKey();
        static::$nodeIdentityCache[$key] ??= $related;

        return true;
    }

    /** Sensitive capabilities require an explicit canonical permission row. */
    protected static function requiresExplicitGrant(string $capability): bool
    {
        return in_array($capability, [
            Capability::OVR_CONFIDENTIAL,
            Capability::OVR_VIEW_CONFIDENTIAL,
        ], true);
    }

    // ========================================================
    // مساعدات إسناد فلاتر القائمة (query-scope) — تعكس قرار can()
    // دون إعادة اشتقاق سلسلة النطاق في SQL.
    // ========================================================

    /**
     * Determine whether an active canonical assignment grants organization-wide
     * list access for the capability.
     */
    public static function grantsAtOrganization(User $user, string $capability): bool
    {
        foreach (static::canonicalListGrantAssignments($user, $capability) as $grant) {
            $assignment = $grant['assignment'];
            $reach = $grant['reach'];

            if ($reach !== 'all') {
                continue;
            }

            if (in_array($assignment->scope_type, [
                AuthorizationRoleAssignment::SCOPE_ALL,
                AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            ], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Expand department scope ids to include the department itself and all of its
     * descendants (the subtree). Ensures list filters match the ascending can()
     * visibility: a parent-department manager sees child-department projects in the
     * list just as they can open them by direct link.
     *
     * Backed by the indexed materialized path (departments.path): each seed
     * department expands to itself plus all descendants via a single `where path
     * like` query (O(1) index per node). Organization isolation is preserved:
     * callers apply where organization_id before this expansion, so any cross-org
     * id stays inert.
     *
     * @param  array<int, int>  $deptIds
     * @return array<int, int>
     */
    public static function subtreeDepartmentIds(array $deptIds): array
    {
        if ($deptIds === []) {
            return [];
        }

        // Resolve only the seeds we have not already expanded this request; the
        // department subtree is stable within a request, so the same seed yields
        // the same descendants every time. Cache misses are loaded in one query.
        $missing = array_values(array_filter(
            array_map('intval', $deptIds),
            fn (int $id) => ! array_key_exists($id, static::$descendantDeptCache)
        ));

        if ($missing !== []) {
            foreach (Department::whereIn('id', $missing)->get() as $dept) {
                static::$descendantDeptCache[(int) $dept->id] = array_map('intval', $dept->descendantIdsViaPath());
            }

            // Seeds that matched no Department row (stale/cross-org id) still need a
            // cache entry so they are not re-queried; expand to nothing.
            foreach ($missing as $id) {
                static::$descendantDeptCache[$id] ??= [];
            }
        }

        $all = [];
        foreach (array_map('intval', $deptIds) as $id) {
            $all = array_merge($all, static::$descendantDeptCache[$id]);
        }

        return array_values(array_unique($all));
    }

    /**
     * Project active canonical grants into list-filter scope buckets, for example
     * ['department' => [3, 5], 'project' => [12]].
     *
     * @return array<string, array<int, int>>
     */
    public static function grantingScopes(User $user, string $capability): array
    {
        $out = [];
        $userDepartmentId = $user->department_id;
        $userOrganizationId = $user->organization_id;

        foreach (static::canonicalListGrantAssignments($user, $capability) as $grant) {
            $assignment = $grant['assignment'];
            $reach = $grant['reach'];

            if ($reach === 'own') {
                continue;
            }

            if ($assignment->scope_type === AuthorizationRoleAssignment::SCOPE_ALL) {
                if ($reach === 'all' && $userOrganizationId !== null) {
                    $out[AuthorizationRoleAssignment::SCOPE_ORGANIZATION][] = (int) $userOrganizationId;
                }

                continue;
            }

            if ($assignment->scope_type === AuthorizationRoleAssignment::SCOPE_ORGANIZATION) {
                if ($reach === 'all') {
                    $out[AuthorizationRoleAssignment::SCOPE_ORGANIZATION][] = (int) $assignment->scope_id;
                } elseif ($reach === 'department' && $userDepartmentId !== null) {
                    $out[AuthorizationRoleAssignment::SCOPE_DEPARTMENT][] = (int) $userDepartmentId;
                }

                continue;
            }

            if ($assignment->scope_id !== null) {
                $out[(string) $assignment->scope_type][] = (int) $assignment->scope_id;
            }
        }

        return array_map(fn ($ids) => array_values(array_unique($ids)), $out);
    }

    /**
     * Resolve active canonical assignments that grant a list capability and
     * project the permission's module reach. The helper deliberately reads only
     * authorization_* models only.
     *
     * @return list<array{assignment: AuthorizationRoleAssignment, reach: string}>
     */
    protected static function canonicalListGrantAssignments(User $user, string $capability): array
    {
        if ($user->organization_id === null) {
            return [];
        }

        $mapping = CapabilityToAuthorizationRolePermission::map($capability);
        if ($mapping === null) {
            return [];
        }

        $resourceId = AuthorizationResource::query()
            ->where('key', $mapping['resource'])
            ->value('id');
        if ($resourceId === null) {
            return [];
        }

        $permissions = static::loadAuthorizationRolePermissions($user)
            ->filter(fn (AuthorizationRolePermission $permission) => (int) $permission->authorization_resource_id === (int) $resourceId
                && (string) $permission->action === $mapping['action'])
            ->keyBy(fn (AuthorizationRolePermission $permission) => (int) $permission->authorization_role_id);

        $explicitRoleIds = $permissions->keys()->map(fn ($id) => (int) $id)->all();
        $allowAdminShortcut = ! static::requiresExplicitGrant($capability);

        $assignments = AuthorizationRoleAssignment::query()
            ->with('role')
            ->where('user_id', $user->id)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($query) use ($explicitRoleIds, $allowAdminShortcut): void {
                if ($explicitRoleIds !== []) {
                    $query->whereIn('authorization_role_id', $explicitRoleIds);
                } else {
                    $query->whereRaw('1 = 0');
                }

                if ($allowAdminShortcut) {
                    $query->orWhereHas('role', fn ($roleQuery) => $roleQuery->where('is_admin_role', true));
                }
            })
            ->orderBy('id')
            ->get();

        $module = static::moduleOf($capability);
        $grants = [];

        foreach ($assignments as $assignment) {
            if (! self::canonicalRoleIsActive($assignment)
                || ($assignment->expires_at !== null && $assignment->expires_at->isPast())
                || ! static::canonicalListAssignmentMatchesUserOrganization($user, $assignment)) {
                continue;
            }

            $permission = $permissions->get((int) $assignment->authorization_role_id);
            if (! $permission instanceof AuthorizationRolePermission) {
                if (! $allowAdminShortcut || ! (bool) $assignment->role?->is_admin_role) {
                    continue;
                }

                $reach = 'all';
            } else {
                $reachMap = $permission->reach;
                $reach = is_array($reachMap) ? (string) ($reachMap[$module] ?? 'all') : 'all';
            }

            if (! in_array($reach, ['all', 'department', 'own'], true)) {
                continue;
            }

            $grants[] = ['assignment' => $assignment, 'reach' => $reach];
        }

        return $grants;
    }

    protected static function canonicalListAssignmentMatchesUserOrganization(
        User $user,
        AuthorizationRoleAssignment $assignment,
    ): bool {
        $userOrganizationId = $user->organization_id;
        if ($userOrganizationId === null) {
            return false;
        }

        if ($assignment->scope_type === AuthorizationRoleAssignment::SCOPE_ALL) {
            return true;
        }

        $assignmentOrganizationId = $assignment->scope_type === AuthorizationRoleAssignment::SCOPE_ORGANIZATION
            ? ($assignment->organization_id ?? $assignment->scope_id)
            : $assignment->organization_id;

        return $assignmentOrganizationId !== null
            && (int) $assignmentOrganizationId === (int) $userOrganizationId;
    }

    // ========================================================
    // Helpers آمنة
    // ========================================================

    /**
     * استدعاء scopeParent() بأمان — لا يرمي حتى لو علاقة null.
     *
     * Memoized by concrete node identity ("class:id") so building the scope chain
     * for many records that share ancestors (e.g. 10 risks in one department)
     * fetches each ancestor once per request instead of once per record/ability.
     * The key is the node whose parent we resolve, so distinct models never alias.
     */
    protected static function safeParent(ScopeAware $model): ?Model
    {
        $key = get_class($model).':'.$model->getKey();

        if (array_key_exists($key, static::$scopeParentCache)) {
            return static::$scopeParentCache[$key];
        }

        try {
            return static::$scopeParentCache[$key] = $model->scopeParent();
        } catch (\Throwable) {
            return static::$scopeParentCache[$key] = null;
        }
    }

    /**
     * Load (and memoize) every `authorization_role_permissions` row whose role
     * the user is assigned to. The join path is one DB read on first call
     * per user; subsequent calls within the same request reuse the cache.
     *
     * @return Collection<int, AuthorizationRolePermission>
     */
    protected static function loadAuthorizationRolePermissions(User $user): Collection
    {
        if (array_key_exists($user->id, static::$rolePermissionsCache)) {
            return static::$rolePermissionsCache[$user->id];
        }

        $assignmentRoleIds = AuthorizationRoleAssignment::query()
            ->where('user_id', $user->id)
            ->pluck('authorization_role_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($assignmentRoleIds === []) {
            return static::$rolePermissionsCache[$user->id] = collect();
        }

        return static::$rolePermissionsCache[$user->id] = AuthorizationRolePermission::query()
            ->whereIn('authorization_role_id', $assignmentRoleIds)
            ->get();
    }

    /**
     * Load (and memoize) every `AuthorizationRoleAssignment` the user holds
     * whose role carries `is_admin_role = true`. The join path is one read
     * of two tables on first call per user; subsequent calls within the
     * same request reuse the cache.
     *
     * @return Collection<int, AuthorizationRoleAssignment>
     */
    protected static function loadAdminRoleAssignments(User $user): Collection
    {
        if (array_key_exists($user->id, static::$adminAssignmentsCache)) {
            return static::$adminAssignmentsCache[$user->id];
        }

        // Direct join: assignments the user holds whose role's flag is
        // set. A user without any admin role assignment returns an
        // empty collection that is itself memoized, so the per-user
        // shape (admin / non-admin) is read at most once per request.
        $assignments = AuthorizationRoleAssignment::query()
            ->join('authorization_roles', 'authorization_roles.id', '=', 'authorization_role_assignments.authorization_role_id')
            ->where('authorization_role_assignments.user_id', $user->id)
            ->where('authorization_roles.is_admin_role', true)
            ->select('authorization_role_assignments.*')
            ->get();

        return static::$adminAssignmentsCache[$user->id] = $assignments;
    }

    /**
     * Apply the canonical permission row's reach cap to a target.
     *
     * @param  AuthorizationRolePermission  $pivot
     */
    protected static function canonicalReachAdmits(User $user, Model $target, $pivot, string $capabilityOrAction): bool
    {
        $reach = $pivot->reach;
        if ($reach === null) {
            // No reach cap on this canonical permission row.
            return true;
        }

        $module = str_contains($capabilityOrAction, '.')
            ? substr($capabilityOrAction, 0, strpos($capabilityOrAction, '.'))
            : $capabilityOrAction;
        $moduleReach = is_array($reach) ? ($reach[$module] ?? 'all') : 'all';

        if ($moduleReach === 'all') {
            return true;
        }

        if ($moduleReach === 'own') {
            return static::isOwner($user, $target);
        }

        // 'department' applies the user-department-subtree cap
        // unconditionally and cannot widen access.
        if ($moduleReach === 'department') {
            $userDept = $user->department_id;
            if ($userDept === null) {
                return false;
            }

            return static::targetInDepartments(
                $target,
                static::subtreeDepartmentIds([(int) $userDept])
            );
        }

        // Unknown reach value (defensive: a manual override could
        // have introduced a value the engine does not recognize).
        // Invalid policy data must never widen access in enforce mode.
        return false;
    }

    /**
     * Apply every applicable record rule to a probe query restricted to the
     * target key. Returns true when the target survives the AND chain (or no
     * rules apply). Returns false when at least one rule excludes the target.
     */
    protected static function recordRulesAdmitTarget(User $user, string $resourceKey, string $action, Model $target): bool
    {
        $rules = static::loadApplicableAuthorizationRecordRules($user, $resourceKey, $action);

        if ($rules->isEmpty()) {
            return true;
        }

        $query = $target::query()->whereKey($target->getKey());

        $evaluator = new RecordRuleEvaluator;
        $evaluator->compileWheres($resourceKey, $action, $user, $query);

        return $query->exists();
    }

    /**
     * Load (and memoize) the enabled record rules that apply to the
     * (user, resource key, action) triple, matching either by role (via the
     * user's role assignments), by user, or by the global wildcard
     * (NULL role + NULL user).
     *
     * @return Collection<int, AuthorizationRecordRule>
     */
    protected static function loadApplicableAuthorizationRecordRules(User $user, string $resourceKey, ?string $action): Collection
    {
        $cacheKey = $user->id.'|'.$resourceKey.'|'.($action ?? '');

        if (array_key_exists($cacheKey, static::$applicableRecordRulesCache)) {
            return static::$applicableRecordRulesCache[$cacheKey];
        }

        $roleIds = AuthorizationRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereHas('role', fn ($query) => $query->where('is_active', true))
            ->pluck('authorization_role_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $query = AuthorizationRecordRule::query()
            ->enabled()
            ->forResource($resourceKey)
            ->forAction($action)
            ->where(function ($q) use ($user, $roleIds) {
                $q->where(function ($inner) {
                    $inner->whereNull('authorization_role_id')->whereNull('user_id');
                });

                if ($roleIds !== []) {
                    $q->orWhereIn('authorization_role_id', $roleIds)->whereNull('user_id');
                }

                $q->orWhere('user_id', $user->id);
            })
            ->orderByDesc('priority')
            ->orderBy('id');

        return static::$applicableRecordRulesCache[$cacheKey] = $query->get();
    }
}
