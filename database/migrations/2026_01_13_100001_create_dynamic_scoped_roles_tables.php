<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // جدول أنواع السياقات (department, project, contract, etc.)
        if (! Schema::hasTable('scope_types')) {
            Schema::create('scope_types', function (Blueprint $table) {
                $table->id();
                $table->string('key', 50)->unique(); // department, project, contract
                $table->string('label_ar', 100); // القسم، المشروع، العقد
                $table->string('label_en', 100)->nullable();
                $table->string('model_class'); // App\Modules\HR\Models\Department
                $table->string('icon')->nullable(); // heroicon-o-building-office
                $table->string('color', 20)->default('primary'); // اللون في الواجهة
                $table->boolean('supports_hierarchy')->default(false); // هل يدعم التوريث للفرعيات
                $table->boolean('supports_expiry')->default(false); // هل يدعم تاريخ انتهاء
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // تخطي إنشاء scoped_role_definitions إذا كان موجوداً
        // (تم إنشاؤه في 2026_01_12_100001_create_scoped_roles_tables.php)
        if (Schema::hasTable('scoped_role_definitions')) {
            return;
        }

        // جدول تعريفات الأدوار لكل نوع سياق (نسخة بديلة)
        Schema::create('scoped_role_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scope_type_id')->constrained('scope_types')->cascadeOnDelete();
            $table->string('role_key', 50); // department_manager, project_leader
            $table->string('label_ar', 100); // مدير القسم، مدير المشروع
            $table->string('label_en', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('color', 20)->default('primary'); // لون الـ badge
            $table->json('permissions')->nullable(); // صلاحيات هذا الدور
            $table->boolean('is_admin_role')->default(false); // هل هذا دور إداري
            $table->boolean('can_manage_members')->default(false); // هل يمكنه إدارة الأعضاء
            $table->boolean('can_edit')->default(false); // هل يمكنه التعديل
            $table->boolean('can_delete')->default(false); // هل يمكنه الحذف
            $table->boolean('can_view_all')->default(false); // هل يمكنه رؤية الكل
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // كل دور فريد لكل نوع سياق
            $table->unique(['scope_type_id', 'role_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('model_has_scoped_roles', 'role_definition_id')) {
            Schema::table('model_has_scoped_roles', function (Blueprint $table) {
                $table->dropForeign(['role_definition_id']);
                $table->dropColumn('role_definition_id');
            });
        }

        Schema::dropIfExists('scoped_role_definitions');
        Schema::dropIfExists('scope_types');
    }
};
