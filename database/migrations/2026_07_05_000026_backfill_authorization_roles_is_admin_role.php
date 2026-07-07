<?php

use App\Modules\Core\Authorization\AccessDecision;
use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.1.4a -- additive backfill of `is_admin_role` onto the unified
 * `authorization_roles` table.
 *
 * Background:
 *   The 000025 migration added `authorization_roles.is_admin_role` with
 *   DEFAULT false. Before this migration runs, every row is non-admin,
 *   which means the new-path admin shortcut in
 *   `AccessDecision::hasNewPermission` cannot fire. This migration reads
 *   the source-of-truth `scoped_role_definitions.is_admin_role` column
 *   keyed by `role_key` and copies it onto the matching
 *   `authorization_roles` row.
 *
 * Source matching:
 *   - For every `authorization_roles` row, look up the
 *     `scoped_role_definitions` row whose `role_key` equals
 *     `authorization_roles.name`.
 *   - The 000021 and 000010 backfills both keyed on the same name (the
 *     Spatie role name for 000010, the `model_has_scoped_roles.role`
 *     column for 000021), so matching by `role_key` reaches every role
 *     either backfill materialized.
 *
 * Audit markers (this migration's shape):
 *   - One `permission_audits` row per write
 *     (`reason = is_admin_role_backfilled`) AND one per skip
 *     (`reason = unmappable_is_admin_role`). The `event` discriminator
 *     is `legacy_is_admin_role_backfill_000026`.
 *   - down() reads only its own audit markers, resets every referenced
 *     role's `is_admin_role` to false, and deletes the audit markers
 *     themselves. Pre-existing roles (never visited by up()) are NOT
 *     touched.
 *
 * Skip + audit (no widening):
 *   - Roles whose `name` does NOT resolve to a `scoped_role_definitions`
 *     row are SKIPPED -- they get a `reason: unmappable_is_admin_role`
 *     audit marker and NO `is_admin_role` write. No silent widening.
 *   - Roles whose source `is_admin_role` is already false are ALSO
 *     skipped (no-op write) and the audit marker is suppressed --
 *     otherwise a 50-role seeding would emit 49 audit rows for the
 *     non-actionable skip case, drowning the actionable ones.
 *   - Multiple `scoped_role_definitions` rows that share the same
 *     `role_key` are tolerated: when ANY of them carries
 *     `is_admin_role = true`, the role is marked true. This matches
 *     legacy semantics (the engine reads ALL scoped_role_definitions
 *     rows for a role_key and ORs the admin grant).
 *
 * Safe to run twice: up()'s existed check is on
 * (authorization_role_id, is_admin_role) pair. A second up() finds the
 * value already in place and writes no new audit marker.
 *
 * Operational cache note:
 *   up() / down() call `AccessDecision::flushCache()` to drop the
 *   in-process memoization held by the PHP process that ran
 *   `artisan migrate`. That single process re-reads the freshly-written
 *   flag value on its next `can()` call.
 *
 *   Long-running PHP workers (queue listeners, Horizon supervisors,
 *   scheduler daemons) hold their OWN copy of the in-memory cache and
 *   are NOT invalidated cross-process by `flushCache()`. They will
 *   continue serving pre-migration `is_admin_role` until their own
 *   request finishes. Production deploys must restart / recycle those
 *   workers normally alongside the migration so they pick up the new
 *   flag values; the model hooks that normally invalidate the cache on
 *   a write do not fire from a raw `DB::table()` update inside a
 *   migration, so do not rely on them here.
 *
 * PostgreSQL only.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_05_000026_backfill_authorization_roles_is_admin_role';

    private const AUDIT_EVENT = 'legacy_is_admin_role_backfill_000026';

    private const AUDIT_REASON_WRITTEN = 'is_admin_role_backfilled';

    private const AUDIT_REASON_SKIPPED = 'unmappable_is_admin_role';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                self::MIGRATION_NAME.' is PostgreSQL-only. Detected driver: ['.DB::getDriverName().'].'
            );
        }

        $hasRoles = DB::selectOne(
            "SELECT 1 FROM information_schema.tables WHERE table_name = 'authorization_roles'"
        );
        if ($hasRoles === null) {
            throw new RuntimeException(
                self::MIGRATION_NAME.' requires authorization_roles (added by 2026_07_03_000001).'
            );
        }

        $hasColumn = DB::selectOne(
            'SELECT 1 FROM information_schema.columns '
            ."WHERE table_name = 'authorization_roles' AND column_name = 'is_admin_role'"
        );
        if ($hasColumn === null) {
            throw new RuntimeException(
                self::MIGRATION_NAME.' requires the authorization_roles.is_admin_role '
                .'column (added by 2026_07_05_000025).'
            );
        }

        // Read every authorization_roles row in one query so the loop
        // has the (id, name) tuple it needs without N+1 lookups. We
        // intentionally do NOT pre-join scoped_role_definitions here --
        // per-role lookup at the source keeps the audit-marker logic
        // uniform with the reach backfill's source-shape (one source
        // row per role, with optional skip if missing).
        $roles = DB::table('authorization_roles')
            ->select(['id', 'name', 'is_admin_role'])
            ->orderBy('id')
            ->get();

        if ($roles->isEmpty()) {
            AccessDecision::flushCache();

            return;
        }

        $auditRows = [];
        $now = now();

        DB::transaction(function () use ($roles, &$auditRows, $now) {
            foreach ($roles as $role) {
                $roleName = (string) $role->name;

                // Resolve every scoped_role_definitions row whose
                // role_key equals the authorization_roles.name. The
                // legacy engine ORs across rows, so we match the same
                // way: any row with is_admin_role=true => mark true.
                $sourceRows = DB::table('scoped_role_definitions')
                    ->where('role_key', $roleName)
                    ->get(['id', 'is_admin_role']);

                if ($sourceRows->isEmpty()) {
                    $auditRows[] = $this->skippedAuditRow(
                        (int) $role->id,
                        $roleName,
                        'no_matching_scoped_role_definitions',
                        $now,
                    );

                    continue;
                }

                $sourceIsAdmin = $sourceRows->contains(
                    fn ($r) => (bool) $r->is_admin_role === true
                );

                // Non-actionable skip: the source has the same value
                // (false) the row already carries. Suppress the audit
                // marker so a 50-role seeded environment does not emit
                // 49 noise rows. The migration's only observable
                // contract is the column value, which is unchanged in
                // this branch.
                if ($sourceIsAdmin === false) {
                    continue;
                }

                $firstSourceId = (int) $sourceRows->first()->id;

                // Existed check: skip the UPDATE if the pivot already
                // carries the same flag. up() is idempotent: a second
                // up() finds the value already in place and writes no
                // new marker.
                $existed = DB::table('authorization_roles')
                    ->where('id', (int) $role->id)
                    ->where('is_admin_role', true)
                    ->exists();

                if ($existed) {
                    continue;
                }

                DB::table('authorization_roles')
                    ->where('id', (int) $role->id)
                    ->update(['is_admin_role' => true]);

                $auditRows[] = [
                    'event' => self::AUDIT_EVENT,
                    'actor_id' => null,
                    'target_user_id' => null,
                    'scope_type' => null,
                    'scope_id' => null,
                    'role' => $roleName,
                    'old_value' => null,
                    'new_value' => json_encode([
                        'migration' => self::MIGRATION_NAME,
                        'authorization_role_id' => (int) $role->id,
                        'authorization_role_name' => $roleName,
                        'legacy_definition_id' => $firstSourceId,
                    ]),
                    'reason' => self::AUDIT_REASON_WRITTEN,
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

                $roleId = $newValue['authorization_role_id'] ?? null;
                if ($roleId === null) {
                    $auditIdsToDelete[] = (int) $auditRow->id;

                    continue;
                }

                // Only reset the role's flag if its current value is
                // true (the value this migration wrote). A role whose
                // operator manually flipped it back to false is left
                // alone -- down() preserves any operator intervention.
                DB::table('authorization_roles')
                    ->where('id', (int) $roleId)
                    ->where('is_admin_role', true)
                    ->update(['is_admin_role' => false]);

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
     * Build a skip audit row for a role that did not resolve to a
     * scoped_role_definitions row. Carries the role_id so operators can
     * identify the orphan; the `reason` field explains the skip.
     *
     * @return array<string, mixed>
     */
    private function skippedAuditRow(int $roleId, string $roleName, string $reason, CarbonInterface $now): array
    {
        return [
            'event' => self::AUDIT_EVENT,
            'actor_id' => null,
            'target_user_id' => null,
            'scope_type' => null,
            'scope_id' => null,
            'role' => $roleName,
            'old_value' => null,
            'new_value' => json_encode([
                'migration' => self::MIGRATION_NAME,
                'authorization_role_id' => $roleId,
                'authorization_role_name' => $roleName,
                'reason' => $reason,
            ]),
            'reason' => self::AUDIT_REASON_SKIPPED,
            'ip_address' => null,
            'user_agent' => 'migration',
            'created_at' => $now,
        ];
    }
};
