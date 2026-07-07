<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit 2026-07-06 (P1): close the audit + performance gaps on
     * project_risks.
     *
     * 1. Add softDeletes. The prior hard-delete on cascade (`deleteProject`)
     *    bypassed the LogsActivity trait (query-builder delete). Soft-deletes
     *    are a precondition for the cascade policy that ProjectCrudService
     *    now enforces (ActivityLog entry per hard-delete). Soft-deletes here
     *    also give us an audit-friendly restore path.
     *
     * 2. Add the missing composite indexes that the 2026_07_11 migration
     *    `add_org_scope_indexes_to_projects_tasks_risks.php` *missed*: it
     *    added (organization_id, status) on the org-wide `risks` table but
     *    not on `project_risks`, which is the actual per-project register
     *    surfaced through Project::risks(). Hot queries:
     *      - Project::risks() ordered (project_id, status)
     *      - Per-project filtering by status (e.g. open risks only)
     *      - softDelete-aware listings (project_id, deleted_at)
     */
    public function up(): void
    {
        // 1. SoftDeletes column (idempotent — guard against re-run).
        if (! Schema::hasColumn('project_risks', 'deleted_at')) {
            Schema::table('project_risks', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        // 2. Composite indexes. Schema::hasIndex is supported on Laravel 11+
        // across pgsql / mysql / sqlite, so we use it for portability instead
        // of a MySQL-only `SHOW INDEX` query.
        Schema::table('project_risks', function (Blueprint $table) {
            if (! Schema::hasIndex('project_risks', 'project_risks_project_id_status_index')) {
                $table->index(['project_id', 'status'], 'project_risks_project_id_status_index');
            }
            if (! Schema::hasIndex('project_risks', 'project_risks_project_id_deleted_at_index')) {
                $table->index(['project_id', 'deleted_at'], 'project_risks_project_id_deleted_at_index');
            }
            if (! Schema::hasIndex('project_risks', 'project_risks_status_index')) {
                $table->index('status', 'project_risks_status_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_risks', function (Blueprint $table) {
            $table->dropIndex('project_risks_project_id_status_index');
            $table->dropIndex('project_risks_project_id_deleted_at_index');
            $table->dropIndex('project_risks_status_index');
            $table->dropSoftDeletes();
        });
    }
};
