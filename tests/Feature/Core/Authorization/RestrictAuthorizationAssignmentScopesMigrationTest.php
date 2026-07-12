<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class RestrictAuthorizationAssignmentScopesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_07_12_000012_restrict_authorization_assignment_scopes.php';

    public function test_supported_scope_constraint_rejects_removed_scope_kinds(): void
    {
        $migration = $this->migration();
        $migration->up();

        $this->expectException(QueryException::class);
        $this->insertAssignment('cluster');
    }

    public function test_migration_fails_before_constraint_change_when_unsupported_rows_exist(): void
    {
        $this->dropScopeConstraint();
        $this->insertAssignment('team');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('require remediation');
        $this->migration()->up();
    }

    private function insertAssignment(string $scope): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $role = AuthorizationRole::query()->create([
            'name' => 'scope_test_'.bin2hex(random_bytes(4)),
            'label' => 'Scope test',
            'scope_type' => 'organization',
            'is_admin_role' => false,
            'is_system' => false,
            'is_active' => true,
        ]);

        DB::table('authorization_role_assignments')->insert([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => $scope,
            'scope_id' => $organization->id,
            'organization_id' => $organization->id,
            'inherit_to_children' => false,
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function dropScopeConstraint(): void
    {
        DB::statement('ALTER TABLE authorization_role_assignments DROP CONSTRAINT IF EXISTS authorization_role_assignments_scope_type_check');
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }
}
