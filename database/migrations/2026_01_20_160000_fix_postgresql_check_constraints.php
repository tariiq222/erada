<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * إصلاح PostgreSQL check constraints لتتوافق مع القيم الصحيحة
     *
     * المشاكل:
     * 1. stakeholders.role - يجب أن تقبل: end_user, implementer, consultant, governance, operations, influencer, other
     * 2. milestones.status - يجب أن تقبل: pending, in_progress, completed, overdue (وليس delayed)
     * 3. project_risks.status - يجب أن تقبل: open, mitigated, closed
     * 4. tasks.priority - يجب أن تقبل: low, medium, high, urgent, critical
     * 5. projects.priority - يجب أن تقبل: low, medium, high, urgent, critical
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // 1. إصلاح stakeholders.role check constraint
        $this->fixStakeholdersRoleConstraint();

        // 2. إصلاح milestones.status check constraint
        $this->fixMilestonesStatusConstraint();

        // 3. إصلاح project_risks.status check constraint
        $this->fixProjectRisksStatusConstraint();

        // 4. إصلاح tasks.priority check constraint
        $this->fixTasksPriorityConstraint();

        // 5. إصلاح projects.priority check constraint
        $this->fixProjectsPriorityConstraint();
    }

    private function fixStakeholdersRoleConstraint(): void
    {
        // حذف الـ constraint القديم إذا وجد
        DB::statement('ALTER TABLE stakeholders DROP CONSTRAINT IF EXISTS stakeholders_role_check');

        // إضافة constraint جديد بالقيم الصحيحة
        DB::statement("ALTER TABLE stakeholders ADD CONSTRAINT stakeholders_role_check CHECK (role IN ('end_user', 'implementer', 'consultant', 'governance', 'operations', 'influencer', 'other'))");
    }

    private function fixMilestonesStatusConstraint(): void
    {
        // أولاً: تحويل أي قيم delayed إلى overdue
        DB::table('milestones')->where('status', 'delayed')->update(['status' => 'overdue']);

        // حذف الـ constraint القديم
        DB::statement('ALTER TABLE milestones DROP CONSTRAINT IF EXISTS milestones_status_check');

        // إضافة constraint جديد مع overdue بدلاً من delayed
        DB::statement("ALTER TABLE milestones ADD CONSTRAINT milestones_status_check CHECK (status IN ('pending', 'in_progress', 'completed', 'overdue'))");
    }

    private function fixProjectRisksStatusConstraint(): void
    {
        // حذف الـ constraint القديم
        DB::statement('ALTER TABLE project_risks DROP CONSTRAINT IF EXISTS project_risks_status_check');

        // إضافة constraint جديد
        DB::statement("ALTER TABLE project_risks ADD CONSTRAINT project_risks_status_check CHECK (status IN ('open', 'mitigated', 'closed'))");
    }

    private function fixTasksPriorityConstraint(): void
    {
        // حذف الـ constraint القديم
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_priority_check');

        // إضافة constraint جديد مع urgent
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('low', 'medium', 'high', 'urgent', 'critical'))");
    }

    private function fixProjectsPriorityConstraint(): void
    {
        // حذف الـ constraint القديم
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_priority_check');

        // إضافة constraint جديد مع urgent
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_priority_check CHECK (priority IN ('low', 'medium', 'high', 'urgent', 'critical'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // إعادة الـ constraints القديمة
        DB::statement('ALTER TABLE stakeholders DROP CONSTRAINT IF EXISTS stakeholders_role_check');
        DB::statement("ALTER TABLE stakeholders ADD CONSTRAINT stakeholders_role_check CHECK (role IN ('sponsor', 'client', 'team_member', 'consultant', 'vendor', 'other'))");

        DB::statement('ALTER TABLE milestones DROP CONSTRAINT IF EXISTS milestones_status_check');
        DB::statement("ALTER TABLE milestones ADD CONSTRAINT milestones_status_check CHECK (status IN ('pending', 'in_progress', 'completed', 'delayed'))");

        DB::statement('ALTER TABLE project_risks DROP CONSTRAINT IF EXISTS project_risks_status_check');
        DB::statement("ALTER TABLE project_risks ADD CONSTRAINT project_risks_status_check CHECK (status IN ('identified', 'mitigating', 'resolved', 'accepted'))");
    }
};
