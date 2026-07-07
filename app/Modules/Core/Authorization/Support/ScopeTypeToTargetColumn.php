<?php

namespace App\Modules\Core\Authorization\Support;

/**
 * ScopeTypeToTargetColumn -- Phase 2.1.2.
 *
 * Pure value object: maps a Phase 1 / 2 assignment `scope_type` string to
 * the column on a target model that carries the same id the assignment
 * holds.
 *
 * CURRENT STATUS (Phase 2.1.2 hardening):
 *   This class is currently UNUSED by ScopeAssignmentResolver at runtime.
 *   The resolver's chain / org-walk branches hardcode the supported scope
 *   set + the column projection (`id`) inline. The class is preserved
 *   here because:
 *     - the supported scope set is the canonical reference for the slice
 *       (the test file tests/Unit/Authorization/ScopeAssignmentResolverTest
 *       pins it here, NOT in the resolver itself, so a future scope type
 *       can be added in one place);
 *     - the column projection is the planned indirection point: a future
 *       scope type that DOES use a different column (e.g. a polymorphic
 *       project_id on a child model) can extend the SUPPORTED table
 *       with a (scope_type => column) entry and the resolver can then
 *       start routing through `columnFor()` without rewriting its match
 *       arms.
 *   The docstring previously claimed the resolver "uses this" -- that was
 *   misleading: as of Phase 2.1.2 the resolver does not. This file
 *   documents the current truth.
 *
 * Unsupported scope types (cluster, hospital, team, own, all) return
 * null. The resolver treats the unsupported set as fail-closed: an
 * assignment carrying one of those scope_types never grants via the
 * new path.
 *
 * This class performs no DB I/O and contains no side-effects.
 */
final class ScopeTypeToTargetColumn
{
    /**
     * The supported scope types. The order is the documented decision
     * order from the plan; consumers should not depend on it.
     *
     * @var list<string>
     */
    private const SUPPORTED = [
        'organization',
        'department',
        'project',
        'program',
        'portfolio',
        'kpi',
        'meeting',
        'survey',
    ];

    /**
     * Return the column a probe target of `scope_type` should be matched
     * against. Returns null for any unsupported / unknown scope type.
     */
    public static function columnFor(string $scopeType): ?string
    {
        if (! in_array($scopeType, self::SUPPORTED, true)) {
            return null;
        }

        // Every supported scope type identifies its target by the row's
        // primary key. We keep the indirection through this method so a
        // future scope type that DOES use a different column (e.g. a
        // polymorphic project_id on a child model) can be added without
        // touching the resolver's call site.
        return 'id';
    }

    /**
     * The supported scope types. Read-only; consumers must not mutate the
     * returned array.
     *
     * @return list<string>
     */
    public static function supportedScopeTypes(): array
    {
        return self::SUPPORTED;
    }
}
