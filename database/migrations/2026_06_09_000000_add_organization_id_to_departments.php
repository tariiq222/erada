<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إضافة عمود organization_id إلى جدول departments (Phase 4.7).
     *
     * - العمود: nullable FK على organizations + index باسم departments_org_id_idx.
     * - backfill: UPDATE departments SET organization_id = (SELECT u.organization_id
     *   FROM users u WHERE u.id = departments.manager_id LIMIT 1)
     *   WHERE departments.organization_id IS NULL.
     * - up() محصَّن بـ `if (! Schema::hasColumn(...))` (D-10).
     * - down() يحذف FK + index + العمود بالترتيب الصحيح.
     * - PostgreSQL فقط (CLAUDE.md / docs/MIGRATION_CHECKLIST.md). لا SQLite.
     */
    public function up(): void
    {
        if (Schema::hasColumn('departments', 'organization_id')) {
            return; // العمود موجود مسبقاً، لا حاجة لإعادة الإضافة (D-10).
        }

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->nullOnDelete();
            $table->index('organization_id', 'departments_org_id_idx');
        });

        // Backfill: المدير → users.organization_id
        // WHERE organization_id IS NULL يضمن idempotency على إعادة التشغيل.
        DB::statement('
            UPDATE departments
            SET organization_id = (
                SELECT u.organization_id
                FROM users u
                WHERE u.id = departments.manager_id
                LIMIT 1
            )
            WHERE departments.organization_id IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex('departments_org_id_idx');
            $table->dropColumn('organization_id');
        });
    }
};
