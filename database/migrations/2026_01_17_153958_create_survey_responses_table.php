<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_version_id')->nullable()->constrained('survey_versions')->nullOnDelete();

            $table->string('respondent_type', 20)->default('public'); // public, user
            $table->foreignId('respondent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('respondent_name')->nullable();
            $table->string('respondent_email')->nullable();
            $table->string('respondent_phone', 30)->nullable();

            $table->foreignId('invitation_id')->nullable()->constrained('survey_invitations')->nullOnDelete();

            $table->string('status', 20)->default('submitted'); // submitted, invalid, flagged
            $table->string('ip_hash', 64)->nullable();
            $table->string('fingerprint_hash', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedInteger('completion_time')->nullable(); // بالثواني

            $table->timestamp('consented_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reviewer_notes')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['survey_id', 'created_at']);
            $table->index(['fingerprint_hash', 'created_at']);
        });

        // إضافة foreign key للـ response_id في survey_invitations
        Schema::table('survey_invitations', function (Blueprint $table) {
            $table->foreign('response_id')->references('id')->on('survey_responses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('survey_invitations', function (Blueprint $table) {
            $table->dropForeign(['response_id']);
        });

        Schema::dropIfExists('survey_responses');
    }
};
