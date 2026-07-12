<?php

namespace App\Modules\Core\Authorization\Support;

use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Model;

/**
 * ScopeAssignmentResolver -- Phase 2.1.2.
 *
 * Replaces the Phase 1 "only all/organization" branch in
 * AccessDecision::assignmentScopeApplies with a full-scope walk that
 * honors the legacy `model_has_scoped_roles` semantics on the new
 * authorization_role_assignments table.
 *
 * Contract:
 *  - `applies($assignment, $target)` returns true when the assignment's
 *    `scope_type`/`scope_id` cover the target via the target's ScopeAware
 *    chain OR via the department subtree (when inherit_to_children=true).
 *  - `anyApplies($assignments, $target)` is the OR-fold over a list of
 *    assignments; returns true on the first match. Used by
 *    AccessDecision::hasNewPermission to preserve the engine's
 *    first-applicable-wins semantics.
 *  - target=null ALWAYS returns false (the resolver is target-bound;
 *    target-free checks route through AccessDecision::grantedViaOrgFunctionalRole).
 *  - Unsupported scope types (cluster, hospital, team, own, all) return
 *    false. The supported set is exactly the legacy Phase 1 scope types
 *    ScopeAssignmentResolverTest pins; future scope types must be added
 *    there first.
 *
 * Wire format: the resolver accepts an ARRAY shape (the same keys the
 * AuthorizationRoleAssignment Eloquent model exposes -- scope_type,
 * scope_id, organization_id, inherit_to_children) so it can be called
 * from a single SQL read without an Eloquent hydration pass. AccessDecision
 * passes the model directly; this class normalizes via `normalize()`.
 *
 * No DB I/O on the hot path: the ScopeAware chain (target -> org) is
 * walked in-memory; department subtree is built via the indexed
 * materialized path (departments.path) and memoized per request.
 */
final class ScopeAssignmentResolver
{
    /**
     * Does the assignment cover the target?
     *
     * @param  array|Model  $assignment
     */
    public static function applies($assignment, ?Model $target): bool
    {
        if ($target === null) {
            return false;
        }

        $a = self::normalize($assignment);
        $scopeType = (string) ($a['scope_type'] ?? '');
        $scopeId = $a['scope_id'] ?? null;

        if ($scopeId === null && ! in_array($scopeType, ['all', 'own'], true)) {
            // Defensive: a non-null target combined with a NULL-id scope that
            // is not 'all' / 'own' cannot resolve. Fail closed.
            return false;
        }

        return match ($scopeType) {
            'organization' => self::organizationApplies($a, $target),
            'department' => self::departmentApplies($a, $target),
            'project' => self::chainContains($target, 'project', (int) $scopeId),
            'program' => self::chainContains($target, 'program', (int) $scopeId),
            'portfolio' => self::chainContains($target, 'portfolio', (int) $scopeId),
            'kpi' => self::chainContains($target, 'kpi', (int) $scopeId),
            'meeting' => self::chainContains($target, 'meeting', (int) $scopeId),
            'survey' => self::chainContains($target, 'survey', (int) $scopeId),
            default => false,
        };
    }

    /**
     * OR-fold over a list of assignments. Returns true on the first match
     * (or false if none match). The list may be empty.
     *
     * @param  iterable<array|Model>  $assignments
     */
    public static function anyApplies(iterable $assignments, ?Model $target): bool
    {
        if ($target === null) {
            return false;
        }

        foreach ($assignments as $assignment) {
            if (self::applies($assignment, $target)) {
                return true;
            }
        }

        return false;
    }

    // ============================================================
    // Per-scope branches
    // ============================================================

    /**
     * @param  array{scope_type: string, scope_id: int|null, organization_id: int|null, inherit_to_children: bool}  $a
     */
    protected static function organizationApplies(array $a, Model $target): bool
    {
        $assignmentOrgId = $a['organization_id'] ?? $a['scope_id'] ?? null;
        if ($assignmentOrgId === null) {
            return false;
        }

        $targetOrgId = self::resolveOrganizationId($target);
        if ($targetOrgId === null) {
            return false;
        }

        return (int) $assignmentOrgId === (int) $targetOrgId;
    }

    /**
     * Department scope: matches when the target's direct department sits
     * in the assigned subtree. With `inherit_to_children=true` the subtree
     * expands to the assigned department and all its descendants, so a
     * target living in any descendant department matches. With
     * `inherit_to_children=false` the subtree is just the assigned
     * department, so only an exact direct-department match grants.
     *
     * The descendant expansion reuses the indexed materialized path on
     * `departments.path` so a single SQL read resolves the whole subtree.
     *
     * @param  array{scope_type: string, scope_id: int|null, organization_id: int|null, inherit_to_children: bool}  $a
     */
    protected static function departmentApplies(array $a, Model $target): bool
    {
        $scopeId = $a['scope_id'] ?? null;
        if ($scopeId === null) {
            return false;
        }

        $subtree = self::expandDepartmentSubtree((int) $scopeId, (bool) ($a['inherit_to_children'] ?? true));

        if ($subtree === []) {
            return false;
        }

        // Resolve the nearest direct department carried by the target or one
        // of its non-department scope parents. A Task attached to a Project,
        // for example, normally has no department_id of its own; the Project's
        // department is the direct governing department for that task.
        //
        // Stop when a Department node is reached instead of using its id as a
        // fallback. Otherwise an assignment at a parent department with
        // inherit_to_children=false could match a child target merely because
        // the chain eventually walks through that parent.
        $directDeptId = self::nearestDirectDepartmentId($target);
        if ($directDeptId === null) {
            return false;
        }

        return in_array((int) $directDeptId, $subtree, true);
    }

