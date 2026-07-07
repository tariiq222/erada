<?php

namespace App\Modules\Shared\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Support\Facades\Log;

/**
 * ActivityLogOrganizationResolver - اشتقاق organization_id لسجل النشاط
 *
 * ترتيب الأولويات (أول مطابقة تفوز):
 *
 *   1. loggable.organization_id (عمود مباشر على الـ loggable).
 *   2. loggable implements ScopeAware → scopeOrganizationId().
 *   3. loggable parent chain عبر AccessDecision::resolveScopeParent().
 *   4. scope_type/scope_id (SCOPE_ORGANIZATION يرجع scope_id مباشرة؛
 *      SCOPE_PROJECT/DEPARTMENT/... يحلّ الـ target ويستخرج org).
 *   5. target_user.organization_id (المستخدم المتأثر في أحداث الصلاحيات).
 *   6. actor (user_id).organization_id — FALLBACK موثّق، فقط عندما
 *      target_user_id = null أو target_user_id === user_id.
 *   7. null + INFO log للأحداث النظامية cross-org، WARNING لغيرها.
 *
 * actor.organization_id لا يُستخدم أبداً كمصدر أول.
 */
class ActivityLogOrganizationResolver
{
    /** مفاتيح scope_type التي تدلّ على tenant نوعه "organization" */
    public const SCOPE_TYPE_ORGANIZATION = 'organization';

    /** scope_types التي نملك لها موديل ونستطيع استخراج org عبره */
    public const RESOLVABLE_SCOPE_TYPES = [
        'project' => Project::class,
        'department' => Department::class,
        'program' => Program::class,
        'portfolio' => Portfolio::class,
        'risk' => Risk::class,
        'incident' => IncidentReport::class,
        'kpi' => Kpi::class,
        'survey' => Survey::class,
    ];

    /** actions cross-org متعمّدة (INFO log، لا WARNING) */
    public const CROSS_ORG_ACTIONS = [
        ActivityLog::ACTION_LOGIN_FAILED,
        'private_attachments_purged',
        'scope_type_created',
        'scope_type_updated',
        'scope_type_deleted',
        'incident_type_created',
        'incident_type_updated',
        'incident_type_deleted',
        'reportable_type_created',
        'system_settings_updated',
        'role_definition_created',
        'role_definition_updated',
        'role_definition_deleted',
    ];

    /** مفاتيح dedupe للـ WARNING log داخل نفس الـ request */
    protected array $loggedWarnings = [];

    /**
     * حلّ organization_id من payload (المصفوفة التي ستُمرَّر إلى ActivityLog::create()).
     * يُعيد ?int — null عند تعذّر الاشتقاق.
     */
    public function resolve(array $payload): ?int
    {
        return $this->resolveWithTrace($payload)['organization_id'];
    }

    /**
     * نسخة موسومة تُعيد المصدر والسبب، يستخدمها أمر الـ backfill لإحصاء source_breakdown.
     *
     * @return array{organization_id: ?int, source: string|null, reason: string|null}
     */
    public function resolveWithTrace(array $payload): array
    {
        // 1) loggable direct organization_id (أو short-circuit للـ Organization)
        $loggableOrg = $this->resolveForLoggable(
            $payload['loggable_type'] ?? null,
            $payload['loggable_id'] ?? null
        );
        if ($loggableOrg !== null) {
            return [
                'organization_id' => $loggableOrg,
                'source' => 'loggable',
                'reason' => 'loggable.organization_id (direct column or ScopeAware chain)',
            ];
        }

        // 2) scope_type/scope_id
        $scopeOrg = $this->resolveForScope(
            $payload['scope_type'] ?? null,
            $payload['scope_id'] ?? null
        );
        if ($scopeOrg !== null) {
            return [
                'organization_id' => $scopeOrg,
                'source' => 'scope',
                'reason' => "scope_type={$payload['scope_type']} resolved to org via target",
            ];
        }

        // 3) target_user.organization_id (المتأثر في أحداث الصلاحيات)
        $targetUserId = $payload['target_user_id'] ?? null;
        if ($targetUserId !== null) {
            $targetUser = User::find($targetUserId);
            if ($targetUser && $targetUser->organization_id !== null) {
                return [
                    'organization_id' => (int) $targetUser->organization_id,
                    'source' => 'target_user',
                    'reason' => "target_user_id={$targetUserId} → users.organization_id",
                ];
            }
        }

        // 4) actor (user_id) — DOCUMENTED FALLBACK
        //    يُستخدم فقط عندما target_user_id = null أو target_user_id === user_id.
        $userId = $payload['user_id'] ?? null;
        if ($userId !== null && ($targetUserId === null || (int) $targetUserId === (int) $userId)) {
            $actor = User::find($userId);
            if ($actor && $actor->organization_id !== null) {
                return [
                    'organization_id' => (int) $actor->organization_id,
                    'source' => 'actor',
                    'reason' => "user_id={$userId} → users.organization_id (fallback: target_user_id matches or absent)",
                ];
            }
        }

        // 5) null — سجّل حسب نوع الحدث
        $reason = $this->explainUnresolved($payload);
        $this->logUnresolved($payload, $reason);

        return [
            'organization_id' => null,
            'source' => 'none',
            'reason' => $reason,
        ];
    }

