<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ====== Projects ======
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasIndex('projects', 'projects_status_created_at_index')) {
                $table->index(['status', 'created_at'], 'projects_status_created_at_index');
            }
            if (! Schema::hasIndex('projects', 'projects_manager_id_index')) {
                $table->index('manager_id', 'projects_manager_id_index');
            }
            if (! Schema::hasIndex('projects', 'projects_department_id_index')) {
                $table->index('department_id', 'projects_department_id_index');
            }
            if (! Schema::hasIndex('projects', 'projects_end_date_status_index')) {
                $table->index(['end_date', 'status'], 'projects_end_date_status_index');
            }
        });

        // ====== Tasks ======
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasIndex('tasks', 'tasks_assigned_to_status_index')) {
                $table->index(['assigned_to', 'status'], 'tasks_assigned_to_status_index');
            }
            if (! Schema::hasIndex('tasks', 'tasks_project_id_status_index')) {
                $table->index(['project_id', 'status'], 'tasks_project_id_status_index');
            }
            if (! Schema::hasIndex('tasks', 'tasks_due_date_index')) {
                $table->index('due_date', 'tasks_due_date_index');
            }
            if (! Schema::hasIndex('tasks', 'tasks_status_due_date_index')) {
                $table->index(['status', 'due_date'], 'tasks_status_due_date_index');
            }
        });

        // ====== Activity Logs ======
        if (Schema::hasTable('activity_logs')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                if (! Schema::hasIndex('activity_logs', 'activity_logs_loggable_created_index')) {
                    $table->index(
                        ['loggable_type', 'loggable_id', 'created_at'],
                        'activity_logs_loggable_created_index'
                    );
                }
            });
        }

        // ====== Project Members ======
        if (Schema::hasTable('project_members')) {
            Schema::table('project_members', function (Blueprint $table) {
                if (! Schema::hasIndex('project_members', 'project_members_user_id_index')) {
                    $table->index('user_id', 'project_members_user_id_index');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndexIfExists('projects_status_created_at_index');
            $table->dropIndexIfExists('projects_manager_id_index');
            $table->dropIndexIfExists('projects_department_id_index');
            $table->dropIndexIfExists('projects_end_date_status_index');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndexIfExists('tasks_assigned_to_status_index');
            $table->dropIndexIfExists('tasks_project_id_status_index');
            $table->dropIndexIfExists('tasks_due_date_index');
            $table->dropIndexIfExists('tasks_status_due_date_index');
        });

        if (Schema::hasTable('activity_logs')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->dropIndexIfExists('activity_logs_loggable_created_index');
            });
        }

        if (Schema::hasTable('project_members')) {
            Schema::table('project_members', function (Blueprint $table) {
                $table->dropIndexIfExists('project_members_user_id_index');
            });
        }
    }
};
