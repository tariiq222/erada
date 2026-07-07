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
        Schema::create('project_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            // تفاصيل المصروف
            $table->string('title'); // عنوان المصروف
            $table->text('description')->nullable(); // وصف تفصيلي
            $table->decimal('amount', 15, 2); // المبلغ
            $table->enum('category', [
                'human_resources',    // موارد بشرية
                'materials',          // مواد ومعدات
                'services',           // خدمات خارجية
                'operational',        // تشغيلية
                'travel',             // سفر وتنقل
                'training',           // تدريب
                'other',               // أخرى
            ])->default('other');

            $table->date('expense_date'); // تاريخ المصروف
            $table->string('reference_number')->nullable(); // رقم مرجعي (فاتورة، إيصال)
            $table->string('attachment_path')->nullable(); // مرفق (فاتورة)

            $table->timestamps();

            // فهرس للبحث السريع
            $table->index(['project_id', 'expense_date']);
            $table->index(['project_id', 'category']);
        });

        // إضافة حقل spent_amount في جدول المشاريع إذا لم يكن موجوداً
        if (! Schema::hasColumn('projects', 'spent_amount')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->decimal('spent_amount', 15, 2)->default(0)->after('budget');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_expenses');

        if (Schema::hasColumn('projects', 'spent_amount')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('spent_amount');
            });
        }
    }
};
