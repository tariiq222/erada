<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase R2 / Direction B — extend `recommendations.status` check constraint.
 *
 * Phase R1 collapsed `decisions` + `recommendations` into a single table
 * with ruling kinds; the STATUSES constant added `pending` and `approved`,
 * but the original CHECK constraint (created by
 * 2026_06_19_000004_create_recommendations_table.php) was not widened.
 *
 * This migration widens the constraint to the full Direction B status set.
 * Per LR-004 we add a new migration rather than editing the original
 * constraint definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE recommendations DROP CONSTRAINT IF EXISTS recommendations_status_check');
        DB::statement(
            'ALTER TABLE recommendations ADD CONSTRAINT recommendations_status_check '
            ."CHECK (status IN ('proposed','pending','accepted','approved','rejected','deferred','completed'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recommendations DROP CONSTRAINT IF EXISTS recommendations_status_check');
        DB::statement(
            'ALTER TABLE recommendations ADD CONSTRAINT recommendations_status_check '
            ."CHECK (status IN ('proposed','accepted','rejected','deferred','completed'))"
        );
    }
};
