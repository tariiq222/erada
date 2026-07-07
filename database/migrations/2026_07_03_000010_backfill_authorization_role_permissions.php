<?php

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\CapabilityAlias;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.1.1 -- additive backfill of legacy Spatie `role_has_permissions`
 * onto the new `authorization_role_permissions` pivot.
 *
 * For every (legacy_role, legacy_permission) pair in the `web` guard where
 *   CapabilityAlias::toCapability(legacy_permission)   != null
 *   CapabilityToAuthorizationRolePermission::map(cap) != null
 * this migration upserts an `authorization_roles` row matching the legacy
 * role name (readable label), an `authorization_resources` row keyed by the
 * resolved FQCN, and one `authorization_role_permissions` pivot row.
 * A pair that fails either mapping is skipped -- no pivot, no widening,
 * and no audit marker. Pre-existing Phase 1 super_admin pivot rows are
 * NOT touched (the composite primary key dedupes); they are also not
 * audit-marked.
 *
 * Every pivot row NEWLY created by this migration is audited via one
 * `permission_audits` row carrying `event = 'legacy_backfill_000010'`
 * and a JSON `new_value` payload with the migration tag, the new
 * authorization_role_id / resource_id / action, and the legacy role /
 * permission ids + names.
 *
 * down() reverses ONLY what up() wrote: it deletes the pivot rows that
 * carry a matching audit marker (and the audit markers themselves). It
 * NEVER deletes authorization_roles, authorization_resources, or any
 * legacy Spatie row -- so a Phase 1 pre-existing pivot row is preserved
 * across down(), and Spatie tables are left fingerprint-identical.
 *
 * Safe to run twice: the second up() finds every pivot row already in
 * place (the existed-check) and writes no new audit marker. The second
 * down() finds no audit markers left and is a no-op.
 *
 * PostgreSQL-only -- the Spatie `role_has_permissions` / `permissions`
 * / `roles` tables are configured by the laravel-permission package, and
 * the backfill's existence checks rely on `whereExists` semantics that
 * are deterministic on PostgreSQL.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_03_000010_backfill_authorization_role_permissions';

    private const AUDIT_EVENT = 'legacy_backfill_000010';

    private const AUDIT_REASON = 'Phase 2.1.1 authorization role permission backfill';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                self::MIGRATION_NAME.' is PostgreSQL-only. Detected driver: ['.DB::getDriverName().'].'
            );
        }

        $tableNames = config('permission.table_names');
        if (! is_array($tableNames) || empty($tableNames['roles']) || empty($tableNames['permissions']) || empty($tableNames['role_has_permissions'])) {
            // Defensive: laravel-permission config not loaded -- refuse to
            // guess table names. The Phase 1 authz tables are intact and
            // this migration's up() will simply produce no rows, but the
            // Spatie-side query would otherwise explode.
            return;
        }

        $guardName = 'web';

        // Single read of every legacy (role, permission) pair joined to
        // their names so the mapper has both ids and strings. The join
        // avoids N+1 lookups in the per-pair loop below.
        $pairs = DB::table($tableNames['role_has_permissions'].' as rhp')
            ->join($tableNames['roles'].' as r', 'r.id', '=', 'rhp.role_id')
            ->join($tableNames['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
            ->where('r.guard_name', $guardName)
            ->where('p.guard_name', $guardName)
            ->select(
                'rhp.role_id as legacy_role_id',
                'rhp.permission_id as legacy_permission_id',
                'r.name as legacy_role_name',
                'p.name as legacy_permission_name',
            )
            ->get();

        if ($pairs->isEmpty()) {
            AccessDecision::flushCache();

            return;
        }

        // Collect audit rows in memory and bulk-insert at the end of the
        // transaction. Per-pair INSERTs would be N round-trips and would
        // also risk leaving partial audits on a transaction failure.
        $auditRows = [];
        $now = now();

        DB::transaction(function () use ($pairs, &$auditRows, $now) {
            foreach ($pairs as $pair) {
                $capability = CapabilityAlias::toCapability($pair->legacy_permission_name);
                if ($capability === null) {
                    // Transition alias -- no canonical Capability yet.
                    // Skip without writing a pivot or audit row (no widening).
                    continue;
                }

                $map = CapabilityToAuthorizationRolePermission::map($capability);
                if ($map === null) {
                    // Capability exists but the prefix is unmapped -- skip
                    // without widening the catalog.
                    continue;
                }

                $resourceKey = $map['resource'];
                $action = $map['action'];

                // Upsert the authz role matching the legacy role name.
                // firstOrCreate does not overwrite an existing row, so a
                // Phase 1 row with a richer label (e.g. super_admin ->
                // 'Super Admin' from the artisan seeder) is preserved.
                $authRole = AuthorizationRole::firstOrCreate(
                    ['name' => $pair->legacy_role_name],
                    ['label' => $this->humanLabel($pair->legacy_role_name)],
                );

                // Upsert the authz resource keyed by FQCN. Same rule --
                // existing rows (with potentially richer labels) survive.
                $authResource = AuthorizationResource::firstOrCreate(
                    ['key' => $resourceKey],
                    ['label' => $this->shortName($resourceKey)],
                );

                // Detect "newly created by this migration" vs "pre-existing
                // (e.g. Phase 1 super_admin row)". Only the new rows are
                // audited -- pre-existing rows are NEVER marked and NEVER
                // deleted, so down() has nothing to do for them.
                $existed = DB::table('authorization_role_permissions')
                    ->where('authorization_role_id', $authRole->id)
                    ->where('authorization_resource_id', $authResource->id)
                    ->where('action', $action)
                    ->exists();

                if ($existed) {
                    continue;
                }

                DB::table('authorization_role_permissions')->insert([
                    'authorization_role_id' => $authRole->id,
                    'authorization_resource_id' => $authResource->id,
                    'action' => $action,
                ]);

                $auditRows[] = [
                    'event' => self::AUDIT_EVENT,
                    'actor_id' => null,
                    'target_user_id' => null,
                    'scope_type' => null,
                    'scope_id' => null,
                    'role' => $pair->legacy_role_name,
                    'old_value' => null,
                    'new_value' => json_encode([
                        'migration' => self::MIGRATION_NAME,
                        'authorization_role_id' => $authRole->id,
                        'authorization_resource_id' => $authResource->id,
                        'action' => $action,
                        'legacy_role_id' => (int) $pair->legacy_role_id,
                        'legacy_permission_id' => (int) $pair->legacy_permission_id,
                        'legacy_permission_name' => $pair->legacy_permission_name,
                        'capability' => $capability,
                    ]),
                    'reason' => self::AUDIT_REASON,
                    'ip_address' => null,
                    'user_agent' => 'migration',
                    'created_at' => $now,
                ];
            }

            if ($auditRows !== []) {
                DB::table('permission_audits')->insert($auditRows);
            }
        });

        AccessDecision::flushCache();
    }

    public function down(): void
    {
        // Read ONLY the audit markers this migration wrote. Anything else
        // in permission_audits (Phase 1, controllers, future migrations)
        // is left untouched.
        $auditRows = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->get();

        if ($auditRows->isEmpty()) {
            AccessDecision::flushCache();

            return;
        }

        $pivotDeletes = [];
        $auditIdsToDelete = [];

        foreach ($auditRows as $auditRow) {
            // Defensive parse: a row written by some OTHER future migration
            // that re-uses the same event name must not be matched here.
            // The migration tag in new_value is the only safe gate.
            $newValue = json_decode($auditRow->new_value, true);
            if (! is_array($newValue)) {
                continue;
            }
            if (($newValue['migration'] ?? null) !== self::MIGRATION_NAME) {
                continue;
            }

            $authRoleId = $newValue['authorization_role_id'] ?? null;
            $authResourceId = $newValue['authorization_resource_id'] ?? null;
            $action = $newValue['action'] ?? null;

            if ($authRoleId === null || $authResourceId === null || $action === null) {
                continue;
            }

            $pivotDeletes[] = [
                'authorization_role_id' => (int) $authRoleId,
                'authorization_resource_id' => (int) $authResourceId,
                'action' => (string) $action,
            ];
            $auditIdsToDelete[] = (int) $auditRow->id;
        }

        DB::transaction(function () use ($pivotDeletes, $auditIdsToDelete) {
            // Delete only the pivot rows this migration wrote. Pre-existing
            // Phase 1 rows have no matching audit row and therefore no
            // entry in $pivotDeletes, so they survive.
            foreach ($pivotDeletes as $key) {
                DB::table('authorization_role_permissions')
                    ->where('authorization_role_id', $key['authorization_role_id'])
                    ->where('authorization_resource_id', $key['authorization_resource_id'])
                    ->where('action', $key['action'])
                    ->delete();
            }

            // Drop the audit markers themselves. The legacy `permission_audits`
            // table is preserved -- only this migration's own markers go.
            if ($auditIdsToDelete !== []) {
                DB::table('permission_audits')
                    ->whereIn('id', $auditIdsToDelete)
                    ->delete();
            }
        });

        AccessDecision::flushCache();
    }

    /**
     * Derive a readable label for an authorization_roles row from a legacy
     * Spatie role name. `firstOrCreate` does not overwrite an existing row,
     * so this only seeds the label on the FIRST create for that name -- it
     * is safe for Phase 1 rows whose label may already be set explicitly.
     */
    private function humanLabel(string $roleName): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $roleName));
    }

    /**
     * Derive a short resource label from a model FQCN. The FQCN is the
     * unique key; the label is the basename after the last namespace
     * separator, which is what the Phase 1 seeder writes.
     */
    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');
        if ($position === false) {
            return $fqcn;
        }

        return substr($fqcn, $position + 1);
    }
};
