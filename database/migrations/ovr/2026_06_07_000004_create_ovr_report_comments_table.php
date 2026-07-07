<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ovr_report_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('ovr_incident_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('author_name', 255);
            $table->text('text');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index('report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ovr_report_comments');
    }
};
