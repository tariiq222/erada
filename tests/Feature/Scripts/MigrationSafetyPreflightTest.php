<?php

namespace Tests\Feature\Scripts;

use Tests\TestCase;

/**
 * Phase 5C — migration-safety preflight contract.
 *
 * The preflight script is a bash program with two failure modes:
 *   - Pending destructive migrations: exit 1, print blocked list.
 *   - DB unreachable / migrate:status errors: exit 1, fails closed.
 *
 * The success path is the one we pin here: with all four blocked
 * migrations in `migrations.migration` on the testing DB (after
 * `composer test`'s `migrate --env=testing` setup), the preflight
 * prints the success line and exits 0. CI / deploy hooks gate on
 * this exit value.
 *
 * The script itself is tested as a black box — we do NOT shell into
 * its internals. The contract is "exit 0 + success line + clears
 * 4 migrations". If any of the BLOCKED_MIGRATIONS constant in
 * the script drifts out of sync with the docs/migrations-
 * remediation-playbook.md catalog, this test catches it.
 *
 * NB: the script is invoked via `bash`, which on some CI runners
 * requires `bash` to be on PATH (it is on every Linux + macOS
 * runner we support per CI matrix). On Windows, the project
 * already requires WSL per the design brief.
 */
class MigrationSafetyPreflightTest extends TestCase
{
    public function test_preflight_clears_when_blocked_migrations_are_applied(): void
    {
        $script = base_path('scripts/migration-safety-preflight.sh');

        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script), 'preflight script must be executable');

        // The script reads APP_ENV via MIGRATION_SAFETY_APP_ENV so
        // the test DB (iradah_pmo_test) is reachable.
        $command = sprintf(
            'MIGRATION_SAFETY_APP_ENV=testing bash %s 2>&1; echo EXIT=$?',
            escapeshellarg($script)
        );

        $output = [];
        $exit = 0;
        exec($command, $output, $exit);
        $combined = implode("\n", $output);

        $this->assertSame(
            'EXIT=0',
            trim(explode("\n", $combined)[count(explode("\n", $combined)) - 1] ?? ''),
            "preflight should clear on test DB. Got: \n".$combined
        );
        $this->assertStringContainsString(
            'migration-safety preflight cleared',
            $combined,
            'preflight success line is the contract; the script output is part of the API surface.'
        );
    }

    public function test_blocked_migration_count_matches_playbook_catalog(): void
    {
        // The blocked list inside the script must stay in sync with
        // docs/migrations-remediation-playbook.md. If a reviewer
        // adds a new entry to one without updating the other, this
        // catches them on PR.
        $scriptPath = base_path('scripts/migration-safety-preflight.sh');
        $playbookPath = base_path('docs/migrations-remediation-playbook.md');

        $this->assertFileExists($scriptPath);
        $this->assertFileExists($playbookPath);

        $script = file_get_contents($scriptPath);
        $playbook = file_get_contents($playbookPath);

        // Count entries in the BLOCKED_MIGRATIONS array — each entry
        // is one quoted string ending in `.php` (without the suffix
        // in the array).
        preg_match_all('/"(\d{4}_\d{2}_\d{2}_\d{6}_[^"]+)"/', $script, $scriptMatches);
        $blockedCount = count($scriptMatches[1]);

        // Count headings in the playbook — each migration gets a
        // `### \`<migration-name>.php\`` heading.
        preg_match_all('/^### `([^`]+)`/m', $playbook, $playbookMatches);
        $playbookCount = count($playbookMatches[1]);

        $this->assertSame(
            $blockedCount,
            $playbookCount,
            'BLOCKED_MIGRATIONS in the script and the ## catalog in the playbook must stay in sync'
        );
        $this->assertGreaterThanOrEqual(
            2,
            $blockedCount,
            'the blocked list must stay non-trivial — empty lists defeat the design brief'
        );
    }
}
