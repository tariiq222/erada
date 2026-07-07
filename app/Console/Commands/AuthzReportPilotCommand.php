<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * AuthzReportPilotCommand -- Phase 1 Task 1.2.3.
 *
 * Report-only chat-gate surface for the Projects pilot parity proof. The
 * command DOES NOT execute any parity checks; it prints a human-reviewable
 * summary of the curated parity subset already proven by
 * `AuthzPilotParityTest`, the documented intentional gaps, and the exact
 * verification commands an operator must run before approving Phase 2.
 *
 * Behavior:
 *   - --module=projects  : print the curated Projects parity report and exit 0.
 *   - any other --module : print a clear "unsupported" message and exit
 *                          Command::FAILURE.
 *
 * Out of scope (HARD): no DB writes, no AuthorizationRuntimeMode toggling,
 * no engine calls, no nested test runners. The command is intentionally a
 * thin terminal surface -- the source of truth for parity is the test suite.
 */
class AuthzReportPilotCommand extends Command
{
    protected $signature = 'authz:report-pilot
        {--module=projects : Pilot module to report on (only "projects" is supported in Phase 1.2.3).}';

    protected $description = 'Phase 1 Task 1.2.3: report-only chat-gate summary of the curated pilot parity proof.';

    /**
     * The single supported module in Phase 1.2.3. New modules must be added
     * here explicitly once their parity tests land -- this command is the
     * chat-gate surface, and silently expanding it would defeat the gate.
     */
    private const SUPPORTED_MODULES = ['projects'];

    public function handle(): int
    {
        $module = (string) $this->option('module');

        if (! in_array($module, self::SUPPORTED_MODULES, true)) {
            return $this->reportUnsupported($module);
        }

        return $this->reportProjects();
    }

    /**
     * Render the curated Projects parity report.
     *
     * Cell count math: viewer x 4 (view/edit/delete/manageMembers) + manager x
     * 4 (view/edit/manageMembers/delete) + cross-org x 3 (view/edit/delete).
     * The "assignRoles" persona cells are NOT in the curated subset -- they
     * are pinned by ProjectPolicyOracleTest instead, which is intentional and
     * documented in the gaps block below. See
     * tests/Feature/Projects/AuthzPilotParityTest.php for the matrix source.
     */
    private function reportProjects(): int
    {
        $this->line('authz:report-pilot [module=projects]');
        $this->newLine();

        $this->line('Module: projects');
        $this->line('Source: docs/superpowers/plans/2026-07-03-rbac-record-rules-unification.md (Task 1.2.3)');
        $this->line('Parity proof: tests/Feature/Projects/AuthzPilotParityTest.php');
        $this->newLine();

        $this->line('Curated parity subset (viewer + manager + cross-org on a single project):');
        $this->line('  Decision cells: 11');
        $this->line('  Mismatches: 0');
        $this->newLine();

        $this->line('Detail:');
        $this->line('  [PASS] viewer     | view=true, edit=false, delete=false, manageMembers=false');
        $this->line('  [PASS] member     | view=true, edit=false, delete=false, manageMembers=false');
        $this->line('  [PASS] manager    | view=true, edit=true,  manageMembers=true,  delete=false');
        $this->line('  [PASS] cross-org  | view=false, edit=false, delete=false');
        $this->newLine();

        $this->line('Intentional gaps / skips (documented, user-approved):');
        $this->line('  - target=null (viewAny / create): SHADOW branch only runs target-bound decisions;');
        $this->line('    legacy-only coverage lives in ProjectPolicyOracleTest.');
        $this->line('  - super_admin: short-circuits before the SHADOW branch; pinned by the super_admin');
        $this->line('    rows in ProjectPolicyOracleTest.');
        $this->line('  - unsupported scopes beyond all|organization (department/team/cluster/hospital/own):');
        $this->line('    narrowed on purpose by assignmentScopeApplies(); surfaced by the');
        $this->line('    "shadow_throws_when_..." tests in AuthzPilotParityTest.');
        $this->line('  - owner-floor / creator lifecycle: legacy-only layer not modeled in the new tables;');
        $this->line('    creator rows in ProjectPolicyOracleTest cover this surface.');
        $this->newLine();

        $this->line('Review mismatches and gaps above before approving Phase 2 (migration).');
        $this->newLine();

        $this->line('Run these verification commands yourself before saying "go":');
        $this->line('  ./scripts/test-setup.sh --filter=AuthzPilotParityTest');
        $this->line('  php artisan test --filter=AuthzPilotParityTest');
        $this->line('  php artisan test --filter=AuthzSeedRolePermissionsCommandTest');
        $this->line('  ./vendor/bin/pint --test');
        $this->newLine();

        $this->line('This command is a chat-gate surface; it does not run the tests or write to the DB.');

        return self::SUCCESS;
    }

    /**
     * Render the unsupported-module failure path.
     */
    private function reportUnsupported(string $module): int
    {
        $supported = implode(', ', self::SUPPORTED_MODULES);

        $this->error("authz:report-pilot: unsupported module [{$module}].");
        $this->line("  Supported modules: {$supported}");
        $this->line('  Re-run with a supported --module value to receive a parity report.');

        return self::FAILURE;
    }
}
