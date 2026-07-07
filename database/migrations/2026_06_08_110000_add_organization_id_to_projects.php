<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إعادة إضافة عمود organization_id إلى جدول projects (D-01 / D-10).
     *
     * كان العمود موجوداً ثم أُزيل في migration 2025_12_31_130000_remove_organization_id_from_projects
     * كجزء من إزالة Multi-Tenancy. يُعاد الآن لاغلاق ثغرة عزل مؤسسي P0
     * في وحدة Projects (R13).
     *
     * - العمود: nullable FK على organizations + index باسم projects_org_id_idx.
     * - backfill: UPDATE projects SET organization_id = (SELECT u.organization_id
     *   FROM users u WHERE u.id = COALESCE(projects.manager_id, projects.supervisor_id,
     *   projects.created_by) LIMIT 1) WHERE projects.organization_id IS NULL.
     *   الصفوف التي لا يمكن اشتقاق org لها تبقى NULL — deny-not-bypass (D-02)
     *   يعالجها في طبقة الـ controller في خطة 4.5-02.
     * - up() محصَّن بـ `if (! Schema::hasColumn(...))` فيعيد تشغيله بأمان (D-10).
     * - down() يحذف FK + index + العمود بالترتيب الصحيح.
     * - PostgreSQL فقط (CLAUDE.md / docs/MIGRATION_CHECKLIST.md). لا SQLite.
     */
    public function up(): void
    {
        if (Schema::hasColumn('projects', 'organization_id')) {
            return; // العمود موجود مسبقاً، لا حاجة لإعادة الإضافة (D-10).
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->nullOnDelete();
            $table->index('organization_id', 'projects_org_id_idx');
        });

        // Backfill: المالك الفعلي من COALESCE(manager_id, supervisor_id, created_by) → users.organization_id
        // WHERE organization_id IS NULL يضمن idempotency على إعادة التشغيل.
        DB::statement('
            UPDATE projects
            SET organization_id = (
                SELECT u.organization_id
                FROM users u
                WHERE u.id = COALESCE(projects.manager_id, projects.supervisor_id, projects.created_by)
                LIMIT 1
            )
            WHERE projects.organization_id IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex('projects_org_id_idx');
            $table->dropColumn('organization_id');
        });
    }
};
