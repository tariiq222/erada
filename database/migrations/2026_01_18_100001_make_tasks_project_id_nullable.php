<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * جعل project_id nullable لدعم المهام الشخصية والإدارية
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite لا يدعم تعديل الأعمدة مباشرة
            // نتجاهل لأن SQLite أكثر تساهلاً
            return;
        }

        // MySQL/PostgreSQL: تعديل العمود ليكون nullable
        // أولاً: حذف الـ foreign key
        try {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropForeign(['project_id']);
            });
        } catch (Exception $e) {
            // FK غير موجود أو اسم مختلف
        }

        // ثانياً: تعديل العمود ليكون nullable
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->change();
        });

        // ثالثاً: إعادة إضافة الـ foreign key
        try {
            Schema::table('tasks', function (Blueprint $table) {
                $table->foreign('project_id')
                    ->references('id')
                    ->on('projects')
                    ->nullOnDelete();
            });
        } catch (Exception $e) {
            // FK موجود مسبقاً
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // لا نعكس هذا التغيير لأنه قد يسبب فقدان بيانات
    }
};
