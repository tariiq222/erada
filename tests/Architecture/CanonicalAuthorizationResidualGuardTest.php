<?php

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class CanonicalAuthorizationResidualGuardTest extends TestCase
{
    private const SOURCE_ROOTS = [
        'app',
        'bootstrap',
        'config',
        'database/seeders',
        'resources/js',
        'routes',
    ];

    private const TEST_SEEDER_ALLOWLIST = [
        'database/seeders/AdminE2ETestSeeder.php',
    ];

    private const UPGRADE_TEST_ALLOWLIST = [
        'tests/Feature/Core/Authorization/AuthorizationAssignmentReconciliationMigrationTest.php',
        'tests/Feature/Core/Authorization/LegacyAuthorizationTablesDropMigrationTest.php',
        'tests/Feature/Api/Admin/AdminRouteContractTest.php',
        'tests/Feature/Api/Shared/ActivityLogExportSearchTest.php',
        'tests/Feature/Core/UserIndexIsolationTest.php',
        'tests/Feature/Core/UserUpdateIsolationTest.php',
        'tests/Feature/Migrations/MigrationOrderTest.php',
        'tests/Feature/OVR/IncidentTypeControllerTest.php',
    ];

    /** @var array<string, string> */
    private const FORBIDDEN_RUNTIME_PATTERNS = [
        'Spatie permission namespace' => '/(?:use|new|extends|implements|instanceof)\s+[\\\\]?Spatie\\\\Permission\\\\/i',
        'Spatie HasRoles trait' => '/\buse\s+(?:[A-Za-z_][A-Za-z0-9_]*\s*,\s*)*HasRoles\b/',
        'legacy role or permission method' => '/(?:->|::|\.)\s*(?:assignRole|givePermissionTo|hasRole|hasPermissionTo|getRoleNames)\s*\(/',
        'legacy role or permission middleware' => '/\bmiddleware\s*\(\s*[\'"`](?:role|permission):[^\'"`\s]+/',
        'legacy scoped-role model' => '/(?:use\s+App\\\\Modules\\\\Core\\\\Models\\\\|\bnew\s+|\binstanceof\s+|\bextends\s+|\b)(?:ScopedRoleDefinition|ScopedRole)\b/',
        'legacy authorization table query' => '/(?:DB::)?(?:table|from|join|leftJoin|rightJoin)\s*\(\s*[\'"](?:model_has_scoped_roles|scoped_role_definitions|model_has_roles|model_has_permissions|role_has_permissions|roles|permissions)[\'"]/',
        'legacy authorization table name' => '/[\'"](?:model_has_scoped_roles|scoped_role_definitions|model_has_roles|model_has_permissions|role_has_permissions)[\'"]/',
        'legacy scoped-role endpoint alias' => '/[\'"`]\/?scoped-roles(?:\/|[\'"`])/',
    ];

    public function test_runtime_has_no_legacy_authorization_dependencies_or_entry_points(): void
    {
        $violations = [];

        foreach ($this->runtimeSourceFiles() as $relativePath) {
            $source = $this->sourceWithoutComments($relativePath);

            foreach (self::FORBIDDEN_RUNTIME_PATTERNS as $label => $pattern) {
                if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $offset = $match[0][1];
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $violations[] = "{$relativePath}:{$line} {$label}";
            }
        }

        self::assertSame(
            [],
            $violations,
            "Legacy authorization runtime surfaces are forbidden.\n".implode("\n", $violations),
        );
    }

    public function test_spatie_permission_package_cannot_be_reintroduced(): void
    {
        $composer = file_get_contents($this->projectPath('composer.json'));

        self::assertIsString($composer);
        self::assertStringNotContainsString('spatie/laravel-permission', $composer);
    }

    public function test_legacy_references_are_limited_to_migrations_and_explicit_upgrade_tests(): void
    {
        $violations = [];

        foreach ($this->filesUnder(['database/migrations', 'resources/js/__tests__', 'tests'], ['php', 'js', 'jsx', 'ts', 'tsx']) as $relativePath) {
            if (str_starts_with($relativePath, 'database/migrations/')
                || in_array($relativePath, self::UPGRADE_TEST_ALLOWLIST, true)
                || in_array($relativePath, [
                    'tests/Architecture/CanonicalAuthorizationAssignmentNamingTest.php',
                    'tests/Architecture/CanonicalAuthorizationResidualGuardTest.php',
                ], true)) {
                continue;
            }

            $source = $this->sourceWithoutComments($relativePath);

            foreach (self::FORBIDDEN_RUNTIME_PATTERNS as $label => $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = "{$relativePath} {$label}";
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Only migrations and the explicit upgrade-test allowlist may reference legacy authorization.\n".implode("\n", $violations),
        );
    }

    /** @return list<string> */
    private function runtimeSourceFiles(): array
    {
        return array_values(array_filter(
            $this->filesUnder(self::SOURCE_ROOTS, ['php', 'js', 'jsx', 'ts', 'tsx']),
            fn (string $path): bool => ! in_array($path, self::TEST_SEEDER_ALLOWLIST, true),
        ));
    }

    /** @param list<string> $roots
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function filesUnder(array $roots, array $extensions): array
    {
        $files = [];

        foreach ($roots as $root) {
            $absoluteRoot = $this->projectPath($root);
            if (! is_dir($absoluteRoot)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }

                $relativePath = str_replace($this->projectPath('').DIRECTORY_SEPARATOR, '', $file->getPathname());
                if (str_starts_with($relativePath, 'bootstrap/cache/')) {
                    continue;
                }

                if (in_array('resources/js', $roots, true)
                    && str_starts_with($relativePath, 'resources/js/__tests__/')) {
                    continue;
                }

                $files[] = $relativePath;
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    private function sourceWithoutComments(string $relativePath): string
    {
        $source = file_get_contents($this->projectPath($relativePath));
        if (! is_string($source)) {
            return '';
        }

        if (str_ends_with($relativePath, '.php')) {
            $clean = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $clean .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }

                $clean .= is_array($token) ? $token[1] : $token;
            }

            return $clean;
        }

        return preg_replace(['/\/\*.*?\*\//s', '/(^|\s)\/\/.*$/m'], '$1', $source) ?? $source;
    }

    private function projectPath(string $relativePath): string
    {
        return dirname(__DIR__, 2).($relativePath === '' ? '' : DIRECTORY_SEPARATOR.$relativePath);
    }
}
