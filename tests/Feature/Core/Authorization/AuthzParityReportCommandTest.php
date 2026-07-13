<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthzParityReportCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_a_deterministic_sanitized_success_report_when_no_blockers_exist(): void
    {
        $first = storage_path('framework/testing/authz-parity-first.json');
        $second = storage_path('framework/testing/authz-parity-second.json');
        @unlink($first);
        @unlink($second);

        $this->artisan('authz:parity-report', ['--json' => $first])->assertExitCode(0);
        $this->artisan('authz:parity-report', ['--json' => $second])->assertExitCode(0);

        $firstJson = file_get_contents($first);
        $this->assertSame($firstJson, file_get_contents($second));
        $this->assertIsString($firstJson);

        $report = json_decode($firstJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['schema_version']);
        $this->assertSame(0, $report['summary']['issues']);
        $this->assertSame(count(array_unique(Capability::all())), $report['summary']['capabilities_scanned']);
        $this->assertSame(
            ['orphan', 'unknown', 'duplicate', 'cross_org'],
            array_keys($report['categories']),
        );
        $this->assertStringNotContainsString('email', $firstJson);
        $this->assertStringNotContainsString('reason', $firstJson);
    }

    #[Test]
    public function it_exits_nonzero_and_reports_an_unknown_canonical_permission_without_pii(): void
    {
        $roleId = DB::table('authorization_roles')->insertGetId([
            'name' => 'parity_unknown_permission',
            'label' => 'Parity unknown permission',
            'is_admin_role' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $resourceId = DB::table('authorization_resources')->insertGetId([
            'key' => 'parity.unknown.resource',
            'label' => 'Parity unknown resource',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('authorization_role_permissions')->insert([
            'authorization_role_id' => $roleId,
            'authorization_resource_id' => $resourceId,
            'action' => 'secret_export',
        ]);

        $path = storage_path('framework/testing/authz-parity-blocked.json');
        @unlink($path);

        $this->artisan('authz:parity-report', ['--json' => $path])->assertExitCode(1);

        $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['summary']['issues']);
        $this->assertSame(1, $report['summary']['by_category']['unknown']);
        $this->assertSame('role_permission', $report['categories']['unknown'][0]['entity']);
        $this->assertSame('parity.unknown.resource', $report['categories']['unknown'][0]['resource_key']);
        $this->assertSame('secret_export', $report['categories']['unknown'][0]['action']);
        $this->assertArrayNotHasKey('name', $report['categories']['unknown'][0]);
    }
}
