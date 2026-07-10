<?php

namespace App\Modules\Shared\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserActivityLogScope — الفلتر الموحّد لعزل سجلات النشاط على مستوى المؤسسة.
 *
 * هذا هو المكان الوحيد الذي يطبّق فلتر organization_id على ActivityLog.
 * لا يُعاد تنفيذه في أي Controller. عند الإضافة على Builder استعلام Eloquent
 * يخصّ ActivityLog، يجب استدعاء apply($query, $user).
 *
 * - super_admin: لا فلتر (يشمل organization_id IS NULL events النظامية).
 * - مستخدم عادي بلا organization_id: whereRaw(false) (fail-closed — لا يرى شيئاً).
 * - مستخدم عادي له organization_id: where('organization_id', $user.organization_id).
 *
 * Phase CFA-11 — cluster_auditor widening:
 *   When the actor holds EITHER:
 *     (a) AUDIT_VIEW + CLUSTER_TREE_VIEW, OR
 *     (b) AUDIT_EXPORT + CLUSTER_TREE_EXPORT
 *   on actor.organization_id, the `where organization_id IN (...)` widens
 *   to actor.organization_id plus every descendant organization id
 *   returned by `Organization::descendantIds()`. Either combination
 *   means the actor is a cluster_auditor with cluster-tree reach.
 *
 *   Same strict contract as AccessDecision's cluster_tree rescue branch:
 *     - super_admin still sees ALL rows (including null-org events);
 *     - null-org actor still gets whereRaw('false') (fail-closed);
 *     - missing both pairs ⇒ strict same-org only.
 *
 *   This scope NEVER widens module resource-level access; the polymorphic
 *   loggable_type / loggable_id pointer remains governed by the pointed-to
 *   record's own policy.
 */
class UserActivityLogScope
{
    /**
     * تطبيق الفلتر على الـ query.
     */
    public function apply(Builder $query, User $user): Builder
    {
        return $this->applyForPair($query, $user, Capability::AUDIT_VIEW, Capability::CLUSTER_TREE_VIEW);
    }

    /**
     * Apply the read-only audit scope. Export grants never widen this path.
     *
     * @param  Builder<ActivityLog>  $query
     */
    public function applyForRead(Builder $query, User $user): Builder
    {
        return $this->applyForPair($query, $user, Capability::AUDIT_VIEW, Capability::CLUSTER_TREE_VIEW);
    }

    /**
     * Apply the export audit scope. Read grants never widen this path.
     *
     * @param  Builder<ActivityLog>  $query
     */
    public function applyForExport(Builder $query, User $user): Builder
    {
        return $this->applyForPair($query, $user, Capability::AUDIT_EXPORT, Capability::CLUSTER_TREE_EXPORT);
    }

    /**
     * @param  Builder<ActivityLog>  $query
     */
    private function applyForPair(
        Builder $query,
        User $user,
        string $auditCapability,
        string $clusterCapability,
    ): Builder {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->organization_id === null) {
            // fail-closed: مستخدم بلا مؤسسة لا يرى أي سجل نشاط.
            return $query->whereRaw('false');
        }

        $orgId = (int) $user->organization_id;

        // Cluster widening — actor holds either audit pair on
        // actor.organization_id. Expand to actor.org + every descendant
        // org. Otherwise strict same-org.
        $visible = $this->hasClusterPair($user, $auditCapability, $clusterCapability)
            ? $this->clusterAuditableOrgIds($orgId)
            : [$orgId];

        $filter = count($visible) === 1
            ? 'activity_logs.organization_id = ?'
            : 'activity_logs.organization_id IN ('.implode(',', array_fill(0, count($visible), '?')).')';

        return $query->whereRaw($filter, $visible);
    }

    /**
     * Does the actor hold either audit pair on actor.org? Either:
     *   (a) AUDIT_VIEW + CLUSTER_TREE_VIEW
     *   (b) AUDIT_EXPORT + CLUSTER_TREE_EXPORT
     *
     * Mirrors the CFA-00 / CFA-02 two-capability contract.
     */
    protected function hasClusterPair(User $user, string $auditCapability, string $clusterCapability): bool
    {
        return AccessDecision::can($user, $auditCapability)
            && AccessDecision::can($user, $clusterCapability);
    }

    /**
     * The set of organization ids the cluster_auditor can read activity-log
     * rows for: actor.org + every descendant org (via the parent_id walk).
     *
     * @return list<int>
     */
    protected function clusterAuditableOrgIds(int $orgId): array
    {
        $org = Organization::query()->find($orgId);
        if (! $org instanceof Organization) {
            return [$orgId];
        }

        return array_values(array_unique(array_merge([$orgId], $org->descendantIds())));
    }
}