    /**
     * حلّ org من loggable عبر (1) عمود مباشر (2) ScopeAware (3) parent chain.
     * يُعيد ?int — null إذا تعذّر.
     */
    public function resolveForLoggable(?string $type, mixed $id): ?int
    {
        if (empty($type) || $id === null || $id === '' || $id === '0') {
            return null;
        }

        // loggable_type = Organization نفسه
        if ($type === Organization::class || $this->classBasenameMatches($type, Organization::class)) {
            return (int) $id;
        }

        if (! class_exists($type)) {
            return null;
        }

        try {
            $model = $type::query()->find($id);
        } catch (\Throwable $e) {
            return null;
        }
        if (! $model) {
            return null;
        }

        // عمود مباشر organization_id
        if (isset($model->organization_id) && $model->organization_id !== null) {
            return (int) $model->organization_id;
        }

        // ScopeAware — يسأل الموديل نفسه
        if ($model instanceof ScopeAware) {
            $org = $model->scopeOrganizationId();
            if ($org !== null) {
                return (int) $org;
            }
        }

        // parent chain — يصلح للوصول إلى parent عبر AccessDecision
        $parent = AccessDecision::resolveScopeParent($type, is_numeric($id) ? (int) $id : null);
        if ($parent && isset($parent->organization_id) && $parent->organization_id !== null) {
            return (int) $parent->organization_id;
        }
        if ($parent instanceof ScopeAware) {
            $org = $parent->scopeOrganizationId();
            if ($org !== null) {
                return (int) $org;
            }
        }

        return null;
    }

    /**
     * حلّ org من scope_type/scope_id.
     * scope_type='organization' ⇒ scope_id.
     * غير ذلك ⇒ resolve target عبر RESOLVABLE_SCOPE_TYPES ثم scopeOrganizationId().
     */
    public function resolveForScope(?string $scopeType, mixed $scopeId): ?int
    {
        if (empty($scopeType) || $scopeId === null || $scopeId === '' || $scopeId === '0') {
            return null;
        }

        if ($scopeType === self::SCOPE_TYPE_ORGANIZATION) {
            return (int) $scopeId;
        }

        $modelClass = self::RESOLVABLE_SCOPE_TYPES[$scopeType] ?? null;
        if (! $modelClass || ! class_exists($modelClass)) {
            return null;
        }

        try {
            $model = $modelClass::query()->find($scopeId);
        } catch (\Throwable $e) {
            return null;
        }
        if (! $model) {
            return null;
        }

        if (isset($model->organization_id) && $model->organization_id !== null) {
            return (int) $model->organization_id;
        }
        if ($model instanceof ScopeAware) {
            $org = $model->scopeOrganizationId();
            if ($org !== null) {
                return (int) $org;
            }
        }

        return null;
    }

    /**
     * شرح موجز عن سبب تعذّر الاشتقاق (للـ backfill report).
     */
    protected function explainUnresolved(array $payload): string
    {
        $parts = [];
        if (! empty($payload['loggable_type'])) {
            $parts[] = "loggable_type={$payload['loggable_type']}";
        }
        if (! empty($payload['loggable_id'])) {
            $parts[] = "loggable_id={$payload['loggable_id']}";
        }
        if (! empty($payload['scope_type'])) {
            $parts[] = "scope_type={$payload['scope_type']}";
        }
        if (! empty($payload['user_id'])) {
            $parts[] = "user_id={$payload['user_id']}";
        }
        if (! empty($payload['target_user_id'])) {
            $parts[] = "target_user_id={$payload['target_user_id']}";
        }

        return $parts === [] ? 'empty payload' : 'unresolved: '.implode(', ', $parts);
    }

    /**
     * سجّل عدم القدرة على الاشتقاق.
     * - cross-org events ⇒ INFO.
     * - غير ذلك ⇒ WARNING.
     * - dedupe بـ (action, loggable_type) لتفادي إغراق اللوج داخل حلقة ساخنة.
     */
    protected function logUnresolved(array $payload, string $reason): void
    {
        $action = $payload['action'] ?? 'unknown';
        $loggableType = $payload['loggable_type'] ?? 'none';
        $dedupeKey = $action.':'.$loggableType;
        if (isset($this->loggedWarnings[$dedupeKey])) {
            return;
        }
        $this->loggedWarnings[$dedupeKey] = true;

        $level = in_array($action, self::CROSS_ORG_ACTIONS, true) ? 'info' : 'warning';
        $context = [
            'action' => $action,
            'loggable_type' => $loggableType,
            'loggable_id' => $payload['loggable_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'target_user_id' => $payload['target_user_id'] ?? null,
            'scope_type' => $payload['scope_type'] ?? null,
            'scope_id' => $payload['scope_id'] ?? null,
        ];

        Log::log($level, "activity_log.org_unresolved: {$reason}", $context);
    }

    /**
     * قارن basename لأن الـ polymorphic loggable_type قد يأتي بصيغ مختلفة.
     */
    protected function classBasenameMatches(string $type, string $fqcn): bool
    {
        return ltrim($type, '\\') === ltrim($fqcn, '\\')
            || class_basename($type) === class_basename($fqcn);
    }
}
