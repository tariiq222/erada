<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * أرشفة وحذف جدول strategic_objectives:
     * 1. إنشاء جدول archived_strategic_objectives
     * 2. نسخ البيانات
     * 3. تحديث العلاقات في الجداول الأخرى
     * 4. حذف الجدول الأصلي
     */
    public function up(): void
    {
        // تخطي إذا كان جدول الأرشيف موجود وجدول objectives غير موجود (تم التحويل مسبقاً)
        if (Schema::hasTable('archived_strategic_objectives') && ! Schema::hasTable('strategic_objectives')) {
            return;
        }

        // الخطوة 1: إنشاء جدول الأرشيف (تخطي إذا موجود)
        if (Schema::hasTable('archived_strategic_objectives')) {
            // الجدول موجود، تخطي الإنشاء
        } else {
            Schema::create('archived_strategic_objectives', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('original_id');
                $table->string('code', 20);
                $table->string('name');
                $table->text('description')->nullable();
                $table->unsignedBigInteger('portfolio_id')->nullable();
                $table->string('bsc_perspective', 50)->nullable();
                $table->decimal('target_value', 15, 2)->nullable();
                $table->string('measurement_unit', 50)->nullable();
                $table->decimal('current_value', 15, 2)->default(0);
                $table->decimal('baseline_value', 15, 2)->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->decimal('weight', 5, 2)->default(1);
                $table->string('status', 20)->default('draft');
                $table->unsignedTinyInteger('order')->default(0);
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->timestamp('archived_at')->useCurrent();
                $table->string('archive_reason', 500)->default('PMI restructuring: objectives layer removed');

                $table->index('original_id');
                $table->index('portfolio_id');
            });
        }

        // الخطوة 2: نسخ البيانات إلى جدول الأرشيف
        if (Schema::hasTable('strategic_objectives')) {
            DB::statement('
                INSERT INTO archived_strategic_objectives
                (original_id, code, name, description, portfolio_id, bsc_perspective,
                 target_value, measurement_unit, current_value, baseline_value,
                 start_date, end_date, weight, status, "order", owner_id, created_by,
                 created_at, updated_at)
                SELECT
                    id, code, name, description, portfolio_id, bsc_perspective,
                    target_value, measurement_unit, current_value, baseline_value,
                    start_date, end_date, weight, status, "order", owner_id, created_by,
                    created_at, updated_at
                FROM strategic_objectives
            ');
        }

        // الخطوة 3: تحديث strategic_kpis لإزالة العلاقات مع objectives
        // نحتاج لتحديث measurable_type من StrategicObjective إلى Program إذا كان مرتبط
        if (Schema::hasTable('strategic_kpis')) {
            // حذف KPIs المرتبطة بـ objectives (ستُحفظ في الأرشيف)
            DB::statement("
                DELETE FROM strategic_kpis
                WHERE measurable_type = 'App\\\\Modules\\\\Strategy\\\\Models\\\\StrategicObjective'
            ");
        }

        // الخطوة 4: حذف الجدول الأصلي
        Schema::dropIfExists('strategic_objectives');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // إعادة إنشاء جدول strategic_objectives
        Schema::create('strategic_objectives', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('portfolio_id')->nullable()->constrained('portfolios')->nullOnDelete();
            $table->string('bsc_perspective', 50)->nullable();
            $table->decimal('target_value', 15, 2)->nullable();
            $table->string('measurement_unit', 50)->nullable();
            $table->decimal('current_value', 15, 2)->default(0);
            $table->decimal('baseline_value', 15, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('weight', 5, 2)->default(1);
            $table->string('status', 20)->default('draft');
            $table->unsignedTinyInteger('order')->default(0);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['portfolio_id', 'status', 'deleted_at']);
            $table->index(['bsc_perspective', 'status']);
        });

        // استعادة البيانات من الأرشيف
        if (Schema::hasTable('archived_strategic_objectives')) {
            DB::statement('
                INSERT INTO strategic_objectives
                (id, code, name, description, portfolio_id, bsc_perspective,
                 target_value, measurement_unit, current_value, baseline_value,
                 start_date, end_date, weight, status, "order", owner_id, created_by,
                 created_at, updated_at)
                SELECT
                    original_id, code, name, description, portfolio_id, bsc_perspective,
                    target_value, measurement_unit, current_value, baseline_value,
                    start_date, end_date, weight, status, "order", owner_id, created_by,
                    created_at, updated_at
                FROM archived_strategic_objectives
            ');
        }

        // حذف جدول الأرشيف
        Schema::dropIfExists('archived_strategic_objectives');
    }
};
