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
        // جدول مخرجات المرحلة (Deliverables) - كل مرحلة لها مخرجات
        Schema::create('milestone_deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // اسم المخرج
            $table->text('description')->nullable(); // وصف المخرج (الأنشطة ومراحل الإنجاز)
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->decimal('progress', 5, 2)->default(0); // نسبة الإنجاز
            $table->integer('order')->default(0); // الترتيب
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_deliverables');
    }
};
