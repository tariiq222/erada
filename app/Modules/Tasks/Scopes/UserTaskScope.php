<?php

namespace App\Modules\Tasks\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * UserTaskScope - the unified org-isolation filter for the Tasks module.
 *
 * Phase CFA-08 — Cluster Full Authority widening (read-only + PDCA status writes).
 *
 * Provides the cluster_tree floor at the LIST layer for cross-org
 * Tasks reads. The Tasks list path delegates primarily to
 * Task::scopeVisibleTo (which is cluster-aware via resolveClusterVisibleOrgIds
 * + the source-aware sourceAwareScope) — this scope class serves two roles:
 *
 *   1) clusterVisibleOrgIds() — the descendant-org list helper that the
 *      Task model and policy layers consult for two-path cluster rescue.
 *   2) applyClusterListFilter() — an alternate list filter used by tests
 *      (and any custom external list query) that wants to widen the
 *      project/department floor to actor's cluster + apply the
 *      unconditional confidential source_sensitivity filter.
 *
 * Source-aware widening for OVR / Recommendation / MeetingResolution /
 * Risk / Kpi / Milestone tasks is handled inside Task::scopeVisibleTo's
 * branch (3). OVR source IDs cannot be joined directly because incident
 * IDs are UUIDs while tasks.source_id is bigint; the copied
 * source_sensitivity stamp is the task-side confidentiality authority.
 *
 * CFA-08 widening rules (read + PDCA status writes):
 *   - super_admin: no filter.
 *   - actor without organization_id: whereRaw('false') — fail-closed.
 *   - regular actor: tasks whose project / department floor widens to
 *     clusterVisibleOrgIds().
 *   - clusterVisibleOrgIds() widens [actor.org] to [actor.org + descendants]
 *     ONLY when the actor holds BOTH Capability::TASKS_VIEW AND
 *     Capability::CLUSTER_TREE_VIEW on actor.organization_id (mirrors the
 *     CFA-04 UserProjectScope contract; uses Organization::descendantIds()
 *     with depth cap 32 + cycle-guard).
 *   - Missing either TASKS_VIEW or CLUSTER_TREE_VIEW ⇒ strict same-org.
 *
 * NON-NEGOTIABLE INVARIANTS — verified by ClusterTreeOvrSourcedTaskForbiddenTest
 * + ClusterTreeConfidentialTaskForbiddenTest + ClusterTreeTaskSensitivelyScopedTest:
 *
 *   1. source_sensitivity = 'confidential' rows are UNCONDITIONALLY excluded
 *      from any cluster-widened query. The cluster widening grants do NOT
 *      imply OVR_CONFIDENTIAL.
 *
 *   2. Personal tasks (type = personal) NEVER widen cross-org. The
 *      personal-task owner floor is owner-only (per CFA-00 owner decisions
 *      2026-07-09); cluster widening does not bypass the owner floor.
 *
 *   3. Create / update / delete (except status transitions) stay strict
 *      same-org. This scope widens read + PDCA status writes ONLY.
 */
class UserTaskScope
{
    /**
     * Build the org-id list an actor may aggregate across for the Tasks
     * read surface.
     *
     *   - Default: [actor.organization_id] only (strict same-org) when EITHER
     *     TASKS_VIEW or CLUSTER_TREE_VIEW is missing on actor.org. Preserves
     *     the pre-CFA-08 same-org behavior for users who do not hold both
     *     grants — the strict-equality gate remains in force.
     *
     *   - Widening (read-only): when the actor holds BOTH
     *     Capability::TASKS_VIEW + Capability::CLUSTER_TREE_VIEW on
     *     actor.organization_id, descendant organizations (via parent_id
     *     BFS) are added to the list.
     *
     * Returns [] for null-org actors — the caller must short-circuit.
     * super_admin is short-circuited in TaskPolicy::view / TaskPolicy::changeStatus
     * via the engine's before() hook (not here).
     *
     * @return list<int>
     */
    public function clusterVisibleOrgIds(User $actor): array
    {
        if ($actor->organization_id === null) {
            return [];
        }

        $orgId = (int) $actor->organization_id;
        $visible = [$orgId];

        $hasTasksView = AccessDecision::can($actor, Capability::TASKS_VIEW);
        $hasClusterTreeView = AccessDecision::can($actor, Capability::CLUSTER_TREE_VIEW);
        if (! $hasTasksView || ! $hasClusterTreeView) {
            return $visible;
        }

        $org = Organization::query()->find($orgId);
        if (! $org instanceof Organization) {
            return $visible;
        }

        return array_values(array_unique(array_merge($visible, $org->descendantIds())));
    }

