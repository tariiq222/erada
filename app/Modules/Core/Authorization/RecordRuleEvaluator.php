<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Authorization\Models\AuthorizationRecordRule;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Task 1.1.3 — `AuthorizationRecordRuleEvaluator`.
 *
 * Compiles structured `authorization_record_rules` rows into a chain of
 * Eloquent `where` clauses applied to a query builder. Each rule row holds a
 * structured `domain_json` payload — never raw SQL — and the evaluator turns
 * each payload into an equivalent parameterized WHERE.
 *
 * Allowlisted operators: `eq`, `neq`, `in`, `not_in`, `belongs_to_dept`,
 * `owned_by`. Anything outside the allowlist, with missing required args,
 * referencing a column not present on the target table, or carrying a raw
 * SQL fragment, is treated as malformed and produces a deny-equivalent
 * predicate (`0 = 1`) so a single bad row can never widen access.
 *
 * Composition: matching enabled rules are ANDed together in priority-desc
 * order. If no rules match, the original query is returned unchanged. Per-
 * table column listings are cached for the lifetime of one instance to
 * avoid repeated `Schema::getColumnListing` calls across rules.
 *
 * ---------------------------------------------------------------------
 * Phase 7.1 — Resource coverage classification
 * ---------------------------------------------------------------------
 * Compared every row of `docs/authz/resource-authorization-graph.md`
 * (21 primary resources) against this evaluator's operator surface.
 * Classification taxonomy:
 *
 *   direct_column_supported   — at least one column on the resource
 *                                matches a foreign key for an ancestor
 *                                (organization_id, department_id, project_id,
 *                                etc.); rule operators `eq`/`neq`/`in`/
 *                                `not_in`/`owned_by` can express the filter.
 *
 *   relationship_supported    — the evaluator's `belongs_to_dept` walks the
 *                                materialized department subtree, so the
 *                                resource's *direct* department column can be
 *                                matched against any user's dept chain. No
 *                                other chain (program/portfolio/project) is
 *                                walked by this evaluator.
 *
 *   sensitivity_supported     — RESERVED. The evaluator has no operator that
 *                                reads a sensitivity/confidentiality column.
 *                                No row of the graph is classifiable here
 *                                today. Adding it would require a new
 *                                operator (e.g. `sensitivity_eq`) plus the
 *                                engine wiring that resolves the sensitivity
 *                                of the resource via the parent chain.
 *
 *   requires_policy_only      — the resource's visibility logic depends on
 *                                something this evaluator cannot express
 *                                (polymorphic parent walk, sensitivity /
 *                                confidentiality check, subject/source
 *                                polymorphism). The corresponding Policy
 *                                MUST keep the rule. Do not try to model
 *                                this with a record rule.
 *
 * Per-resource verdict (graph row -> verdict -> reason):
 *
 *   Portfolio                 direct_column_supported
 *                                organization_id direct; normal sensitivity.
 *   Program                   direct_column_supported
 *                                portfolio_id + organization_id direct;
 *                                normal sensitivity.
 *   Project                   direct_column_supported
 *                                department_id + organization_id direct;
 *                                normal sensitivity.
 *   Department                direct_column_supported
 *                                organization_id direct; normal sensitivity.
 *   Milestone                 direct_column_supported
 *                                project_id direct; normal sensitivity.
 *   Task                      relationship_supported (partial)
 *                                Project/department_id is direct; the
 *                                source_type/source_id polymorphic arm and
 *                                the personal-owner arm require policy.
 *                                Sensitivity is inherited from source, which
 *                                the evaluator cannot express.
 *   Risk                      relationship_supported (partial)
 *                                department_id direct + belongs_to_dept
 *                                subtree walk; riskable_type/riskable_id
 *                                polymorphism and risk-sensitivity inherited
 *                                check require policy.
 *   RiskAssessment            requires_policy_only
 *                                risk_id is direct, but inherits risk
 *                                sensitivity — not expressible here.
 *   RiskAction                requires_policy_only
 *                                risk_id is direct, but inherits risk
 *                                sensitivity — not expressible here.
 *   IncidentReport            requires_policy_only
 *                                `confidential_when_marked` requires the
 *                                evaluator to read a sensitivity column
 *                                (none of the operators do that today).
 *   Meeting                   requires_policy_only
 *                                subject_type/subject_id polymorphism AND
 *                                subject-sensitivity inheritance — neither
 *                                is expressible here.
 *   Decision                  requires_policy_only
 *                                inherits meeting sensitivity via meeting
 *                                subject walk.
 *   Recommendation            requires_policy_only
 *                                inherits decision sensitivity via decision
 *                                -> meeting -> subject walk.
 *   Kpi                       direct_column_supported
 *                                department_id + organization_id direct;
 *                                normal sensitivity.
 *   KpiMeasurement            requires_policy_only
 *                                source_type/source_id polymorphism AND
 *                                inherits source sensitivity.
 *   Survey                    requires_policy_only
 *                                privacy_sensitive.
 *   DataImportRequest         requires_policy_only
 *                                privacy_sensitive.
 *   Comment                   requires_policy_only
 *                                polymorphic parent (commentable_type/id) AND
 *                                inherits commentable sensitivity.
 *   Attachment                requires_policy_only
 *                                polymorphic parent (attachable_type/id) AND
 *                                inherits attachable sensitivity.
 *   Review                    direct_column_supported
 *                                portfolio_id/program_id/project_id are all
 *                                direct FK columns on the resource; normal
 *                                sensitivity.
 *   Blocker                   direct_column_supported
 *                                portfolio_id/program_id/project_id are all
 *                                direct FK columns on the resource; normal
 *                                sensitivity. Owner column reachable via
 *                                `owned_by`.
 *
 * Summary: 8 direct_column_supported, 2 relationship_supported (partial,
 * keep the non-record-rule branch in policy), 11 requires_policy_only,
 * 0 sensitivity_supported.
 *
 * Conclusion: Record rules can express coarse list-level filtering for the
 * 8 fully-supported resources and a partial department-subtree slice of
 * Task/Risk. They CANNOT replace the existing Policy for any sensitivity-
 * gated or polymorphic resource. Phase 7 does not add new operators; the
 * Policy layer remains the source of truth for the 11 policy-only rows.
 */
