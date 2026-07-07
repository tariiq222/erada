<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: a project belongs to its DEPARTMENT's organization, which is the
 * source of truth for multi-tenant isolation (visibility/scoping all key off it).
 *
 * Historic rows were tagged with the creator's organization_id, which could differ
 * from the department's org — that mismatch hid those projects from their own
 * organization under org isolation (e.g. a project in an org-2 department tagged
 * org-1 was invisible to org-2 managers). Re-derive organization_id from the
 * department. The creation path is also fixed (ProjectCrudService) so this cannot
 * recur. Idempotent: only touches rows whose org actually differs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE projects p
            SET organization_id = d.organization_id
            FROM departments d
            WHERE p.department_id = d.id
              AND p.organization_id IS DISTINCT FROM d.organization_id
        ');
    }

    public function down(): void
    {
        // Data correction backfill — no safe inverse.
    }
};
