<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('version_hash', 64);
            $table->json('snapshot_json');
            $table->unsignedInteger('fields_count')->default(0);
            $table->unsignedInteger('sections_count')->default(0);
            $table->timestamp('created_at');

            $table->unique('version_hash');
            $table->index('survey_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_versions');
    }
};
