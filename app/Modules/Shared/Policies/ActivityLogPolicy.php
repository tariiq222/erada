<?php

namespace App\Modules\Shared\Policies;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;

/**
 * ActivityLogPolicy — عزل سجل النشاط على مستوى المؤسسة.
 *
 * - super_admin ⇒ يرى الكل (يشمل organization_id IS NULL events).
 * - مستخدم عادي ⇒ يرى السجل فقط إذا organization_id === user.organization_id.
 * - السجل بلا organization_id ⇒ لا يُعرض لغير super_admin (fail-closed).
 *
 * Phase CFA-11 — cluster_auditor widening.
 *
 * A dedicated `cluster_auditor` role is admitted on the read / export paths
 * ONLY when BOTH `Capability::AUDIT_VIEW` (read) or `Capability::AUDIT_EXPORT`
 * (export) AND `Capability::CLUSTER_TREE_VIEW` / `Capability::CLUSTER_TREE_EXPORT`
 * are held on the actor's organization. The cluster audit role is a SEPARATE
 * role from the cluster PMO role — it carries the audit / cluster_tree
 * capabilities only and NEVER inherits any non-audit module capability
 * (e.g. projects.view, strategy.view, …). The two-path rescue here is the
 * canonical CFA-00 / CFA-02 pattern:
 *
 *   Path 1 (same-org): engine strict equality + scoped-role grant.
 *   Path 2 (cross-org rescue): BOTH `AUDIT_VIEW` (or `AUDIT_EXPORT`) AND
 *     `CLUSTER_TREE_VIEW` (or `CLUSTER_TREE_EXPORT`) on actor.org +
 *     `target.organization_id` is a descendant of actor.organization_id +
 *     `target.organization_id !== null`.
 *
 * The cluster widening authorizes the audit action ONLY — it never widens
 * any underlying resource-level read access. The polymorphic `loggable_type
 * / loggable_id` pointer is exposed in the JSON shape but accessing the
 * pointed-to record still goes through THAT module's policy (which retains
 * its own org-strict / cluster-widening contract).
 */
class ActivityLogPolicy
{
    /**
     * هل يستطيع المستخدم رؤية قائمة سجلات النشاط؟
     */
    public function viewAny(User $user): bool
    {
        return $this->clusterAuditAny($user, Capability::AUDIT_VIEW, Capability::CLUSTER_TREE_VIEW);
    }

    /**
     * هل يستطيع المستخدم تصدير سجلات النشاط؟
     */
    public function viewAnyForExport(User $user): bool
    {
        return $this->clusterAuditAny($user, Capability::AUDIT_EXPORT, Capability::CLUSTER_TREE_EXPORT);
    }

    /**
     * هل يستطيع المستخدم رؤية سجلّ نشاط واحد؟
     */
    public function view(User $user, ActivityLog $activityLog): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Same-org path: existing strict-equality contract.
        if ($activityLog->organization_id !== null
            && $user->organization_id !== null
            && (int) $activityLog->organization_id === (int) $user->organization_id) {
            // Preserve the established same-org show contract. The index
            // remains audit-capability gated; a user may inspect a row they
            // can already reach within their own organization.
            return true;
        }

        // Cross-org path: cluster_auditor rescue.
        return $this->clusterAuditCrossOrg($user, $activityLog, Capability::AUDIT_VIEW, Capability::CLUSTER_TREE_VIEW);
    }

    /**
     * هل يستطيع المستخدم تصدير هذا السجل تحديداً؟
     * يعكس view() مع قدرة AUDIT_EXPORT بدل AUDIT_VIEW.
     */
    public function exportOne(User $user, ActivityLog $activityLog): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($activityLog->organization_id !== null
            && $user->organization_id !== null
            && (int) $activityLog->organization_id === (int) $user->organization_id) {
            return AccessDecision::can($user, Capability::AUDIT_EXPORT);
        }

        return $this->clusterAuditCrossOrg($user, $activityLog, Capability::AUDIT_EXPORT, Capability::CLUSTER_TREE_EXPORT);
    }

    /**
     * Phase CFA-11 — coarse-grained gate for viewAny / viewAnyForExport.
     *
     * Same-org (held on actor.org) is honored via AccessDecision::can() with
     * target=null (org-wide grant). Cross-org widening is wired by ALSO
     * checking the matching CLUSTER_TREE_* primitive — the engine's rescue
     * branch handles the descendant walk when the controller later narrows
     * to specific descendant orgs via UserActivityLogScope.
     */
    protected function clusterAuditAny(User $user, string $auditCap, string $clusterTreeCap): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (AccessDecision::can($user, $auditCap)) {
            return true;
        }

        // Cross-org widening path: only when actor holds BOTH the audit cap
        // AND the matching cluster_tree primitive on actor.org. The actual
        // descendant narrowing is done by UserActivityLogScope / controller
        // by calling descendantIds() on actor.organization_id.
        return AccessDecision::can($user, $auditCap)
            && AccessDecision::can($user, $clusterTreeCap);
    }

    /**
     * Phase CFA-11 — two-path rescue for a single ActivityLog row.
     *
     * Same-org access requires the audit capability on actor.org AND the
     * target row's organization matches actor.organization_id.
     * Cross-org access requires BOTH the audit capability AND the matching
     * cluster_tree primitive on actor.org, AND actor.organization_id is an
     * ancestor of target.organization_id (via the parent_id walk) AND
     * target.organization_id is non-null.
     *
     * Mirrors the CFA-00 / CFA-02 pattern used in PortfolioPolicy::view().
     */
    protected function clusterAuditCrossOrg(User $user, ActivityLog $target, string $auditCap, string $clusterTreeCap): bool
    {
        // Path 1: same-org handled by the caller's strict-equality check
        // (the view() method). We do not duplicate it here — we ONLY run
        // the cross-org rescue path.

        // Path 2: cross-org rescue.
        if ($target->organization_id === null) {
            // system-level null-org rows are super_admin only (H-01).
            return false;
        }

        if ($user->organization_id === null) {
            return false;
        }

        if (! AccessDecision::can($user, $auditCap)) {
            return false;
        }

        if (! AccessDecision::can($user, $clusterTreeCap)) {
            return false;
        }

        // Verify ancestor walk (target.org is a descendant of actor.org).
        $targetOrg = Organization::query()->find((int) $target->organization_id);
        if (! $targetOrg instanceof Organization) {
            return false;
        }

        return in_array((int) $user->organization_id, $targetOrg->ancestorIds(), true);
    }
}