    /**
     * Apply the cluster-widened project/department floor + the
     * UNCONDITIONAL source_sensitivity='confidential' filter to a Task
     * list query.
     *
     * NOTE: this scope is provided for tests and for callers that need an
     * EXPLICIT cluster list filter outside Task::scopeVisibleTo (which is
     * itself cluster-aware). Production list endpoints route through
     * Task::scopeVisibleTo directly — the per-source-type widening for
     * OVR / Recommendation / MeetingResolution / Risk / Kpi / Milestone
     * lives in Task::sourceAwareScope. This scope intentionally narrows to
     * project/department tasks + the unconditional source_sensitivity
     * filter; it does NOT widen the polymorphic-source branch (the
     * source-aware widening is centralized on Task::scopeVisibleTo).
     *
     * Mirrors the floor used by TaskPolicy::view for per-record checks
     * so the SQL list and the policy per-record agree. The unconditional
     * confidential filter rides on top of the cluster widening (it
     * never relaxes).
     *
     * Behavior:
     *   - super_admin: no filter.
     *   - null-org actor: whereRaw('false').
     *   - regular actor: project/department floor widens to clusterVisibleOrgIds;
     *     the unconditional source_sensitivity filter applies.
     *
     * @param  Builder<Task>  $query
     */
    public function applyClusterListFilter(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->organization_id === null) {
            return $query->whereRaw('false');
        }

        $tasksTable = $query->getModel()->getTable();
        $visibleOrgIds = $this->clusterVisibleOrgIds($actor);

        // Branch (1) — personal task floor (actor owns the row). NEVER widens.
        // Branch (2) — project/department tasks with the cluster-widened
        // organization floor. Polygamic-source (branch 3) tasks rely on
        // Task::scopeVisibleTo's source-aware path (see note above).
        $query->where(function (Builder $outer) use ($tasksTable, $visibleOrgIds, $actor) {
            $outer->where(function (Builder $personal) use ($tasksTable, $actor) {
                $personal->where("{$tasksTable}.type", 'personal')
                    ->where("{$tasksTable}.owner_id", $actor->id);
            })->orWhere(function (Builder $projectOrDept) use ($tasksTable, $visibleOrgIds) {
                $projectOrDept->where('type', '!=', 'personal')
                    ->where(function (Builder $legacy) use ($tasksTable) {
                        $legacy->whereNull("{$tasksTable}.source_type")
                            ->orWhereIn("{$tasksTable}.source_type", ['Project', 'Department']);
                    })
                    ->where(function (Builder $orgMatch) use ($visibleOrgIds) {
                        $orgMatch->whereHas('project', fn (Builder $p) => $p->whereIn('organization_id', $visibleOrgIds))
                            ->orWhereHas('department', fn (Builder $d) => $d->whereIn('organization_id', $visibleOrgIds));
                    });
            });
        });

        // UNCONDITIONAL confidential floor (CFA-08 invariant 1). The cluster
        // grants do NOT imply OVR_CONFIDENTIAL — confidential tasks never
        // surface to cluster actors regardless of scoped roles. The
        // per-row source_sensitivity stamp is the authoritative signal here.
        $this->applyUnconditionalConfidentialFloor($query, $tasksTable);

        return $query;
    }

    /**
     * Apply the UNCONDITIONAL source_sensitivity stamp filter as an
     * AND-clause on the query (so it cannot be bypassed by an OR-branch
     * in applyClusterListFilter's outer wrap):
     *
     *   source_sensitivity IS NULL OR source_sensitivity != 'confidential'
     *
     * Schema note: tasks.source_id is bigint while IncidentReport.id is
     * UUID, so direct source-row comparison is not expressible in PostgreSQL.
     * The copied source_sensitivity stamp is therefore authoritative for both
     * list filtering and the per-record SensitivelyScoped gate.
     */
    private function applyUnconditionalConfidentialFloor(Builder $query, string $tasksTable): Builder
    {
        $query->where(function (Builder $c) use ($tasksTable) {
            $c->whereNull("{$tasksTable}.source_sensitivity")
                ->orWhere("{$tasksTable}.source_sensitivity", '!=', 'confidential');
        });

        return $query;
    }
}
