<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 / Direction R — add `deleted_at` column to `meeting_resolutions`.
 *
 * The model uses Laravel's `SoftDeletes` trait; the original Phase 1
 * migration (2026_07_07_000001) was missing the column. Per LR-004 we
 * never edit an applied migration; this strictly additive migration adds
 * the column with its standard index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_resolutions', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('meeting_resolutions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
