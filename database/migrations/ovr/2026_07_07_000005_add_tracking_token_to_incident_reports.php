<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Add a per-report random tracking token used by the public, unauthenticated
 * /api/ovr/track/{tracking_token} endpoint.
 *
 * Design intent: the existing public endpoint keyed on the enumerable
 * `report_number` (e.g. OVR-2026-0001). Switching to a 64-char opaque token
 * removes the enumeration leak (a reporter could otherwise guess adjacent
 * report numbers and peek at their status). The token is included in the
 * notification email/SMS the reporter receives at submission time and never
 * re-displayed in the authenticated UI.
 *
 * Backfill: every existing row that does not yet have a token gets one.
 * Stale or soft-deleted rows are backfilled too — the public endpoint already
 * filters drafts, so this only affects routable reports and an unused token
 * is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ovr_incident_reports', 'tracking_token')) {
            return;
        }

        Schema::table('ovr_incident_reports', function (Blueprint $table) {
            // 64-char URL-safe random. Unique index; nullable to allow a clean
            // add-column without a default, then backfilled below.
            $table->string('tracking_token', 64)->nullable()->unique()->after('report_number');
        });

        // Backfill in chunks so the migration stays cheap on large tables.
        DB::table('ovr_incident_reports')
            ->whereNull('tracking_token')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('ovr_incident_reports')
                        ->where('id', $row->id)
                        ->update(['tracking_token' => Str::random(64)]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('ovr_incident_reports', 'tracking_token')) {
            Schema::table('ovr_incident_reports', function (Blueprint $table) {
                $table->dropUnique(['tracking_token']);
                $table->dropColumn('tracking_token');
            });
        }
    }
};
