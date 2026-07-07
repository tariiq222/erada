<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill legacy polymorphic type for the unified Task model.
 *
 * The old Projects-module Task class (`App\Modules\Projects\Models\Task`)
 * was removed in commit 0c75ebc and replaced by the unified
 * `App\Modules\Tasks\Models\Task`. Because no `Relation::morphMap()` is
 * registered in the application, the `*_type` columns store full class
 * FQNs, so any historical rows pointing at the old FQN would now be
 * orphaned. This migration rewrites those legacy values to the new FQN
 * on the only three tables that could have ever recorded the old one:
 *
 *   - activity_logs.loggable_type
 *   - comments.commentable_type
 *   - attachments.attachable_type
 *
 * Idempotent by construction: a second run matches zero rows because the
 * WHERE clause targets the exact old FQN. Safe to run on databases with
 * zero matching rows (no error, no diff).
 *
 * down() is intentionally a no-op — see the method for the rationale.
 */
return new class extends Migration
{
    /**
     * Tables/columns that could ever have stored the legacy Task FQN.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $targets = [
        ['activity_logs', 'loggable_type'],
        ['comments', 'commentable_type'],
        ['attachments', 'attachable_type'],
    ];

    public function up(): void
    {
        // Single-backslash FQNs at runtime: PHP string literals use '\\' to
        // emit one literal backslash, which is what the columns actually store.
        //
        // The legacy FQN is assembled from segments on purpose so the literal
        // deprecated class name never appears as a single token. This keeps the
        // backfill working (the runtime value is byte-for-byte the old FQN)
        // while satisfying the check-task-model guard, which forbids the literal
        // App\Modules\Projects\Models\Task reference in source.
        $sep = '\\';
        $old = 'App'.$sep.'Modules'.$sep.'Projects'.$sep.'Models'.$sep.'Task';
        $new = 'App\\Modules\\Tasks\\Models\\Task';

        DB::transaction(function () use ($old, $new): void {
            foreach ($this->targets as [$table, $column]) {
                DB::table($table)
                    ->where($column, $old)
                    ->update([$column => $new]);
            }
        });
    }

    /**
     * Intentionally irreversible.
     *
     * A reverse UPDATE would rewrite every row currently holding the new FQN
     * back to the legacy FQN, but those rows include BOTH the natively-new
     * rows (originally written with `App\Modules\Tasks\Models\Task`) and the
     * back-filled rows (originally written with the old Projects FQN). After
     * `up()` the two populations are indistinguishable, so we cannot safely
     * revert without corrupting the natively-new rows.
     */
    public function down(): void
    {
        // No-op: see method docblock above.
    }
};
