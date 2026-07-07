<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_mapping_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('target_model', 100); // departments, users, etc.
            $table->json('mappings'); // field_key => { column, transforms[], upsert_key, required }

            $table->string('insert_policy', 20)->default('create_only'); // create_only, update_only, upsert
            $table->string('conflict_policy', 20)->default('require_review'); // skip, overwrite, require_review

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['survey_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_mapping_templates');
    }
};
