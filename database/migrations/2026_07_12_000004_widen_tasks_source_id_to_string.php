<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen tasks.source_id from unsignedBigInteger to VARCHAR(36).
 *
 * Direction R (commit 44e5667, 2026-07-07) introduced polymorphic source
 * stamping onto IncidentReport (UUID) and MeetingResolution (UUID) via the
 * Task::SOURCE_CLASS_MAP. The source_id column was originally declared as
 * unsignedBigInteger in 2026_07_05_171421_add_source_fields_to_tasks_table
 * because the only existing source rows at the time were Project /
 * Department / Risk (all bigint). UUIDs (the IncidentReport.id primary
 * key) cannot fit in a bigint, so every insert that stamped an OVR handler
 * task via createHandlerTask() failed with:
 *
 *   SQLSTATE[22P02]: Invalid text representation: 7 ERROR:
 *     invalid input syntax for type bigint: "019f4224-..."
 *
 * That single statement aborted the surrounding transaction, so every
 * later SELECT inside the same transaction surfaced as
 * SQLSTATE[25P02]: current transaction is aborted — which is what
 * Pass 2 saw across 78 OVR tests.
 *
 * This migration widens the column to VARCHAR(36) so both bigint IDs
 * (cast to their decimal string form) and UUIDs (36 chars including
 * dashes) fit. The composite index (source_type, source_id) is left
 * intact; the new varchar key is indexable. Existing rows keep their
 * values: PG casts bigint -> varchar losslessly.
 *
 * No data loss. The change is monotonic: any bigint value that already
 * fit still fits (as a decimal string), and UUIDs now fit too.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Postgres-only path. SQLite/MySQL would need a different rewrite
        // but the test env is pgsql and the prod stack is also pgsql.
        DB::statement('ALTER TABLE tasks ALTER COLUMN source_id TYPE VARCHAR(36) USING source_id::varchar(36)');
    }

    public function down(): void
    {
        // Best-effort rollback: if every value still parses as a bigint,
        // the column fits back into unsignedBigInteger. If any UUID sneaked
        // in, Postgres raises 22P02 — by design, the down migration must
        // surface that instead of silently truncating.
        DB::statement('ALTER TABLE tasks ALTER COLUMN source_id TYPE BIGINT USING source_id::bigint');
    }
};
