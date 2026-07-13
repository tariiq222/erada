<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SUPPORTED = [
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
        if (! Schema::hasTable('authorization_role_assignments')) {
            throw new RuntimeException('Canonical authorization assignments table is missing.');
        }

        $unsupported = DB::table('authorization_role_assignments')
            ->whereNotIn('scope_type', self::SUPPORTED)
            ->count();

        if ($unsupported !== 0) {
            throw new RuntimeException("Cannot restrict authorization scopes: {$unsupported} unsupported assignment(s) require remediation.");
        }

        $values = implode(',', array_map(
            static fn (string $scope): string => DB::getPdo()->quote($scope),
            self::SUPPORTED,
        ));

        DB::statement('ALTER TABLE authorization_role_assignments DROP CONSTRAINT IF EXISTS authorization_role_assignments_scope_type_check');
        DB::statement("ALTER TABLE authorization_role_assignments ADD CONSTRAINT authorization_role_assignments_scope_type_check CHECK (scope_type IN ({$values}))");
    }

    public function down(): void
    {
        // Forward-only boundary: removed scope kinds have no runtime resolver.
    }
};
