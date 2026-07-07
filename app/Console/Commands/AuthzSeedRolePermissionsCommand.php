<?php

namespace App\Console\Commands;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AuthzSeedRolePermissionsCommand -- Phase 1 Task 1.2.1.
 *
 * Seeds the `authorization_role_permissions` pivot (and the
 * `authorization_resources` + `authorization_roles.super_admin` rows it joins
 * against) from the legacy `Capability::all()` catalog.
 *
 * Behavior:
 *   - No flag        : dry-run preview, writes nothing.
 *   - --dry-run      : prints the same preview, writes nothing.
 *   - --apply        : PostgreSQL-only; idempotently upserts resources, the
 *                      super_admin role, and pivot rows in a single DB
 *                      transaction; flushes AccessDecision's cache.
 *
 * Out of scope (HARD): legacy Spatie tables (`roles`, `permissions`,
 * `role_has_permissions`, ...). This command is additive only; it never
 * deletes or modifies Spatie rows.
 */
class AuthzSeedRolePermissionsCommand extends Command
{
    protected $signature = 'authz:seed-role-permissions
        {--dry-run : Print the planned seed and exit without writing anything.}
        {--apply : Idempotently write the seed into the new authorization_* tables.}';

    protected $description = 'Phase 1 Task 1.2.1: seed authorization_role_permissions from Capability::all() onto super_admin.';

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

        return $this->apply($mappings, array_keys($distinctResources));
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
     *
     * @param  list<array{capability: string, resource: class-string, action: string}>  $mappings
     * @param  list<class-string>  $distinctResources
     */
    protected function apply(array $mappings, array $distinctResources): int
    {
        $this->info('authz:seed-role-permissions --apply');

        DB::transaction(function () use ($mappings, $distinctResources) {
            // 1. Upsert the super_admin role (composite target for the
            //    first-pass seed; Phase 2 will introduce additional roles).
            $role = AuthorizationRole::firstOrCreate(
                [
                    'name' => CapabilityToAuthorizationRolePermission::SEED_ROLE_NAME,
                ],
                [
                    'label' => CapabilityToAuthorizationRolePermission::SEED_ROLE_LABEL,
                ],
            );

            // 2. Upsert every distinct resource FQCN. We use the model
            //    short-name as the human label and the FQCN as the unique
            //    key; the seeded map is the only writer that ever needs
            //    the resource catalog at this point.
            foreach ($distinctResources as $fqcn) {
                $shortName = $this->shortName($fqcn);

                $existing = AuthorizationResource::where('key', $fqcn)->first();
                if ($existing === null) {
                    AuthorizationResource::create([
                        'key' => $fqcn,
                        'label' => $shortName,
                    ]);
                } elseif ($existing->label !== $shortName) {
                    // Refresh the label so a future rename of the canonical
                    // model does not leave a stale label behind. Skip when
                    // unchanged so the timestamp doesn't churn.
                    $existing->label = $shortName;
                    $existing->save();
                }
            }

            // 3. Resolve every resource id by FQCN (one read after the
            //    upserts above so we hit the cache), then upsert each
            //    pivot row against the composite primary key.
            $resourcesByKey = AuthorizationResource::query()
                ->whereIn('key', $distinctResources)
                ->pluck('id', 'key');

            foreach ($mappings as $row) {
                $resourceId = $resourcesByKey[$row['resource']] ?? null;
                if ($resourceId === null) {
                    // Defensive: should be impossible because we just
                    // upserted every distinct resource above.
                    continue;
                }

                // updateOrInsert() returns bool true on both insert and
                // "no-op update" paths, so its return value is unsafe to
                // use as a write counter -- it over-counts when the same
                // (resource, action) pair is mapped by more than one
                // Capability. The pivot's composite primary key dedupes
                // the row, but the boolean cannot distinguish the two
                // branches. We therefore report present counts after the
                // transaction commits instead of in-loop write deltas.
                DB::table('authorization_role_permissions')->updateOrInsert(
                    [
                        'authorization_role_id' => $role->id,
                        'authorization_resource_id' => $resourceId,
                        'action' => $row['action'],
                    ],
                    [],
                );
            }
        });

        // Drop every memoized input the engine caches so the freshly seeded
        // pivot rows are visible to the next AccessDecision::can() call.
        AccessDecision::flushCache();

        // Present counts are derived from the DB after the transaction
        // commits so the telemetry is deterministic on every --apply run
        // (first run and any subsequent idempotent re-run report the same
        // numbers). This also makes the report independent of the
        // updateOrInsert() boolean quirks called out above.
        $resourcesPresent = AuthorizationResource::count();
        $pivotsPresent = DB::table('authorization_role_permissions')->count();

        $this->line(sprintf('  Resources present: %d', $resourcesPresent));
        $this->line(sprintf('  Pivot rows present: %d', $pivotsPresent));
        $this->info('Seed completed successfully (apply mode).');

        return self::SUCCESS;
    }

    /**
     * Derive a short label from a model FQCN -- the basename without the
     * `App\Modules\<Module>\Models\` prefix.
     */
    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');
        if ($position === false) {
            return $fqcn;
        }

        return substr($fqcn, $position + 1);
    }
}
