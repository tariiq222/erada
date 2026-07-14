<?php

namespace App\Modules\Core\Authorization\Actions;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite — Task 5 targeted sweep).
 *
 * Shared engine for migration `2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots`
 * and the strengthened unit test that owns the deletion + audit invariants.
 *
 * Behavior contract — matches the migration class-for-class so a test that
 * runs the action directly is exercising the same code the operator ships:
 *   - Driver must be pgsql (raises RuntimeException otherwise). The audit
 *     table comparison uses jsonb containment semantics; SQLite is
 *     forbidden at the project level (CI guard job).
 *   - Each pivot's deletion AND its corresponding audit insert happen
 *     inside one transaction so a mid-sweep failure leaves zero orphan
 *     deletions AND zero orphan audits. A partial sweep is impossible
 *     to observe: either everything rolls back, or every deletion is
 *     mirrored by an audit row.
 *   - Re-run convergence: an obsolete pivot that exists again (e.g.,
 *     because the operator re-ran `RolesAndPermissionsSeeder` between
 *     migration runs) is re-deleted every time the action runs, but no
 *     duplicate audit row is written for a pivot already audited by a
 *     prior run of this same migration. The audit marker is keyed by
 *     `$roleId.'|'.$resourceId.'|'.$action` inside `new_value.migration`
 *     and `event`, so only this migration's audits count.
 *   - Other roles' Organization × view/edit pivots are NEVER read,
 *     compared, or written by this action — `cluster_auditor`'s
 *     legitimate `Organization × view` pivot (introduced by the cluster
 *     tree capability) is preserved untouched.
 *
 * Forward-only semantics: this action does not restore pivots on a
 * rollback. The companion migration's `down()` is intentionally a
 * no-op for the same reason.
 *
 * Returned array: the set of pivot keys that were (re-)deleted in this
 * run, keyed by `$roleId.'|'.$resourceId.'|'.$action`. Empty when the
 * sweep is a no-op (no obsolete pivots present).
 */
final class SweepObsoleteOrgSuperOrganizationViewEditPivotsAction
{
    public const MIGRATION_NAME = '2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots';

    /**
     * Audit event tag stored in `authorization_assignment_audits.event`.
     *
     * The original brief specified a longer name
     * (`obsolete_orgsuper_organization_view_edit_pivot_removed`, 54 chars)
     * but the `event` column is `varchar(50)` per the source migration
     * `2026_01_12_100001_create_scoped_roles_tables`, so PostgreSQL
     * truncates (string-data-right-truncated). The migration would have
     * crashed on real databases where obsolete pivots existed at sweep
     * time; tests only passed because the seeders had already swept the
     * pivots before 000022 ran, leaving `existing->isEmpty()` true and
     * never exercising the audit-insert path. This 37-char name is the
     * authoritative category tag; the precise migration marker is in
     * `new_value.migration` (column JSONB), so consumers disambiguate via
     * the JSONB marker, not via this column.
     */
    public const AUDIT_EVENT = 'obsolete_orgsuper_org_pivot_removed';

    /** @var list<string> */
    public const TARGET_ACTIONS = ['view', 'edit'];

