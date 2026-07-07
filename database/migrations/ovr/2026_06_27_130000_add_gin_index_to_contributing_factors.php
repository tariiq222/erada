<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CONCURRENTLY cannot run inside a transaction — Laravel migrations auto-wrap.
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY ovr_incident_reports_contributing_factors_gin
            ON ovr_incident_reports
            USING GIN ((contributing_factors::jsonb) jsonb_path_ops)
            WHERE contributing_factors IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ovr_incident_reports_contributing_factors_gin');
    }
};
