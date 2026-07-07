<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Partial unique indexes — allow multiple NULLs (pre-backfill rows),
        // but reject duplicate non-null reference numbers at the DB level.
        // The advisory-lock guard in ReferenceNumberGenerator is the primary
        // concurrency control; these indexes are the required DB-level backstop.
        DB::statement('CREATE UNIQUE INDEX meetings_reference_number_unique ON meetings (reference_number) WHERE reference_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX recommendations_reference_number_unique ON recommendations (reference_number) WHERE reference_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX decisions_reference_number_unique ON decisions (reference_number) WHERE reference_number IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS meetings_reference_number_unique');
        DB::statement('DROP INDEX IF EXISTS recommendations_reference_number_unique');
        DB::statement('DROP INDEX IF EXISTS decisions_reference_number_unique');
    }
};
