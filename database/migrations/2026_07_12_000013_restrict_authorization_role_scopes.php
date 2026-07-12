<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Declared role scope_type values accepted by StoreRoleRequest /
     * UpdateRoleRequest. This MUST mirror AssignmentScope::TYPES exactly:
     * AssignmentScope::isCompatibleWithRoleScope() requires the assignment's
     * scope_type to match the role's declared scope_type, so a role scoped at
     * `own` (for example) is a valid canonical path tested by
     * CanonicalRoleAssignmentEndpointTest::test_guard_denial_rolls_back_every_assignment_in_the_request
     * and other assignment-level tests. Restricting the role table to a subset
     * would silently break those paths at the DB layer.
     *
     * @var list<string>
     */
    private const ROLE_DEFINITION_SCOPES = [
        'all',
        'organization',
        'department',
        'own',
        'project',
        'program',
        'portfolio',
        'kpi',
        'meeting',
        'survey',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('authorization_roles')) {
            throw new RuntimeException('Canonical authorization roles table is missing.');
        }

        $unsupported = DB::table('authorization_roles')
            ->whereNotIn('scope_type', self::ROLE_DEFINITION_SCOPES)
            ->count();

        if ($unsupported !== 0) {
            throw new RuntimeException("Cannot restrict authorization role scopes: {$unsupported} role row(s) carry an unsupported scope_type and must be remediated first.");
        }

        $values = implode(',', array_map(
            static fn (string $scope): string => DB::getPdo()->quote($scope),
            self::ROLE_DEFINITION_SCOPES,
        ));

        DB::statement('ALTER TABLE authorization_roles DROP CONSTRAINT IF EXISTS authorization_roles_scope_type_check');
        DB::statement("ALTER TABLE authorization_roles ADD CONSTRAINT authorization_roles_scope_type_check CHECK (scope_type IN ({$values}))");
    }

    public function down(): void
    {
        // Forward-only boundary: removed scope kinds have no runtime resolver.
    }
};
