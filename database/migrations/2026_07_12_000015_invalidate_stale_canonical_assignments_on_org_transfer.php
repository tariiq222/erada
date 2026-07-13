<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CSD-CA23078-CORE-002 — Idempotent safety net for stale canonical assignments
 * after an org transfer.
 *
 * When a super_admin moves a user from Org A to Org B via direct DB write,
 * every `authorization_role_assignments` row whose `organization_id` is still
 * bound to Org A becomes stale: the assignment's scope may now reference an
 * org the user no longer belongs to. The runtime filters added in this fix
 * (UserProjectScope::canonicalGrantingScopes + AuthorizationRoleAssignmentController
 * ::canonicalAssignmentSummaries) treat those rows as invisible at read time.
 *
 * This migration is the in-place cleanup. For each user, it expires every
 * non-`all` row whose `organization_id` does not equal the user's current
 * `organization_id` and writes one `authorization_assignment_audits` row per
 * stale assignment so the historical record is preserved.
 *
 * Idempotency: a re-run is a no-op because (a) we filter to rows where
 * `expires_at IS NULL`, so already-expired rows are skipped, and (b) the
 * audit marker (`new_value ->> 'migration' = self::MIGRATION_NAME &&
 * new_value ->> 'authorization_role_assignment_id' = X`) is checked before
 * updating, so a row whose audit already exists from a prior run is skipped.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_12_000015_invalidate_stale_canonical_assignments_on_org_transfer';

    private const AUDIT_EVENT = 'stale_canonical_assignment_invalidated';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                self::MIGRATION_NAME.' is PostgreSQL-only. Detected driver: ['.DB::getDriverName().'].'
            );
        }

        foreach (['users', 'authorization_role_assignments', 'authorization_assignment_audits'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(self::MIGRATION_NAME." requires table [{$table}] to exist.");
            }
        }

        // Pre-compute the set of (assignment_id) markers already written by this
        // migration so a re-run skips rows that have already been audited. This
        // keeps the migration idempotent without relying on `expires_at` only.
        $alreadyAudited = $this->loadAlreadyAuditedAssignmentIds();
        $now = now();
        $auditRows = [];

        DB::transaction(function () use (&$auditRows, $alreadyAudited, $now): void {
            // Pull every stale row in one query. The set is bounded by the
            // number of users that have ever been moved between orgs — in
            // practice this is a few hundred at most — so a single read is
            // cheaper than chunkById with a join (which has edge cases around
            // the chunking column qualification).
            $rows = DB::table('authorization_role_assignments as ara')
                ->join('users', 'users.id', '=', 'ara.user_id')
                ->whereNotNull('ara.organization_id')
                ->whereNotNull('users.organization_id')
                ->whereColumn('ara.organization_id', '!=', 'users.organization_id')
                ->where('ara.scope_type', '!=', 'all')
                ->whereNull('ara.expires_at')
                ->orderBy('ara.id')
                ->select([
                    'ara.id',
                    'ara.user_id',
                    'ara.authorization_role_id',
                    'ara.scope_type',
                    'ara.scope_id',
                    'ara.organization_id as stale_organization_id',
                ])
                ->get();

            foreach ($rows as $row) {
                $assignmentId = (int) $row->id;
                if (isset($alreadyAudited[$assignmentId])) {
                    continue;
                }

                DB::table('authorization_role_assignments')
                    ->where('id', $assignmentId)
                    ->update([
                        'expires_at' => $now,
                        'updated_at' => $now,
                    ]);

                $auditRows[] = [
                    'event' => self::AUDIT_EVENT,
                    'actor_id' => null,
                    'target_user_id' => (int) $row->user_id,
                    'scope_type' => $row->scope_type,
                    'scope_id' => $row->scope_id === null ? null : (int) $row->scope_id,
                    'role' => null,
                    'old_value' => json_encode([
                        'expires_at' => null,
                        'organization_id' => (int) $row->stale_organization_id,
                    ], JSON_THROW_ON_ERROR),
                    'new_value' => json_encode([
                        'migration' => self::MIGRATION_NAME,
                        'authorization_role_assignment_id' => $assignmentId,
                        'authorization_role_id' => (int) $row->authorization_role_id,
                        'stale_organization_id' => (int) $row->stale_organization_id,
                        'reason' => 'cross-org transfer safety net',
                    ], JSON_THROW_ON_ERROR),
                    'reason' => 'CSD-CA23078-CORE-002 stale canonical assignment invalidated by org-transfer safety net',
                    'ip_address' => null,
                    'user_agent' => 'migration',
                    'created_at' => $now,
                ];

                // Track in-memory so a duplicate inside the same query (should
                // not happen — id is unique — but defensive) would not produce
                // two audits.
                $alreadyAudited[$assignmentId] = true;
            }
        });

        if ($auditRows !== []) {
            // Chunk the audit insert to stay under PostgreSQL's parameter limit
            // (~65k bind params per statement). 500 rows × ~10 columns is well
            // below that, but the chunk is also clearer in the logs.
            foreach (array_chunk($auditRows, 500) as $chunk) {
                DB::table('authorization_assignment_audits')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        // Forward-only: this migration's purpose is to expire stale rows so they
        // can no longer be evaluated by the engine. Rolling back would un-expire
        // rows the user may have already been moved away from and re-introduce
        // the leak. Audit rows are preserved in all directions.
    }

    /**
     * @return array<int, true>
     */
    private function loadAlreadyAuditedAssignmentIds(): array
    {
        $ids = [];
        DB::table('authorization_assignment_audits')
            ->where('event', self::AUDIT_EVENT)
            ->whereRaw("new_value ->> 'migration' = ?", [self::MIGRATION_NAME])
            ->select(['new_value'])
            ->orderBy('id')
            ->each(function (object $row) use (&$ids): void {
                $stored = json_decode((string) $row->new_value, true);
                if (is_array($stored) && isset($stored['authorization_role_assignment_id'])) {
                    $ids[(int) $stored['authorization_role_assignment_id']] = true;
                }
            });

        return $ids;
    }
};
