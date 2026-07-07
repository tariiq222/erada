<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * توحيد أدوار المشاريع إلى نظام الأدوار السياقية (scoped roles).
 *
 * 1) نقل حاملي manager_id / supervisor_id / sponsor_id إلى صفوف
 *    model_has_scoped_roles بدور 'manager' (صف manager واحد لكل مستخدم/مشروع).
 * 2) ترقية أي صفوف 'team_member' (سياق project) إلى 'member'.
 * 3) إسقاط الأعمدة الثلاثة من جدول projects نهائياً.
 *
 * ملاحظة: القيد الفريد هو (user_id, role, scope_type, scope_id) — لذا قد يملك
 * المستخدم صف 'member' وصف 'manager' معاً دون تعارض. نحرس صف المدير بـ NOT EXISTS
 * على نفس الدور لتفادي التكرار حين يكون المستخدم في أكثر من عمود.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            if (! Schema::hasTable('projects') || ! Schema::hasTable('model_has_scoped_roles')) {
                return;
            }

            // (أ) نقل حاملي الأعمدة الثلاثة إلى دور 'manager'
            $managerColumns = array_values(array_filter(
                ['manager_id', 'supervisor_id', 'sponsor_id'],
                fn (string $column) => Schema::hasColumn('projects', $column)
            ));

            if (! empty($managerColumns)) {
                // بناء UNION من أزواج (user_id, project_id) المميزة عبر الأعمدة الموجودة
                $selects = array_map(
                    fn (string $column) => "SELECT {$column} AS user_id, id AS project_id FROM projects WHERE {$column} IS NOT NULL",
                    $managerColumns
                );
                $unionSql = implode("\n                    UNION\n                    ", $selects);

                DB::statement(<<<SQL
                    INSERT INTO model_has_scoped_roles
                        (user_id, role, scope_type, scope_id, inherit_to_children, granted_by, expires_at, created_at, updated_at)
                    SELECT DISTINCT leaders.user_id, 'manager', 'project', leaders.project_id, false, NULL::bigint, NULL::timestamp, NOW(), NOW()
                    FROM (
                        {$unionSql}
                    ) AS leaders
                    WHERE NOT EXISTS (
                        SELECT 1 FROM model_has_scoped_roles s
                        WHERE s.user_id = leaders.user_id
                          AND s.scope_type = 'project'
                          AND s.scope_id = leaders.project_id
                          AND s.role = 'manager'
                    )
                    ON CONFLICT (user_id, role, scope_type, scope_id) DO NOTHING
                    SQL);
            }

            // (ب) ترقية صفوف team_member إلى member (سياق project)
            DB::table('model_has_scoped_roles')
                ->where('scope_type', 'project')
                ->where('role', 'team_member')
                ->update(['role' => 'member']);

            // (ج) إسقاط الأعمدة الثلاثة من جدول projects
            foreach (['manager_id', 'supervisor_id', 'sponsor_id'] as $column) {
                if (Schema::hasColumn('projects', $column)) {
                    // إسقاط المفتاح الأجنبي أولاً إن وُجد (أسماء افتراضية + مخصصة)
                    foreach (["projects_{$column}_foreign", "projects_{$column}_fk"] as $fkName) {
                        DB::statement("ALTER TABLE projects DROP CONSTRAINT IF EXISTS \"{$fkName}\"");
                    }
                    Schema::table('projects', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                }
            }
        });
    }

    public function down(): void
    {
        // إعادة الأعمدة nullable فقط (دون استعادة البيانات — البيانات الآن في scoped roles)
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'manager_id')) {
                $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('projects', 'supervisor_id')) {
                $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('projects', 'sponsor_id')) {
                $table->foreignId('sponsor_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }
};
