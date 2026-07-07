<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Consolidate the final tasks_status_check constraint set.
 *
 * History:
 *   - 2026_01_20_152559 (orig) defined the constraint with 4 statuses.
 *   - 2026_06_07_051035 (fix) widened it to 6 statuses to match the
 *     application enum (cancelled, on_hold added later).
 *   - Per LR-004 (immutable migrations) we never edit prior migrations,
 *     so this is a NEW idempotent migration that drops-and-re-adds the
 *     constraint with the FINAL set. Its down() drops the constraint
 *     cleanly; the app layer enforces validity if the constraint is
 *     temporarily absent (rolled-back environments).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check');
        DB::statement(
            'ALTER TABLE tasks ADD CONSTRAINT tasks_status_check '
            .'CHECK (status::text = ANY (ARRAY['
            ."'todo'::text, 'in_progress'::text, 'in_review'::text, "
            ."'completed'::text, 'cancelled'::text, 'on_hold'::text"
            .']))'
        );
    }

    public function down(): void
    {
        // Safe rollback: drop the constraint only. The application
        // layer enforces status validity, so this is reversible
        // without losing data (rows with cancelled/on_hold remain
        // valid rows; they just lose DB-level enforcement until the
        // next forward migration re-adds it).
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check');
    }
};
