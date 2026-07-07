<?php

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.1.2 -- additive backfill from legacy `model_has_scoped_roles`
 * onto the new `authorization_roles` + `authorization_role_permissions`
 * + `authorization_role_assignments` chain.
 *
 * For every row in `model_has_scoped_roles` whose
 *   (scope_type, role) resolves to a `scoped_role_definitions` row
 *   whose `permissions[]` contains at least one capability that
 *   `CapabilityToAuthorizationRolePermission::map()` knows about,
 * this migration materializes:
 *
 *   - an `authorization_roles` row named after the legacy role key
 *     (one per distinct role key across the seeded set);
 *   - one `authorization_role_permissions` row per capability in the
 *     legacy definition's `permissions[]` (resolved through the
 *     Capability mapper to (resource FQCN, action));
 *   - one `authorization_role_assignments` row carrying the legacy
 *     row's real (scope_type, scope_id) plus a resolved organization_id
 *     and the original user_id.
 *
 * The scope_type values written include the legacy scoped types
 * (project, program, portfolio, kpi, meeting, survey) in addition to
 * the Phase 1 set, which is why migration 000020 (the CHECK relaxation)
 * is a hard prerequisite and must run before this one.
 *
 * Audit markers:
 *   - One `permission_audits` row per materialization (assignment +
 *     role permission + role catalog row the migration created), with
 *     `event = legacy_scoped_backfill_000021` and a JSON `new_value`
 *     payload carrying the migration tag, the legacy `model_has_scoped_roles`
 *     row id, the legacy source (auto/manual), the new assignment id,
 *     and the new (role_id, resource_id, action) composite. The audit
 *     marker shape matches the pattern Phase 2.1.1 / 000010 uses so a
 *     single down() can find the rows it wrote.
 *
 * Skip + audit (no widening):
 *   - Legacy rows whose (scope_type, role) does NOT resolve to a
 *     scoped_role_definitions row are SKIPPED -- they get a
 *     `reason: unmappable` audit marker and NO assignment / role
 *     permission / role catalog row. No silent widening.
 *   - Legacy rows whose definition's `permissions[]` contains NO
 *     capability the mapper can resolve are also SKIPPED with the
 *     same audit shape. They cannot widen the catalog.
 *   - Legacy rows whose `scope_id` IS NULL on a scope_type other
 *     than 'all' or 'own' are SKIPPED. The CHECK constraint added
 *     by migration 000020 forbids inserting an
 *     authorization_role_assignments row with scope_id=NULL for any
 *     scope_type except 'all'/'own', so without this defensive check
 *     the migration would crash. Such rows get a
 *     `reason: unmappable_null_scope_id_for_scope_type` audit marker
 *     and NO assignment / role permission / role catalog row, so
 *     operators can identify and clean them up post-migration.
 *   - A pre-existing authorization_role_permissions / assignment row
 *     (composite key match) is detected and left alone: the migration
 *     does not duplicate it and does not write a new audit marker
 *     for it. This is the same "existed check" pattern 000010 uses.
 *
 * down():
 *   Reads only the audit markers this migration wrote (event discriminator
 *   + migration tag in new_value) and deletes:
 *     - the audit markers themselves;
 *     - the authorization_role_assignments rows they reference;
 *     - the authorization_role_permissions rows that were created
 *       alongside them (and ONLY those -- pre-existing pivots are
 *       never deleted, the same way 000010 protects Phase 1 super_admin
 *       pivot rows).
 *   It does NOT delete authorization_roles, authorization_resources,
 *   scoped_role_definitions, scoped_roles, model_has_scoped_roles, or
 *   any legacy Spatie table. Safe to run twice: the second down() finds
 *   no audit markers and is a no-op.
 *
 * Safe to run twice: up()'s existed-check on (role_name, resource_key,
 * action) plus the (authorization_role_id, user_id, scope_type, scope_id)
 * partial unique index dedupe assignment INSERTs, so a second up() is
 * a no-op. The second up() also does not write a new audit marker.
 *
 * PostgreSQL only.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_04_000021_backfill_scoped_roles_full_semantics';

    private const AUDIT_EVENT = 'legacy_scoped_backfill_000021';

    private const AUDIT_REASON = 'Phase 2.1.2 full-semantics scoped-role backfill';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                self::MIGRATION_NAME.' is PostgreSQL-only. Detected driver: ['.DB::getDriverName().'].'
            );
        }

        // Ensure the inherit_to_children column exists BEFORE this migration
        // materializes assignment rows. The legacy
        // model_has_scoped_roles.inherit_to_children boolean must be
        // preserved on every new authorization_role_assignments row so a
        // legacy false does not silently widen onto descendant departments.
        //
        // 000021 sorts BEFORE the dedicated column-add migration 000022,
        // so we cannot rely on 000022 having run. Add the column here
        // (idempotent hasColumn guard) -- 000022's up() also adds the
        // column and will be a no-op when this guard already ran.
        //
        // The column is added as nullable here; 000022 promotes it to
        // NOT NULL after running its safety-net backfill. Operators do
        // NOT need to apply 000022 before 000021.
        if (Schema::hasTable('authorization_role_assignments')
            && ! Schema::hasColumn('authorization_role_assignments', 'inherit_to_children')) {
            Schema::table('authorization_role_assignments', function (Blueprint $table) {
                // DEFAULT true preserves Phase 1 semantics for any rows
                // that predate this column; the backfill below writes the
                // column directly for rows it creates.
                $table->boolean('inherit_to_children')->nullable()->default(true)->after('organization_id');
            });
        }

        // Read every legacy row in one query, joined to its definition so
        // the per-row loop has the (scope_type, role, permissions[], id)
        // tuple it needs without N+1 lookups. LEFT JOIN: rows whose
        // (scope_type, role) does NOT resolve to a definition appear
        // with definition_id NULL and an empty permissions[] -- the
        // mapper will skip them as unmappable.
        $rows = DB::table('model_has_scoped_roles as msr')
            ->leftJoin('scoped_role_definitions as srd', function ($join) {
                $join->on('srd.name', '=', DB::raw("msr.scope_type || '.' || msr.role"))
                    ->orOn('srd.role_key', '=', 'msr.role');
            })
            ->select(
                'msr.id as scoped_role_id',
                'msr.user_id',
                'msr.role',
                'msr.scope_type',
                'msr.scope_id',
                'msr.inherit_to_children',
                'msr.source as legacy_source',
                'srd.id as definition_id',
                'srd.permissions as definition_permissions',
                'srd.name as definition_name',
            )
            ->orderBy('msr.id')
            ->get();

        if ($rows->isEmpty()) {
            AccessDecision::flushCache();

            return;
        }

        $auditRows = [];
        $now = now();
        $createdAssignmentIds = [];

        DB::transaction(function () use ($rows, &$auditRows, &$createdAssignmentIds, $now) {
            foreach ($rows as $row) {
                $row = (array) $row;

                // 1. Definition absent => skip + audit.
                if (empty($row['definition_id'])) {
                    $auditRows[] = $this->skippedAuditRow($row, 'unmappable: no matching scoped_role_definitions row', $now);

                    continue;
                }

                // 1b. Legacy row with NULL scope_id on a scope_type whose
                //     CHECK constraint forbids it. Migration 000020
                //     enforces that scope_id IS NOT NULL for every
                //     scope_type except 'all' and 'own'. A legacy row
                //     whose scope_id was lost (legacy bug, partial
                //     rollback, schema variant) cannot be backfilled --
                //     skip + audit, do not crash. The same check is
                //     exercised defensively for 'all' and 'own' too,
                //     since the resolver expects NULL there but the
                //     migration only materializes assignments for
                //     scope_types that map to a real scope row.
                if ($row['scope_id'] === null && ! in_array((string) $row['scope_type'], ['all', 'own'], true)) {
                    $auditRows[] = $this->skippedAuditRow($row, 'unmappable_null_scope_id_for_scope_type', $now);

                    continue;
                }

                $permissions = $this->decodePermissions($row['definition_permissions']);
                if ($permissions === []) {
                    $auditRows[] = $this->skippedAuditRow($row, 'unmappable: scoped_role_definitions.permissions is empty', $now);

                    continue;
                }

                // 2. Resolve every capability in the definition's
                //    permissions[] through the Phase 1 mapper. A row
                //    whose permissions[] contains ZERO mappable
                //    capabilities is unmappable and is skipped.
                $mapped = [];
                foreach ($permissions as $capability) {
                    $map = CapabilityToAuthorizationRolePermission::map($capability);
                    if ($map === null) {
                        continue;
                    }
                    $mapped[$map['resource'].'|'.$map['action']] = $map;
                }
                if ($mapped === []) {
                    $auditRows[] = $this->skippedAuditRow($row, 'unmappable: no mappable capability in scoped_role_definitions.permissions', $now);

                    continue;
                }

                // 3. Materialize the authorization_roles row by legacy
                //    role name. firstOrCreate preserves any richer label
                //    a Phase 1 row may already carry.
                $authRole = AuthorizationRole::firstOrCreate(
                    ['name' => $row['role']],
                    ['label' => $this->humanLabel($row['role'])],
                );

                // 4. Per mapped capability, materialize the resource
                //    (if missing) and the (role, resource, action)
                //    pivot. Audit only NEWLY created pivots so down()
                //    can find them.
                $createdRolePermissions = [];
                foreach ($mapped as $compositeKey => $map) {
                    [$resourceKey, $action] = explode('|', $compositeKey, 2);

                    $authResource = AuthorizationResource::firstOrCreate(
                        ['key' => $resourceKey],
                        ['label' => $this->shortName($resourceKey)],
                    );

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

                    $createdRolePermissions[] = [
                        'authorization_role_id' => (int) $authRole->id,
                        'authorization_resource_id' => (int) $authResource->id,
                        'action' => $action,
                        'capability' => $this->findCapabilityForComposite($permissions, $resourceKey, $action),
                    ];
                }

                // 5. Resolve the target scope row so we can derive an
                //    organization_id. organization_id is denormalized
                //    for query convenience (Phase 1 index
                //    authorization_role_assignments_organization_id_index
                //    is partial WHERE organization_id IS NOT NULL). When
                //    we can resolve org, fill it; otherwise leave NULL
                //    and the new-path query plan still works because the
                //    scope_type / scope_id pair drives the join.
                $organizationId = $this->resolveOrganizationIdForScope(
                    (string) $row['scope_type'],
                    $row['scope_id'] !== null ? (int) $row['scope_id'] : null,
                );

                $existedAssignment = DB::table('authorization_role_assignments')
                    ->where('authorization_role_id', $authRole->id)
                    ->where('user_id', $row['user_id'])
                    ->where('scope_type', $row['scope_type'])
                    ->where('scope_id', $row['scope_id'])
                    ->exists();

                if ($existedAssignment) {
                    // Pre-existing assignment -- not this migration's
                    // creation, not audit-marked, not deleted by down().
                    continue;
                }

                $assignmentId = DB::table('authorization_role_assignments')->insertGetId([
                    'authorization_role_id' => (int) $authRole->id,
                    'user_id' => (int) $row['user_id'],
                    'scope_type' => (string) $row['scope_type'],
                    'scope_id' => $row['scope_id'] !== null ? (int) $row['scope_id'] : null,
                    'organization_id' => $organizationId,
                    // Persist the legacy inherit_to_children flag. The
                    // column is ensured to exist by this migration's up()
                    // (see the hasColumn guard above) before any INSERT
                    // runs, so a legacy `false` survives the backfill.
                    'inherit_to_children' => (bool) ($row['inherit_to_children'] ?? true),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $createdAssignmentIds[] = $assignmentId;

                $auditRows[] = [
                    'event' => self::AUDIT_EVENT,
                    'actor_id' => null,
                    'target_user_id' => (int) $row['user_id'],
                    'scope_type' => (string) $row['scope_type'],
                    'scope_id' => $row['scope_id'] !== null ? (int) $row['scope_id'] : null,
                    'role' => (string) $row['role'],
                    'old_value' => null,
                    'new_value' => json_encode([
                        'migration' => self::MIGRATION_NAME,
                        'source_scoped_role_id' => (int) $row['scoped_role_id'],
                        'source' => (string) ($row['legacy_source'] ?? 'manual'),
                        'new_authorization_role_assignment_id' => (int) $assignmentId,
                        'authorization_role_id' => (int) $authRole->id,
                        'authorization_role_name' => (string) $row['role'],
                        'scope_type' => (string) $row['scope_type'],
                        'scope_id' => $row['scope_id'] !== null ? (int) $row['scope_id'] : null,
                        'organization_id' => $organizationId,
                        'inherit_to_children' => (bool) $row['inherit_to_children'],
                        'created_role_permissions' => $createdRolePermissions,
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
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // If this migration added the inherit_to_children column (because
        // 000022 had not run yet), drop it on the way back down. The
        // hasColumn guard makes this safe when 000022 owned the column add
        // -- the column will still exist (because 000022 ran first), and
        // 000022's down() handles the drop in that scenario.
        if (Schema::hasTable('authorization_role_assignments')
            && Schema::hasColumn('authorization_role_assignments', 'inherit_to_children')
            && ! $this->columnOwnedBy000022()) {
            Schema::table('authorization_role_assignments', function (Blueprint $table) {
                $table->dropColumn('inherit_to_children');
            });
        }

        $auditRows = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->get();

        if ($auditRows->isEmpty()) {
            AccessDecision::flushCache();

            return;
        }

        $assignmentDeletes = [];
        $rolePermissionDeletes = [];
        $auditIdsToDelete = [];

        foreach ($auditRows as $auditRow) {
            $newValue = json_decode($auditRow->new_value, true);
            if (! is_array($newValue)) {
                continue;
            }
            if (($newValue['migration'] ?? null) !== self::MIGRATION_NAME) {
                continue;
            }

            $assignmentId = $newValue['new_authorization_role_assignment_id'] ?? null;
            if ($assignmentId !== null) {
                $assignmentDeletes[] = (int) $assignmentId;
            }

            foreach ($newValue['created_role_permissions'] ?? [] as $pivot) {
                $rolePermissionDeletes[] = [
                    'authorization_role_id' => (int) $pivot['authorization_role_id'],
                    'authorization_resource_id' => (int) $pivot['authorization_resource_id'],
                    'action' => (string) $pivot['action'],
                ];
            }

            $auditIdsToDelete[] = (int) $auditRow->id;
        }

        DB::transaction(function () use ($assignmentDeletes, $rolePermissionDeletes, $auditIdsToDelete) {
            if ($assignmentDeletes !== []) {
                DB::table('authorization_role_assignments')
                    ->whereIn('id', $assignmentDeletes)
                    ->delete();
            }

            // role_permission pivots are composite-keyed; delete by exact
            // (role, resource, action). Pre-existing pivots (from a
            // Phase 1 / 2.1.1 / future backfill run) carry a different
            // audit row OR no audit row at all, so they are NOT in
            // $rolePermissionDeletes and survive the rollback.
            foreach ($rolePermissionDeletes as $key) {
                DB::table('authorization_role_permissions')
                    ->where('authorization_role_id', $key['authorization_role_id'])
                    ->where('authorization_resource_id', $key['authorization_resource_id'])
                    ->where('action', $key['action'])
                    ->delete();
            }

            if ($auditIdsToDelete !== []) {
                DB::table('permission_audits')
                    ->whereIn('id', $auditIdsToDelete)
                    ->delete();
            }
        });

        AccessDecision::flushCache();
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * Was the inherit_to_children column added by migration 000022 (rather
     * than this migration)? Used by down() to decide whether to drop the
     * column -- when 000022 owned the column add, its own down() drops
     * the column and we must NOT drop it again here (the schema_change_log
     * would double-drop and either error or be a silent no-op).
     *
     * Heuristic: 000022 sets the column to NOT NULL after adding it.
     * This migration adds the column as nullable and lets 000022 promote
     * it. If the column is NOT NULL on disk, 000022 must have been the
     * final owner (it ran AFTER this migration); if it is nullable,
     * 000022 has not promoted it yet (only this migration has touched it).
     */
    private function columnOwnedBy000022(): bool
    {
        if (! Schema::hasTable('authorization_role_assignments')
            || ! Schema::hasColumn('authorization_role_assignments', 'inherit_to_children')) {
            return false;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        $row = DB::selectOne(
            'SELECT is_nullable FROM information_schema.columns '
            ."WHERE table_name = 'authorization_role_assignments' "
            ."AND column_name = 'inherit_to_children'"
        );

        return $row !== null && strtoupper((string) $row->is_nullable) === 'NO';
    }

    /**
     * Decode the JSON-cast `scoped_role_definitions.permissions` column
     * into a list of capability strings. Returns an empty list when
     * the column is null or not an array (defensive against bad data
     * left over from older migrations).
     *
     * @return list<string>
     */
    private function decodePermissions(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, fn ($v) => is_string($v) && $v !== ''));
    }

    /**
     * Walk a legacy definition's permission list to find the
     * Capability string that produced the (resource, action) pair.
     * Best-effort: returns the first matching capability so the audit
     * marker records something human-readable; if the mapper rewrote
     * a capability (e.g. none in this slice) the audit marker just
     * says "unknown_capability" and the row is still traceable via
     * the composite key.
     *
     * @param  list<string>  $permissions
     */
    private function findCapabilityForComposite(array $permissions, string $resourceKey, string $action): string
    {
        foreach ($permissions as $capability) {
            $map = CapabilityToAuthorizationRolePermission::map($capability);
            if ($map !== null && $map['resource'] === $resourceKey && $map['action'] === $action) {
                return $capability;
            }
        }

        return 'unknown_capability';
    }

    /**
     * Resolve the organization_id of a (scope_type, scope_id) pair. The
     * scope row lives in a module-specific table; we look it up
     * conditionally and return null when the row is missing (the
     * backfill still writes a valid scope_type / scope_id pair on the
     * assignment, the engine fills in organization_id at query time).
     */
    private function resolveOrganizationIdForScope(string $scopeType, ?int $scopeId): ?int
    {
        if ($scopeId === null) {
            return null;
        }

        $table = match ($scopeType) {
            'organization' => 'organizations',
            'department' => 'departments',
            'project' => 'projects',
            'program' => 'programs',
            'portfolio' => 'portfolios',
            'kpi' => 'kpis',
            'meeting' => 'meetings',
            'survey' => 'surveys',
            default => null,
        };

        if ($table === null) {
            return null;
        }

        $row = DB::table($table)->where('id', $scopeId)->first(['organization_id']);

        return $row?->organization_id !== null ? (int) $row->organization_id : null;
    }

    /**
     * Audit row shape for a SKIPPED legacy row. Carries the same
     * composite markers the materialized branch uses, so down()'s
     * audit-marker scan is uniform; the `reason` field explains the
     * skip. The new assignment id is null because no assignment was
     * created.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function skippedAuditRow(array $row, string $reason, CarbonInterface $now): array
    {
        return [
            'event' => self::AUDIT_EVENT,
            'actor_id' => null,
            'target_user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'scope_type' => isset($row['scope_type']) ? (string) $row['scope_type'] : null,
            'scope_id' => isset($row['scope_id']) && $row['scope_id'] !== null ? (int) $row['scope_id'] : null,
            'role' => isset($row['role']) ? (string) $row['role'] : null,
            'old_value' => null,
            'new_value' => json_encode([
                'migration' => self::MIGRATION_NAME,
                'source_scoped_role_id' => isset($row['scoped_role_id']) ? (int) $row['scoped_role_id'] : null,
                'source' => (string) ($row['legacy_source'] ?? 'manual'),
                'new_authorization_role_assignment_id' => null,
                'authorization_role_id' => null,
                'authorization_role_name' => isset($row['role']) ? (string) $row['role'] : null,
                'scope_type' => isset($row['scope_type']) ? (string) $row['scope_type'] : null,
                'scope_id' => isset($row['scope_id']) && $row['scope_id'] !== null ? (int) $row['scope_id'] : null,
                'reason' => $reason,
            ]),
            'reason' => $reason,
            'ip_address' => null,
            'user_agent' => 'migration',
            'created_at' => $now,
        ];
    }

    /**
     * Derive a readable label for an authorization_roles row from a
     * legacy scoped role name (same humanLabel convention 000010 uses).
     */
    private function humanLabel(string $roleName): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $roleName));
    }

    /**
     * Derive a short resource label from a model FQCN.
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
