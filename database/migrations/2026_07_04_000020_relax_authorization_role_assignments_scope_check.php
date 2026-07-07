<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.1.2 -- relax `authorization_role_assignments.scope_type` CHECK
 * to include the legacy scoped-role scope types that the new path
 * (authorization_role_assignments + authorization_role_permissions) is
 * now wired to support: project, program, portfolio, kpi, meeting, survey.
 *
 * The Phase 1 schema (2026_07_03_000003_create_authorization_role_assignments)
 * allowed: all, organization, cluster, hospital, department, team, own.
 * The Phase 2.1.2 full-semantics backfill (2026_07_04_000021) materializes
 * legacy `model_has_scoped_roles` rows onto the new path; those rows carry
 * scope_type in {project, program, portfolio, kpi, meeting, survey} -- the
 * ones the legacy scoped-role system used. The CHECK must be widened so
 * the backfill's INSERTs do not violate the constraint.
 *
 * The companion NOT-NULL CHECK on (scope_type, scope_id) is LEFT INTACT
 * ('all' and 'own' are still the only scope_types that allow scope_id IS
 * NULL). Every newly added scope_type requires scope_id IS NOT NULL, which
 * the existing companion CHECK already enforces. No data migration needed.
 *
 * The CHECK is a PostgreSQL named constraint; we drop + recreate it. The
 * other constraints on the table (partial unique indexes on scope_id,
 * FK to authorization_roles, FK to users) are untouched. Safe to run
 * twice: the second up() drops the relaxed constraint and recreates it
 * (idempotent).
 *
 * down() restores the original Phase 1 set. AFTER down() the new
 * scope_types are rejected again, so a follow-up Phase 2.1.2 backfill
 * (000021) would fail. Callers must roll them back in order
 * (000021 down first, then 000020 down) to leave the schema in a
 * runnable state.
 *
 * PostgreSQL only: the constraint is named in a DB::statement() raw
 * call; the test (BackfillScopedRolesFullSemanticsTest) gates on
 * the pgsql driver.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_04_000020_relax_authorization_role_assignments_scope_check';

    /**
     * The relaxed scope_type set: Phase 1 set + the six legacy scoped
     * types the new path now supports.
     */
    private const RELAXED_SCOPE_TYPES = [
        'all',
        'organization',
        'cluster',
        'hospital',
        'department',
        'team',
        'own',
        // Legacy scoped-role types (model_has_scoped_roles.scope_type values)
        'project',
        'program',
        'portfolio',
        'kpi',
        'meeting',
        'survey',
    ];

    /**
     * The original Phase 1 set; restored by down().
     */
    private const PHASE1_SCOPE_TYPES = [
        'all',
        'organization',
        'cluster',
        'hospital',
        'department',
        'team',
        'own',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                self::MIGRATION_NAME.' is PostgreSQL-only. Detected driver: ['.DB::getDriverName().'].'
            );
        }

        self::recreateScopeTypeCheck(self::RELAXED_SCOPE_TYPES);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // The same driver gate as up(); down() on a non-pgsql driver
            // is a no-op rather than a hard error so a cross-driver
            // migration rollback (rare) does not blow up.
            return;
        }

        self::recreateScopeTypeCheck(self::PHASE1_SCOPE_TYPES);
    }

    /**
     * Drop + recreate the scope_type CHECK with the given allowed values.
     * The NOT-NULL companion CHECK on (scope_type, scope_id) is NOT
     * touched: 'all' and 'own' still allow NULL scope_id, and every
     * other scope_type (now including the six new legacy types) still
     * requires scope_id IS NOT NULL.
     *
     * @param  list<string>  $allowed
     */
    private static function recreateScopeTypeCheck(array $allowed): void
    {
        $values = implode(',', array_map(
            fn (string $v) => "'".str_replace("'", "''", $v)."'",
            $allowed
        ));

        // Drop the named Phase 1 constraint if it exists. IF EXISTS
        // keeps the operation idempotent across re-runs.
        DB::statement(
            'ALTER TABLE authorization_role_assignments '
            .'DROP CONSTRAINT IF EXISTS authorization_role_assignments_scope_type_check'
        );

        // Re-create with the new value list.
        DB::statement(
            'ALTER TABLE authorization_role_assignments '
            .'ADD CONSTRAINT authorization_role_assignments_scope_type_check '
            ."CHECK (scope_type IN ({$values}))"
        );
    }
};
