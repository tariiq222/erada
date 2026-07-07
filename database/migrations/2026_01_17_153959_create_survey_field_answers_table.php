<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_field_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained('survey_responses')->cascadeOnDelete();
            $table->foreignId('field_id')->constrained('survey_fields')->cascadeOnDelete();
            $table->string('field_key', 100); // نسخة للثبات

            $table->json('answer_value')->nullable(); // القيمة الأساسية (JSON لدعم كل الأنواع)
            $table->text('answer_text')->nullable(); // نسخة نصية للبحث السريع
            $table->decimal('answer_number', 20, 4)->nullable(); // للقيم الرقمية
            $table->date('answer_date')->nullable(); // للتواريخ

            $table->timestamps();

            $table->unique(['response_id', 'field_id']);
            $table->index('field_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_field_answers');
    }
};
