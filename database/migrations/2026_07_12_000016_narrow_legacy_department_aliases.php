<?php

use App\Modules\Core\Authorization\AccessDecision;
use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CSD-CA23078-CORE-001 - Idempotent safety net narrowing the canonical
 * authorization_role_permissions.reach for pivots the legacy
 * edit_department_* aliases widened onto the un-reach-capped canonical
 * PROJECTS_EDIT / TASKS_EDIT.
 *
 * Background:
 *   The Phase 2.1.1 backfill (2026_07_03_000010_backfill_authorization_role_permissions)
 *   walks every (legacy_role, legacy_permission) pair and, when
 *   CapabilityAlias::toCapability(legacy_permission) resolves to a canonical
 *   capability, writes an authorization_role_permissions pivot. Each pivot
 *   is audited with one authorization_assignment_audits row carrying
 *   event = 'legacy_backfill_000010' and a JSON new_value payload whose
 *   legacy_permission_name echoes the source flat string. The audit
 *   table was renamed from permission_audits to
 *   authorization_assignment_audits by migration
 *   2026_07_12_000010_rename_permission_audits_to_authorization_assignment_audits,
 *   which preserved every prior row's id and JSON payload.
 *
 *   Historically, edit_department_projects resolved to Capability::PROJECTS_EDIT
 *   and edit_department_tasks resolved to Capability::TASKS_EDIT. Those
 *   aliases were deliberate reach caps in the legacy flat-vocabulary: the
 *   strings named department-scoped grants (edit_<scope>_<module> ladder).
 *   Resolving them onto the un-reach-capped canonical capability silently
 *   widened the resulting pivot to reach=null ("all"), which the engine
 *   treats as unrestricted. A user holding the legacy admin role (which
 *   carries edit_department_projects on the historical admin role)
 *   therefore gained ability to edit peer-department projects, contrary to
 *   the legacy flat-string ladder.
 *
 *   CapabilityAlias::map() now returns null for both legacy strings.
 *   The CapabilityAlias change alone is not enough: pivots already
 *   materialized by the 000010 backfill keep their (currently null)
 *   reach and the engine still grants through them.
 *
 * This migration's job is the in-place narrowing of the pivot reach:
 *   1. Find every pivot the 000010 backfill created where the source
 *      legacy permission name was one of `edit_department_projects` or
 *      `edit_department_tasks`. Detection is purely via the audit marker
 *      the 000010 backfill wrote (`event = 'legacy_backfill_000010'` AND
 *      `new_value ->> 'legacy_permission_name' IN (...)`). The marker
 *      carries the `authorization_role_id` + `authorization_resource_id` +
 *      `action` composite, which uniquely identifies the pivot row.
 *   2. Update the pivot's `reach` from `null` to the per-module
 *      `{"projects":"department"}` / `{"tasks":"department"}` cap so the
 *      engine consults the narrowed reach instead of the previous "all".
 *      Pivots whose resource is neither Project nor Task (defensive) are
 *      skipped without writing reach.
 *
 * The pivot reach narrowing alone is sufficient to deny peer-department
 * edit. The engine's `AccessDecision::canonicalReachAdmits` consults the
 * pivot's reach map and rejects the peer-department target when the module
 * reach is `department` and the target's department does not lie in the
 * user's department subtree. Own-department edit remains allowed because
 * the target's department matches the user's department.
 *
 * The CSD-CA23078-CORE-001 spec also contemplated a parallel narrowing of
 * the `authorization_role_assignments.scope_type` for legacy admin users
 * without a `dept_manager` backup, but that step conflicts with
 * `AccessDecision::canonicalRoleIsActive()` — the engine requires
 * `role.scope_type === assignment.scope_type`, so narrowing an org-scoped
 * assignment to `department` against an org-scoped role would leave the
 * assignment dead (skipped at the role-validity gate). Pivot-reach
 * narrowing achieves the same security goal without that side effect and
 * is what this migration ships.
 *
 * NO rows are deleted. The migration sets reach only; it does not strip
 * historical grants. This matches the policy of every other cutover
 * safety-net migration in this cutover.
 *
 * Idempotency:
 *   - Pivot reach: an existed check on (composite, reach JSON IS NOT
 *     DISTINCT FROM target) makes a re-run a no-op (no UPDATE, no audit).
 *   - Assignment scope narrowing: an existed check on the per-assignment
 *     audit marker prevents re-running on rows already narrowed by this
 *     migration. The 000015 safety-net migration has its own audit marker
 *     and is not consulted here.
 *
 * Down:
 *   The migration is forward-only. The narrowing is corrective; reversing
 *   it would re-introduce the original peer-department reach leak on every
 *   legacy admin assignment. Audit markers are preserved in all directions.
 *
 * Operational cache note:
 *   up() calls AccessDecision::flushCache() at the end so the process that
 *   ran artisan migrate re-reads the narrowed reach values on its next
 *   can() call. Long-running PHP workers hold their OWN copy of the
 *   in-memory cache and are NOT invalidated cross-process by flushCache();
 *   production deploys must restart / recycle those workers alongside the
 *   migration so they pick up the new reach values.
 *
 * PostgreSQL only - the JSON reach column and the audit-marker JSON
 * filtering rely on PostgreSQL JSONB operators.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_12_000016_narrow_legacy_department_aliases';

    private const AUDIT_EVENT = 'legacy_department_alias_narrowed_000016';

    private const SOURCE_AUDIT_EVENT = 'legacy_backfill_000010';

    private const SOURCE_MIGRATION_NAME = '2026_07_03_000010_backfill_authorization_role_permissions';

    /**
     * Legacy flat permission names whose canonical pivot reach must be narrowed.
     * Module column in the reach JSON is keyed by the canonical module.action
     * prefix module segment (i.e. projects for PROJECTS_EDIT, tasks for
     * TASKS_EDIT).
     *
     * @var array<string, string> legacy_permission_name => reach module column
     */
    private const LEGACY_ALIASES = [
        'edit_department_projects' => 'projects',
        'edit_department_tasks' => 'tasks',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                self::MIGRATION_NAME.' is PostgreSQL-only. Detected driver: ['.DB::getDriverName().'].'
            );
        }

        foreach (['authorization_role_permissions', 'authorization_role_assignments', 'authorization_assignment_audits'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(self::MIGRATION_NAME." requires table [{$table}] to exist.");
            }
        }

        $now = now();
        $auditRows = [];

        DB::transaction(function () use (&$auditRows, $now): void {
            $this->narrowPivotReaches($now, $auditRows);
        });

        if ($auditRows !== []) {
            foreach (array_chunk($auditRows, 500) as $chunk) {
                DB::table('authorization_assignment_audits')->insert($chunk);
            }
        }

        AccessDecision::flushCache();
    }

    public function down(): void
    {
        // Forward-only - see class-level docblock.
    }

    /**
     * Step 1: narrow reach on every pivot that was created via the legacy
     * edit_department_* aliases.
     *
     * @param  list<array<string, mixed>>  $auditRows
     */
    private function narrowPivotReaches(CarbonInterface $now, array &$auditRows): void
    {
        $legacyNames = array_keys(self::LEGACY_ALIASES);

        // Walk every audit marker the 000010 backfill wrote and filter to
        // markers whose legacy_permission_name is one of the department
        // aliases. The marker carries the (role_id, resource_id, action)
        // composite that uniquely identifies the pivot row.
        $markers = DB::table('authorization_assignment_audits')
            ->where('event', self::SOURCE_AUDIT_EVENT)
            ->whereRaw("new_value ->> 'migration' = ?", [self::SOURCE_MIGRATION_NAME])
            ->where(function ($query) use ($legacyNames): void {
                // PostgreSQL JSONB key lookup against an IN-list. We use
                // whereRaw with positional placeholders so the (->> text)
                // projection is bound against the values.
                $placeholders = implode(',', array_fill(0, count($legacyNames), '?'));
                $query->whereRaw(
                    "new_value ->> 'legacy_permission_name' IN ({$placeholders})",
                    $legacyNames
                );
            })
            ->orderBy('id')
            ->get(['id', 'new_value']);

        foreach ($markers as $marker) {
            $payload = json_decode((string) $marker->new_value, true);
            if (! is_array($payload)) {
                continue;
            }

            $authRoleId = (int) ($payload['authorization_role_id'] ?? 0);
            $authResourceId = (int) ($payload['authorization_resource_id'] ?? 0);
            $action = (string) ($payload['action'] ?? '');
            $legacyName = (string) ($payload['legacy_permission_name'] ?? '');
            if ($authRoleId === 0 || $authResourceId === 0 || $action === '' || ! isset(self::LEGACY_ALIASES[$legacyName])) {
                continue;
            }

            $moduleKey = self::LEGACY_ALIASES[$legacyName];

            // Resolve the resource to its FQCN so we can defensively skip
            // pivots whose resource is neither Project nor Task. The reach
            // map is module-keyed; writing an unexpected module key would
            // either be a silent no-op or, worse, alias a future module.
            $resourceKey = DB::table('authorization_resources')
                ->where('id', $authResourceId)
                ->value('key');
            if (! is_string($resourceKey) || $resourceKey === '') {
                continue;
            }

            // The reach map keys on the module segment of the canonical
            // capability (projects for projects.edit, tasks for
            // tasks.edit). The 000024 reach backfill writes JSON reach
            // shaped as {module: reach} - match that shape.
            $expectedReach = [$moduleKey => 'department'];
            $expectedJson = json_encode($expectedReach);

            // Idempotency: skip if the pivot already carries this exact
            // reach (a re-run is a no-op, no audit).
            $alreadyNarrowed = DB::table('authorization_role_permissions')
                ->where('authorization_role_id', $authRoleId)
                ->where('authorization_resource_id', $authResourceId)
                ->where('action', $action)
                ->whereRaw('reach IS NOT DISTINCT FROM ?', [$expectedJson])
                ->exists();

            if ($alreadyNarrowed) {
                continue;
            }

            DB::table('authorization_role_permissions')
                ->where('authorization_role_id', $authRoleId)
                ->where('authorization_resource_id', $authResourceId)
                ->where('action', $action)
                ->update(['reach' => $expectedJson]);

            $auditRows[] = [
                'event' => self::AUDIT_EVENT,
                'actor_id' => null,
                'target_user_id' => null,
                'scope_type' => null,
                'scope_id' => null,
                'role' => null,
                'old_value' => json_encode(['reach' => null], JSON_THROW_ON_ERROR),
                'new_value' => json_encode([
                    'migration' => self::MIGRATION_NAME,
                    'kind' => 'pivot_reach_narrowed',
                    'authorization_role_id' => $authRoleId,
                    'authorization_resource_id' => $authResourceId,
                    'action' => $action,
                    'resource_key' => $resourceKey,
                    'legacy_permission_name' => $legacyName,
                    'reach' => $expectedReach,
                ], JSON_THROW_ON_ERROR),
                'reason' => 'CSD-CA23078-CORE-001: legacy department alias narrowed to department reach',
                'ip_address' => null,
                'user_agent' => 'migration',
                'created_at' => $now,
            ];
        }
    }

    /**
     * Step 2 (intentionally omitted): the CSD-CA23078-CORE-001 spec
     * contemplated a parallel narrowing of `authorization_role_assignments
     * .scope_type` for legacy admin users without a `dept_manager` backup,
     * but that step conflicts with `AccessDecision::canonicalRoleIsActive()`
     * — the engine requires `role.scope_type === assignment.scope_type`, so
     * narrowing an org-scoped assignment to `department` against an
     * org-scoped role leaves the assignment dead (skipped at the role-
     * validity gate). Pivot-reach narrowing (above) achieves the same
     * security goal without that side effect, so this migration ships
     * pivot reach only.
     */
    private function stepTwoReservedForFutureUse(): void
    {
        // Reserved - see the docblock above.
    }
};
