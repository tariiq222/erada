<?php

namespace App\Console\Commands;

use App\Modules\HR\Support\LegacyRoleMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting-only oracle for the Phase 4 legacy department-role migration.
 *
 * It never writes. It reports:
 *  - distinct legacy role names not yet present in LegacyRoleMap::MAP (so the
 *    map can be completed before the production backfill),
 *  - legacy policy/grant row counts,
 *  - stale grants (a grant whose role is no longer a default role of its
 *    department) — these will not be backfilled as a department policy.
 *
 * Exits non-zero when unmapped, non-protected legacy roles exist so the
 * operator must acknowledge the dept_member default before proceeding.
 */
class VerifyLegacyRoleMigration extends Command
{
    protected $signature = 'roles:verify-legacy-migration {--dry-run}';

    protected $description = 'Report legacy department-role data and how it will map to scoped roles';

    public function handle(): int
    {
        // If the legacy tables were already dropped (post Task 5), there is
        // nothing to verify — report and succeed.
        if (! Schema::hasTable('department_default_roles')) {
            $this->info('Legacy tables already dropped — nothing to verify.');

            return self::SUCCESS;
        }

        $legacyRoleNames = DB::table('department_default_roles as ddr')
            ->join('roles as r', 'r.id', '=', 'ddr.role_id')
            ->distinct()->pluck('r.name');

        $unmapped = $legacyRoleNames
            ->reject(fn ($n) => in_array($n, LegacyRoleMap::PROTECTED, true))
            ->reject(fn ($n) => array_key_exists($n, LegacyRoleMap::MAP))
            ->values();

        $this->info('Legacy default-role names: '.$legacyRoleNames->implode(', '));
        if ($unmapped->isNotEmpty()) {
            $this->warn('Unmapped (will default to dept_member): '.$unmapped->implode(', '));
        }

        $grantCount = DB::table('department_role_grants')->count();
        $policyCount = DB::table('department_default_roles')->count();
        $this->info("Legacy policy rows: {$policyCount}; auto grant rows: {$grantCount}.");

        // Member-only assumption: every grant's role is a default role of its department.
        $violations = DB::table('department_role_grants as g')
            ->leftJoin('department_default_roles as d', function ($j) {
                $j->on('d.department_id', '=', 'g.department_id')->on('d.role_id', '=', 'g.role_id');
            })
            ->whereNull('d.id')->count();
        $this->info("Grants without a matching department policy (stale): {$violations}.");

        return $unmapped->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
