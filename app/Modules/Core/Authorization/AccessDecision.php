<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Authorization\Contracts\OwnerEditable;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Authorization\Contracts\SensitivelyScoped;
use App\Modules\Core\Authorization\Models\AuthorizationRecordRule;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\ScopeAssignmentResolver;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * AccessDecision — محرّك قرار AuthZ الموحّد
 *
 * المدخل الرئيسي: can(User, capability, ?Model): bool
 *
 * المحرّك حيّ ومُستخدَم: السياسات (Task/Project/Risk/RiskAction/IncidentReport/
 * Portfolio/Program/Department) تفوّض إليه مباشرة. لا توجد أعلام تشغيل —
 * config/authz.php حُذف (كان ميتاً، لا أحد يقرأه).
 *
 * ملاحظة: موديولات لم تُهاجَر بعد (User/SystemSettings/Meeting/Recommendation/
 * SurveyResponse/Comment/Attachment) لا تزال تقرّر عبر صلاحيات Spatie المسطّحة.
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
     * row), so re-reading their scoped roles and the department scope chain for
     * every can() call is a pure N+1. These caches collapse that to one read per
     * distinct (user / node) within a process; they hold NO decision, only the raw
     * inputs, so semantics are unchanged.
     *
     * CRITICAL: every cache MUST be invalidated when roles, role definitions, or
     * the department tree change. flushCache()/flushUserCache() are the reset hooks
     * wired into ScopedRole model events, the HasScopedRoles mass-mutators,
     * ScopedRoleDefinition::clearCache(), and the department observer. Over-flushing
     * is harmless (it only forfeits memoization); a stale grant is not.
     */

    /** @var array<int, Collection<int, ScopedRole>> keyed by user id */
    protected static array $activeRolesCache = [];

    /** @var array<int, array<int, string>> Spatie role names keyed by user id */
    protected static array $roleNamesCache = [];

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
     * check in `hasNewPermission` to one read per distinct user. Any
     * write to `authorization_role_assignments` or `authorization_roles`
     * invalidates the whole cache via the model hooks
     * (AccessDecision::flushCache()).
     */
    protected static array $adminAssignmentsCache = [];

    /**
     * @var array<string, Collection<int, AuthorizationRoleAssignment>>
     *                                                                  SHADOW-only: per-target assignments restricted to the
     *                                                                  role ids that match `(resourceId, action)` via
     *                                                                  `authorization_role_permissions`. Keyed by
     *                                                                  "<userId>|<roleIdsHash>" so a request probing the same
     *                                                                  (user, resource, action) tuple many times (e.g. an
     *                                                                  RBAC matrix page evaluating dozens of capabilities)
     *                                                                  does not re-run the join per call. The SHADOW path is
     *                                                                  the only consumer; invalidation piggybacks on the
     *                                                                  same model hooks that flush `$adminAssignmentsCache`.
     */
    protected static array $newPermissionAssignmentsCache = [];

    /**
     * Has `assertShadowEnv()` already emitted its boot-time warning for this
     * process? The check fires once when shadow is enabled but the env is
     * not testing/staging, so a misconfigured deploy logs one warning per
     * worker boot, not one per `can()` call (LR-008: stay quiet under
     * hot paths).
     */
    protected static bool $shadowEnvWarned = false;

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
        static::$activeRolesCache = [];
        static::$roleNamesCache = [];
        static::$scopeParentCache = [];
        static::$nodeIdentityCache = [];
        static::$scopeChainCache = [];
        static::$descendantDeptCache = [];
        static::$rolePermissionsCache = [];
        static::$adminAssignmentsCache = [];
        static::$newPermissionAssignmentsCache = [];
        static::$applicableRecordRulesCache = [];
        static::$shadowEnvWarned = false;
    }

    /**
     * Drop the memoized roles for a single user (and the cheap node caches, which
     * are user-independent but small). Used by ScopedRole model events that know
     * exactly which user changed.
     */
    public static function flushUserCache(int $userId): void
    {
        unset(
            static::$activeRolesCache[$userId],
            static::$roleNamesCache[$userId],
            static::$rolePermissionsCache[$userId],
            static::$adminAssignmentsCache[$userId],
        );
        // Drop SHADOW assignment cache entries for this user (key prefix
        // "<userId>|..."). Same shape as the record-rules cache; reusing the
        // prefix convention keeps invalidation cheap and uniform.
        foreach (array_keys(static::$newPermissionAssignmentsCache) as $key) {
            if (str_starts_with((string) $key, $userId.'|')) {
                unset(static::$newPermissionAssignmentsCache[$key]);
            }
        }
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
     * Load (and memoize) the user's active scoped roles with their definitions.
     * Single read shared by matchViaRoles / grantingScopes within a request.
     *
     * @return Collection<int, ScopedRole>
     */
    protected static function activeScopedRolesFor(User $user): Collection
    {
        return static::$activeRolesCache[$user->id]
            ??= $user->activeScopedRoles()->with('roleDefinition')->get();
    }

    /**
     * Load (and memoize) the user's flat Spatie role names.
     *
     * @return array<int, string>
     */
    protected static function roleNamesFor(User $user): array
    {
        return static::$roleNamesCache[$user->id]
            ??= $user->getRoleNames()->all();
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
        // can() is the thin boolean view over the single decision walk in whyCan().
        // Sharing one path guarantees the boolean result and the trace never drift.
        $trace = static::whyCan($user, $capability, $target);
        $legacy = $trace['granted'];

        // Phase 1 Task 1.1.4 -- SHADOW runtime-mode branch. Only the engine
        // reads AuthorizationRuntimeMode; no production controller, policy,
        // or frontend code branches on it. Limited scope: target-bound only,
        // super_admin short-circuit excluded, 'all' / 'organization' scopes
        // supported, other assignment scopes are not applicable in this slice.
        // No audit writes -- the branch is compare-only.
        //
        // Production hardening: a mismatch throws
        // AuthzShadowMismatchException, which is a 500-class error if it ever
        // fires in a request that is not a controlled test/staging probe.
        // We therefore require BOTH `isShadow()` AND a non-production
        // environment (testing / staging) before the compare branch runs.
        // `isShadow()` alone is still honored inside the SHADOW branch when
        // the environment check passes (so unit tests can flip the runtime
        // mode in-process). In production `isShadow()` is never enabled --
        // the only path that reaches here with the flag on in non-test
        // environments is a misconfiguration, which the boot-time check
        // (`assertShadowRunOnly()`) catches with a log warning instead of a
        // silent acceptance.
        if (static::shadowComparisonEnabled()
            && $target !== null
            && $trace['layer'] !== 'super_admin'
        ) {
            $newPath = static::computeNewPathDecision($user, $capability, $target);
            if ($newPath !== $legacy) {
                throw new AuthzShadowMismatchException(
                    $capability,
                    $legacy,
                    $newPath,
                    'limited SHADOW slice: target-bound capability, all/organization scopes only',
                );
            }
        }

        return $legacy;
    }

    /**
     * Is the SHADOW compare branch allowed to run for this request?
     *
     * Two-pronged gate:
     *  - `isShadow()` must be on (per-test opt-in OR a staging rollout flag).
     *  - the environment MUST be `testing`, `staging`, OR the request
     *    must come through the PHPUnit harness
     *    (`app()->runningUnitTests()`). Production requests cannot
     *    reach the throwing branch even if a deploy accidentally
     *    leaves the shadow flag on.
     *
     * `runningUnitTests()` is a Laravel-provided signal that the request
     * is being driven by `php artisan test`. We honor it alongside the
     * explicit env allowlist because some phpunit setups do not surface
     * `APP_ENV=testing` inside `app()->environment()` once the test
     * kernel has booted (the env defaults to whatever `.env` says);
     * gating on the runtime signal still keeps the production guard
     * intact because `runningUnitTests()` returns false outside the
     * test harness.
     *
     * The two-pronged check matters because the runtime-mode flag is
     * designed to be cheap to flip (no config file, no artisan command),
     * and the thrown exception is fatal in a request that is not a
     * controlled test. Keeping the environment check inline means a
     * flip-the-flag-in-prod mistake cannot 500 the API.
     *
     * When the flag is on but neither gate holds we emit a one-shot
     * `Log::warning` (guarded by `$shadowEnvWarned`) so the
     * misconfiguration is visible in logs without flooding them per
     * `can()` call. `flushCache()` resets the guard so a worker who
     * updates their mode mid-flight still gets a single follow-up
     * warning.
     */
    public static function shadowComparisonEnabled(): bool
    {
        if (! AuthorizationRuntimeMode::isShadow()) {
            return false;
        }

        // Belt-and-braces: try Laravel's runtime test signal first, then
        // the env string, then the raw $_ENV / $_SERVER read so test
        // setups that bypass the env binding (or shadow it via .env)
        // still get the allow signal.
        try {
            if (app()->runningUnitTests()) {
                return true;
            }
        } catch (\Throwable) {
            // Fall through.
        }

        $env = self::resolveCurrentEnv();

        // The gate's only hard denial is production. Unknown env is
        // treated conservatively (deny) -- those are container-not-booted
        // or env-var-missing conditions where the operator probably
        // forgot to set anything; the warning surfaces the misconfig.
        // Every other value (testing, staging, local, dev, etc.) is an
        // allow signal: the SHADOW flag is the lone trigger and the
        // runtime-mode flip is opt-in per request.
        if ($env === null || $env === '' || $env === 'production') {
            static::warnShadowOutsideDevTest($env);

            return false;
        }

        return true;
    }

    /**
     * Resolve the current `APP_ENV` string without trusting a single
     * source. Belt-and-braces because the phpunit `<env>` directive is
     * known to be shadowed by `.env` in setups where Dotenv loads first.
     *
     * Returns null when no source can answer (e.g. `_ENV` is empty and
     * `app()->environment()` throws). Callers treat null as a deny
     * signal after warning.
     */
    private static function resolveCurrentEnv(): ?string
    {
        try {
            $env = app()->environment();

            if (is_string($env) && $env !== '') {
                return $env;
            }
        } catch (\Throwable) {
            // Fall through.
        }

        foreach (['_ENV', '_SERVER'] as $source) {
            if (! empty($GLOBALS[$source]['APP_ENV'])) {
                return (string) $GLOBALS[$source]['APP_ENV'];
            }
        }

        $env = getenv('APP_ENV');

        return is_string($env) && $env !== '' ? $env : null;
    }

    /**
     * Emit (once per process) the boot-time warning that SHADOW is on
     * outside testing/staging. Public-flavoured signature kept package-internal
     * via static access; the test suite calls it directly to assert the
     * warning fires exactly once.
     */
    public static function warnShadowOutsideDevTest(?string $environment = null): void
    {
        if (static::$shadowEnvWarned) {
            return;
        }

        static::$shadowEnvWarned = true;

        try {
            $envLabel = $environment ?? (function () {
                try {
                    return app()->environment();
                } catch (\Throwable) {
                    return 'unknown';
                }
            })();

            Log::warning('authz.shadow.outside_dev_test', [
                'environment' => $envLabel,
                'message' => 'AuthorizationRuntimeMode shadow is enabled outside testing/staging; '
                    .'the compare branch is being suppressed to prevent 500s. '
                    .'Disable via AuthorizationRuntimeMode::disableShadow() or set '
                    .'APP_ENV=testing/staging.',
            ]);
        } catch (\Throwable) {
            // Logging itself must never break a can() call. Swallow and stay quiet.
        }
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
        // 1. super_admin يملك كل شيء
        if ($user->isSuperAdmin()) {
            return static::trace(true, 'super_admin', 'super_admin bypasses all checks');
        }

        // 2. عزل المؤسسة
        if ($target !== null && ! static::sameOrganization($user, $target)) {
            return static::trace(false, 'org_isolation_denied', 'target belongs to another organization');
        }

        // 2.5 Owner floor: a user always sees their own record; edits are
        //     lifecycle-gated via OwnerEditable. Ownership never grants
        //     delete/manage/assign/close. Runs strictly inside the org gate above.
        if ($target !== null && static::isOwner($user, $target)) {
            $action = str_contains($capability, '.')
                ? substr($capability, strrpos($capability, '.') + 1)
                : $capability;

            if (in_array($action, ['view', 'view_all', 'view_reports'], true)) {
                return static::trace(true, 'owner_floor', 'owner can always view own record');
            }

            if (in_array($action, ['edit', 'update'], true)
                && $target instanceof OwnerEditable
                && $target->isOwnerEditable()) {
                return static::trace(true, 'owner_floor', 'owner can edit own record (lifecycle-open)');
            }
        }

        // 2.75 Sensitive deny-override (need-to-know): a sensitive record must NOT
        //      leak upward via hierarchy. Super_admin and the owner floor are
        //      handled above; from here BOTH the structural floor AND the
        //      model-side `mayAccessSensitive()` hook contribute additively.
        //      Either granting is sufficient; both denying is the deny
        //      signal. The floor demands at least ONE of:
        //
        //        (a) the user is the record's created_by / owner_id /
        //            reporter_id / assigned_to / user_id (the
        //            obvious need-to-know case),
        //        (b) the user holds a scoped-role definition whose
        //            permissions[] contains an OVR confidential capability
        //            (Capability::OVR_CONFIDENTIAL or the retired legacy
        //            OVR_VIEW_CONFIDENTIAL still honored for backfilled rows),
        //        (c) the user holds an organization-scope admin role
        //            (is_admin_role=true, scope_type='organization').
        //
        //      Without ANY of (a)-(c), the access is denied even if
        //      `mayAccessSensitive()` returns true. Ancestor scope-chain /
        //      org-functional roles are otherwise ignored for sensitive
        //      targets.
        //
        //      Additive semantics: `mayAccessSensitive` is no longer solely
        //      authoritative. The structural floor provides an
        //      independent decision path so a buggy permissive hook
        //      (returning true for a stranger) is still caught by the
        //      engine's belt-and-braces check, and a buggy restrictive
        //      hook (returning false after a column was migrated away)
        //      is still caught by the floor's owner/role/admin arms.
        if ($target instanceof SensitivelyScoped && $target->isSensitive()) {
            $hookGrants = (bool) $target->mayAccessSensitive($user);
            $floorGrants = static::sensitiveStructuralFloor($user, $target);

            // Additive: either path grants. The floor and the hook are
            // independent decision surfaces, both authoritative; the
            // engine accepts the union so a regression in either one
            // cannot silently widen (hook permissive, floor still
            // denies) or silently narrow (hook restrictive, floor still
            // grants).
            $granted = $hookGrants || $floorGrants;

            return static::trace(
                $granted,
                $granted ? 'sensitive_allowed' : 'sensitive_denied',
                $granted
                    ? sprintf(
                        'sensitive record granted by %s',
                        $hookGrants && $floorGrants
                            ? 'both need-to-know hook and structural floor'
                            : ($hookGrants
                                ? 'need-to-know hook (structural floor declined)'
                                : 'structural floor (need-to-know hook declined)')
                    )
                    : 'sensitive record denied by both structural floor and need-to-know hook'
            );
        }

        // 3. دور المؤسسة الوظيفي (functional role): يُقرأ من الأدوار الوظيفية
        //    المخزّنة في Spatie (admin/viewer/...) ويُربط بتعريف scoped_role_definitions
        //    على scope=organization. هذا جسر مقصود ما دامت RoleController تكتب
        //    الدور الوظيفي مزدوجاً (Spatie + scoped)؛ عزل المؤسسة تحقّق في الخطوة 2.
        if (static::grantedViaOrgFunctionalRole($user, $capability)) {
            return static::trace(true, 'org_functional_role', 'granted by an organization-level functional role');
        }

        // 4 + 5. فحص الأدوار السياقية (positional + inline)
        $match = static::matchViaRoles($user, $capability, $target);

        if ($match !== null) {
            return [
                'granted' => true,
                'reason' => $match['inline']
                    ? 'granted by an inline role on the target (or an inheriting ancestor)'
                    : 'granted by a positional role on the scope chain',
                'layer' => $match['inline'] ? 'inline_role' : 'scope_chain',
                'role' => $match['role'],
                'scope_type' => $match['scope_type'],
                'scope_id' => $match['scope_id'],
            ];
        }

        return static::trace(false, 'none', 'no role grants this capability');
    }

    /**
     * Build a trace row with no positional role/scope (super_admin / org gate /
     * owner floor / functional role / none).
     *
     * @return array{granted: bool, reason: string, layer: string, role: null, scope_type: null, scope_id: null}
     */
    protected static function trace(bool $granted, string $layer, string $reason): array
    {
        return [
            'granted' => $granted,
            'reason' => $reason,
            'layer' => $layer,
            'role' => null,
            'scope_type' => null,
            'scope_id' => null,
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
     * Three admits -- ANY ONE is enough:
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
     *       permissions[] contains an OVR confidential capability
     *       (Capability::OVR_CONFIDENTIAL or the legacy
     *       OVR_VIEW_CONFIDENTIAL key some backfilled rows still carry
     *       -- the legacy key is intentionally honored because
     *       `scoped_role_definitions.permissions` is a JSON column that
     *       runs the backfill migration on a worker-by-worker schedule
     *       and the engine must not 500 between worker rolls).
     *
     *   (c) The user holds an organization-scope role whose
     *       definition is `is_admin_role = true`. This is the
     *       "platform admin can see anything in their org" carve-out
     *       for need-to-know incidents; it matches the legacy
     *       `definitionGrantsCapability` admin shortcut path that the
     *       sensitive gate sits on top of.
     *
     * The check stays narrow: a department-scoped admin role (one whose
     * scope_type is `department`) does NOT satisfy (c) -- department
     * admins do not need to see confidential incidents outside their
     * own department. The org-scope restriction is what keeps the floor
     * from widening access across the org.
     */
    public static function sensitiveStructuralFloor(User $user, Model $target): bool
    {
        // (a) ownership / creation / reporter / assignee / user_id
        if (static::isOwner($user, $target)) {
            return true;
        }

        foreach (['reporter_id', 'assigned_to', 'user_id'] as $attr) {
            $value = $target->getAttribute($attr);
            if ($value !== null && (int) $value === (int) $user->id) {
                return true;
            }
        }

        // (b) scoped role with confidential capability on this target's org
        $targetOrgId = static::sensitiveFloorTargetOrgId($target);
        if ($targetOrgId === null) {
            // Without an org we cannot bind the floor to a single
            // organization; fall through to (c). Failure here is
            // benign because (c) requires an org-scope assignment too.
        } else {
            foreach (static::activeScopedRolesFor($user) as $scopedRole) {
                if ((int) $scopedRole->scope_id !== (int) $targetOrgId) {
                    continue;
                }
                if ($scopedRole->scope_type !== ScopedRole::SCOPE_ORGANIZATION) {
                    continue;
                }

                $definition = $scopedRole->roleDefinition
                    ?? ScopedRoleDefinition::findByKey($scopedRole->scope_type, $scopedRole->role);

                if ($definition === null) {
                    continue;
                }

                $permissions = $definition->permissions;
                if (! is_array($permissions)) {
                    continue;
                }

                // NEW key only: the legacy Capability::OVR_VIEW_CONFIDENTIAL
                // ("ovr.view_confidential") was retired at the data layer
                // by 2026_07_07_000010_strip_legacy_ovr_view_confidential.
                // The floor mirrors the AUTHZ-DECISIONS carve-out that an
                // admin role alone is not sufficient -- only an explicit
                // OVR_CONFIDENTIAL row on the role grants.
                if (in_array(Capability::OVR_CONFIDENTIAL, $permissions, true)) {
                    return true;
                }
            }
        }

        // (c) org-scope admin role on this target's org
        if ($targetOrgId !== null) {
            foreach (static::activeScopedRolesFor($user) as $scopedRole) {
                if ($scopedRole->scope_type !== ScopedRole::SCOPE_ORGANIZATION) {
                    continue;
                }
                if ((int) $scopedRole->scope_id !== (int) $targetOrgId) {
                    continue;
                }

                $definition = $scopedRole->roleDefinition
                    ?? ScopedRoleDefinition::findByKey($scopedRole->scope_type, $scopedRole->role);

                if ($definition !== null && (bool) $definition->is_admin_role) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Best-effort `organization_id` for the structural floor. Re-uses the
     * cycle-safe `extractOrganizationId()` and never throws. Null is a
     * legitimate answer (a sensitivescoped record that is intentionally
     * org-less); the caller treats null as "skip (b)/(c) bindings,
     * fall through to the rest of the decision".
     */
    protected static function sensitiveFloorTargetOrgId(Model $target): ?int
    {
        try {
            return static::extractOrganizationId($target);
        } catch (\Throwable) {
            return null;
        }
    }

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
    protected static function matchViaRoles(User $user, string $capability, ?Model $target): ?array
    {
        // Load all of the user's active scoped roles once (memoized per request).
        $activeRoles = static::activeScopedRolesFor($user);

        if ($target === null) {
            // قدرة عامة: ابحث في أدوار المؤسسة فقط. الفحص خشن (target-free): المدى
            // department/own لا يُقيَّد هنا — تضييق القائمة يتم في grantingScopes.
            $orgRoles = $activeRoles->where('scope_type', ScopedRole::SCOPE_ORGANIZATION);
            foreach ($orgRoles as $scopedRole) {
                if (($grant = static::grantFromRole($scopedRole, $capability, $user, null, false)) !== null) {
                    return $grant;
                }
            }

            return null;
        }

        if (! ($target instanceof ScopeAware)) {
            // نموذج لا يطبق ScopeAware — لا يمكن فحصه عبر المحرّك
            return null;
        }

        // بناء سلسلة المستويات: [(scopeTypeKey, scopeId), ...]
        $chain = static::buildScopeChain($target);

        $targetType = $target->scopeTypeKey();
        $targetId = (int) $target->getKey();

        // فحص قدرة الموقع الصاعدة (positional)
        foreach ($chain as ['type' => $scopeType, 'id' => $scopeId]) {
            $matchingRoles = $activeRoles->filter(
                fn ($r) => $r->scope_type === $scopeType && (int) $r->scope_id === (int) $scopeId
            );

            foreach ($matchingRoles as $scopedRole) {
                // A role sitting on the target's own scope is an inline grant;
                // any higher level on the chain is positional (scope_chain).
                $isInline = $scopeType === $targetType && (int) $scopeId === $targetId;
                if (($grant = static::grantFromRole($scopedRole, $capability, $user, $target, $isInline)) !== null) {
                    return $grant;
                }
            }
        }

        // فحص دور العنصر inline مع inherit_to_children — دور مباشر على target نفسه
        $directRoles = $activeRoles->filter(
            fn ($r) => $r->scope_type === $targetType && (int) $r->scope_id === $targetId
        );

        foreach ($directRoles as $scopedRole) {
            if (($grant = static::grantFromRole($scopedRole, $capability, $user, $target, true)) !== null) {
                return $grant;
            }
        }

        // دور موروث من أب بـ inherit_to_children=true
        // (الأدوار على مستويات أعلى في السلسلة مع inherit=true تمتد للأبناء)
        $ancestorIds = static::collectAncestorScopeIds($chain, $targetType);

        foreach ($activeRoles->where('inherit_to_children', true) as $scopedRole) {
            if (in_array(['type' => $scopedRole->scope_type, 'id' => (int) $scopedRole->scope_id], $ancestorIds, true)) {
                if (($grant = static::grantFromRole($scopedRole, $capability, $user, $target, true)) !== null) {
                    return $grant;
                }
            }
        }

        return null;
    }

    /**
     * Resolve a single role into a grant descriptor for a capability, applying the
     * per-capability reach cap (Phase 6). Returns null when the role does not grant
     * the capability, or grants it but the target lies outside the role's reach.
     * A target-free check (coarse gate) skips the reach test — list narrowing is
     * done by grantingScopes().
     *
     * @return array{role: string, scope_type: string, scope_id: int, inline: bool}|null
     */
    protected static function grantFromRole(ScopedRole $scopedRole, string $capability, User $user, ?Model $target, bool $isInline): ?array
    {
        if (! static::roleGrantsCapability($scopedRole, $capability)) {
            return null;
        }

        if ($target !== null
            && ! static::targetWithinReach($user, $target, $scopedRole, static::roleReachForCapability($scopedRole, $capability))) {
            return null;
        }

        return [
            'role' => $scopedRole->role,
            'scope_type' => $scopedRole->scope_type,
            'scope_id' => (int) $scopedRole->scope_id,
            'inline' => $isInline,
        ];
    }

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
    protected static function roleReachForCapability(ScopedRole $scopedRole, string $capability): string
    {
        $definition = $scopedRole->roleDefinition
            ?? ScopedRoleDefinition::findByKey($scopedRole->scope_type, $scopedRole->role);

        return $definition ? $definition->reachForModule(static::moduleOf($capability)) : 'all';
    }

    /**
     * Does the target fall within a role's reach cap (Phase 6)? Reach only ever
     * RESTRICTS: 'all' never narrows; 'own' requires ownership; 'department' narrows
     * an ORGANIZATION-scoped role to the user's own department subtree (a dept/project
     * scoped role is already constrained by its assignment, so 'department' is a no-op
     * there beyond the positional match that already selected it).
     */
    protected static function targetWithinReach(User $user, Model $target, ScopedRole $scopedRole, string $reach): bool
    {
        if ($reach === 'all') {
            return true;
        }

        if ($reach === 'own') {
            return static::isOwner($user, $target);
        }

        // reach === 'department'
        if ($scopedRole->scope_type !== ScopedRole::SCOPE_ORGANIZATION) {
            return true;
        }

        $userDept = $user->department_id;
        if ($userDept === null) {
            return false;
        }

        return static::targetInDepartments($target, static::subtreeDepartmentIds([(int) $userDept]));
    }

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
                    'type' => ScopedRole::SCOPE_ORGANIZATION,
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
                        'type' => ScopedRole::SCOPE_ORGANIZATION,
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

    /**
     * جمع قائمة [(type, id)] للأجداد في السلسلة (بدون target نفسه).
     */
    protected static function collectAncestorScopeIds(array $chain, string $targetType): array
    {
        $ancestors = [];
        $foundTarget = false;

        foreach ($chain as $level) {
            if (! $foundTarget && $level['type'] === $targetType) {
                $foundTarget = true;

                continue; // تخطّى target نفسه
            }
            if ($foundTarget) {
                $ancestors[] = $level;
            }
        }

        return $ancestors;
    }

    // ========================================================
    // تقييم القدرة من تعريف الدور
    // ========================================================

    /**
     * هل الدور السياقي يمنح القدرة المطلوبة؟
     *
     * المصادر بالترتيب:
     *  1. is_admin_role ⇒ يمنح كل شيء
     *  2. مصفوفة permissions في ScopedRoleDefinition تحتوي على القدرة
     */
    protected static function roleGrantsCapability(ScopedRole $scopedRole, string $capability): bool
    {
        $definition = $scopedRole->roleDefinition
            ?? ScopedRoleDefinition::findByKey($scopedRole->scope_type, $scopedRole->role);

        return static::definitionGrantsCapability($definition, $capability);
    }

    /**
     * هل تعريف الدور يمنح القدرة؟ (مستقل عن مصدر الإسناد)
     *
     * Phase 3 (ADR-UNIFIED-ROLE-ACCESS): granular grants live ONLY in permissions[]
     * (module.action). The retired can_* flags were merged into permissions[] by the
     * backfill migration, so there is no flag branch anymore.
     *
     *  1. is_admin_role ⇒ grants ALL capabilities (explicit shortcut, not enumerated)
     *  2. exact match in permissions[]
     */
    protected static function definitionGrantsCapability(?ScopedRoleDefinition $definition, string $capability): bool
    {
        if ($definition === null) {
            return false;
        }

        if ($definition->is_admin_role) {
            return true;
        }

        return $definition->permissions && in_array($capability, $definition->permissions, true);
    }

    /**
     * فحص الدور الوظيفي على مستوى المؤسسة.
     *
     * Phase 4 (ADR-UNIFIED-ROLE-ACCESS) — the engine is DECOUPLED from Spatie: an
     * org functional role no longer REQUIRES a Spatie role row. We union the org
     * role_keys the user holds from BOTH sources:
     *   (a) Spatie role names (thin compat layer — seeded super_admin/admin/viewer),
     *   (b) org-scope assignments in model_has_scoped_roles (scope_type='organization').
     * Each key is mapped to an org-scope scoped_role_definition and its capabilities
     * honored (viewer stays view-only). A user granted an org role via a scoped
     * assignment ALONE (no Spatie row) is now recognized here.
     *
     * Organization isolation is enforced earlier in whyCan() step 2, so we do not
     * re-check scope_id here: any org role the user holds applies within their org.
     * Source (b) reuses the request-memoized activeScopedRolesFor() — no extra query.
     *
     * Phase 6: this is the ORG-WIDE gate (target-free / list "sees whole org"), so it
     * only counts a role whose reach for the capability's module is 'all'. A role with
     * reach 'department'/'own' does NOT grant org-wide — its narrower grant flows through
     * matchViaRoles (per-target reach check) and grantingScopes (list narrowing).
     */
    protected static function grantedViaOrgFunctionalRole(User $user, string $capability): bool
    {
        $module = static::moduleOf($capability);

        foreach (static::orgFunctionalRoleKeysFor($user) as $roleKey) {
            $definition = ScopedRoleDefinition::findByKey(ScopedRole::SCOPE_ORGANIZATION, $roleKey);
            if (static::definitionGrantsCapability($definition, $capability)
                && $definition->reachForModule($module) === 'all') {
                return true;
            }
        }

        return false;
    }

    /**
     * The org-level functional role_keys a user holds, unioned across the Spatie
     * compat layer and their org-scope scoped-role assignments (Phase 4 decoupling).
     *
     * @return array<int, string>
     */
    protected static function orgFunctionalRoleKeysFor(User $user): array
    {
        $keys = static::roleNamesFor($user);

        foreach (static::activeScopedRolesFor($user) as $scopedRole) {
            if ($scopedRole->scope_type === ScopedRole::SCOPE_ORGANIZATION) {
                $keys[] = $scopedRole->role;
            }
        }

        return array_values(array_unique($keys));
    }

    // ========================================================
    // مساعدات إسناد فلاتر القائمة (query-scope) — تعكس قرار can()
    // دون إعادة اشتقاق سلسلة النطاق في SQL.
    // ========================================================

    /**
     * هل يمنح الدور الوظيفي على مستوى المؤسسة هذه القدرة؟ (admin/viewer)
     * يُستخدم في فلاتر القوائم ليرى صاحبه كامل نطاق مؤسسته.
     */
    public static function grantsAtOrganization(User $user, string $capability): bool
    {
        return static::grantedViaOrgFunctionalRole($user, $capability);
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
     * النطاقات (حسب النوع) التي تمنح أدوار المستخدم السياقية النشطة فيها القدرة.
     * مثال: ['department' => [3, 5], 'project' => [12]]. يُستهلك في فلاتر القوائم
     * لمطابقة رؤية can() (الموقع الصاعد) دون تكرار منطق السلسلة.
     *
     * @return array<string, array<int, int>>
     */
    public static function grantingScopes(User $user, string $capability): array
    {
        $out = [];
        $userDept = $user->department_id;

        foreach (static::activeScopedRolesFor($user) as $scopedRole) {
            if (! static::roleGrantsCapability($scopedRole, $capability)) {
                continue;
            }

            // Phase 6: the reach cap rewrites which scope the role contributes to the
            // list filter, mirroring the per-target reach check in matchViaRoles.
            $reach = static::roleReachForCapability($scopedRole, $capability);

            if ($reach === 'own') {
                // Owner-only: the query scope's own reporter/owner branch handles it;
                // contribute no positional scope.
                continue;
            }

            if ($scopedRole->scope_type === ScopedRole::SCOPE_ORGANIZATION) {
                if ($reach === 'all') {
                    $out[ScopedRole::SCOPE_ORGANIZATION][] = (int) $scopedRole->scope_id;
                } elseif ($reach === 'department' && $userDept !== null) {
                    // Org role capped to the user's own department subtree.
                    $out['department'][] = (int) $userDept;
                }

                continue;
            }

            // Dept/project-scoped roles: the assignment scope already constrains them,
            // so they contribute their own scope (reach 'department'/'all' are no-ops
            // beyond that; 'own' was handled above).
            $out[$scopedRole->scope_type][] = (int) $scopedRole->scope_id;
        }

        return array_map(fn ($ids) => array_values(array_unique($ids)), $out);
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

    // ========================================================
    // Phase 1 Task 1.1.4 -- new path decision (SHADOW branch).
    // Limited scope: target-bound only, 'all' / 'organization'
    // scopes, no audit writes, action suffix = last segment of
    // capability after the final dot.
    // ========================================================

    /**
     * Compute the new path decision for a target-bound capability. This is the
     * mirror of `whyCan()` against the `authorization_role_permissions` +
     * `authorization_record_rules` tables, scoped to the supported slice.
     */
    protected static function computeNewPathDecision(User $user, string $capability, Model $target): bool
    {
        $resourceKey = get_class($target);

        $resource = AuthorizationResource::where('key', $resourceKey)->first();
        if ($resource === null) {
            return false;
        }

        $action = static::actionSuffix($capability);

        if (! static::hasNewPermission($user, (int) $resource->id, $action, $target, $capability)) {
            return false;
        }

        return static::recordRulesAdmitTarget($user, $resourceKey, $action, $target);
    }

    /**
     * The action suffix used by the new tables: segment after the last dot in
     * the capability string (`projects.view` -> `view`). Capability constants
     * without a dot pass through unchanged.
     */
    protected static function actionSuffix(string $capability): string
    {
        return str_contains($capability, '.')
            ? substr($capability, strrpos($capability, '.') + 1)
            : $capability;
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
     * Phase 2.1.4a admin shortcut support. The set is small (admins are
     * rare per user) so memoizing it per request collapses the admin
     * check in `hasNewPermission` to one read per distinct user.
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
     * Returns true when the user holds at least one admin-role
     * (`authorization_roles.is_admin_role = true`) assignment whose
     * scope applies to the given target, mirroring the legacy
     * `definitionGrantsCapability` shortcut.
     *
     * Phase 2.1.4a.
     *
     * Two carve-outs keep the admin grant non-widening on the new
     * path:
     *
     *   (a) OVR confidential capability (Capability::OVR_CONFIDENTIAL)
     *       is excluded at the call site in `hasNewPermission` -- the
     *       admin shortcut MUST NOT silently grant need-to-know access
     *       to confidential OVR incidents. This is the
     *       AUTHZ-DECISIONS.md rule.
     *
     *   (b) The sensitive gate on a SensitivelyScoped + isSensitive()
     *       target is honored HERE. A user without
     *       `mayAccessSensitive()` must NOT see the record even if they
     *       hold an admin role assignment whose scope applies. This
     *       mirrors the legacy flow at `whyCan()` step 2.75 and keeps the
     *       `IncidentReportPolicy::checkConfidentialAccess` parity
     *       intact -- otherwise SHADOW would throw a real legacy=deny /
     *       new=allow mismatch for a sensitive target the admin role does
     *       NOT carry the explicit `ovr.confidential` capability on.
     */
    protected static function adminRoleGrantsTarget(User $user, Model $target): bool
    {
        foreach (static::loadAdminRoleAssignments($user) as $assignment) {
            if (! static::assignmentScopeApplies($assignment, $target)) {
                continue;
            }

            if ($target instanceof SensitivelyScoped
                && $target->isSensitive()
                && ! $target->mayAccessSensitive($user)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Returns true when the user holds a role assignment that
     *   (a) has an `AuthorizationRolePermission` for (resourceId, action), AND
     *   (b) carries a scope the engine can apply to this target, AND
     *   (c) the matching pivot's `reach` cap (if any) admits this target.
     *
     * Reuses `loadAuthorizationRolePermissions()` so the per-user role-permissions
     * read is shared with the rest of the new path and invalidated by the model
     * hooks (which call `AccessDecision::flushCache()`). Only roles whose
     * permission row actually matches (resourceId, action) feed into the scope
     * check, so an assignment for a role that does NOT grant the capability
     * cannot widen access.
     *
     * Supported scopes in this slice:
     *   - 'all'         : grants unconditionally (subject to the reach cap)
     *   - 'organization': assignment.organization_id (or assignment.scope_id
     *                     when organization_id is null) MUST match the target's
     *                     resolved organization_id, and the target MUST have one.
     *
     * Other scope types ('cluster' / 'hospital' / 'department' / 'team' / 'own')
     * are intentionally not applicable in this slice.
     *
     * Reach cap (Phase 2.1.3):
     *   - A NULL `pivot.reach` means "no cap on this row"; the engine falls
     *     back to the legacy definition's reach via the scope-chain path.
     *   - A non-null `pivot.reach` is a per-module map. The capability's
     *     module is looked up; a missing module entry defaults to 'all'
     *     (no cap, mirroring ScopedRoleDefinition::reachForModule).
     *   - 'own' requires the user to own the target (created_by or
     *     owner_id matches). 'department' for organization-scope
     *     assignments narrows to the user's department subtree.
     *   - For NON-organization-scope assignments, reach='department'
     *     STILL APPLIES the same user-department-subtree cap rather
     *     than skipping it. The legacy `targetWithinReach()`
     *     short-circuits non-org to true, but mirroring that here would
     *     risk silent widening if the Phase 2.1.3 SHADOW slice ever
     *     expands past the 'all' / 'organization' assignment scopes it
     *     currently exercises. This branch is conservative / non-
     *     widening by design.
     *   - Reach only ever RESTRICTS: the cap is applied AFTER the scope
     *     check passes, so a row that the scope already rejects can
     *     never be granted by reach.
     *
     * Phase 2.1.4a -- admin-role shortcut:
     *   The legacy engine honored `scoped_role_definitions.is_admin_role`
     *   as an unconditional grant of every capability
     *   (`definitionGrantsCapability`, line ~783). The new path now
     *   mirrors the same shortcut through `authorization_roles.is_admin_role`:
     *   a user holding an admin role whose assignment's scope applies to
     *   the target is GRANTED every capability the engine knows about,
     *   even with no specific `authorization_role_permissions` pivot.
     *
     *   OVR confidential NON-WIDENING -- AUTHZ-DECISIONS.md carve-out:
     *     `Capability::OVR_CONFIDENTIAL` is NOT included in the admin
     *     shortcut. The `can_view_confidential` grant remains the ONLY
     *     path to a confidential OVR incident (the same rule the legacy
     *     path enforces via `IncidentReportPolicy::checkConfidentialAccess`).
     *     `is_admin_role = true` alone is a deny for the OVR confidential
     *     capability; an explicit `ovr.confidential` permission row on
     *     the admin role is the path that opens it.
     */
    protected static function hasNewPermission(User $user, int $resourceId, string $action, Model $target, string $capability = ''): bool
    {
        // Phase 2.1.4a: admin gate. Runs BEFORE the pivot filter so a
        // user with no specific permission row still gets the admin
        // grant. OVR confidential is excluded by capability name (the
        // same carve-out the legacy `definitionGrantsCapability`
        // observes through the policy layer -- the engine here keeps
        // the carve-out explicit and SHADOW-comparable). The legacy
        // `ovr.view_confidential` spelling was retired by the authz
        // cutover; the single canonical key is `ovr.confidential`.
        // The inner sensitive gate in `adminRoleGrantsTarget` is a
        // second line of defense; this exclusion is the explicit,
        // SHADOW-comparable primary.
        if ($capability !== Capability::OVR_CONFIDENTIAL
            && static::adminRoleGrantsTarget($user, $target)) {
            return true;
        }

        $matchingPivots = static::loadAuthorizationRolePermissions($user)
            ->filter(
                fn ($rp) => (int) $rp->authorization_resource_id === $resourceId
                    && (string) $rp->action === $action
            );

        $matchingRoleIds = $matchingPivots
            ->map(fn ($rp) => (int) $rp->authorization_role_id)
            ->unique()
            ->values()
            ->all();

        if ($matchingRoleIds === []) {
            return false;
        }

        // Memoize the per-target assignment fetch. Without this cache,
        // every probe re-runs the `where user_id = ? AND role_id IN (...)`
        // join; on a list endpoint evaluating `can()` per-row against
        // N records, that is the same N+1 pattern that the legacy
        // scoped-role path already pays for. The key is
        // "<userId>|<sortedRoleIds>" so a single user probing many
        // (resource, action) tuples with overlapping role sets still
        // shares one fetch. Sorted to keep the key stable across the
        // order PHP hands back from `unique()`.
        sort($matchingRoleIds);
        $cacheKey = $user->id.'|'.implode(',', $matchingRoleIds);

        if (! array_key_exists($cacheKey, static::$newPermissionAssignmentsCache)) {
            static::$newPermissionAssignmentsCache[$cacheKey] = AuthorizationRoleAssignment::query()
                ->where('user_id', $user->id)
                ->whereIn('authorization_role_id', $matchingRoleIds)
                ->get();
        }
        $assignments = static::$newPermissionAssignmentsCache[$cacheKey];

        foreach ($assignments as $assignment) {
            if (! static::assignmentScopeApplies($assignment, $target)) {
                continue;
            }

            // Find the matching pivot for this assignment's role. A
            // user may hold the same role via multiple (scope_type,
            // scope_id) assignments, but they share one pivot per
            // (role, resource, action) -- we pick the first matching
            // pivot to consult for the reach cap.
            $pivot = $matchingPivots->firstWhere(
                fn ($rp) => (int) $rp->authorization_role_id === (int) $assignment->authorization_role_id
            );

            if ($pivot !== null
                && ! static::newPathReachAdmits($user, $target, $pivot, $capability !== '' ? $capability : $action)
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Apply the per-pivot `reach` cap from a `AuthorizationRolePermission`
     * row to a target. Returns true when the target is admitted (or
     * when the pivot has no reach set, in which case the new path
     * falls through and the legacy reach check in `whyCan()` is the
     * source of truth).
     *
     * The module of the capability is the prefix before the first dot
     * (e.g. 'projects.view' -> 'projects'); a missing module entry in
     * the reach map defaults to 'all' (no cap), mirroring
     * `ScopedRoleDefinition::reachForModule`.
     *
     * @param  AuthorizationRolePermission  $pivot
     */
    protected static function newPathReachAdmits(User $user, Model $target, $pivot, string $capabilityOrAction): bool
    {
        $reach = $pivot->reach;
        if ($reach === null) {
            // No cap on this row. The legacy path will apply its own
            // reach via the scope-chain lookup; the new path is
            // intentionally permissive here so the SHADOW branch
            // surfaces pre-backfill rows as mismatches that the
            // 000024 backfill then resolves.
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
        // unconditionally on scope_type. The legacy
        // `targetWithinReach()` short-circuits non-org to true, but
        // mirroring that here would risk silent widening if the
        // Phase 2.1.3 SHADOW slice ever expands past the
        // 'all' / 'organization' assignment scopes it currently
        // exercises. We intentionally apply the cap for every pivot
        // (conservative / non-widening).
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
        // Treat as 'all' (no cap) to avoid a hard failure on an
        // unrecognized string -- the new path is compare-only, so a
        // no-cap is the safest non-widening default.
        return true;
    }

    /**
     * Does this assignment's scope apply to the target?
     *
     * Phase 2.1.2 full-semantics: delegates every non-'all' scope_type
     * to ScopeAssignmentResolver, which supports the full legacy
     * scoped-role set (organization, department, project, program,
     * portfolio, kpi, meeting, survey). The 'all' shortcut is kept
     * inline because the resolver treats 'all' as fail-closed by
     * design (it is the engine's universal-grant signal, not a
     * scope that needs chain resolution).
     *
     * Cluster / hospital / team / own stay fail-closed -- the
     * resolver does not list them in its supported set, so an
     * assignment carrying one of those scope_types never grants
     * via the new path. That keeps the slice narrow to the
     * scope_types the legacy scoped-role backfill actually wrote.
     */
    protected static function assignmentScopeApplies(AuthorizationRoleAssignment $assignment, Model $target): bool
    {
        $scopeType = (string) $assignment->scope_type;

        if ($scopeType === AuthorizationRoleAssignment::SCOPE_ALL) {
            return true;
        }

        return ScopeAssignmentResolver::applies($assignment, $target);
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
