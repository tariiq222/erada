<?php

namespace App\Console\Commands;

use App\Modules\Core\Authorization\AuthorizationRuntimeMode;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * AuthzModeCommand -- chat-gate surface that reports the live AuthZ cutover
 * state. Resolves the documentation mismatch recorded in
 * `docs/authz/deprecation-policy.md` (the policy lists this command as
 * "missing in this checkout") and gives operators a single command that
 * answers:
 *
 *   - Is the engine running as the live Policy path, or is the shadow branch active?
 *   - Is `config/authz.php` present in the repo, or has it been removed (the
 *     cutover deleted it)?
 *   - How many `AccessDecision::can(` callsites are wired in `app/Modules/**`?
 *   - Is `/api/auth/me` still emitting the legacy `permissions[]` blob?
 *
 * The command is read-only by design: it never mutates state, never toggles
 * the shadow flag, and never queries the database. It is a terminal mirror
 * of the release-checklist gates so a reviewer can verify the cutover
 * baseline without grep.
 */
class AuthzModeCommand extends Command
{
    protected $signature = 'authz:mode';

    protected $description = 'Report the live AuthZ cutover mode (engine/shadow), legacy payload shape, and callsite counts.';

    public function handle(): int
    {
        $this->line('authz:mode');
        $this->newLine();

        $this->line('Engine runtime mode');
        $shadow = AuthorizationRuntimeMode::isShadow();
        $this->line(sprintf(
            '  shadow branch active: %s',
            $shadow ? 'YES (parity verification runs against legacy path)' : 'no (engine is the live decision path)',
        ));
        $this->newLine();

        $this->line('config/authz.php file');
        $configExists = file_exists(base_path('config/authz.php'));
        $this->line(sprintf(
            '  %s — %s',
            $configExists ? 'PRESENT' : 'removed',
            $configExists
                ? 'feature flags are still loaded; investigate before Phase 9.4 cleanup'
                : 'no code reads config(\'authz\') after the cutover (Phase 1.1.4 baseline)',
        ));
        $this->newLine();

        $this->line('Engine callsites');
        $engineHits = $this->countMatches(
            base_path('app/Modules'),
            ['php'],
            '/AccessDecision::can\(/',
        );
        $this->line("  AccessDecision::can( count in app/Modules/**/*.php: {$engineHits}");
        $this->newLine();

        $this->line('/api/auth/me payload shape');
        $authController = base_path('app/Modules/Core/Http/Controllers/AuthController.php');
        $payload = file_exists($authController) ? file_get_contents($authController) : '';
        $emitsPermissions = (bool) preg_match("/'permissions'\s*=>\s*\\\$permissions/", $payload);
        $emitsCapabilities = (bool) preg_match("/'capabilities'\s*=>\s*\\\$capabilities/", $payload);
        $emitsAccess = (bool) preg_match("/'access'\s*=>\s*\\\$access/", $payload);
        $this->line(sprintf('  permissions[] emitted: %s', $emitsPermissions ? 'YES' : 'no'));
        $this->line(sprintf('  capabilities[] emitted: %s', $emitsCapabilities ? 'YES' : 'no (engine cutover incomplete)'));
        $this->line(sprintf('  access{} emitted: %s', $emitsAccess ? 'YES' : 'no (canonical map missing)'));
        $this->newLine();

        $this->line('Frontend legacy payload reads');
        $legacyReads = $this->countMatches(
            base_path('resources/js'),
            ['ts', 'tsx'],
            '/user\.permissions(\.length|\.map|\.includes|\?\.includes)/',
            ['__tests__', 'node_modules'],
        );
        $this->line("  user.permissions[] production reads: {$legacyReads}");
        $this->newLine();

        $this->line('Verdict');
        $cutoverDone = ! $emitsPermissions && $emitsCapabilities && $emitsAccess && $engineHits > 100;
        $this->line($cutoverDone
            ? '  PASS — canonical path is live; legacy payload is removed.'
            : '  PARTIAL — see the counts above; consult docs/authz/deprecation-policy.md for the next step.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Count regex matches across the given directory tree, restricting to
     * the supplied file extensions and skipping any excluded path segments.
     * ponytail: RecursiveDirectoryIterator avoids spawning a grep process and
     * keeps the command deterministic on hosts without GNU grep.
     */
    private function countMatches(string $dir, array $extensions, string $pattern, array $exclude = []): int
    {
        if (! is_dir($dir)) {
            return 0;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        $count = 0;

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            foreach ($exclude as $segment) {
                if (str_contains($path, DIRECTORY_SEPARATOR.$segment.DIRECTORY_SEPARATOR)) {
                    continue 2;
                }
            }
            if (! in_array($file->getExtension(), $extensions, true)) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }
            $count += preg_match_all($pattern, $contents);
        }

        return $count;
    }
}
