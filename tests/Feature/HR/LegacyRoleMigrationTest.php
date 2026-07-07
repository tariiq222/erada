<?php

namespace Tests\Feature\HR;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Support\LegacyRoleMap;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4 — legacy department-role migration.
 *
 * Covers the legacy->scoped role mapping, the additive backfill migration,
 * the retirement of the legacy classes, and the dropping of the legacy tables.
 */
class LegacyRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const BACKFILL_MIGRATION = 'database/migrations/2026_06_30_000001_backfill_legacy_department_roles_to_scoped.php';

    private const DROP_MIGRATION = 'database/migrations/2026_06_30_000002_drop_legacy_department_role_tables.php';

    /**
     * Load the (anonymous) migration instance from its file.
     *
     * NOTE on the migrate-already-ran trap: under RefreshDatabase, migrate:fresh
     * has already executed this migration against an EMPTY database and recorded
     * it in the `migrations` table. Re-running `php artisan migrate --path=...`
     * would report "Nothing to migrate" and skip the backfill of the legacy rows
     * we insert in the test. To genuinely exercise the backfill against those
     * rows we require the file (it returns the anonymous Migration instance) and
     * invoke up()/down() directly, bypassing the migrator's already-ran ledger.
     */
    private function backfillMigration(): Migration
    {
        return require base_path(self::BACKFILL_MIGRATION);
    }

    private function dropMigration(): Migration
    {
        return require base_path(self::DROP_MIGRATION);
    }

    /**
     * Recreate the legacy tables for tests that exercise the backfill.
     *
     * The drop migration (2026_06_30_000002) is the last migration, so after the
     * RefreshDatabase migrate:fresh the legacy tables no longer exist. The drop
     * migration's down() recreates their schema, which is exactly the
     * pre-migration state the backfill operates on.
     */
    private function recreateLegacyTables(): void
    {
        if (! Schema::hasTable('department_default_roles')) {
            $this->dropMigration()->down();
        }
    }

    public function test_legacy_role_map_translates_and_defaults_safely(): void
    {
        $this->assertSame('dept_member', LegacyRoleMap::toScopedKey('some_custom_member_role'));
        $this->assertNull(LegacyRoleMap::toScopedKey('admin'));        // protected, never migrated
        $this->assertNull(LegacyRoleMap::toScopedKey('super_admin'));
    }

    public function test_backfill_moves_legacy_rows_to_scoped_model(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $this->recreateLegacyTables();

        $dept = Department::factory()->create();
        $role = Role::create(['name' => 'legacy_member', 'guard_name' => 'web']);

        // Create the user BEFORE inserting the legacy policy/grant rows. This
        // mirrors a real pre-migration database (the rows already exist) and
        // avoids the legacy UserObserver writing a competing
        // department_role_grants row at creation time. The user is also created
        // before any capacity policy exists, so the scoped UserObserver finds no
        // member capacity role and grants nothing — the backfill is the only
        // thing that can produce the scoped rows asserted below.
        $user = User::factory()->create(['department_id' => $dept->id]);

        DB::table('department_default_roles')->insert([
            'department_id' => $dept->id, 'role_id' => $role->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('department_role_grants')->insert([
            'user_id' => $user->id, 'role_id' => $role->id, 'department_id' => $dept->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->backfillMigration()->up();

        $this->assertDatabaseHas('department_capacity_roles', [
            'department_id' => $dept->id, 'capacity' => 'member', 'role_key' => 'dept_member',
        ]);
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $user->id, 'role' => 'dept_member',
            'scope_type' => 'department', 'scope_id' => $dept->id, 'source' => 'auto',
        ]);

        // An audit row documents the backfill.
        $this->assertDatabaseHas('permission_audits', [
            'event' => 'migration',
            'reason' => 'Phase 4: backfill legacy department roles to scoped model',
        ]);
    }

    public function test_backfill_is_idempotent(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $this->recreateLegacyTables();

        $dept = Department::factory()->create();
        $role = Role::create(['name' => 'legacy_member', 'guard_name' => 'web']);

        $user = User::factory()->create(['department_id' => $dept->id]);
        DB::table('department_default_roles')->insert([
            'department_id' => $dept->id, 'role_id' => $role->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('department_role_grants')->insert([
            'user_id' => $user->id, 'role_id' => $role->id, 'department_id' => $dept->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Running the backfill twice must not create duplicate rows.
        $this->backfillMigration()->up();
        $this->backfillMigration()->up();

        $this->assertSame(1, DB::table('department_capacity_roles')->where([
            'department_id' => $dept->id, 'capacity' => 'member', 'role_key' => 'dept_member',
        ])->count());

        $this->assertSame(1, DB::table('model_has_scoped_roles')->where([
            'user_id' => $user->id, 'role' => 'dept_member',
            'scope_type' => 'department', 'scope_id' => $dept->id, 'source' => 'auto',
        ])->count());
    }

    public function test_backfill_down_removes_only_backfilled_grants(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $this->recreateLegacyTables();

        $dept = Department::factory()->create();
        $role = Role::create(['name' => 'legacy_member', 'guard_name' => 'web']);

        $user = User::factory()->create(['department_id' => $dept->id]);
        DB::table('department_default_roles')->insert([
            'department_id' => $dept->id, 'role_id' => $role->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('department_role_grants')->insert([
            'user_id' => $user->id, 'role_id' => $role->id, 'department_id' => $dept->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A legitimate manual grant for the SAME role+scope must survive down().
        $manualUser = User::factory()->create();
        $manualUser->scopedRoles()->create([
            'role' => 'dept_member', 'scope_type' => 'department', 'scope_id' => $dept->id,
            'inherit_to_children' => true, 'source' => 'manual',
        ]);

        $migration = $this->backfillMigration();
        $migration->up();
        $migration->down();

        // The backfilled auto grant is gone.
        $this->assertDatabaseMissing('model_has_scoped_roles', [
            'user_id' => $user->id, 'role' => 'dept_member',
            'scope_type' => 'department', 'scope_id' => $dept->id, 'source' => 'auto',
        ]);

        // The unrelated manual grant is untouched.
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $manualUser->id, 'role' => 'dept_member',
            'scope_type' => 'department', 'scope_id' => $dept->id, 'source' => 'manual',
        ]);
    }

    public function test_legacy_role_classes_are_removed(): void
    {
        // String literals (not ::class) because these classes no longer exist
        // and must not be imported — this is the deletion guard for Phase 4.
        $this->assertFalse(class_exists('App\Modules\HR\Services\DepartmentRoleSyncService'));
        $this->assertFalse(class_exists('App\Modules\HR\Models\DepartmentDefaultRole'));
        $this->assertFalse(class_exists('App\Modules\HR\Models\DepartmentRoleGrant'));
        $this->assertFalse(class_exists('App\Modules\HR\Http\Controllers\DepartmentDefaultRoleController'));
        $this->assertFalse(class_exists('App\Console\Commands\SyncDepartmentDefaultRoles'));
    }

    public function test_drop_migration_removes_legacy_tables_and_is_reversible(): void
    {
        $drop = $this->dropMigration();

        // The drop migration is the last migration, so after migrate:fresh the
        // legacy tables are already gone.
        $this->assertFalse(Schema::hasTable('department_default_roles'));
        $this->assertFalse(Schema::hasTable('department_role_grants'));

        // down() recreates the legacy schema for rollback parity.
        $drop->down();

        $this->assertTrue(Schema::hasTable('department_default_roles'));
        $this->assertTrue(Schema::hasTable('department_role_grants'));

        // up() drops them again (idempotent via dropIfExists).
        $drop->up();

        $this->assertFalse(Schema::hasTable('department_default_roles'));
        $this->assertFalse(Schema::hasTable('department_role_grants'));
    }
}
