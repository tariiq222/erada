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
        Schema::table('tasks', function (Blueprint $table) {
            // نوع المهمة: project, personal, department, recurring
            if (! Schema::hasColumn('tasks', 'type')) {
                $table->string('type', 20)->default('project')->after('id');
            }

            // صاحب المهمة (للمهام الشخصية)
            if (! Schema::hasColumn('tasks', 'owner_id')) {
                $table->foreignId('owner_id')->nullable()->after('created_by')
                    ->constrained('users')->nullOnDelete();
            }

            // القسم (للمهام الإدارية)
            if (! Schema::hasColumn('tasks', 'department_id')) {
                $table->foreignId('department_id')->nullable()->after('project_id')
                    ->constrained('departments')->nullOnDelete();
            }

            // هل المهمة خاصة؟
            if (! Schema::hasColumn('tasks', 'is_private')) {
                $table->boolean('is_private')->default(false)->after('order');
            }

            // للمهام المتكررة
            if (! Schema::hasColumn('tasks', 'recurrence_rule')) {
                $table->string('recurrence_rule')->nullable()->after('is_private');
            }
            if (! Schema::hasColumn('tasks', 'recurring_parent_id')) {
                $table->foreignId('recurring_parent_id')->nullable()->after('recurrence_rule')
                    ->constrained('tasks')->nullOnDelete();
            }
            if (! Schema::hasColumn('tasks', 'next_occurrence')) {
                $table->date('next_occurrence')->nullable()->after('recurring_parent_id');
            }
        });

        // إضافة الفهارس (تجاهل الأخطاء إذا كانت موجودة)
        try {
            Schema::table('tasks', function (Blueprint $table) {
                $table->index('type', 'tasks_type_index');
            });
        } catch (Exception $e) {
            // الفهرس موجود مسبقاً
        }

        try {
            Schema::table('tasks', function (Blueprint $table) {
                $table->index('owner_id', 'tasks_owner_id_index');
            });
        } catch (Exception $e) {
            // الفهرس موجود مسبقاً
        }

        try {
            Schema::table('tasks', function (Blueprint $table) {
                $table->index(['type', 'owner_id'], 'tasks_type_owner_id_index');
            });
        } catch (Exception $e) {
            // الفهرس موجود مسبقاً
        }

        try {
            Schema::table('tasks', function (Blueprint $table) {
                $table->index(['type', 'department_id'], 'tasks_type_department_id_index');
            });
        } catch (Exception $e) {
            // الفهرس موجود مسبقاً
        }

        // تحديث المهام الموجودة لتكون من نوع project
        DB::table('tasks')->whereNull('type')->update(['type' => 'project']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropForeign(['department_id']);
            $table->dropForeign(['recurring_parent_id']);

            $table->dropIndex(['type']);
            $table->dropIndex(['owner_id']);
            $table->dropIndex(['type', 'owner_id']);
            $table->dropIndex(['type', 'department_id']);

            $table->dropColumn([
                'type',
                'owner_id',
                'department_id',
                'is_private',
                'recurrence_rule',
                'recurring_parent_id',
                'next_occurrence',
            ]);
        });
    }
};
