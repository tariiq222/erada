<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // إضافة حقل المشرف في جدول المشاريع (إذا لم يكن موجوداً)
        if (! Schema::hasColumn('projects', 'supervisor_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->foreignId('supervisor_id')
                    ->nullable()
                    ->after('manager_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        // إنشاء جدول إعدادات المشاريع (إذا لم يكن موجوداً)
        if (! Schema::hasTable('project_settings')) {
            Schema::create('project_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->string('type')->default('string'); // string, json, boolean, integer
                $table->string('description')->nullable();
                $table->timestamps();
            });

            // إدراج الإعداد الافتراضي للأقسام المسموح لها بالإشراف
            DB::table('project_settings')->insert([
                [
                    'key' => 'supervisor_allowed_departments',
                    'value' => json_encode([]), // افتراضياً فارغ - يجب تحديده من الإعدادات
                    'type' => 'json',
                    'description' => 'الأقسام المسموح لمستخدميها بالإشراف على المشاريع',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'key' => 'supervisor_required',
                    'value' => 'false',
                    'type' => 'boolean',
                    'description' => 'هل تحديد مشرف المشروع إلزامي',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // حذف جدول الإعدادات
        Schema::dropIfExists('project_settings');

        // حذف حقل المشرف
        if (Schema::hasColumn('projects', 'supervisor_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropForeign(['supervisor_id']);
                $table->dropColumn('supervisor_id');
            });
        }
    }
};
