<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_answer_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('answer_id')->constrained('survey_field_answers')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0); // بالبايت
            $table->timestamp('uploaded_at');

            $table->index('answer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_answer_files');
    }
};
