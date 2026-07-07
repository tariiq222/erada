<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * جدول الأدوار السياقية (Scoped Roles)
     * يدعم:
     * - أدوار على مستوى المؤسسة (organization)
     * - أدوار على مستوى القسم (department) مع وراثة هرمية
     * - أدوار على مستوى المشروع (project)
     */
    public function up(): void
    {
        // جدول تعريف الأدوار السياقية المتاحة
        Schema::create('scoped_role_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('اسم الدور: project_leader, department_manager, etc.');
            $table->string('display_name')->comment('الاسم العربي للعرض');
            $table->string('scope_type', 50)->comment('نوع السياق: organization, department, project');
            $table->text('description')->nullable();
            $table->json('default_abilities')->nullable()->comment('الصلاحيات الافتراضية لهذا الدور');
            $table->integer('level')->default(0)->comment('مستوى الدور (للترتيب)');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['name', 'scope_type']);
            $table->index('scope_type');
        });

        // جدول ربط المستخدمين بالأدوار السياقية
        Schema::create('model_has_scoped_roles', function (Blueprint $table) {
            $table->id();

            // المستخدم
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // الدور السياقي
            $table->string('role', 50)->comment('اسم الدور: project_leader, team_member, etc.');

            // السياق (Polymorphic)
            $table->string('scope_type', 50)->comment('نوع السياق: organization, department, project');
            $table->unsignedBigInteger('scope_id')->comment('ID السياق');

            // خيارات إضافية
            $table->boolean('inherit_to_children')->default(true)
                ->comment('هل يرث للفروع؟ (للأقسام)');

            // من أعطى هذا الدور
            $table->foreignId('granted_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // تاريخ الانتهاء (للأدوار المؤقتة)
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // منع التكرار
            $table->unique(['user_id', 'role', 'scope_type', 'scope_id'], 'unique_user_scoped_role');

            // فهارس للبحث السريع
            $table->index(['scope_type', 'scope_id'], 'idx_scope');
            $table->index(['user_id', 'scope_type'], 'idx_user_scope_type');
            $table->index('role');
            $table->index('expires_at');
        });

        // جدول سجل تغييرات الصلاحيات (Audit Trail)
        Schema::create('permission_audits', function (Blueprint $table) {
            $table->id();

            // نوع الحدث
            $table->string('event', 50)->comment('نوع الحدث: role_assigned, role_revoked, permission_changed, etc.');

            // من قام بالعملية
            $table->foreignId('actor_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // الهدف (المستخدم المتأثر)
            $table->foreignId('target_user_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // السياق (إن وجد)
            $table->string('scope_type', 50)->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();

            // التفاصيل
            $table->string('role')->nullable()->comment('الدور المتأثر');
            $table->json('old_value')->nullable()->comment('القيمة السابقة');
            $table->json('new_value')->nullable()->comment('القيمة الجديدة');
            $table->text('reason')->nullable()->comment('سبب التغيير');

            // معلومات الطلب
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // فهارس للتقارير
            $table->index('event');
            $table->index('actor_id');
            $table->index('target_user_id');
            $table->index(['scope_type', 'scope_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permission_audits');
        Schema::dropIfExists('model_has_scoped_roles');
        Schema::dropIfExists('scoped_role_definitions');
    }
};
