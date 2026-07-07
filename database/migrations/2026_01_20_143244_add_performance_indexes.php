<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration لإضافة الفهارس الناقصة لتحسين الأداء
 *
 * تم تحديد هذه الفهارس بناءً على تحليل الاستعلامات الشائعة
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // فهارس جدول tasks
        Schema::table('tasks', function (Blueprint $table) {
            // فهرس للبحث بالحالة (شائع جداً)
            if (! $this->indexExists('tasks', 'tasks_status_index')) {
                $table->index('status', 'tasks_status_index');
            }

            // فهرس لتاريخ الاستحقاق (المهام القادمة)
            if (! $this->indexExists('tasks', 'tasks_due_date_index')) {
                $table->index('due_date', 'tasks_due_date_index');
            }

            // فهرس للأولوية
            if (! $this->indexExists('tasks', 'tasks_priority_index')) {
                $table->index('priority', 'tasks_priority_index');
            }

            // فهرس مركب للبحث بالحالة والأولوية معاً
            if (! $this->indexExists('tasks', 'tasks_status_priority_index')) {
                $table->index(['status', 'priority'], 'tasks_status_priority_index');
            }

            // فهرس للمهام المسندة لمستخدم معين
            if (! $this->indexExists('tasks', 'tasks_assigned_to_status_index')) {
                $table->index(['assigned_to', 'status'], 'tasks_assigned_to_status_index');
            }
        });

        // فهارس جدول projects
        Schema::table('projects', function (Blueprint $table) {
            // فهرس للبحث بالحالة
            if (! $this->indexExists('projects', 'projects_status_index')) {
                $table->index('status', 'projects_status_index');
            }

            // فهرس للمدير
            if (! $this->indexExists('projects', 'projects_manager_id_index')) {
                $table->index('manager_id', 'projects_manager_id_index');
            }

            // فهرس للقسم
            if (! $this->indexExists('projects', 'projects_department_id_index')) {
                $table->index('department_id', 'projects_department_id_index');
            }

            // فهرس مركب للحالة والأولوية
            if (! $this->indexExists('projects', 'projects_status_priority_index')) {
                $table->index(['status', 'priority'], 'projects_status_priority_index');
            }
        });

        // فهارس جدول milestones
        Schema::table('milestones', function (Blueprint $table) {
            // فهرس للحالة
            if (! $this->indexExists('milestones', 'milestones_status_index')) {
                $table->index('status', 'milestones_status_index');
            }

            // فهرس لتاريخ الاستحقاق
            if (! $this->indexExists('milestones', 'milestones_due_date_index')) {
                $table->index('due_date', 'milestones_due_date_index');
            }

            // فهرس للمشروع
            if (! $this->indexExists('milestones', 'milestones_project_id_index')) {
                $table->index('project_id', 'milestones_project_id_index');
            }
        });

        // فهارس جدول programs
        Schema::table('programs', function (Blueprint $table) {
            // فهرس للحالة
            if (! $this->indexExists('programs', 'programs_status_index')) {
                $table->index('status', 'programs_status_index');
            }

            // فهرس للمحفظة
            if (! $this->indexExists('programs', 'programs_portfolio_id_index')) {
                $table->index('portfolio_id', 'programs_portfolio_id_index');
            }
        });

        // فهارس جدول users
        Schema::table('users', function (Blueprint $table) {
            // فهرس للبحث بالاسم (للـ autocomplete)
            if (! $this->indexExists('users', 'users_name_index')) {
                $table->index('name', 'users_name_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_status_index');
            $table->dropIndex('tasks_due_date_index');
            $table->dropIndex('tasks_priority_index');
            $table->dropIndex('tasks_status_priority_index');
            $table->dropIndex('tasks_assigned_to_status_index');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_status_index');
            $table->dropIndex('projects_manager_id_index');
            $table->dropIndex('projects_department_id_index');
            $table->dropIndex('projects_status_priority_index');
        });

        Schema::table('milestones', function (Blueprint $table) {
            $table->dropIndex('milestones_status_index');
            $table->dropIndex('milestones_due_date_index');
            $table->dropIndex('milestones_project_id_index');
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->dropIndex('programs_status_index');
            $table->dropIndex('programs_portfolio_id_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_name_index');
        });
    }

    /**
     * التحقق من وجود فهرس
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select(
                "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?",
                [$table, $indexName]
            );

            return count($indexes) > 0;
        }

        if ($driver === 'mysql') {
            $indexes = $connection->select(
                "SHOW INDEX FROM {$table} WHERE Key_name = ?",
                [$indexName]
            );

            return count($indexes) > 0;
        }

        if ($driver === 'pgsql') {
            $indexes = $connection->select(
                'SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $indexName]
            );

            return count($indexes) > 0;
        }

        return false;
    }
};
