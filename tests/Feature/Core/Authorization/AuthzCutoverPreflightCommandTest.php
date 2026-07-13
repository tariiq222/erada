<?php

namespace Tests\Feature\Core\Authorization;

use App\Console\Commands\AuthzCutoverPreflightCommand;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class AuthzCutoverPreflightCommandTest extends TestCase
{
    public function test_it_prints_ready_and_exits_zero_only_when_every_gate_passes(): void
    {
        $command = new class extends AuthzCutoverPreflightCommand
        {
            protected function routeMiddlewareInventory(): array
            {
                return [true, ['legacy_middleware=0']];
            }

            protected function productionCallsites(): array
            {
                return [true, ['legacy_decision_callsites=0']];
            }

            protected function canonicalIntegrity(): array
            {
                return [true, ['canonical_integrity=1']];
            }

            protected function canonicalIntegrityReport(): array
            {
                return [true, ['exit_code=0']];
            }

            protected function seedReadiness(): array
            {
                return [true, ['catalog_complete=1']];
            }

            protected function canonicalRuntimeConfiguration(): array
            {
                return [true, ['canonical_engine=only']];
            }
        };
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringEndsWith("READY\n", $tester->getDisplay());
        $this->assertStringNotContainsString('NOT READY', $tester->getDisplay());
    }

    public function test_one_failed_gate_blocks_ready_and_returns_nonzero(): void
    {
        $command = new class extends AuthzCutoverPreflightCommand
        {
            protected function routeMiddlewareInventory(): array
            {
                return [false, ['GET|HEAD api/legacy -> role'.':admin']];
            }

            protected function productionCallsites(): array
            {
                return [true, []];
            }

            protected function canonicalIntegrity(): array
            {
                return [true, []];
            }

            protected function canonicalIntegrityReport(): array
            {
                return [true, []];
            }

            protected function seedReadiness(): array
            {
                return [true, []];
            }

            protected function canonicalRuntimeConfiguration(): array
            {
                return [true, []];
            }
        };
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('[FAIL] route middleware inventory', $tester->getDisplay());
        $this->assertStringEndsWith("NOT READY\n", $tester->getDisplay());
    }

    public function test_route_inventory_detects_legacy_role_and_permission_middleware(): void
    {
        $roleMiddleware = 'role'.':admin';
        $permissionMiddleware = 'permission'.':projects.view';
        Route::get('/__authz-preflight-role', fn () => null)->middleware($roleMiddleware);
        Route::get('/__authz-preflight-permission', fn () => null)->middleware($permissionMiddleware);

        $command = new class extends AuthzCutoverPreflightCommand
        {
            public function inspectRoutes(): array
            {
                return $this->routeMiddlewareInventory();
            }
        };

        [$passed, $details] = $command->inspectRoutes();

        $this->assertFalse($passed);
        $this->assertStringContainsString($roleMiddleware, implode("\n", $details));
        $this->assertStringContainsString($permissionMiddleware, implode("\n", $details));
    }

    public function test_runtime_configuration_rejects_deprecated_runtime_switches(): void
    {
        $command = new class extends AuthzCutoverPreflightCommand
        {
            public function inspectRuntimeConfiguration(): array
            {
                return $this->canonicalRuntimeConfiguration();
            }
        };

        [$passed, $details] = $command->inspectRuntimeConfiguration();

        $this->assertTrue($passed, implode("\n", $details));
        $this->assertContains('canonical_engine=only', $details);
        $this->assertContains('deprecated_runtime_mode=0', $details);
    }
}
