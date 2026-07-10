<?php

namespace App\Modules\Core\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserOrganizationScope - الفلتر الموحّد لعزل قوائم المستخدمين على مستوى المؤسسة.
 *
 * هذا هو المكان الوحيد الذي يطبّق فلتر organization_id (وقسم subtree لغير admin)
 * على استعلامات User. لا يُعاد تنفيذه في أي Controller. عند الإضافة على Builder أي
 * استعلام Eloquent يخصّ User، يجب استدعاء applyToUsers.
 *
 * السلوك (يطابق UserController::applyUserVisibility السابق byte-for-byte):
 *   - super_admin: لا فلتر (يرى الكل).
 *   - actor بلا organization_id: whereRaw('false') — fail-closed (لا يرى شيئاً).
 *   - actor بـ admin (organization-wide role): كل مستخدمي المؤسسة.
 *   - غير admin: مستخدمو المؤسسة داخل القسم الفرعي المُدار (subtree) + قسمه الخاص.
 *
 * لا تعتمد على سلسلة الأقسام الهرمية لأبعد من الـ dept subtree الحالي؛ الـ
 * AccessDecision engine يتولّى التفصيل الهرفي عبر scope-chain للـ Per-target
 * abilities. هذا الـ Scope مسؤول فقط عن الـ horizontal org floor + dept narrowing
 * لقوائم الـ index/list/stats.
 *
 * Phase 3 — minimal-risk: السلوك مطابق تمامًا للمنطق المُهاجَر من
 * UserController::applyUserVisibility (لا توسيع رؤية، لا تغيير semantics).
 */
class UserOrganizationScope
{
    /**
     * فلتر استعلام User.
     *
     * يُستخدم في UserController::index / stats / list.
     *
     * @param  Builder<User>  $query
     */
    public function applyToUsers(Builder $query, User $actor): Builder
    {
        // super_admin: لا فلتر (يرى كل المستخدمين عبر كل المؤسسات).
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        // actor بلا organization_id: fail-closed — لا يرى أي مستخدم.
        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        // org floor: كل مستخدم يجب أن يكون في نفس المؤسسة.
        $query->where('users.organization_id', $actor->organization_id);

        // admin (organization-wide role عبر AccessDecision::can(SETTINGS_MANAGE)):
        // يرى كل مستخدمي المؤسسة بدون dept narrowing.
        if ($actor->isAdmin()) {
            return $query;
        }

        // غير admin: dept subtree فقط.
        $deptIds = $this->resolveUserDepartmentSubtree($actor);

        // ponytail: [0] sentinel — مستخدم بلا قسم ولا managed departments يرى لا أحد
        // (لا ينتج عنه "whereIn()" فارغ قد يطابق الكل في بعض drivers).
        return $query->whereIn('users.department_id', $deptIds ?: [0]);
    }

    /**
     * قائمة معرّفات الأقسام المرئية للـ actor: managed departments + own department.
     *
     * مطابق للمنطق في UserController::applyUserVisibility السابق:
     *   - getManagedDepartmentIds() يفترض أنه يوسّع للأبناء (السلوك المحفوظ).
     *   - نضيف قسم الـ actor نفسه لضمان أن member يرى زملاءه في نفس القسم.
     *
     * @return array<int, int>
     */
    private function resolveUserDepartmentSubtree(User $actor): array
    {
        $managed = $actor->getManagedDepartmentIds();
        $own = $actor->department_id !== null ? [(int) $actor->department_id] : [];

        $ids = array_values(array_unique(array_filter(array_merge($managed, $own))));

        return $ids;
    }

    /**
     * Phase CFA-07 (HIGH PII) - Cluster limited-directory widening scope.
     *
     * Used by the UserController::list (and similar directory endpoints)
     * to widen the org floor to descendant organizations for actors who
     * hold Capability::USERS_VIEW + Capability::CLUSTER_TREE_VIEW on
     * actor.organization_id. Distinct from `applyToUsers` because:
     *
     *   - it does NOT apply the dept-subtree narrowing (a cluster directory
     *     is intentionally org-wide within the cluster),
     *   - it widens ONLY when the actor holds BOTH capabilities
     *     (otherwise it returns the same-org filter, no widening),
     *   - it is opt-in: callers invoke `applyToUsersClusterDirectory`
     *     explicitly to opt into the cluster shape; the default
     *     `applyToUsers` keeps its pre-CFA-07 behavior byte-identical
     *     for backwards compatibility with the admin / user-management
     *     endpoints that MUST NOT widen.
     *
     * STOP CONDITIONS per CFA-00 owner decision:
     *   - NEVER return UserResource via this widening - only the
     *     UserDirectoryResource (which the controller routes to).
     *   - NEVER apply this scope to write endpoints (store / update /
     *     destroy / role assignment).
     *
     * @param  Builder<User>  $query
     */
    public function applyToUsersClusterDirectory(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        $visibleOrgIds = $this->clusterVisibleOrgIds($actor);

        return $query->whereIn('users.organization_id', $visibleOrgIds);
    }

    /**
     * Whether the actor may opt into the sanitized cluster directory.
     *
     * Super admins keep the existing unrestricted list response. For every
     * other actor, both capabilities and an organization are mandatory.
     */
    public function canViewClusterDirectory(User $actor): bool
    {
        return ! $actor->isSuperAdmin()
            && $actor->organization_id !== null
            && Organization::query()
                ->whereKey($actor->organization_id)
                ->where('type', Organization::TYPE_CLUSTER)
                ->exists()
            && AccessDecision::can($actor, Capability::USERS_VIEW)
            && AccessDecision::can($actor, Capability::CLUSTER_TREE_VIEW);
    }

    /**
     * Cluster visible organization ids (Phase CFA-07, read-only).
     *
     * Returns [actor.org] unless the actor holds both required capabilities;
     * paired actors receive [actor.org, ...descendants].
     *
     * Mirrors UserKpiScope::clusterVisibleOrgIds pattern: same one-directional
     * walk from actor.org toward descendants, same dependency on
     * AccessDecision::can() for the two-path check.
     *
     * @return list<int>
     */
    private function clusterVisibleOrgIds(User $actor): array
    {
        $orgId = (int) $actor->organization_id;
        $visible = [$orgId];

        $hasModuleCap = AccessDecision::can($actor, Capability::USERS_VIEW);
        $hasClusterTreeCap = AccessDecision::can($actor, Capability::CLUSTER_TREE_VIEW);

        if (! $hasModuleCap || ! $hasClusterTreeCap) {
            return $visible;
        }

        $org = Organization::query()->find($orgId);
        if (! $org instanceof Organization || ! $org->isCluster()) {
            return $visible;
        }

        return array_values(array_unique(array_merge($visible, $org->descendantIds())));
    }
}
