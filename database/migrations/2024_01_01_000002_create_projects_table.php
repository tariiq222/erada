<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // اسم المشروع
            $table->string('code')->unique()->nullable(); // رمز المشروع
            $table->text('description')->nullable(); // وصف المشروع
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete(); // مدير المشروع

            // الحالة والأولوية
            $table->enum('status', ['draft', 'planning', 'in_progress', 'on_hold', 'completed', 'cancelled'])
                ->default('draft');
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');

            // التواريخ
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();

            // نسبة الإنجاز
            $table->decimal('progress', 5, 2)->default(0); // 0.00 - 100.00

            // الميزانية
            $table->decimal('budget', 15, 2)->nullable();
            $table->decimal('actual_cost', 15, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
