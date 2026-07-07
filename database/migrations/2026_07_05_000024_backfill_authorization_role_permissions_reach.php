<?php

use App\Modules\Core\Authorization\AccessDecision;
use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.1.3 -- additive backfill from legacy
 * `scoped_role_definitions.reach` onto the new
 * `authorization_role_permissions.reach` JSONB column.
 *
 * Background:
 *   Migration 2026_07_05_000023 added the per-pivot `reach`
 *   column. Before this migration runs, every pivot the 000021
 *   backfill materialized is NULL on `reach`, which means the
 *   new-path reach check has nothing to consult and falls
 *   through to "no cap" -- surfacing every legacy
 *   `reach = own / department` grant as a SHADOW mismatch.
 *
 *   This migration reads the 000021 audit markers, resolves the
 *   source legacy `scoped_role_definitions.reach` for each
 *   created pivot, and writes the per-pivot reach.
 *
 * Audit marker linkage (the 000021 shape):
 *   For every row the 000021 backfill materialized, the audit
 *   marker carries:
 *     - `event = legacy_scoped_backfill_000021`
 *     - `new_value.source_scoped_role_id` -> the legacy
 *       `model_has_scoped_roles.id` we should resolve.
 *     - `new_value.created_role_permissions` -> a list of
 *       composites with `authorization_role_id`,
 *       `authorization_resource_id`, `action`, and `capability`.
 *
 * Reach semantics (mirrors `scoped_role_definitions.reach`):
 *   - The pivot's `reach` is set to the FULL legacy reach JSON
 *     (e.g. own on projects, department on tasks), not just
 *     the module of the original capability. This preserves the
 *     legacy definition's reach map exactly.
 *   - Legacy `reach IS NULL` -> the pivot's reach is written as
 *     NULL too (no fake "all" default). The engine treats NULL
 *     as "no cap on this row" and falls back to the legacy
 *     definition read in the SHADOW path.
 *   - Legacy reach is malformed (not a JSON object, or contains
 *     an invalid value) -> SKIP + audit, do NOT write to the new
 *     column. This is the no-widening path: a bad legacy value
 *     must not silently widen onto the new path as 'all'.
 *
 * Audit markers (this migration's shape):
 *   - One `permission_audits` row per write
 *     (`reason = reach_backfilled`) AND one per skip
 *     (`reason = malformed_legacy_reach`). The `event`
 *     discriminator is `legacy_reach_backfill_000024`.
 *   - down() reads only its own audit markers, resets every
 *     referenced pivot's `reach` to NULL, and deletes the audit
 *     markers themselves. The 000021 markers and any rows
 *     pre-existing this migration are NOT touched.
 *
 * Safe to run twice: up()'s existed check is on the pivot
 * composite (role, resource, action) + reach value pair. A
 * second up() finds the reach value already in place and writes
 * a no-op UPDATE that does not produce a new audit marker.
 *
 * Operational cache note:
 *   up() / down() call `AccessDecision::flushCache()` to drop the
 *   in-process role-permissions memoization held by the PHP process
 *   that ran `artisan migrate`. That single process re-reads the
 *   freshly-written reach values on its next `can()` call.
 *
 *   Long-running PHP workers (queue listeners, Horizon supervisors,
 *   scheduler daemons) hold their OWN copy of the in-memory cache
 *   and are NOT invalidated cross-process by `flushCache()`. They
 *   will continue serving the pre-migration reach (NULL or the old
 *   value) for any user whose assignment this migration touched
 *   until their own request finishes. Production deploys must
 *   restart / recycle those workers normally alongside the
 *   migration so they pick up the new reach values; the model
 *   hooks that normally invalidate the cache on a write do not
 *   fire from a raw `DB::table()` update inside a migration, so do
 *   not rely on them here.
 *
 * PostgreSQL only.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_05_000024_backfill_authorization_role_permissions_reach';

    private const AUDIT_EVENT = 'legacy_reach_backfill_000024';

    private const SOURCE_AUDIT_EVENT = 'legacy_scoped_backfill_000021';

    private const AUDIT_REASON_WRITTEN = 'reach_backfilled';

    private const AUDIT_REASON_SKIPPED = 'malformed_legacy_reach';

    /**
     * The set of values reach may legally hold. Any other value
     * (e.g. 'nope', 'team', 'cluster') is malformed and triggers
     * skip+audit instead of a silent 'all' default.
     */
    private const VALID_REACH_VALUES = ['own', 'department', 'all'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                self::MIGRATION_NAME.' is PostgreSQL-only. Detected driver: ['.DB::getDriverName().'].'
            );
        }

        $hasPivots = DB::selectOne(
            "SELECT 1 FROM information_schema.tables WHERE table_name = 'authorization_role_permissions'"
        );
        if ($hasPivots === null) {
            throw new RuntimeException(
                self::MIGRATION_NAME.' requires authorization_role_permissions '
                .'(added by 2026_07_03_000004).'
            );
        }

        $hasReachColumn = DB::selectOne(
            'SELECT 1 FROM information_schema.columns '
            ."WHERE table_name = 'authorization_role_permissions' AND column_name = 'reach'"
        );
        if ($hasReachColumn === null) {
            throw new RuntimeException(
                self::MIGRATION_NAME.' requires the authorization_role_permissions.reach '
                .'column (added by 2026_07_05_000023).'
            );
        }

        $sourceMarkers = DB::table('permission_audits')
            ->where('event', self::SOURCE_AUDIT_EVENT)
            ->orderBy('id')
            ->get();

        if ($sourceMarkers->isEmpty()) {
            AccessDecision::flushCache();

            return;
        }

        $auditRows = [];
        $now = now();

        DB::transaction(function () use ($sourceMarkers, &$auditRows, $now) {
            foreach ($sourceMarkers as $sourceMarker) {
                $sourceValue = json_decode((string) $sourceMarker->new_value, true);
                if (! is_array($sourceValue)) {
                    continue;
                }
                if (($sourceValue['migration'] ?? null) !== '2026_07_04_000021_backfill_scoped_roles_full_semantics') {
                    continue;
                }

                $sourceScopedRoleId = $sourceValue['source_scoped_role_id'] ?? null;
                if ($sourceScopedRoleId === null) {
                    continue;
                }

                $legacyRow = DB::table('model_has_scoped_roles')
                    ->where('id', (int) $sourceScopedRoleId)
                    ->first();
                if ($legacyRow === null) {
                    continue;
                }

                $legacyReach = $this->loadLegacyDefinitionReach(
                    (string) $legacyRow->scope_type,
                    (string) $legacyRow->role
                );

                $createdRolePermissions = $sourceValue['created_role_permissions'] ?? [];
                if (! is_array($createdRolePermissions) || $createdRolePermissions === []) {
                    continue;
                }

                foreach ($createdRolePermissions as $pivot) {
                    $composite = [
                        'authorization_role_id' => (int) ($pivot['authorization_role_id'] ?? 0),
                        'authorization_resource_id' => (int) ($pivot['authorization_resource_id'] ?? 0),
                        'action' => (string) ($pivot['action'] ?? ''),
                    ];
                    if ($composite['authorization_role_id'] === 0
                        || $composite['authorization_resource_id'] === 0
                        || $composite['action'] === '') {
                        continue;
                    }

                    $capability = (string) ($pivot['capability'] ?? '');
                    if ($capability === '' || $capability === 'unknown_capability') {
                        $auditRows[] = $this->skippedAuditRow(
                            $composite,
                            (int) $sourceScopedRoleId,
                            $capability,
                            'missing_capability_for_module_lookup',
                            $now
                        );

                        continue;
                    }

                    $validation = $this->validateLegacyReach($legacyReach);

                    if ($validation['valid'] === false) {
                        $auditRows[] = $this->skippedAuditRow(
                            $composite,
                            (int) $sourceScopedRoleId,
                            $capability,
                            $validation['reason'],
                            $now
                        );

                        continue;
                    }

                    $reachToWrite = $validation['normalized_reach']; // array|null

                    // Existed check: skip the UPDATE if the pivot
                    // already carries the same reach value. This
                    // makes up() idempotent: a second up() finds
                    // the value in place and writes no new marker.
                    $expectedJson = $reachToWrite === null
                        ? null
                        : json_encode($reachToWrite);

                    $existed = DB::table('authorization_role_permissions')
                        ->where('authorization_role_id', $composite['authorization_role_id'])
                        ->where('authorization_resource_id', $composite['authorization_resource_id'])
                        ->where('action', $composite['action'])
                        ->whereRaw('reach IS NOT DISTINCT FROM ?', [$expectedJson])
                        ->exists();

                    if ($existed) {
                        continue;
                    }

                    DB::table('authorization_role_permissions')
                        ->where('authorization_role_id', $composite['authorization_role_id'])
                        ->where('authorization_resource_id', $composite['authorization_resource_id'])
                        ->where('action', $composite['action'])
                        ->update(['reach' => $expectedJson]);

                    $auditRows[] = [
                        'event' => self::AUDIT_EVENT,
                        'actor_id' => null,
                        'target_user_id' => $sourceMarker->target_user_id !== null
                            ? (int) $sourceMarker->target_user_id
                            : null,
                        'scope_type' => $sourceMarker->scope_type,
                        'scope_id' => $sourceMarker->scope_id !== null
                            ? (int) $sourceMarker->scope_id
                            : null,
                        'role' => $sourceMarker->role,
                        'old_value' => null,
                        'new_value' => json_encode([
                            'migration' => self::MIGRATION_NAME,
                            'source_scoped_role_id' => (int) $sourceScopedRoleId,
                            'authorization_role_id' => $composite['authorization_role_id'],
                            'authorization_resource_id' => $composite['authorization_resource_id'],
                            'action' => $composite['action'],
                            'capability' => $capability,
                            'reach' => $reachToWrite,
                        ]),
                        'reason' => self::AUDIT_REASON_WRITTEN,
                        'ip_address' => null,
                        'user_agent' => 'migration',
                        'created_at' => $now,
                    ];
                }
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

        $auditRows = DB::table('permission_audits')
            ->where('event', self::AUDIT_EVENT)
            ->orderBy('id')
            ->get();

        if ($auditRows->isEmpty()) {
            AccessDecision::flushCache();

            return;
        }

        $auditIdsToDelete = [];

        DB::transaction(function () use ($auditRows, &$auditIdsToDelete) {
            foreach ($auditRows as $auditRow) {
                $newValue = json_decode((string) $auditRow->new_value, true);
                if (! is_array($newValue)) {
                    $auditIdsToDelete[] = (int) $auditRow->id;

                    continue;
                }
                if (($newValue['migration'] ?? null) !== self::MIGRATION_NAME) {
                    continue;
                }

                $authRoleId = $newValue['authorization_role_id'] ?? null;
                $authResourceId = $newValue['authorization_resource_id'] ?? null;
                $action = $newValue['action'] ?? null;
                if ($authRoleId === null || $authResourceId === null || $action === null) {
                    $auditIdsToDelete[] = (int) $auditRow->id;

                    continue;
                }

                // Only reset the pivot if its current reach matches
                // the value this migration wrote. A pivot that
                // another migration has since rewritten (e.g. an
                // operator manually setting reach) is left alone.
                $expectedReach = $newValue['reach'] ?? null;
                $expectedJson = $expectedReach === null
                    ? null
                    : json_encode($expectedReach);

                DB::table('authorization_role_permissions')
                    ->where('authorization_role_id', (int) $authRoleId)
                    ->where('authorization_resource_id', (int) $authResourceId)
                    ->where('action', (string) $action)
                    ->whereRaw('reach IS NOT DISTINCT FROM ?', [$expectedJson])
                    ->update(['reach' => null]);

                $auditIdsToDelete[] = (int) $auditRow->id;
            }

            if ($auditIdsToDelete !== []) {
                DB::table('permission_audits')
                    ->whereIn('id', $auditIdsToDelete)
                    ->delete();
            }
        });

        AccessDecision::flushCache();
    }

    /**
     * Resolve the legacy `scoped_role_definitions.reach` for a
     * (scope_type, role) pair, using the same join the 000021
     * backfill uses. Returns the raw column value (NULL, a
     * string, or already-decoded) -- callers must re-validate
     * via validateLegacyReach() before writing.
     */
    private function loadLegacyDefinitionReach(string $scopeType, string $role): mixed
    {
        $definition = DB::table('scoped_role_definitions')
            ->where(function ($q) use ($scopeType, $role) {
                $q->where('name', $scopeType.'.'.$role)
                    ->orWhere('role_key', $role);
            })
            ->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [$scopeType.'.'.$role])
            ->first(['reach']);

        if ($definition === null) {
            return null;
        }

        return $definition->reach;
    }

    /**
     * Validate a legacy reach value (NULL, JSON string, or array)
     * and return a normalized form for writing.
     *
     * Validation rules:
     *   - NULL is valid (writes NULL onto the new column).
     *   - String is decoded as JSON. A non-object decoded value
     *     is invalid.
     *   - Each entry in the decoded object must be one of the
     *     three valid values (own, department, all). An entry
     *     with an invalid value rejects the whole object so a
     *     partial round-trip is not possible.
     *
     * @return array<string, mixed>
     */
    private function validateLegacyReach(mixed $legacyReach): array
    {
        if ($legacyReach === null) {
            return ['valid' => true, 'normalized_reach' => null];
        }

        $decoded = is_string($legacyReach) ? json_decode($legacyReach, true) : $legacyReach;

        if (! is_array($decoded)) {
            return [
                'valid' => false,
                'reason' => 'malformed_legacy_reach: not a JSON object',
                'normalized_reach' => null,
            ];
        }

        // Reject JSON arrays -- reach is always an object.
        if (array_is_list($decoded)) {
            return [
                'valid' => false,
                'reason' => 'malformed_legacy_reach: not a JSON object',
                'normalized_reach' => null,
            ];
        }

        $normalized = [];
        foreach ($decoded as $module => $value) {
            if (! is_string($module) || $module === '') {
                return [
                    'valid' => false,
                    'reason' => 'malformed_legacy_reach: non-string module key',
                    'normalized_reach' => null,
                ];
            }
            if (! is_string($value) || ! in_array($value, self::VALID_REACH_VALUES, true)) {
                return [
                    'valid' => false,
                    'reason' => 'malformed_legacy_reach: invalid reach value for module ['.$module.']',
                    'normalized_reach' => null,
                ];
            }
            $normalized[$module] = $value;
        }

        return ['valid' => true, 'normalized_reach' => $normalized];
    }

    /**
     * Build a skip audit row for a pivot whose legacy reach was
     * malformed (or whose capability could not be resolved). The
     * reason field carries the malformed_reason so operators can
     * see why a pivot was left at NULL reach.
     *
     * @param  array<string, int|string>  $composite
     * @return array<string, mixed>
     */
    private function skippedAuditRow(array $composite, int $sourceScopedRoleId, string $capability, string $reason, CarbonInterface $now): array
    {
        return [
            'event' => self::AUDIT_EVENT,
            'actor_id' => null,
            'target_user_id' => null,
            'scope_type' => null,
            'scope_id' => null,
            'role' => null,
            'old_value' => null,
            'new_value' => json_encode([
                'migration' => self::MIGRATION_NAME,
                'source_scoped_role_id' => $sourceScopedRoleId,
                'authorization_role_id' => $composite['authorization_role_id'],
                'authorization_resource_id' => $composite['authorization_resource_id'],
                'action' => $composite['action'],
                'capability' => $capability,
                'malformed_reason' => $reason,
            ]),
            'reason' => self::AUDIT_REASON_SKIPPED,
            'ip_address' => null,
            'user_agent' => 'migration',
            'created_at' => $now,
        ];
    }
};
