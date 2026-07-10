<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3A — Survey respondent-organization snapshot.
 *
 * The cluster aggregate on /api/surveys/{survey}/cluster-stats is the
 * only historical attribution path for survey answers. Pre-Phase-3A
 * the aggregate joined through the live users.organization_id (via the
 * respondent_id FK), which silently rotated org attribution when a
 * user moved organizations or was deleted — historical responses
 * stopped belonging to the org that received them.
 *
 * The design brief is explicit: the cluster aggregate MUST group by
 * a respondent-organization snapshot stamped at submission time, NOT
 * by the mutable users.organization_id relation. The snapshot column
 * also lets the cluster cross-org aggregate stay stable as users
 * churn through membership changes.
 *
 * Forward-only backfill (legacy fallback):
 *   - Identified respondents (respondent_id IS NOT NULL): stamp the
 *     respondent's CURRENT organization. The design notes this as a
 *     legacy fallback — historical rows pre-Phase-3A cannot recover
 *     their org at submission time; the current org is the best
 *     available approximation.
 *   - Anonymous (respondent_id IS NULL) or respondents with deleted
 *     users: stamp the survey's organization (survey.organization_id)
 *     so cluster aggregates on a survey still have a row to attribute
 *     to. This makes the "anonymous" rows belong to the survey's home
 *     org rather than dropping into a null bucket.
 *
 * Schema:
 *   - respondent_organization_id bigint nullable, FK → organizations.id
 *     via ON DELETE SET NULL (a deleted org shouldn't blow up survey
 *     responses; the snapshot survives).
 *   - composite index on (survey_id, respondent_organization_id) so
 *     the cluster aggregate runs in O(visibleOrgIds) without a full
 *     scan of survey_responses.
 *
 * Idempotent: the backfill UPDATE is a one-shot idempotent assignment
 * (only writes when the column is NULL) so a re-run of this migration
 * does not double-stamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->unsignedBigInteger('respondent_organization_id')
                ->nullable()
                ->after('respondent_id');

            $table->foreign('respondent_organization_id')
                ->references('id')->on('organizations')
                ->nullOnDelete();

            $table->index(
                ['survey_id', 'respondent_organization_id'],
                'survey_responses_survey_org_idx'
            );
        });

        // Phase 3A — forward-only backfill. Two LEFT JOINs coalesce on
        // (1) the respondent's current organization, falling back to
        // (2) the survey's organization for anonymous / deleted
        // respondents. The CTE form is the only one Postgres accepts
        // when the FROM clause itself carries a LEFT JOIN that needs
        // to reach the UPDATE target (`sr.id`); a flat UPDATE…FROM
        // with a LEFT JOIN referencing `sr` raises 42P10. The
        // CTE computes the org per row, the UPDATE applies it.
        // Re-runs are safe (the WHERE filters untouched rows only).
        DB::statement(<<<'SQL'
            WITH snapshots AS (
                SELECT
                    sr.id AS response_id,
                    COALESCE(u.organization_id, s.organization_id) AS org_id
                FROM survey_responses sr
                JOIN surveys s ON s.id = sr.survey_id
                LEFT JOIN users u ON u.id = sr.respondent_id
                WHERE sr.respondent_organization_id IS NULL
            )
            UPDATE survey_responses AS sr
            SET respondent_organization_id = snapshots.org_id
            FROM snapshots
            WHERE sr.id = snapshots.response_id
              AND snapshots.org_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropForeign(['respondent_organization_id']);
            $table->dropIndex('survey_responses_survey_org_idx');
            $table->dropColumn('respondent_organization_id');
        });
    }
};
