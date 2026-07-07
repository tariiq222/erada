<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->unsignedTinyInteger('likelihood');
            $table->unsignedTinyInteger('impact');
            $table->unsignedTinyInteger('score');
            $table->string('level', 20);
            $table->unsignedTinyInteger('residual_likelihood')->nullable();
            $table->unsignedTinyInteger('residual_impact')->nullable();
            $table->unsignedTinyInteger('residual_score')->nullable();
            $table->string('residual_level', 20)->nullable();
            $table->foreignId('assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->date('next_review_at')->nullable();
            $table->timestamp('review_due_notified_at')->nullable();
            $table->timestamps();

            $table->index(['risk_id', 'created_at'], 'risk_assessments_risk_created_idx');
            $table->index(['organization_id', 'next_review_at'], 'risk_assessments_org_next_review_idx');
            $table->index(['organization_id', 'level'], 'risk_assessments_org_level_idx');
        });

        DB::statement("ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessments_level_check CHECK (level IN ('low','medium','high','critical'))");
        DB::statement("ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessments_residual_level_check CHECK (residual_level IN ('low','medium','high','critical'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
