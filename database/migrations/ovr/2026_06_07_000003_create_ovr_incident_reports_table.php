<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ovr_incident_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('report_number', 50)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users');
            $table->string('reporter_name', 255);
            $table->string('reporter_email', 255)->nullable();
            $table->string('reporter_extension', 20)->nullable();
            $table->string('reporter_job_title', 100)->nullable();
            $table->foreignId('reporter_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('reporter_section_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->timestamp('incident_datetime');
            $table->boolean('is_patient_related')->default(false);
            $table->string('patient_name', 255)->nullable();
            $table->string('patient_file_number', 100)->nullable();
            $table->string('patient_gender', 20)->nullable();
            $table->date('patient_dob')->nullable();
            $table->boolean('informed_authority')->default(false);
            $table->foreignUuid('incident_type_id')->constrained('ovr_incident_types');
            $table->foreignUuid('reportable_incident_type_id')->nullable()->constrained('ovr_reportable_types')->nullOnDelete();
            $table->text('incident_description');
            $table->text('actions_taken')->nullable();
            $table->json('contributing_factors')->nullable();
            $table->boolean('immediate_action_required')->default(false);
            $table->string('severity_level', 20);
            $table->string('status', 20)->default('draft');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closure_reason')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable();
            $table->boolean('is_confidential')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'severity_level']);
            $table->index(['reporter_id', 'status']);
            $table->index('report_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ovr_incident_reports');
    }
};