    /**
     * @return array<string, true>
     */
    public static function execute(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                self::MIGRATION_NAME.' is PostgreSQL-only. Detected driver: ['.DB::getDriverName().'].'
            );
        }

        foreach (['authorization_roles', 'authorization_resources', 'authorization_role_permissions', 'authorization_assignment_audits'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(self::MIGRATION_NAME." requires table [{$table}] to exist.");
            }
        }

        $orgSuper = AuthorizationRole::query()->where('name', 'organization_super_admin')->first();
        if ($orgSuper === null) {
            // No OrgSuper role yet — nothing to sweep. The curated sweep in
            // 2026_07_14_000020 will refuse to seed OrgSuper without the
            // preceding migrations; if we got here without OrgSuper, the
            // role catalog sync migration has not run yet. Bail safely.
            return [];
        }

        $organizationResourceId = DB::table('authorization_resources')
            ->where('key', Organization::class)
            ->value('id');

        if ($organizationResourceId === null) {
            return [];
        }

        $existing = DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $orgSuper->id)
            ->where('authorization_resource_id', $organizationResourceId)
            ->whereIn('action', self::TARGET_ACTIONS)
            ->orderBy('authorization_resource_id')
            ->orderBy('action')
            ->get();

        if ($existing->isEmpty()) {
            return [];
        }

        $alreadyAudited = self::loadAlreadyAuditedPivotKeys((int) $orgSuper->id);
        $deletedKeys = [];

        // Atomic delete+audit: every pivot that we delete is mirrored by
        // a fresh audit row in the same transaction. The whole loop runs
        // under DB::transaction so a partial sweep is impossible to
        // observe from outside — every deletion paired with its audit
        // either commits together or rolls back together.
        DB::transaction(function () use (&$alreadyAudited, &$deletedKeys, $orgSuper, $existing): void {
            $now = now();
            foreach ($existing as $pivot) {
                $auditKey = $orgSuper->id.'|'.$pivot->authorization_resource_id.'|'.$pivot->action;

                // Always re-delete the obsolete pivot if it currently
                // exists. This is the convergence contract: a recreated
                // pivot (e.g., because the operator re-ran
                // RolesAndPermissionsSeeder between migration runs) is
                // swept every time this action runs.
                DB::table('authorization_role_permissions')
                    ->where('authorization_role_id', $orgSuper->id)
                    ->where('authorization_resource_id', $pivot->authorization_resource_id)
                    ->where('action', $pivot->action)
                    ->delete();

                $deletedKeys[$auditKey] = true;

                if (isset($alreadyAudited[$auditKey])) {
                    // Already audited in a prior run of this migration.
                    // Skip the audit insert to avoid a duplicate row —
                    // the delete above still happened, so the
                    // convergence invariant is preserved.
                    continue;
                }

                DB::table('authorization_assignment_audits')->insert([
                    'event' => self::AUDIT_EVENT,
                    'actor_id' => null,
                    'target_user_id' => null,
                    'scope_type' => null,
                    'scope_id' => null,
                    'role' => 'organization_super_admin',
                    'old_value' => json_encode([
                        'authorization_role_id' => (int) $orgSuper->id,
                        'authorization_resource_id' => (int) $pivot->authorization_resource_id,
                        'authorization_resource_key' => Organization::class,
                        'action' => $pivot->action,
                    ], JSON_THROW_ON_ERROR),
                    'new_value' => json_encode([
                        'migration' => self::MIGRATION_NAME,
                        'authorization_role_id' => (int) $orgSuper->id,
                        'authorization_resource_id' => (int) $pivot->authorization_resource_id,
                        'authorization_resource_key' => Organization::class,
                        'action' => $pivot->action,
                        'reason' => 'obsolete OrgSuper pivot caused by previous core.cluster_tree mapping alias to Organization::class',
                        'source' => 'migration',
                        'ticket' => 'CSD-CA23078-CORE-009',
                    ], JSON_THROW_ON_ERROR),
                    'reason' => 'CSD-CA23078-CORE-009 obsolete OrgSuper Organization view/edit pivot removed',
                    'ip_address' => null,
                    'user_agent' => 'migration',
                    'created_at' => $now,
                ]);

                // Track in-memory so a duplicate within the same query
                // (should not happen — pivot key is unique — but
                // defensive) would not produce two audits.
                $alreadyAudited[$auditKey] = true;
            }
        });

        return $deletedKeys;
    }

    /**
     * Load audit markers written by prior runs of this action so a
     * re-run skips the audit insert for pivots whose audit already
     * exists (while still re-deleting them for convergence).
     *
     * @return array<string, true>
     */
    private static function loadAlreadyAuditedPivotKeys(int $roleId): array
    {
        $keys = [];
        DB::table('authorization_assignment_audits')
            ->where('event', self::AUDIT_EVENT)
            ->whereRaw("new_value ->> 'migration' = ?", [self::MIGRATION_NAME])
            ->select(['new_value'])
            ->orderBy('id')
            ->each(function (object $row) use (&$keys, $roleId): void {
                $stored = json_decode((string) $row->new_value, true);
                if (! is_array($stored)) {
                    return;
                }

                $storedRoleId = $stored['authorization_role_id'] ?? null;
                $resourceId = $stored['authorization_resource_id'] ?? null;
                $action = $stored['action'] ?? null;

                if ($storedRoleId !== $roleId || $resourceId === null || $action === null) {
                    return;
                }

                $keys[$roleId.'|'.$resourceId.'|'.$action] = true;
            });

        return $keys;
    }
}
