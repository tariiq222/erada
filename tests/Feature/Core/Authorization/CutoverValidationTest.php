<?php

namespace Tests\Feature\Core\Authorization;

use PHPUnit\Framework\TestCase;

class CutoverValidationTest extends TestCase
{
    public function test_tracked_configuration_has_no_runtime_mode_switch(): void
    {
        $environment = file_get_contents($this->projectPath('.env.example'));

        $this->assertFileDoesNotExist($this->projectPath('config/authorization.php'));
        $this->assertIsString($environment);
        $this->assertStringNotContainsString('AUTHORIZATION_RUNTIME_MODE', $environment);
        $this->assertStringNotContainsString('AUTHORIZATION_SHADOW_MISMATCH', $environment);
    }

    public function test_ci_has_a_canonical_enforce_gate_without_shadow(): void
    {
        $workflow = file_get_contents($this->projectPath('.github/workflows/ci.yml'));

        $this->assertIsString($workflow);
        $this->assertDoesNotMatchRegularExpression('/^  authorization-shadow:\n/m', $workflow);
        $this->assertMatchesRegularExpression('/^  authorization-enforce:\n/m', $workflow);
        $this->assertStringNotContainsString('AUTHORIZATION_RUNTIME_MODE', $workflow);
        $this->assertStringContainsString('php artisan authz:cutover-preflight', $workflow);
        $this->assertStringContainsString('CanonicalAuthorizationResidualGuardTest.php', $workflow);
        $this->assertStringContainsString('npm run build', $workflow);
        $this->assertStringContainsString('e2e/core-auth-and-orgs.spec.ts', $workflow);
    }

    public function test_runbook_covers_enforce_preflight_integrity_and_snapshot_rollback(): void
    {
        $runbook = file_get_contents($this->projectPath('docs/runbooks/authorization-cutover.md'));

        $this->assertIsString($runbook);
        foreach (['canonical integrity report', 'Pre-deploy gates', 'Rollback', 'database snapshot', 'legacy authorization tables have been removed'] as $required) {
            $this->assertStringContainsString($required, $runbook);
        }
    }

    private function projectPath(string $path): string
    {
        return dirname(__DIR__, 4).'/'.$path;
    }
}
