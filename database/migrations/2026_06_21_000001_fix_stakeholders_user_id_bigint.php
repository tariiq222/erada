<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fix stakeholders.user_id: original column was INTEGER, but users.id is BIGINT.
     * Also add an index on user_id for join performance.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('stakeholders', 'user_id')) {
            return;
        }

        // Drop the existing FK if present (constraint name from the original migration
        // is platform-dependent; we look it up dynamically to be safe).
        $constraintName = DB::selectOne(
            "SELECT conname FROM pg_constraint
             WHERE conrelid = 'stakeholders'::regclass
               AND contype = 'f'
               AND pg_get_constraintdef(oid) ILIKE '%REFERENCES users%'"
        );

        if ($constraintName) {
            DB::statement("ALTER TABLE stakeholders DROP CONSTRAINT {$constraintName->conname}");
        }

        // Drop the index if it exists (added or not originally).
        $existingIndex = DB::selectOne(
            "SELECT indexname FROM pg_indexes
             WHERE tablename = 'stakeholders' AND indexname = 'stakeholders_user_id_index'"
        );

        if ($existingIndex) {
            Schema::table('stakeholders', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
            });
        }

        // Change column type to BIGINT.
        DB::statement('ALTER TABLE stakeholders ALTER COLUMN user_id TYPE BIGINT USING user_id::bigint');

        // Re-add the FK and index.
        Schema::table('stakeholders', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('stakeholders', 'user_id')) {
            return;
        }

        Schema::table('stakeholders', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
        });

        DB::statement('ALTER TABLE stakeholders ALTER COLUMN user_id TYPE INTEGER USING user_id::integer');

        Schema::table('stakeholders', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('user_id');
        });
    }
};
