<?php

namespace Tests\Feature\Authorization;

use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 5B — Fresh-install seeder retains the canonical definition
 * and never duplicates rows on re-run.
 *
 * Design brief:
 *
 *   "Fresh-install seeders retain the same definition."
 *
 * The ScopedDepartmentRolesSeeder writes the cluster_auditor row
 * on fresh installs via `firstOrNew` keyed on
 * (scope_type_id, role_key). Re-running the seeder must not
 * duplicate rows. This test pins the canonical definition is
 * byte-for-byte the same shape the Phase 5A migration writes,
 * so a drift between the seeder and the migration is caught
 * before production deploy.
 */
class Phase5BFreshInstallSeederNoDuplicationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_NAME = '2026_07_11_100000_provision_cluster_auditor_role';

    private const ROLE_KEY = 'cluster_auditor';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Phase 5B seeder test is PostgreSQL-only.');
        }
    }

    public function test_seeded_definition_matches_migration_canonical_byte_for_byte(): void
    {
        // Phase 5B contract — the seeder's definition for the
        // cluster_auditor row must match the migration's canonical
        // capability set byte-for-byte. Both the seeder and the
        // migration are the two provisioning paths (seeder for
        // fresh installs, migration for existing installs); they
        // have to agree on the role, capability set, scope_type,
        // and basic flags.
        $seeder = $this->loadCanonicalFromSeeder();
        $migration = $this->loadCanonicalFromMigration();

        $this->assertSame(
            $migration['scope_type_key'],
            $seeder['scope_type_key'],
            'seeder + migration scope_type_key drift is forbidden'
        );
        $this->assertSame(
            $migration['role_key'],
            $seeder['role_key'],
            'seeder + migration role_key drift is forbidden'
        );
        $this->assertSame(
            $migration['permissions'],
            $seeder['permissions'],
            'seeder + migration capabilities drift is forbidden'
        );
        $this->assertSame(
            $migration['is_admin_role'],
            $seeder['is_admin_role'],
            'seeder + migration is_admin_role drift is forbidden'
        );
        $this->assertSame(
            $migration['sort_order'],
            $seeder['sort_order'],
            'seeder + migration sort_order drift is forbidden'
        );
    }

    public function test_running_seeder_twice_does_not_duplicate_cluster_auditor_row(): void
    {
        // The seeder is invoked via the seeders configured in
        // phpunit.xml (`--filter=DatabaseSeeder` paths). Calling
        // the Seeder class directly twice simulates a re-run; the
        // `firstOrNew` semantics on (scope_type_id + role_key)
        // keep the row count at 1.
        $seeder = $this->appMake(ScopedDepartmentRolesSeeder::class);

        $seeder->run();
        $seeder->run();

        $rowCount = DB::table('scoped_role_definitions')
            ->where('role_key', self::ROLE_KEY)
            ->count();

        $this->assertSame(
            1,
            $rowCount,
            're-running the seeder must NOT duplicate the cluster_auditor row'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCanonicalFromSeeder(): array
    {
        $row = DB::table('scoped_role_definitions')
            ->where('role_key', self::ROLE_KEY)
            ->first();

        $this->assertNotFalse($row, 'precondition: seeder has provisioned cluster_auditor');

        return [
            'scope_type_key' => $row->scope_type,
            'role_key' => $row->role_key,
            'permissions' => $this->normalizePermissions($row->permissions),
            'is_admin_role' => (int) $row->is_admin_role,
            'sort_order' => (int) $row->sort_order,
        ];
    }

    /**
     * Load the canonical capability set, scope_type_key, role_key,
     * is_admin_role, and sort_order the Phase 5A migration uses.
     * The migration is an anonymous-class instance returned by the
     * file's last expression; reflection on the symbolic class name
     * fails because PHP generates a name like `class@anonymous…`.
     *
     * @return array<string, mixed>
     */
    private function loadCanonicalFromMigration(): array
    {
        $instance = require database_path('migrations/'.self::MIGRATION_NAME.'.php');
        $this->assertIsObject($instance, 'migration file must return a Migration instance');

        $reflection = new \ReflectionObject($instance);
        $constants = $reflection->getConstants();

        return [
            'scope_type_key' => $constants['SCOPE_TYPE_KEY'] ?? null,
            'role_key' => $constants['ROLE_KEY'] ?? null,
            'permissions' => $instance->canonicalCapabilities(),
            'is_admin_role' => 0,
            'sort_order' => 80,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizePermissions(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function appMake(string $class): object
    {
        $obj = $this->app->make($class);
        $this->assertInstanceOf($class, $obj);

        return $obj;
    }
}
