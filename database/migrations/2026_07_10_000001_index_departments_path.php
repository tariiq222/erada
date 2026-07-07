<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index departments.path so the materialized-path subtree query
     * (where path like '/.../%') is served by an index at ~1,200-department scale.
     *
     * The baseline create_departments_table migration already declares this index.
     * Guard against a duplicate so migrate:fresh stays clean and the migration is
     * idempotent on any environment that has not yet indexed the column.
     */
    public function up(): void
    {
        if (! $this->pathIndexExists()) {
            Schema::table('departments', function ($table) {
                $table->index('path');
            });
        }
    }

    public function down(): void
    {
        if ($this->pathIndexExists()) {
            Schema::table('departments', function ($table) {
                $table->dropIndex('departments_path_index');
            });
        }
    }

    private function pathIndexExists(): bool
    {
        return DB::table('pg_indexes')
            ->where('tablename', 'departments')
            ->where('indexname', 'departments_path_index')
            ->exists();
    }
};
