<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إزالة عمود organization_id من جدول المشاريع
     * (جزء من إزالة Multi-Tenancy)
     */
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'organization_id')) {
            return; // العمود غير موجود، لا حاجة لفعل شيء
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // البحث عن اسم الـ foreign key الفعلي
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'projects'
                AND COLUMN_NAME = 'organization_id'
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");

            // حذف كل الـ foreign keys المرتبطة
            foreach ($foreignKeys as $fk) {
                DB::statement("ALTER TABLE projects DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
            }

            // حذف العمود
            DB::statement('ALTER TABLE projects DROP COLUMN organization_id');
        } else {
            // SQLite
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('organization_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id');
            }
        });
    }
};
