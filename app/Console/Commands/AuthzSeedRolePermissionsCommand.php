<?php

namespace App\Console\Commands;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AuthzSeedRolePermissionsCommand -- Phase 1 Task 1.2.1.
 *
 * Seeds the complete canonical authorization role catalog.
 *
 * Behavior:
 *   - No flag        : dry-run preview, writes nothing.
 *   - --dry-run      : prints the same preview, writes nothing.
 *   - --apply        : PostgreSQL-only; idempotently upserts resources, the
 *                      super_admin role, and pivot rows in a single DB
 *                      transaction; flushes AccessDecision's cache.
 *
 * Legacy Spatie and scoped-role tables are intentionally never read or written.
 */
class AuthzSeedRolePermissionsCommand extends Command
{
    protected $signature = 'authz:seed-role-permissions
        {--dry-run : Print the planned seed and exit without writing anything.}
        {--apply : Idempotently write the seed into the new authorization_* tables.}';

    protected $description = 'Seed the canonical authorization resources, roles, and role permissions.';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('authz:seed-role-permissions is PostgreSQL-only. Detected driver: ['.DB::getDriverName().'].');

            return self::FAILURE;
        }

        $mappings = CapabilityToAuthorizationRolePermission::mapAll();
        if ($mappings === []) {
            $this->warn('No Capability constants mapped to resources. Nothing to seed.');

            return self::SUCCESS;
        }

        $distinctResources = [];
        foreach ($mappings as $row) {
            $distinctResources[$row['resource']] = true;
        }

        $isApply = (bool) $this->option('apply');
        $isDryRun = (bool) $this->option('dry-run') || ! $isApply;

        if ($isDryRun) {
            return $this->preview($mappings, array_keys($distinctResources));
        }

        return $this->apply();
    }

    /**
     * Print the planned seed (counts + sample rows) and exit without writing.
     *
     * @param  list<array{capability: string, resource: class-string, action: string}>  $mappings
     * @param  list<class-string>  $distinctResources
     */
    protected function preview(array $mappings, array $distinctResources): int
    {
        $this->info('authz:seed-role-permissions [dry-run preview -- no writes will be performed]');
        $this->line('  Target role        : '.CapabilityToAuthorizationRolePermission::SEED_ROLE_NAME
            .' (label: "'.CapabilityToAuthorizationRolePermission::SEED_ROLE_LABEL.'")');
        $this->line('  Canonical roles    : '.count(RolesAndPermissionsSeeder::roleCatalog()));
        $this->line('  Capabilities       : '.count($mappings));
        $this->line('  Distinct resources : '.count($distinctResources));
        $this->line('  Pivot rows planned : '.count($mappings));
        $this->newLine();
        $this->line('  First 10 mapped capabilities:');

        foreach (array_slice($mappings, 0, 10) as $row) {
            $this->line(sprintf('    %-40s -> %s :: %s', $row['capability'], $row['resource'], $row['action']));
        }

        if (count($mappings) > 10) {
            $this->line('    ... ('.(count($mappings) - 10).' more)');
        }

        $this->newLine();
        $this->line('Re-run with --apply to write these rows.');

        return self::SUCCESS;
    }

    /**
     * Write the seed into the new authorization_* tables inside a single
     * DB transaction, then flush the AccessDecision cache so the next
     * can() call re-reads from the database.
     */
    protected function apply(): int
    {
        $this->info('authz:seed-role-permissions --apply');

        app(RolesAndPermissionsSeeder::class)->run();

        // Drop every memoized input the engine caches so the freshly seeded
        // pivot rows are visible to the next AccessDecision::can() call.
        AccessDecision::flushCache();

        // Present counts are derived from the DB after the transaction
        // commits so the telemetry is deterministic on every --apply run
        // (first run and any subsequent idempotent re-run report the same
        // numbers). This also makes the report independent of the
        // updateOrInsert() boolean quirks called out above.
        $resourcesPresent = AuthorizationResource::count();
        $rolesPresent = AuthorizationRole::count();
        $pivotsPresent = DB::table('authorization_role_permissions')->count();

        $this->line(sprintf('  Resources present: %d', $resourcesPresent));
        $this->line(sprintf('  Roles present: %d', $rolesPresent));
        $this->line(sprintf('  Pivot rows present: %d', $pivotsPresent));
        $this->info('Seed completed successfully (apply mode).');

        return self::SUCCESS;
    }
}
