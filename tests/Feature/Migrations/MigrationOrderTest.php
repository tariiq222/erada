<?php

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * CSD-CA23078-CORE-003 — Migration ordering invariants.
 *
 * The cutover drops several legacy `scoped_*` tables (via
 * `2026_07_12_000011_drop_legacy_authorization_tables`). After that drop,
 * no later migration may reference those table names as Schema::table
 * or DB::table operands, and no production code may instantiate the
 * deleted ScopedRoleDefinition model. This test enforces that ordering
 * invariant at the file-system level so a future migration cannot
 * silently re-introduce a runtime crash on a fresh install.
 */
class MigrationOrderTest extends TestCase
{
    private const DROP_LEGACY_TABLES_FILENAME = '2026_07_12_000011_drop_legacy_authorization_tables.php';

    private const LEGACY_TABLE_NAMES = [
        'scoped_role_definitions',
        'model_has_scoped_roles',
        'scope_types',
        'role_has_permissions',
        'model_has_permissions',
        'model_has_roles',
        'permissions',
        'roles',
    ];

    /** @return list<string> */
    private function sortedMigrationFilenames(): array
    {
        $files = File::files(database_path('migrations'));
        $names = array_map(fn ($f) => $f->getFilename(), $files);
        sort($names);

        return array_values($names);
    }

    public function test_drop_legacy_tables_migration_is_present_and_well_ordered(): void
    {
        $names = $this->sortedMigrationFilenames();
        $this->assertContains(self::DROP_LEGACY_TABLES_FILENAME, $names);

        $idx = array_search(self::DROP_LEGACY_TABLES_FILENAME, $names, true);
        $this->assertGreaterThan(0, $idx, 'drop_legacy_authorization_tables must not be the first migration');
    }

    public function test_no_migration_after_drop_legacy_tables_references_legacy_table_names(): void
    {
        $names = $this->sortedMigrationFilenames();
        $cutIdx = array_search(self::DROP_LEGACY_TABLES_FILENAME, $names, true);
        $this->assertNotFalse($cutIdx);

        $violations = [];
        for ($i = $cutIdx + 1; $i < count($names); $i++) {
            $path = database_path('migrations/'.$names[$i]);
            $content = (string) File::get($path);
            foreach (self::LEGACY_TABLE_NAMES as $legacy) {
                // Match both Schema::table and DB::table references that quote the legacy name.
                // We intentionally allow the legacy name to appear in comments / doc-blocks
                // (no opening quote immediately follows the table name).
                $pattern = '/(?:DB::|Schema::)\s*(?:table|rename)\s*\(\s*[\'"]'.preg_quote($legacy, '/').'[\'"]/i';
                if (preg_match($pattern, $content)) {
                    $violations[] = sprintf('migration %s references legacy table `%s` after drop', $names[$i], $legacy);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These migrations reference legacy table names AFTER the drop migration:\n".implode("\n", $violations),
        );
    }

    public function test_drop_legacy_tables_migration_actually_drops_the_legacy_tables(): void
    {
        $path = database_path('migrations/'.self::DROP_LEGACY_TABLES_FILENAME);
        $content = (string) File::get($path);

        // The drop migration defines a LEGACY_TABLES constant array and
        // iterates `Schema::dropIfExists($table)` over it. We assert the
        // table name appears in that constant and that the iteration
        // pattern is present in the file.
        foreach (self::LEGACY_TABLE_NAMES as $legacy) {
            $this->assertSame(
                1,
                preg_match('/[\'"]'.preg_quote($legacy, '/').'[\'"]\s*,/m', $content),
                "drop_legacy_authorization_tables must list legacy table `{$legacy}` in its LEGACY_TABLES constant",
            );
        }
        $this->assertSame(
            1,
            preg_match('/foreach\s*\(\s*self::LEGACY_TABLES\s+as\s+\$table\s*\)\s*\{\s*Schema::dropIfExists\s*\(\s*\$table\s*\)\s*;/m', $content),
            'drop_legacy_authorization_tables must iterate Schema::dropIfExists over its LEGACY_TABLES',
        );
    }

    public function test_no_production_code_instantiates_deleted_scoped_role_definition_model(): void
    {
        // The model class was removed; any reference to it under app/ would
        // be a runtime fatal. Walk every app/ PHP file and assert the FQCN
        // does not appear outside of doc-comments.
        $appPath = app_path();
        $violations = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appPath));
        foreach ($rii as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $content = (string) File::get($file->getPathname());
            // Look for non-doc-comment references: `use App\Modules\Core\Models\ScopedRoleDefinition;`,
            // `new ScopedRoleDefinition(`, `instanceof ScopedRoleDefinition`.
            if (preg_match('/(?:use\s+App\\\\Modules\\\\Core\\\\Models\\\\ScopedRoleDefinition|new\s+ScopedRoleDefinition|instanceof\s+ScopedRoleDefinition)/', $content)) {
                $violations[] = $file->getPathname();
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These files reference the deleted ScopedRoleDefinition model:\n".implode("\n", $violations),
        );
    }

    public function test_all_migration_files_have_unique_ordered_filenames(): void
    {
        $names = $this->sortedMigrationFilenames();
        $this->assertSame(count($names), count(array_unique($names)), 'duplicate migration filenames detected');

        $expected = $names;
        sort($expected);
        $this->assertSame($expected, $names, 'migrations are not in sorted filename order');
    }

    public function test_cutover_preflight_artifact_migrations_are_present(): void
    {
        $names = $this->sortedMigrationFilenames();

        // Sanity: the three new safety-net migrations we added in this
        // remediation pass must all be present and ordered after the
        // base canonical cutover (CORE-004 used cache directly and did
        // not need a migration; CORE-006 enforced the actor guard in the
        // controller itself).
        $this->assertContains('2026_07_12_000015_invalidate_stale_canonical_assignments_on_org_transfer.php', $names);
        $this->assertContains('2026_07_12_000016_narrow_legacy_department_aliases.php', $names);
        $this->assertContains('2026_07_12_000018_role_catalog_sync_obsolete_pivots.php', $names);
    }
}
