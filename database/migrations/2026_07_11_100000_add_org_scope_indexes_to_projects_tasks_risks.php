<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes for org-scoped list queries.
 *
 * Why these three:
 *  - projects(organization_id, department_id, status):
 *      Covers ProjectQueryService::getPaginatedList (line 149) where the
 *      permission filter is always org-bound and the filter chain commonly
 *      narrows by department_id and status together.
 *  - projects(organization_id, type):
 *      Same code path when callers filter by project type (improvement vs.
 *      development) without a department/status filter.
 *  - tasks(organization_id, status):
 *      Covers EloquentTaskRepository::getPaginated (line 40, visibleTo path)
 *      and UserTaskScope for non-super-admin users; the active-status filter
 *      is the dominant list workload.
 *  - risks(organization_id, status):
 *      Covers RiskController@index (line 112) which calls UserRiskScope::apply
 *      -- that scope always sets organization_id for non-super-admin users,
 *      and the index then narrows by status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->index(
                ['organization_id', 'department_id', 'status'],
                'projects_org_dept_status_index'
            );
            $table->index(
                ['organization_id', 'type'],
                'projects_org_type_index'
            );
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->index(
                ['organization_id', 'status'],
                'tasks_org_status_index'
            );
        });

        Schema::table('risks', function (Blueprint $table) {
            $table->index(
                ['organization_id', 'status'],
                'risks_org_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_org_dept_status_index');
            $table->dropIndex('projects_org_type_index');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_org_status_index');
        });

        Schema::table('risks', function (Blueprint $table) {
            $table->dropIndex('risks_org_status_index');
        });
    }
};
