<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'sqlite') {
                $indexes = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?", [$table, $indexName]);

                return count($indexes) > 0;
            }
            if ($driver === 'pgsql') {
                $indexes = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?', [$table, $indexName]);

                return count($indexes) > 0;
            }
            // MySQL
            $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);

            return count($indexes) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    public function up(): void
    {
        // Projects indexes - only if department_id exists
        if (Schema::hasColumn('projects', 'department_id')) {
            Schema::table('projects', function (Blueprint $table) {
                if (! $this->indexExists('projects', 'idx_projects_dept_status')) {
                    $table->index(['department_id', 'status'], 'idx_projects_dept_status');
                }
            });
        }

        // Tasks indexes - only if columns exist
        if (Schema::hasColumn('tasks', 'project_id')) {
            Schema::table('tasks', function (Blueprint $table) {
                if (! $this->indexExists('tasks', 'idx_tasks_project_status')) {
                    $table->index(['project_id', 'status'], 'idx_tasks_project_status');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if ($this->indexExists('projects', 'idx_projects_dept_status')) {
                $table->dropIndex('idx_projects_dept_status');
            }
        });

        Schema::table('tasks', function (Blueprint $table) {
            if ($this->indexExists('tasks', 'idx_tasks_project_status')) {
                $table->dropIndex('idx_tasks_project_status');
            }
        });
    }
};