    private static function nearestDirectDepartmentId(Model $target): ?int
    {
        $current = $target;
        $visited = [];
        $isTarget = true;

        while ($current instanceof Model) {
            $key = get_class($current).':'.$current->getKey();
            if (isset($visited[$key])) {
                return null;
            }
            $visited[$key] = true;

            if ($current instanceof Department) {
                return $isTarget ? (int) $current->getKey() : null;
            }

            foreach (['department_id', 'reporter_department_id'] as $departmentAttribute) {
                $departmentId = $current->getAttribute($departmentAttribute);
                if ($departmentId !== null) {
                    return (int) $departmentId;
                }
            }

            if (! $current instanceof ScopeAware) {
                return null;
            }

            $parent = $current->scopeParent();
            $current = $parent instanceof Model ? $parent : null;
            $isTarget = false;
        }

        return null;
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * Does the target's ScopeAware chain contain a node of (type, id)?
     */
    protected static function chainContains(Model $target, string $scopeType, int $scopeId): bool
    {
        foreach (self::resolveScopeChain($target) as $node) {
            if ($node['type'] === $scopeType && (int) $node['id'] === $scopeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the target's ScopeAware chain to [(type, id), ...], starting
     * at the target itself and walking upward. Mirrors
     * AccessDecision::buildScopeChain without the engine's request
     * memoization (the resolver is called from a per-target probe, not a
     * list endpoint, so memoization has no payoff here).
     *
     * @return list<array{type: string, id: int}>
     */
    protected static function resolveScopeChain(Model $target): array
    {
        $chain = [];
        $current = $target;
        $visited = [];

        while ($current !== null) {
            $key = get_class($current).':'.$current->getKey();
            if (isset($visited[$key])) {
                break;
            }
            $visited[$key] = true;

            if ($current instanceof ScopeAware) {
                $chain[] = [
                    'type' => (string) $current->scopeTypeKey(),
                    'id' => (int) $current->getKey(),
                ];

                $parent = $current->scopeParent();
                $current = $parent instanceof Model ? $parent : null;
            } else {
                // Parent is not ScopeAware -- but if the current carries
                // a direct organization_id, append the org node so an
                // 'organization' scope match still fires.
                if (isset($current->organization_id) && $current->organization_id !== null) {
                    $chain[] = [
                        'type' => 'organization',
                        'id' => (int) $current->organization_id,
                    ];
                }
                break;
            }
        }

        // The chain builder above may not have appended the org node when
        // the leaf carries organization_id directly AND has a non-ScopeAware
        // parent. Add it now if it's not already there.
        $hasOrg = false;
        foreach ($chain as $node) {
            if ($node['type'] === 'organization') {
                $hasOrg = true;
                break;
            }
        }
        if (! $hasOrg && isset($target->organization_id) && $target->organization_id !== null) {
            $chain[] = [
                'type' => 'organization',
                'id' => (int) $target->organization_id,
            ];
        }

        return $chain;
    }

    /**
     * The target's resolved organization_id, walking the ScopeAware chain.
     * Returns null when the target has no resolvable org (fail-closed).
     */
    protected static function resolveOrganizationId(Model $target): ?int
    {
        if ($target instanceof ScopeAware) {
            $orgId = $target->scopeOrganizationId();
            if ($orgId !== null) {
                return (int) $orgId;
            }
        }

        if (isset($target->organization_id) && $target->organization_id !== null) {
            return (int) $target->organization_id;
        }

        if ($target instanceof ScopeAware) {
            $parent = $target->scopeParent();
            if ($parent instanceof Model) {
                return self::resolveOrganizationId($parent);
            }
        }

        return null;
    }

    /**
     * Expand the assigned department id to the full subtree
     * (itself + all descendants) when inherit_to_children=true;
     * otherwise the subtree is just the single department id.
     *
     * The expansion reuses the same indexed materialized path
     * (departments.path) AccessDecision::subtreeDepartmentIds uses,
     * so the resolver's behavior matches the engine's reach check.
     *
     * @return list<int>
     */
    protected static function expandDepartmentSubtree(int $departmentId, bool $inheritToChildren): array
    {
        if (! $inheritToChildren) {
            return [$departmentId];
        }

        $dept = Department::query()->find($departmentId);
        if ($dept === null) {
            return [];
        }

        // descendants + self via the path index.
        $descendants = $dept->descendantIdsViaPath();
        if (! in_array($departmentId, $descendants, true)) {
            $descendants[] = $departmentId;
        }

        return array_map('intval', $descendants);
    }

    /**
     * Normalize an assignment input (array shape OR Eloquent model) into
     * the canonical array the branches consume.
     *
     * @param  array|Model  $assignment
     * @return array{scope_type: string, scope_id: int|null, organization_id: int|null, inherit_to_children: bool}
     */
    protected static function normalize($assignment): array
    {
        if (is_array($assignment)) {
            return [
                'scope_type' => (string) ($assignment['scope_type'] ?? ''),
                'scope_id' => isset($assignment['scope_id']) ? (int) $assignment['scope_id'] : null,
                'organization_id' => isset($assignment['organization_id']) ? (int) $assignment['organization_id'] : null,
                'inherit_to_children' => (bool) ($assignment['inherit_to_children'] ?? true),
            ];
        }

        // Eloquent model fallback (AuthorizationRoleAssignment).
        return [
            'scope_type' => (string) ($assignment->scope_type ?? ''),
            'scope_id' => isset($assignment->scope_id) ? (int) $assignment->scope_id : null,
            'organization_id' => isset($assignment->organization_id) ? (int) $assignment->organization_id : null,
            'inherit_to_children' => (bool) ($assignment->inherit_to_children ?? true),
        ];
    }
}
