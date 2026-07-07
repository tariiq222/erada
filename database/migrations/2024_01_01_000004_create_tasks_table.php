<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->nullOnDelete(); // المهمة الأم
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete(); // المكلف بالمهمة
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title'); // عنوان المهمة
            $table->text('description')->nullable();

            $table->enum('status', ['todo', 'in_progress', 'in_review', 'completed'])
                ->default('todo');
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');

            // التواريخ
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('completed_date')->nullable();

            // نسبة الإنجاز والوقت المقدر
            $table->decimal('progress', 5, 2)->default(0);
            $table->integer('estimated_hours')->nullable(); // الساعات المقدرة
            $table->integer('actual_hours')->nullable(); // الساعات الفعلية

            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
