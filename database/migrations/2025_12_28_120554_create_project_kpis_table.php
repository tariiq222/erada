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
        Schema::create('project_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('indicator'); // المؤشر
            $table->string('measurement_method')->nullable(); // طريقة القياس
            $table->string('baseline')->nullable(); // القيمة الحالية (Baseline)
            $table->string('target')->nullable(); // المستهدف
            $table->string('current_value')->nullable(); // القيمة الحالية المحققة
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_kpis');
    }
};
