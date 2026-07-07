<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase R1 / Direction B — promote `recommendations` to a unified entity.
 *
 * Absorbs the ruling-side columns formerly living on the `decisions` table
 * so a single row can carry either an action_item lifecycle
 * (proposed -> accepted -> completed) or a ruling lifecycle
 * (pending -> approved | rejected | deferred) depending on `kind`.
 *
 * `meeting_id` replaces the old `decision_id` link so the parent scope
 * chain now reads directly: meeting -> department.
 *
 * `decidable_type` + `decidable_id` keep the polymorphic target a ruling
 * is about (project / program / portfolio / risk), matching the legacy
 * Decision schema and DecidableType allowlist.
 *
 * The CHECK constraint on `kind` is enforced via raw SQL because Laravel's
 * Blueprint has no native CHECK API; values mirror Recommendation::KIND_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $t) {
            $t->foreignId('meeting_id')->nullable()->after('id')->constrained('meetings')->restrictOnDelete();
            $t->string('decidable_type')->nullable()->after('meeting_id');
            $t->unsignedBigInteger('decidable_id')->nullable()->after('decidable_type');

            $t->string('kind', 20)->default('action_item')->after('decidable_id');
            $t->string('type', 40)->nullable()->after('kind');

            $t->foreignId('requested_by')->nullable()->after('type')->constrained('users')->restrictOnDelete();
            $t->foreignId('made_by')->nullable()->after('requested_by')->constrained('users')->restrictOnDelete();

            $t->date('decision_date')->nullable()->after('made_by');
            $t->date('effective_date')->nullable()->after('decision_date');
            $t->text('impact')->nullable()->after('effective_date');
            $t->text('rationale')->nullable()->after('impact');

            $t->index('meeting_id', 'recommendations_meeting_id_idx');
            $t->index(['kind', 'status'], 'recommendations_kind_status_idx');
        });

        DB::statement("ALTER TABLE recommendations ADD CONSTRAINT recommendations_kind_check CHECK (kind IN ('ruling', 'action_item'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recommendations DROP CONSTRAINT IF EXISTS recommendations_kind_check');

        Schema::table('recommendations', function (Blueprint $t) {
            $t->dropIndex('recommendations_kind_status_idx');
            $t->dropIndex('recommendations_meeting_id_idx');

            $t->dropConstrainedForeignId('meeting_id');
            $t->dropConstrainedForeignId('requested_by');
            $t->dropConstrainedForeignId('made_by');

            $t->dropColumn([
                'decidable_type',
                'decidable_id',
                'kind',
                'type',
                'decision_date',
                'effective_date',
                'impact',
                'rationale',
            ]);
        });
    }
};
