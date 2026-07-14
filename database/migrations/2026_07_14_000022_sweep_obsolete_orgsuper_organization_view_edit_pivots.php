<?php

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CSD-CA23078-CORE-009 (OrgSuper rewrite — Task 5 targeted sweep).
 *
 * Targeted sweep of obsolete authorization_role_permissions pivots caused by
 * the previous `core.cluster_tree` → `Organization::class` mapping alias in
 * `CapabilityToAuthorizationRolePermission::PREFIX_TO_RESOURCE`.
 *
 * Scope (deliberately narrow):
 *   - authorization_role_id corresponding to name = 'organization_super_admin'
 *   - authorization_resource_id corresponding to Organization::class
 *   - action IN ('view', 'edit')
 *
 * Out of scope (intentionally):
 *   - organizations.settings column on the organizations table — UNTOUCHED.
 *     The new contract writes to `organization_settings`, never to
 *     `organizations.settings`; this migration does not read or write that
 *     column.
 *   - cluster_auditor role — its pivots on `Organization` are legitimate
 *     cluster_tree pivots and must NOT be swept.
 *   - admin, super_admin, viewer, dept_manager, member, project_*,
 *     dept_member, pmo_*, quality_manager, risk_manager — none of their
 *     pivots are touched.
 *   - any other resource (User, Department, Project, Task, Meeting, etc.).
 *
 * Idempotent: re-run is a no-op because the audit-event check below skips
 * pivots whose `obsolete_orgsuper_organization_view_edit_pivot_removed`
 * audit row already exists. Forward-only: `down()` is intentionally a
 * no-op so a rollback does not re-introduce the obsolete pivots.
 *
 * PostgreSQL-only: the audit table comparison uses jsonb containment
 * semantics; SQLite is forbidden at the project level (CI guard job).
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_14_000022_sweep_obsolete_orgsuper_organization_view_edit_pivots';

    private const AUDIT_EVENT = 'obsolete_orgsuper_organization_view_edit_pivot_removed';

    /** @var list<string> */
    private const TARGET_ACTIONS = ['view', 'edit'];

    public function up(): void
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
            return;
        }

        $organizationResourceId = DB::table('authorization_resources')
            ->where('key', Organization::class)
            ->value('id');

        if ($organizationResourceId === null) {
            return;
        }

        $existing = DB::table('authorization_role_permissions')
            ->where('authorization_role_id', $orgSuper->id)
            ->where('authorization_resource_id', $organizationResourceId)
            ->whereIn('action', self::TARGET_ACTIONS)
            ->orderBy('authorization_resource_id')
            ->orderBy('action')
            ->get();

        if ($existing->isEmpty()) {
            return;
        }

        $alreadyAudited = $this->loadAlreadyAuditedPivotKeys((int) $orgSuper->id);
        $auditRows = [];
        $now = now();

        DB::transaction(function () use (&$auditRows, $alreadyAudited, $orgSuper, $existing, $now): void {
            foreach ($existing as $pivot) {
                $auditKey = $orgSuper->id.'|'.$pivot->authorization_resource_id.'|'.$pivot->action;
                if (isset($alreadyAudited[$auditKey])) {
                    continue;
                }

                DB::table('authorization_role_permissions')
                    ->where('authorization_role_id', $orgSuper->id)
                    ->where('authorization_resource_id', $pivot->authorization_resource_id)
                    ->where('action', $pivot->action)
                    ->delete();

                $auditRows[] = [
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
                ];

                $alreadyAudited[$auditKey] = true;
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
        // Forward-only — see class-level docblock.
    }

    /**
     * @return array<string, true>
     */
    private function loadAlreadyAuditedPivotKeys(int $roleId): array
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
};
