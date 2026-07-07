<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('survey_sections')->nullOnDelete();

            $table->string('field_key', 100);
            $table->string('name', 100);
            $table->string('label');
            $table->text('description')->nullable();

            $table->string('type', 30); // text, textarea, select, radio, checkbox, etc.
            $table->json('config')->nullable(); // options, validation, matrix config, etc.

            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('visibility_rules')->nullable();

            $table->timestamps();

            $table->unique(['survey_id', 'field_key']);
            $table->index(['survey_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_fields');
    }
};
