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
        Schema::create('project_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('risk'); // الخطر
            $table->enum('probability', ['low', 'medium', 'high'])->default('medium'); // الاحتمالية
            $table->enum('impact', ['low', 'medium', 'high'])->default('medium'); // الأثر
            $table->text('response')->nullable(); // خطة الاستجابة
            $table->enum('status', ['identified', 'mitigating', 'resolved', 'accepted'])->default('identified'); // الحالة
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_risks');
    }
};
