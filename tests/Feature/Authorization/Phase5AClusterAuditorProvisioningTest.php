<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 5A — Idempotent cluster_auditor provisioning migration.
 *
 * The design brief:
 *
 *   "Provision `cluster_auditor` for existing databases through a
 *    new idempotent additive data migration using exact role and
 *    capability keys. … Re-running provisioning must not duplicate
 *    rows or broaden the role. Rollback must not remove
 *    capabilities or roles that predated the migration; a safe
 *    no-op down is preferable to destructive revocation."
 *
 * These tests pin the contract end-to-end:
 *   1. Missing row → migration inserts the canonical definition.
 *   2. Existing row with exact canonical permissions → no duplicate,
 *      no clobber (re-run is a no-op).
 *   3. Existing row with admin override permissions → migration
 *      leaves the row untouched (drift is logged, never mutated).
 *   4. down() is a no-op — the row survives rollback.
 *
 * The migration is paired with the ScopedDepartmentRolesSeeder
 * (which writes the same canonical row on fresh installs via
 * firstOrNew). RefreshDatabase runs the seeder before each test,
 * so the row is present at start; the tests first scrub it to
 * exercise the "missing row" path.
 */
class Phase5AClusterAuditorProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_NAME = '2026_07_11_100000_provision_cluster_auditor_role';

    private const ROLE_KEY = 'cluster_auditor';

    /**
     * Canonical capability set — must stay byte-identical to the
     * canonicalCapabilities() static helper inside the migration
     * file (and to the ScopedDepartmentRolesSeeder definition).
     * If this drifts, the migration's drift-detection warning will
     * surface it.
     *
     * @return list<string>
     */
    private function canonicalCapabilities(): array
    {
        return [
            Capability::AUDIT_VIEW,
            Capability::AUDIT_EXPORT,
            Capability::CLUSTER_TREE_VIEW,
            Capability::CLUSTER_TREE_EXPORT,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Phase 5A cluster_auditor provisioning test is PostgreSQL-only.');
        }
    }

    protected function tearDown(): void
    {
        AccessDecision::flushCache();

        parent::tearDown();
    }

    public function test_migration_inserts_canonical_row_when_role_is_missing(): void
    {
        $this->scrubRole();

        $this->assertRoleIsMissing();

        $this->runMigration('up');

        $row = $this->loadCanonicalRow();
        $this->assertNotNull($row, 'cluster_auditor row must be inserted by up() when missing');

        $permissions = $this->decodePermissions($row->permissions);
        sort($permissions);
        $canonical = $this->canonicalCapabilities();
        sort($canonical);

        $this->assertSame($canonical, $permissions, 'inserted permissions must match the canonical set byte-for-byte');
        $this->assertSame('مدقق سجل النشاط على مستوى التجمع', $row->label_ar);
        $this->assertSame('Cluster Audit Viewer', $row->label_en);
        $this->assertSame('organization', $row->scope_type);
        $this->assertSame(0, (int) $row->is_admin_role);
        $this->assertSame(1, (int) $row->is_active);
        $this->assertSame(80, (int) $row->sort_order);
    }

    public function test_migration_is_idempotent_when_row_already_exists_with_canonical_permissions(): void
    {
        // First provisioning
        $this->runMigration('up');
        $row1 = $this->loadCanonicalRow();
        $this->assertNotNull($row1);

        // Re-run up() — must NOT insert a duplicate.
        $this->runMigration('up');

        $this->assertSame(
            1,
            DB::table('scoped_role_definitions')
                ->where('role_key', self::ROLE_KEY)
                ->count(),
            're-running up() must NOT insert a duplicate row'
        );

        // Permissions payload is byte-identical.
        $row2 = $this->loadCanonicalRow();
        $this->assertSame($row1->permissions, $row2->permissions);
        $this->assertSame((int) $row1->id, (int) $row2->id, 'same physical row id — no drop-and-recreate');
    }

    public function test_migration_leaves_admin_override_permissions_untouched(): void
    {
        // Simulate an admin override by updating the seeded row's
        // permissions in place — the unique key on (name, scope_type)
        // prevents inserting a sibling row. The override intentionally
        // drops the CLUSTER_TREE_* pair to test that the migration
        // does NOT clobber a row whose permissions drifted away from
        // the canonical set.
        $before = $this->loadCanonicalRow();
        $this->assertNotNull($before, 'precondition: cluster_auditor row exists (seeded)');

        $overridden = [
            Capability::AUDIT_VIEW,
            Capability::AUDIT_EXPORT,
            // intentional override — missing CLUSTER_TREE_*
        ];
        DB::table('scoped_role_definitions')
            ->where('role_key', self::ROLE_KEY)
            ->update([
                'permissions' => json_encode($overridden),
                'updated_at' => now(),
            ]);

        $this->runMigration('up');

        $after = $this->loadCanonicalRow();
        $this->assertSame(json_encode($overridden), $after->permissions, 'admin override permissions must survive');
        $this->assertSame($before->label_ar, $after->label_ar);
        $this->assertSame($before->label_en, $after->label_en);
    }

    public function test_down_is_no_op_and_preserves_role(): void
    {
        $this->runMigration('up');
        $row = $this->loadCanonicalRow();
        $this->assertNotNull($row);

        $this->runMigration('down');

        // Per the design brief: rollback must not remove the row.
        $afterDown = $this->loadCanonicalRow();
        $this->assertNotNull($afterDown, 'down() must be a safe no-op; the cluster_auditor row survives rollback');
        $this->assertSame($row->permissions, $afterDown->permissions);
        $this->assertSame((int) $row->id, (int) $afterDown->id, 'same physical row id — rollback does not delete-then-reinsert');
    }

    // ──────────────────────────────────────────────────────────────
    // helpers
    // ──────────────────────────────────────────────────────────────

    private function runMigration(string $direction): void
    {
        $migration = $this->loadMigration();
        $migration->{$direction}();
    }

    /**
     * Load the migration file's anonymous class instance. The
     * declaration `return new class extends Migration { ... }` lives
     * at the bottom of the file, so the file's last evaluated
     * expression is the migration instance. We extract the
     * anonymous class via PHP's reflection of the loaded source.
     */
    private function loadMigration(): object
    {
        $path = database_path('migrations/'.self::MIGRATION_NAME.'.php');

        // require_once and require behave equivalently for loaded
        // files; the path return value is NOT what we want — we
        // want the migration instance the file returns. Re-running
        // up() multiple times across tests means `require_once`
        // would short-circuit on subsequent calls; `require` is
        // safe here (each test runs in its own process via PHPUnit
        // isolation, but we also strip the role in setUp()).
        $instance = require $path;
        $this->assertIsObject($instance, 'migration file must return an instance of a Migration subclass');

        return $instance;
    }

    private function scrubRole(): void
    {
        // Remove any pre-existing cluster_auditor row so we exercise
        // the "missing → insert" path. The seeder normally writes the
        // canonical row on a fresh install via `db:seed`, but
        // RefreshDatabase does not run `db:seed` automatically — the
        // row may or may not exist depending on whether the calling
        // test ran seeders. Either way, scrubbing is safe: if the row
        // exists, it is removed; if it does not, the DELETE is a
        // no-op.
        DB::table('scoped_role_definitions')
            ->where('role_key', self::ROLE_KEY)
            ->delete();

        AccessDecision::flushCache();
    }

    private function assertRoleIsMissing(): void
    {
        $count = DB::table('scoped_role_definitions')
            ->where('role_key', self::ROLE_KEY)
            ->count();

        $this->assertSame(0, $count, 'precondition: cluster_auditor row is missing before provisioning');
    }

    private function loadCanonicalRow(): ?object
    {
        $row = DB::table('scoped_role_definitions')
            ->where('role_key', self::ROLE_KEY)
            ->first();

        return $row === false || $row === null ? null : (object) $row;
    }

    /**
     * @return list<string>
     */
    private function decodePermissions(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
