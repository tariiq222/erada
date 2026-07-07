<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 of the master AuthZ unification plan — Task source polymorphism.
 *
 * Adds three columns to `tasks`:
 *   - source_type       : polymorphic parent (Project, Department, Risk,
 *                         IncidentReport, MeetingDecision, Recommendation,
 *                         Kpi, Milestone, …) — class basename or kebab token
 *   - source_id         : polymorphic parent id
 *   - source_sensitivity: copied from the parent at the time of attach
 *                         (e.g. "confidential" for OVR incidents, "normal"
 *                         otherwise) — drives Task::scopeParent priority
 *                         once source_type is honored.
 *
 * Plus a composite index on (source_type, source_id) so the engine's
 * parent lookup stays O(1) when the resolution flows through AccessDecision.
 *
 * Backfill: existing rows are stamped from project_id / department_id so
 * today's data keeps behaving exactly the same — source_type=null on
 * personal tasks (path unchanged), source_type=Project when project_id is
 * set, source_type=Department when only department_id is set. New code
 * can attach tasks to non-project sources; old code's behavior is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // source_type is a string (class basename or kebab token), NOT a
            // morphs() helper — the engine's resolution path uses class
            // basenames and a class map; we do not bind it to a particular
            // parent table at the DB level. Index alone gives us a fast
            // reverse lookup when the engine walks back from a source.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_sensitivity')->nullable();

            $table->index('source_type', 'tasks_source_type_index');
            $table->index(['source_type', 'source_id'], 'tasks_source_type_id_index');
        });

        // Backfill: every task that already has a parent MUST be stamped
        // with the corresponding source so Phase 4's scopeParent() priority
        // change (source first, project second) keeps the existing
        // authorization decisions byte-for-byte identical to pre-Phase-4.
        //
        // Priority order in the backfill mirrors Task::scopeParent() before
        // Phase 4: project wins over department when both are present, and
        // milestone is preserved as a relationship edge (not a source) so
        // the engine continues to consult Project as the primary parent.
        DB::table('tasks')
            ->whereNotNull('project_id')
            ->update([
                'source_type' => 'Project',
                'source_id' => DB::raw('project_id'),
            ]);

        DB::table('tasks')
            ->whereNull('project_id')
            ->whereNotNull('department_id')
            ->update([
                'source_type' => 'Department',
                'source_id' => DB::raw('department_id'),
            ]);

        // Tasks with neither project_id nor department_id stay source_type=null;
        // they continue to ride the personal-owner floor in TaskPolicy (see
        // TaskPolicy::assign / view / update / etc. — unchanged for this
        // slice). The backfill is intentionally idempotent: re-running it
        // produces the same output.
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_source_type_id_index');
            $table->dropIndex('tasks_source_type_index');
            $table->dropColumn(['source_type', 'source_id', 'source_sensitivity']);
        });
    }
};
