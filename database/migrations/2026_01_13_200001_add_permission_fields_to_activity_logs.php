<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إضافة حقول الصلاحيات إلى جدول activity_logs
 * هذا يتيح دمج سجل الصلاحيات (permission_audits) مع سجل الأنشطة
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            // المستخدم المتأثر (في أحداث الصلاحيات)
            if (! Schema::hasColumn('activity_logs', 'target_user_id')) {
                $table->foreignId('target_user_id')->nullable()->after('user_id')
                    ->constrained('users')->nullOnDelete();
            }

            // نوع السياق (project, department, organization)
            if (! Schema::hasColumn('activity_logs', 'scope_type')) {
                $table->string('scope_type')->nullable()->after('loggable_id');
            }

            // معرف السياق
            if (! Schema::hasColumn('activity_logs', 'scope_id')) {
                $table->unsignedBigInteger('scope_id')->nullable()->after('scope_type');
            }

            // الدور
            if (! Schema::hasColumn('activity_logs', 'role')) {
                $table->string('role')->nullable()->after('scope_id');
            }

            // السبب
            if (! Schema::hasColumn('activity_logs', 'reason')) {
                $table->text('reason')->nullable()->after('role');
            }
        });

        // إضافة فهارس
        try {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->index('scope_type', 'activity_logs_scope_type_idx');
            });
        } catch (Exception $e) {
            // الفهرس موجود مسبقاً
        }

        try {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->index(['scope_type', 'scope_id'], 'activity_logs_scope_idx');
            });
        } catch (Exception $e) {
            // الفهرس موجود مسبقاً
        }
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            // إزالة الفهارس أولاً
            try {
                $table->dropIndex('activity_logs_scope_type_idx');
            } catch (Exception $e) {
            }

            try {
                $table->dropIndex('activity_logs_scope_idx');
            } catch (Exception $e) {
            }

            // إزالة العلاقات
            if (Schema::hasColumn('activity_logs', 'target_user_id')) {
                try {
                    $table->dropForeign(['target_user_id']);
                } catch (Exception $e) {
                }
                $table->dropColumn('target_user_id');
            }

            // إزالة الأعمدة
            $columns = ['scope_type', 'scope_id', 'role', 'reason'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('activity_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