class RecordRuleEvaluator
{
    /**
     * Operators the evaluator recognizes. Any other operator falls through
     * to the deny-equivalent fallback.
     */
    private const ALLOWED_OPERATORS = [
        'eq', 'neq', 'in', 'not_in', 'belongs_to_dept', 'owned_by',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private array $columnListingCache = [];

    /**
     * Append WHERE clauses for every matching enabled rule to `$query` and
     * return the builder. Rules are ANDed together in priority-desc order.
     * If no rule matches, the original query is returned unchanged.
     */
    public function compileWheres(
        string $resource,
        ?string $action,
        User $user,
        Builder $query,
    ): Builder {
        $rules = $this->loadRules($resource, $action, $user);

        if ($rules->isEmpty()) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        foreach ($rules as $rule) {
            $compiled = $this->compileRule($rule, $table, $user);

            if ($compiled === null) {
                // Malformed/unknown rule -> deny-equivalent impossible predicate.
                $query->whereRaw('0 = 1');

                continue;
            }

            [$column, $callback] = $compiled;

            $query->where(function (Builder $inner) use ($column, $callback) {
                $callback($inner, $column);
            });
        }

        return $query;
    }

    /**
     * Return the IDs of enabled rules that match the (resource, action) pair
     * for the user, ordered by `priority DESC`. Used by the engine for dry-run
     * and audit paths.
     *
     * @return array<int, int>
     */
    public function applies(User $user, string $resource, ?string $action): array
    {
        return $this->loadRules($resource, $action, $user)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Resolve enabled rules for (resource, action) that match the user's
     * role assignments OR the user himself OR the global wildcard.
     *
     * @return Collection<int, AuthorizationRecordRule>
     */
    private function loadRules(string $resource, ?string $action, User $user): Collection
    {
        $roleIds = AuthorizationRoleAssignment::query()
            ->where('user_id', $user->id)
            ->pluck('authorization_role_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $query = AuthorizationRecordRule::query()
            ->enabled()
            ->forResource($resource)
            ->forAction($action)
            ->where(function (Builder $q) use ($user, $roleIds) {
                $q->where(function (Builder $inner) {
                    $inner->whereNull('authorization_role_id')
                        ->whereNull('user_id');
                });

                if ($roleIds !== []) {
                    $q->orWhereIn('authorization_role_id', $roleIds)
                        ->whereNull('user_id');
                }

                $q->orWhere('user_id', $user->id);
            })
            ->orderByDesc('priority')
            ->orderBy('id');

        return $query->get();
    }

    /**
     * Compile a single rule. Returns `null` for malformed / unknown /
     * unsafe rules so the caller can substitute a deny-equivalent predicate.
     *
     * @return array{0: string, 1: \Closure(Builder, string): void}|null
     */
    private function compileRule(AuthorizationRecordRule $rule, string $table, User $user): ?array
    {
        $domain = $rule->domain_json;

        if (! is_array($domain)) {
            return null;
        }

        $operator = $domain['operator'] ?? null;
        $column = $domain['column'] ?? null;

        if (! is_string($operator) || ! is_string($column)) {
            return null;
        }

        if (! in_array($operator, self::ALLOWED_OPERATORS, true)) {
            return null;
        }

        if (! $this->isColumnAllowed($column, $table)) {
            return null;
        }

        $safeColumn = $this->safeColumn($column, $table);

        return match ($operator) {
            'eq' => $this->compileEq($safeColumn, $domain),
            'neq' => $this->compileNeq($safeColumn, $domain),
            'in' => $this->compileIn($safeColumn, $domain),
            'not_in' => $this->compileNotIn($safeColumn, $domain),
            'belongs_to_dept' => $this->compileBelongsToDept($safeColumn, $domain, $user),
            'owned_by' => $this->compileOwnedBy($safeColumn, $user),
            default => null,
        };
    }

    /**
     * Validate the column string: a plain column name or a `table.column`
     * where the table matches the model table. Anything else (semicolons,
     * spaces, parentheses, other prefixes) is rejected as a deny-equivalent.
     */
    private function isColumnAllowed(string $column, string $table): bool
    {
        if ($column === '' || preg_match('/[\s;`"\'()=<>!*+\-\/\\\\]/', $column) === 1) {
            return false;
        }

        if (str_contains($column, '.')) {
            [$prefix, $name] = explode('.', $column, 2);
            if ($prefix !== $table) {
                return false;
            }

            return $this->columnExists($table, $name);
        }

        return $this->columnExists($table, $column);
    }

    /**
     * Return the column name suitable for Eloquent: a bare column when only a
     * name was supplied, or the table-qualified form when the caller wrote
     * `table.column`.
     */
    private function safeColumn(string $column, string $table): string
    {
        if (str_contains($column, '.')) {
            return $column;
        }

        return $table.'.'.$column;
    }

    /**
     * @return array{0: string, 1: \Closure(Builder, string): void}|null
     */
    private function compileEq(string $column, array $domain): ?array
    {
        if (! array_key_exists('value', $domain)) {
            return null;
        }

        $value = $domain['value'];

        return [
            $column,
            function (Builder $q, string $col) use ($value): void {
                $q->where($col, '=', $value);
            },
        ];
    }

    /**
     * @return array{0: string, 1: \Closure(Builder, string): void}|null
     */
    private function compileNeq(string $column, array $domain): ?array
    {
        if (! array_key_exists('value', $domain)) {
            return null;
        }

        $value = $domain['value'];

        return [
            $column,
            function (Builder $q, string $col) use ($value): void {
                $q->where($col, '<>', $value);
            },
        ];
    }

    /**
     * @return array{0: string, 1: \Closure(Builder, string): void}|null
     */
    private function compileIn(string $column, array $domain): ?array
    {
        $values = $domain['values'] ?? null;
        if (! is_array($values) || $values === []) {
            return null;
        }

        return [
            $column,
            function (Builder $q, string $col) use ($values): void {
                $q->whereIn($col, $values);
            },
        ];
    }

    /**
     * @return array{0: string, 1: \Closure(Builder, string): void}|null
     */
    private function compileNotIn(string $column, array $domain): ?array
    {
        $values = $domain['values'] ?? null;
        if (! is_array($values) || $values === []) {
            return null;
        }

        return [
            $column,
            function (Builder $q, string $col) use ($values): void {
                $q->whereNotIn($col, $values);
            },
        ];
    }

    /**
     * @return array{0: string, 1: \Closure(Builder, string): void}|null
     */
    private function compileBelongsToDept(string $column, array $domain, User $user): ?array
    {
        $chain = $domain['chain'] ?? ['self'];
        if (! is_array($chain)) {
            return null;
        }

        $departmentIds = $this->resolveDepartmentIds($user, $chain);

        if ($departmentIds === []) {
            // No matching departments -> deny-equivalent predicate.
            return null;
        }

        return [
            $column,
            function (Builder $q, string $col) use ($departmentIds): void {
                $q->whereIn($col, $departmentIds);
            },
        ];
    }

    /**
     * @return array{0: string, 1: \Closure(Builder, string): void}
     */
    private function compileOwnedBy(string $column, User $user): array
    {
        return [
            $column,
            function (Builder $q, string $col) use ($user): void {
                $q->where($col, '=', $user->id);
            },
        ];
    }

    /**
     * Resolve the set of department IDs the user belongs to, expanded by
     * the requested chain (`self`, `descendants`, `ancestors`). Default
     * chain is `['self']`. Returns `[]` when the user has no department.
     *
     * @param  array<int, string>  $chain
     * @return array<int, int>
     */
    private function resolveDepartmentIds(User $user, array $chain): array
    {
        if (! $user->department_id) {
            return [];
        }

        $selfIds = [$user->department_id];
        $ids = [];

        foreach ($chain as $segment) {
            if (! is_string($segment)) {
                return [];
            }

            $segment = strtolower(trim($segment));

            if ($segment === 'self') {
                $ids = array_merge($ids, $selfIds);

                continue;
            }

            if ($segment === 'descendants') {
                $ids = array_merge($ids, $this->descendantDepartmentIds($user->department_id));

                continue;
            }

            if ($segment === 'ancestors') {
                $ids = array_merge($ids, $this->ancestorDepartmentIds($user->department_id));

                continue;
            }

            // Unknown chain segment -> deny-equivalent.
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @return array<int, int>
     */
    private function descendantDepartmentIds(int $departmentId): array
    {
        $department = Department::find($departmentId);
        if (! $department) {
            return [];
        }

        if (! $department->path) {
            return [$department->id];
        }

        $pathPrefix = $department->path;

        return Department::query()
            ->where(function ($q) use ($pathPrefix, $departmentId) {
                $q->where('path', 'like', $pathPrefix.'%')
                    ->orWhere('id', $departmentId);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Walk the materialized `departments.path` segments to collect every
     * ancestor department id. Self is excluded by stripping the trailing
     * segment from the path before splitting.
     *
     * @return array<int, int>
     */
    private function ancestorDepartmentIds(int $departmentId): array
    {
        $department = Department::find($departmentId);
        if (! $department || ! $department->path) {
            return [];
        }

        $path = trim($department->path, '/');
        if ($path === '') {
            return [];
        }

        $segments = explode('/', $path);
        // Last segment is self -> drop it.
        array_pop($segments);

        $ids = [];
        foreach ($segments as $segment) {
            if ($segment === '' || ! ctype_digit((string) $segment)) {
                continue;
            }
            $ids[] = (int) $segment;
        }

        return $ids;
    }

    /**
     * Schema::hasColumn cache to keep the per-rule hot path off repeated
     * metadata lookups for the same table.
     */
    private function columnExists(string $table, string $column): bool
    {
        if (! isset($this->columnListingCache[$table])) {
            $this->columnListingCache[$table] = Schema::getColumnListing($table);
        }

        return in_array($column, $this->columnListingCache[$table], true);
    }
}
