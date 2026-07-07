<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إضافة Foreign Keys المفقودة لجدول projects
 *
 * المشكلة: جدول projects معرّف بأسلوب SQL يدوي ويفتقد 6 علاقات FK
 * الحل: إضافة العلاقات مع التعامل مع خصوصية SQLite
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite لا يدعم ALTER TABLE ADD CONSTRAINT
            // نحتاج إعادة بناء الجدول - لكن هذا خطير على البيانات
            // بدلاً من ذلك، نضيف الفهارس فقط للأداء
            // والتطبيق (Laravel) سيتحقق من العلاقات عبر Eloquent

            // إضافة فهارس للأعمدة التي ستكون FK
            $this->addIndexIfNotExists('projects', 'projects_supervisor_id_fk_index', 'supervisor_id');
            $this->addIndexIfNotExists('projects', 'projects_created_by_fk_index', 'created_by');
            $this->addIndexIfNotExists('projects', 'projects_program_id_fk_index', 'program_id');

            // ملاحظة: manager_id, department_id, organization_id لديهم فهارس بالفعل

        } else {
            // MySQL / PostgreSQL - يدعمون إضافة FK مباشرة
            Schema::table('projects', function (Blueprint $table) {
                // التحقق من عدم وجود FK قبل الإضافة
                if (! $this->foreignKeyExists('projects', 'projects_manager_id_foreign')) {
                    $table->foreign('manager_id', 'projects_manager_id_foreign')
                        ->references('id')
                        ->on('users')
                        ->onDelete('set null');
                }

                if (! $this->foreignKeyExists('projects', 'projects_supervisor_id_foreign')) {
                    $table->foreign('supervisor_id', 'projects_supervisor_id_foreign')
                        ->references('id')
                        ->on('users')
                        ->onDelete('set null');
                }

                if (! $this->foreignKeyExists('projects', 'projects_department_id_foreign')) {
                    $table->foreign('department_id', 'projects_department_id_foreign')
                        ->references('id')
                        ->on('departments')
                        ->onDelete('set null');
                }

                if (! $this->foreignKeyExists('projects', 'projects_created_by_foreign')) {
                    $table->foreign('created_by', 'projects_created_by_foreign')
                        ->references('id')
                        ->on('users')
                        ->onDelete('set null');
                }

                if (! $this->foreignKeyExists('projects', 'projects_program_id_foreign')) {
                    $table->foreign('program_id', 'projects_program_id_foreign')
                        ->references('id')
                        ->on('programs')
                        ->onDelete('set null');
                }

                if (! $this->foreignKeyExists('projects', 'projects_organization_id_foreign')) {
                    $table->foreign('organization_id', 'projects_organization_id_foreign')
                        ->references('id')
                        ->on('organizations')
                        ->onDelete('set null');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // حذف الفهارس
            DB::statement('DROP INDEX IF EXISTS "projects_supervisor_id_fk_index"');
            DB::statement('DROP INDEX IF EXISTS "projects_created_by_fk_index"');
            DB::statement('DROP INDEX IF EXISTS "projects_program_id_fk_index"');
        } else {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropForeign('projects_manager_id_foreign');
                $table->dropForeign('projects_supervisor_id_foreign');
                $table->dropForeign('projects_department_id_foreign');
                $table->dropForeign('projects_created_by_foreign');
                $table->dropForeign('projects_program_id_foreign');
                $table->dropForeign('projects_organization_id_foreign');
            });
        }
    }

    /**
     * إضافة فهرس إذا لم يكن موجوداً
     */
    private function addIndexIfNotExists(string $table, string $indexName, string $column): void
    {
        $exists = DB::select(
            "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?",
            [$table, $indexName]
        );

        if (empty($exists)) {
            DB::statement("CREATE INDEX \"{$indexName}\" ON \"{$table}\" (\"{$column}\")");
        }
    }

    /**
     * التحقق من وجود FK
     */
    private function foreignKeyExists(string $table, string $keyName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $result = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = ?
                 AND CONSTRAINT_NAME = ?
                 AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$table, $keyName]
            );

            return count($result) > 0;
        }

        if ($driver === 'pgsql') {
            $result = DB::select(
                'SELECT conname FROM pg_constraint
                 WHERE conname = ?',
                [$keyName]
            );

            return count($result) > 0;
        }

        return false;
    }
};
