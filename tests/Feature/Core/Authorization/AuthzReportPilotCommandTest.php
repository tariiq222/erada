<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\AuthorizationRuntimeMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AuthzReportPilotCommandTest -- Phase 1 Task 1.2.3.
 *
 * Drives `php artisan authz:report-pilot --module=projects` and asserts the
 * chat-gate surface is:
 *   - registered and exits SUCCESS for the supported module (projects);
 *   - FAILURE with a clear message for any unknown module;
 *   - report-only -- it must NOT write to any authorization_* table and must
 *     NOT leave AuthorizationRuntimeMode::isShadow() = true after it returns;
 *   - human-reviewable -- the text output must include the header, the module
 *     label, the curated decision-cell count, "Mismatches: 0", the documented
 *     intentional gaps/skips, a [PASS]-style detail table for viewer / member /
 *     manager / cross-org cases, and the exact verification command hints the
 *     user is expected to run before approving Phase 2.
 *
 * The command is a chat-gate surface only; the underlying parity proof lives
 * in AuthzPilotParityTest. We therefore do NOT enable shadow mode inside the
 * command under test -- the report is built over the already-tested pilot
 * matrix (see docs/superpowers/plans/2026-07-03-rbac-record-rules-unification.md
 * lines 120-139).
 */
class AuthzReportPilotCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        AuthorizationRuntimeMode::reset();
    }

    protected function tearDown(): void
    {
        AuthorizationRuntimeMode::reset();

        parent::tearDown();
    }

    public function test_command_is_registered_and_exits_success_for_projects_module(): void
    {
        $exitCode = Artisan::call('authz:report-pilot', ['--module' => 'projects']);
        $output = Artisan::output();

        $this->assertSame(
            0,
            $exitCode,
            "authz:report-pilot --module=projects exited with non-zero status [{$exitCode}].\nOutput:\n{$output}"
        );

        // The exact header text the chat gate contract pins -- kept in sync with
        // the brief so the user can grep for it in the terminal scrollback.
        $this->assertStringContainsString('authz:report-pilot [module=projects]', $output);
        $this->assertStringContainsString('Module: projects', $output);
    }

    public function test_report_includes_decision_cells_mismatches_and_intentional_gaps(): void
    {
        $exitCode = Artisan::call('authz:report-pilot', ['--module' => 'projects']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        // Decision-cell count for the curated Projects subset: viewer x 4 +
        // manager x 4 + cross-org x 3 = 11. The exact number is part of the
        // report contract; if the curated subset changes, update both the
        // report and this assertion.
        $this->assertMatchesRegularExpression(
            '/Decision cells:\s*([1-9]\d*)/',
            $output,
            'Report must include a "Decision cells: <n>" line with a positive integer.'
        );
        $this->assertStringContainsString('Decision cells: 11', $output);
        $this->assertStringContainsString('Mismatches: 0', $output);

        // The intentional gaps/skips are the user-approved "why these cells are
        // not in the curated subset" -- see AuthzPilotParityTest docblock.
        $this->assertStringContainsString('target=null', $output);
        $this->assertStringContainsString('super_admin', $output);
        $this->assertStringContainsString('unsupported scopes', $output);
        $this->assertStringContainsString('owner-floor', $output);
        $this->assertStringContainsString('creator lifecycle', $output);

        // The user must be told to review mismatches/gaps before Phase 2 --
        // this is the literal go/no-go gate from Task 1.2.3.
        $this->assertStringContainsString('Phase 2', $output);
    }

    public function test_report_includes_pass_table_for_curated_personas(): void
    {
        $exitCode = Artisan::call('authz:report-pilot', ['--module' => 'projects']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        // At minimum one [PASS] row per curated persona, so an operator can
        // scan the table and confirm the parity subset is covered.
        $this->assertStringContainsString('[PASS]', $output);
        $this->assertStringContainsString('viewer', $output);
        $this->assertStringContainsString('member', $output);
        $this->assertStringContainsString('manager', $output);
        $this->assertStringContainsString('cross-org', $output);
    }

    public function test_report_includes_verification_command_hints(): void
    {
        $exitCode = Artisan::call('authz:report-pilot', ['--module' => 'projects']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        // The report is a chat-gate surface; it must point the user at the
        // exact verification commands the plan pins for Phase 1 gates
        // (docs/superpowers/plans/2026-07-03-rbac-record-rules-unification.md
        // lines 134-139). Mirroring them here keeps the report self-contained
        // for the chat reviewer.
        $this->assertStringContainsString(
            './scripts/test-setup.sh --filter=AuthzPilotParityTest',
            $output,
            'Report must reference the curated-parity verification command.'
        );
        $this->assertStringContainsString(
            'AuthzPilotParityTest',
            $output,
            'Report must reference the curated-parity test class by name.'
        );
        $this->assertStringContainsString(
            'AuthzSeedRolePermissionsCommandTest',
            $output,
            'Report must reference the seed command test class by name.'
        );
    }

    public function test_unknown_module_exits_failure_with_clear_message(): void
    {
        $exitCode = Artisan::call('authz:report-pilot', ['--module' => 'risk-register']);
        $output = Artisan::output();

        $this->assertSame(
            1,
            $exitCode,
            "Unknown module must return Command::FAILURE.\nOutput:\n{$output}"
        );

        // The message must name the unknown module so an operator knows which
        // flag is wrong, AND it must list the supported module so they can
        // self-correct without reading docs.
        $this->assertStringContainsString('risk-register', $output);
        $this->assertStringContainsString('projects', $output);
        $this->assertStringContainsString('unsupported', strtolower($output));
    }

    public function test_command_does_not_persistently_enable_shadow_mode(): void
    {
        $this->assertFalse(
            AuthorizationRuntimeMode::isShadow(),
            'Pre-condition: shadow mode is disabled before the command runs.'
        );

        Artisan::call('authz:report-pilot', ['--module' => 'projects']);

        $this->assertFalse(
            AuthorizationRuntimeMode::isShadow(),
            'authz:report-pilot must not leave AuthorizationRuntimeMode::isShadow() = true.'
        );
    }

    public function test_command_does_not_write_to_authorization_tables(): void
    {
        // The full set of authorization_* tables that any future Phase 1.2.x
        // command might touch. The report is a chat-gate surface; it must
        // mutate none of them.
        $tables = [
            'authorization_resources',
            'authorization_roles',
            'authorization_role_permissions',
            'authorization_role_assignments',
            'authorization_record_rules',
            'authorization_decision_audits',
            'permission_audits',
        ];

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        // Drive the report twice -- including the unknown-module path -- to
        // confirm even error paths do not touch the authorization catalog.
        Artisan::call('authz:report-pilot', ['--module' => 'projects']);
        Artisan::call('authz:report-pilot', ['--module' => 'unknown']);

        foreach ($tables as $table) {
            $this->assertSame(
                $before[$table],
                DB::table($table)->count(),
                "Authorization table [{$table}] row count changed during authz:report-pilot -- the command must be report-only."
            );
        }
    }
}
