<?php

namespace Tests\Feature\Core\Authorization;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CanonicalAssignmentAuditMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_audit_history_lives_only_in_the_canonical_store(): void
    {
        self::assertTrue(Schema::hasTable('authorization_assignment_audits'));
        self::assertFalse(Schema::hasTable('permission_audits'));

        DB::table('authorization_assignment_audits')->insert([
            'event' => 'canonical_assignment_assigned',
            'scope_type' => 'all',
            'role' => 'super_admin',
            'new_value' => json_encode(['source' => 'migration']),
            'reason' => 'historical audit receipt',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('authorization_assignment_audits', [
            'event' => 'canonical_assignment_assigned',
            'role' => 'super_admin',
            'reason' => 'historical audit receipt',
        ]);
    }

    public function test_rename_preserves_historical_receipts_and_primary_keys_in_both_directions(): void
    {
        $id = DB::table('authorization_assignment_audits')->insertGetId([
            'event' => 'legacy_backfill_receipt',
            'role' => 'project_manager',
            'reason' => 'preserve me',
            'created_at' => now(),
        ]);

        $migration = $this->migration();
        $migration->down();

        self::assertTrue(Schema::hasTable('permission_audits'));
        $this->assertDatabaseHas('permission_audits', ['id' => $id, 'reason' => 'preserve me']);

        $migration->up();

        self::assertFalse(Schema::hasTable('permission_audits'));
        $this->assertDatabaseHas('authorization_assignment_audits', ['id' => $id, 'reason' => 'preserve me']);
    }

    private function migration(): Migration
    {
        return require base_path('database/migrations/2026_07_12_000010_rename_permission_audits_to_authorization_assignment_audits.php');
    }
}
