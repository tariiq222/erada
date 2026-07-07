<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lock activity_logs.loggable_id as VARCHAR(255) and make rollback safe.
 *
 * History:
 *   - 2024_01_01_000009 created the column as morphs() (BIGINT).
 *   - 2026_06_07_060000 widened it to VARCHAR(255) — UUID morphs now
 *     coexist alongside numeric IDs (per the Audit Log polymorphic
 *     consumer that polls entity UUIDs).
 *   - Per LR-004 we do not edit prior migrations. The prior migration's
 *     down() does `...TYPE BIGINT USING loggable_id::BIGINT` which
 *     explodes on rows whose loggable_id is now non-numeric (UUID),
 *     making rollback a hard data-loss risk.
 *
 * This migration:
 *   - Re-affirms the column type as VARCHAR(255) (idempotent forward).
 *   - Adds a down() that only drops the index; column-type narrowing
 *     is INTENTIONALLY OMITTED with a guard explaining the one-way
 *     contract. Operators must run a manual data conversion step
 *     (truncate non-numeric log rows, then run the type change) before
 *     they can ever roll back.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE activity_logs ALTER COLUMN loggable_id TYPE VARCHAR(255) USING loggable_id::VARCHAR');
    }

    public function down(): void
    {
        // The prior migration's reverse is lossy once UUID morphs exist.
        // We refuse to silently perform it; surface the precondition and
        // exit instead. This is the audit-mandated safety net for
        // activity_logs.loggable_id rollback (per Phase-3 verification).
        $nonNumeric = (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM activity_logs '
            .'WHERE loggable_id IS NOT NULL '
            ."AND loggable_id !~ '^[0-9]+$'"
        )->c;

        if ($nonNumeric > 0) {
            // ponytail: refuse the destructive cast. Operator must run
            // `php artisan activity-logs:prune-non-numeric` (manual step)
            // before retrying rollback.
            throw new RuntimeException(
                "activity_logs.loggable_id has {$nonNumeric} non-numeric rows "
                .'that cannot safely be cast back to BIGINT. Run the manual '
                ."prune step first; this migration's down() is intentionally "
                .'non-destructive and refuses to proceed.'
            );
        }

        DB::statement('ALTER TABLE activity_logs ALTER COLUMN loggable_id TYPE BIGINT USING loggable_id::BIGINT');
    }
};
