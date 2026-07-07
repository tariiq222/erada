<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase R1 / Direction B — drop the legacy `recommendations.decision_id` link.
 *
 * The Meeting -> Recommendation -> Task chain no longer routes through a
 * separate `decisions` table; recommendations absorb ruling fields directly
 * and link to the meeting via `meeting_id` (added in migration 300002).
 *
 * Raw SQL is used because the FK constraint name from the original
 * `constrained('decisions')` helper follows Laravel's auto-generated
 * pattern (`<table>_<column>_foreign`) but we want this migration to be
 * idempotent regardless of whether the FK was created via Blueprint or
 * raw DDL in some earlier pass.
 *
 * The companion `down()` is for rollback parity only — fresh cut.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE recommendations DROP CONSTRAINT IF EXISTS recommendations_decision_id_foreign');
        DB::statement('ALTER TABLE recommendations DROP COLUMN IF EXISTS decision_id');
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $t) {
            $t->foreignId('decision_id')->nullable()->constrained('decisions')->restrictOnDelete();
        });
    }
};
