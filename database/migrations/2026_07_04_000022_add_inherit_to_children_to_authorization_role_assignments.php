<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.1.2 hardening -- preserve legacy `inherit_to_children` semantics
 * on the new authorization_role_assignments table.
 *
 * Background:
 *   The legacy `model_has_scoped_roles` table carries an
 *   `inherit_to_children` boolean that controls whether a department-scoped
 *   role leaks onto descendant departments. The companion backfill
 *   migration 000021 now persists that value on the new
 *   authorization_role_assignments row, so the resolver does NOT fall back
 *   to its hardcoded `default true` and silently widen access for rows
 *   whose legacy value was `false`.
 *
 *   This migration is the additive hardening:
 *     - Adds `inherit_to_children boolean NOT NULL DEFAULT true` to
 *       `authorization_role_assignments`. The DEFAULT true is the safe
 *       Phase 1 baseline (Phase 1 rows predate this column; the default
 *       preserves "no information => no narrowing" semantics). The
 *       hasColumn guard means 000021 (which also adds the column) does
 *       not conflict -- whichever migration runs first wins.
 *     - Promotes the column to NOT NULL (idempotent; safe to re-run).
 *     - Backfills the column for any rows that 000021 already created in
 *       earlier runs (before the column existed), using the legacy
 *       model_has_scoped_roles row that the audit marker references.
 *       This is a safety net for pre-fix 000021 runs whose assignments
 *       were filled with the column DEFAULT (true) the moment the column
 *       was added -- the backfill writes the legacy value UNCONDITIONALLY
 *       (no `whereNull` filter) so a legacy `false` is corrected even
 *       after the default has overwritten the NULL.
 *
 * down():
 *   Drops the column. The data is recoverable from the legacy
 *   model_has_scoped_roles table via the audit-marker linkage (or by
 *   re-running up() then down() + up() if the legacy table is gone).
 *   000021's own down() detects via information_schema whether THIS
 *   migration owns the column add and skips its own drop when it does,
 *   so double-drop is avoided.
 *
 * Safe to run twice:
 *   - up() skips the column add when it already exists (hasColumn guard).
 *   - The SET NOT NULL is a no-op on already-NOT-NULL columns.
 *   - The legacy backfill rewrites every audit-linked assignment to the
 *     legacy value; on a re-run this is idempotent.
 *
 * PostgreSQL only:
 *   The boolean column with default works on any driver, but the legacy
 *   backfill (the safety-net path) reads from `permission_audits` whose
 *   `new_value` is JSON-shaped and the audit event discriminator is
 *   Phase-2.1.2-specific. The other tests gating the slice also pin pgsql.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_04_000022_add_inherit_to_children_to_authorization_role_assignments';

    private const BACKFILL_AUDIT_EVENT = 'legacy_scoped_backfill_000021';

    public function up(): void
    {
        if (! Schema::hasTable('authorization_role_assignments')) {
            return;
        }

        // Add the column if missing. Companion migration 000021 ALSO
        // adds the column (so it can INSERT inherit_to_children without
        // depending on ordering); the hasColumn guard makes both
        // statements idempotent. After this block the column exists with
        // a default of true (Phase 1 baseline).
        if (! Schema::hasColumn('authorization_role_assignments', 'inherit_to_children')) {
            Schema::table('authorization_role_assignments', function (Blueprint $table) {
                // DEFAULT true preserves Phase 1 semantics for any rows that
                // predate this migration (legacy Phase 1 super_admin pivots
                // etc. inherit_to_children defaulted to true conceptually).
                $table->boolean('inherit_to_children')->default(true)->after('organization_id');
            });
        }

        // Promote the column to NOT NULL. PostgreSQL `SET NOT NULL` is a
        // no-op when the column is already NOT NULL, so it is safe to
        // run unconditionally (regardless of whether THIS migration or
        // 000021 added the column). This is the guarantee the resolver
        // relies on: every row must carry a concrete value so the
        // resolver never falls back to its hardcoded `default true`.
        if (DB::getDriverName() === 'pgsql'
            && Schema::hasColumn('authorization_role_assignments', 'inherit_to_children')) {
            DB::statement(
                'ALTER TABLE authorization_role_assignments '
                .'ALTER COLUMN inherit_to_children SET NOT NULL'
            );
        }

        // Safety net: any authorization_role_assignments row that was
        // created by 000021 BEFORE the column existed has its value
        // filled by the column DEFAULT (true) at the moment the column
        // is added. A legacy inherit_to_children=false row therefore has
        // its assignment sitting at `true` until this safety net
        // corrects it. The audit marker that 000021 wrote links each
        // assignment id to the legacy model_has_scoped_roles row id, so
        // we read the legacy value and write it back UNCONDITIONALLY
        // (NOT `whereNull` only -- the default fills NULLs immediately,
        // so whereNull would match nothing and a legacy false would
        // silently stay widened to true).
        //
        // On a re-run the UPDATE is idempotent: every audit-linked
        // assignment is rewritten to the same legacy value.
        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('permission_audits')) {
            $auditRows = DB::table('permission_audits')
                ->where('event', self::BACKFILL_AUDIT_EVENT)
                ->get();

            foreach ($auditRows as $auditRow) {
                $newValue = json_decode((string) $auditRow->new_value, true);
                if (! is_array($newValue)) {
                    continue;
                }
                $newAssignmentId = $newValue['new_authorization_role_assignment_id'] ?? null;
                $legacyId = $newValue['source_scoped_role_id'] ?? null;
                if ($newAssignmentId === null || $legacyId === null) {
                    continue;
                }

                $legacyRow = DB::table('model_has_scoped_roles')->where('id', (int) $legacyId)->first();
                if ($legacyRow === null) {
                    continue;
                }

                $value = (bool) ($legacyRow->inherit_to_children ?? true);

                DB::table('authorization_role_assignments')
                    ->where('id', (int) $newAssignmentId)
                    ->update(['inherit_to_children' => $value]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('authorization_role_assignments')) {
            return;
        }

        if (Schema::hasColumn('authorization_role_assignments', 'inherit_to_children')) {
            Schema::table('authorization_role_assignments', function (Blueprint $table) {
                $table->dropColumn('inherit_to_children');
            });
        }
    }
};
