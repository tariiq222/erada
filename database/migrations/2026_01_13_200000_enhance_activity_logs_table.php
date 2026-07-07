<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            // إضافة حقل user_agent إذا لم يكن موجوداً
            if (! Schema::hasColumn('activity_logs', 'user_agent')) {
                $table->text('user_agent')->nullable()->after('ip_address');
            }

            // إضافة حقل description للوصف
            if (! Schema::hasColumn('activity_logs', 'description')) {
                $table->string('description')->nullable()->after('action');
            }

            // إضافة حقل للبيانات الإضافية
            if (! Schema::hasColumn('activity_logs', 'metadata')) {
                $table->json('metadata')->nullable()->after('new_values');
            }
        });

        // إضافة فهارس لتحسين الأداء (تنفيذ منفصل لتجنب مشاكل SQLite)
        try {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->index('action', 'activity_logs_action_idx');
            });
        } catch (Exception $e) {
            // الفهرس موجود مسبقاً
        }

        try {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->index('created_at', 'activity_logs_created_at_idx');
            });
        } catch (Exception $e) {
            // الفهرس موجود مسبقاً
        }
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (Schema::hasColumn('activity_logs', 'user_agent')) {
                $table->dropColumn('user_agent');
            }
            if (Schema::hasColumn('activity_logs', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('activity_logs', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });
    }
};
