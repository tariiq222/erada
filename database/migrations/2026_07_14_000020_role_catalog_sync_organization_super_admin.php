<?php

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CSD-CA23078-SEEDER-001 — incremental role catalog sync for
 * `organization_super_admin`.
 *
 * The canonical role catalog lives in
 * `Database\Seeders\RolesAndPermissionsSeeder::roleCatalog()`. The
 * seeder itself runs the obsolete-pivot sweep on fresh installs and on
 * `db:seed --force`, but databases that upgraded without a re-seed
 * carry obsolete pivots left behind by an earlier catalog version.
 *
 * This migration is the incremental companion to
 * `2026_07_12_000018_role_catalog_sync_obsolete_pivots`:
 *   - The earlier migration sweeps `admin`, `viewer`, `dept_manager`,
 *     and `member`. Those four roles' pivots are its sole scope.
 *   - This migration sweeps the new `organization_super_admin` role
 *     introduced by Task 3. `admin`, `viewer`, `dept_manager`, and
 *     `member` continue to be handled by the earlier migration; this
 *     file does NOT touch them.
 *
 * The two migrations agree by construction on `RolesAndPermissionsSeeder::
 * roleCatalog()` — that array is the only source of truth for what each
 * swept role is supposed to grant.
 *
 * Idempotency: a re-run is a no-op because (a) the audit-event check
 * below skips pivots that have already been audited by this migration,
 * and (b) pivots deleted by a prior run are gone, so the loop finds
 * nothing to remove. The net effect is exactly one audit row per
 * deleted pivot, regardless of how many times the migration runs.
 *
 * Forward-only: `down()` is intentionally a no-op. The purpose of this
 * migration is to converge obsolete pivots to the canonical catalog.
 * Re-introducing them on rollback would undo the cleanup and re-expose
 * the very bug the ticket is fixing. Audit rows are preserved in all
 * directions.
 *
 * PostgreSQL-only: the schema uses jsonb + composite-key indexes whose
 * comparison semantics depend on PG. SQLite is forbidden at the project
 * level (CI guard job); the explicit check keeps a misconfigured local
 * environment from silently no-op'ing the migration.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_14_000020_role_catalog_sync_organization_super_admin';

    private const AUDIT_EVENT = 'role_catalog_sync_organization_super_admin_obsolete_pivot_removed';

    /**
     * Seeded system roles that participate in this incremental sweep.
     *
     * The earlier migration (`2026_07_12_000018_role_catalog_sync_obsolete_pivots`)
     * owns `admin`, `viewer`, `dept_manager`, and `member`. This
     * migration is intentionally narrow: it sweeps ONLY the
     * `organization_super_admin` role introduced by Task 3.
     *
     * @var list<string>
     */
    private const SWEPT_SYSTEM_ROLES = [
        'organization_super_admin',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                self::MIGRATION_NAME.' is PostgreSQL-only. Detected driver: ['.DB::getDriverName().'].'
            );
        }

        foreach (['authorization_roles', 'authorization_resources', 'authorization_role_permissions', 'authorization_assignment_audits'] as $table) {
            if (! Schema::hasTable($table)) {
                // Forward-only safety: a missing prerequisite means an
                // earlier migration failed. Refuse to silently no-op so
                // the operator notices and runs `migrate` from scratch.
                throw new RuntimeException(self::MIGRATION_NAME." requires table [{$table}] to exist.");
            }
        }

        // Build the desired (resource_id|action) set for the swept role
        // by replaying the same mapping the seeder uses. The migration
        // must agree with the seeder byte-for-byte; the
        // OrganizationSuperAdminRoleSeedTest pins both sides on every
        // PR to catch drift early.
        $catalog = RolesAndPermissionsSeeder::roleCatalog();
        $sweptRoleNames = array_values(array_intersect(
            array_keys($catalog),
            self::SWEPT_SYSTEM_ROLES,
        ));

        if ($sweptRoleNames === []) {
            return;
        }

        $roles = AuthorizationRole::query()
            ->whereIn('name', $sweptRoleNames)
            ->get(['id', 'name'])
            ->keyBy('name');

        $desiredByRole = [];
        foreach ($sweptRoleNames as $roleName) {
            $role = $roles->get($roleName);
            if ($role === null) {
                continue;
            }

            $resources = $this->resourceIdsForCatalog($catalog[$roleName]['capabilities']);

            $keys = [];
            foreach ($catalog[$roleName]['capabilities'] as $capability) {
                $mapping = CapabilityToAuthorizationRolePermission::map($capability);
                if ($mapping === null) {
                    continue;
                }

                $resourceId = $resources[$mapping['resource']] ?? null;
                if ($resourceId === null) {
                    continue;
                }

                $keys[] = $resourceId.'|'.$mapping['action'];
            }

            $desiredByRole[$roleName] = [
                'role_id' => (int) $role->id,
                'desired_keys' => $keys,
            ];
        }

        $resourceKeyById = DB::table('authorization_resources')->pluck('key', 'id');
        $alreadyAudited = $this->loadAlreadyAuditedPivotKeys();
        $auditRows = [];
        $now = now();

        DB::transaction(function () use (&$auditRows, $alreadyAudited, $desiredByRole, $resourceKeyById, $now): void {
            foreach ($desiredByRole as $roleName => $entry) {
                $existing = DB::table('authorization_role_permissions')
                    ->where('authorization_role_id', $entry['role_id'])
                    ->select(['authorization_resource_id', 'action'])
                    ->orderBy('authorization_resource_id')
                    ->orderBy('action')
                    ->get();

                foreach ($existing as $pivot) {
                    $key = $pivot->authorization_resource_id.'|'.$pivot->action;
                    if (in_array($key, $entry['desired_keys'], true)) {
                        continue;
                    }

                    $auditKey = $entry['role_id'].'|'.$pivot->authorization_resource_id.'|'.$pivot->action;
                    if (isset($alreadyAudited[$auditKey])) {
                        // A prior run already wrote the audit row for
                        // this pivot; deleting it again would just
                        // produce a duplicate audit we already skip.
                        // Skip cleanly so the migration is idempotent.
                        continue;
                    }

                    DB::table('authorization_role_permissions')
                        ->where('authorization_role_id', $entry['role_id'])
                        ->where('authorization_resource_id', $pivot->authorization_resource_id)
                        ->where('action', $pivot->action)
                        ->delete();

                    $auditRows[] = [
                        'event' => self::AUDIT_EVENT,
                        'actor_id' => null,
                        'target_user_id' => null,
                        'scope_type' => null,
                        'scope_id' => null,
                        'role' => $roleName,
                        'old_value' => json_encode([
                            'authorization_role_id' => (int) $entry['role_id'],
                            'authorization_resource_id' => (int) $pivot->authorization_resource_id,
                            'authorization_resource_key' => $resourceKeyById[$pivot->authorization_resource_id] ?? null,
                            'action' => $pivot->action,
                        ], JSON_THROW_ON_ERROR),
                        'new_value' => json_encode([
                            'migration' => self::MIGRATION_NAME,
                            'authorization_role_id' => (int) $entry['role_id'],
                            'authorization_resource_id' => (int) $pivot->authorization_resource_id,
                            'authorization_resource_key' => $resourceKeyById[$pivot->authorization_resource_id] ?? null,
                            'action' => $pivot->action,
                            'reason' => 'obsolete pivot no longer in canonical role catalog',
                            'source' => 'migration',
                            'ticket' => 'CSD-CA23078-SEEDER-001',
                        ], JSON_THROW_ON_ERROR),
                        'reason' => 'CSD-CA23078-SEEDER-001 obsolete pivot removed by role catalog sync migration',
                        'ip_address' => null,
                        'user_agent' => 'migration',
                        'created_at' => $now,
                    ];

                    // Track in-memory so a duplicate within the same
                    // query (should not happen — pivot key is unique —
                    // but defensive) would not produce two audits.
                    $alreadyAudited[$auditKey] = true;
                }
            }
        });

        if ($auditRows !== []) {
            foreach (array_chunk($auditRows, 500) as $chunk) {
                DB::table('authorization_assignment_audits')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        // Forward-only — see the class-level docblock for the rationale.
        // Re-introducing obsolete pivots on rollback would undo the
        // cleanup. Audit rows are preserved in all directions.
    }

    /**
     * @param  list<string>  $capabilities
     * @return array<string, int>
     */
    private function resourceIdsForCatalog(array $capabilities): array
    {
        $resourceKeys = [];
        foreach ($capabilities as $capability) {
            $mapping = CapabilityToAuthorizationRolePermission::map($capability);
            if ($mapping === null) {
                continue;
            }
            $resourceKeys[$mapping['resource']] = true;
        }

        if ($resourceKeys === []) {
            return [];
        }

        return DB::table('authorization_resources')
            ->whereIn('key', array_keys($resourceKeys))
            ->pluck('id', 'key')
            ->all();
    }

    /**
     * Load audit markers written by prior runs of this migration so a
     * re-run skips pivots whose audit already exists.
     *
     * @return array<string, true>
     */
    private function loadAlreadyAuditedPivotKeys(): array
    {
        $keys = [];
        DB::table('authorization_assignment_audits')
            ->where('event', self::AUDIT_EVENT)
            ->whereRaw("new_value ->> 'migration' = ?", [self::MIGRATION_NAME])
            ->select(['new_value'])
            ->orderBy('id')
            ->each(function (object $row) use (&$keys): void {
                $stored = json_decode((string) $row->new_value, true);
                if (! is_array($stored)) {
                    return;
                }

                $roleId = $stored['authorization_role_id'] ?? null;
                $resourceId = $stored['authorization_resource_id'] ?? null;
                $action = $stored['action'] ?? null;

                if ($roleId === null || $resourceId === null || $action === null) {
                    return;
                }

                $keys[$roleId.'|'.$resourceId.'|'.$action] = true;
            });

        return $keys;
    }
};
