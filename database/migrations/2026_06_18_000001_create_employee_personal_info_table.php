<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_personal_info', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')
                ->unique()
                ->constrained('employee_profiles')
                ->cascadeOnDelete();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name_arabic')->nullable();

            $table->string('nationality', 2)->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();

            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone', 20)->nullable();
            $table->string('emergency_contact_relation')->nullable();

            $table->string('national_id', 10)->nullable();
            $table->date('national_id_issue_date')->nullable();
            $table->string('national_id_issue_place')->nullable();
            $table->date('national_id_expiry_date')->nullable();
            $table->string('national_id_document_path')->nullable();

            $table->string('iqama_number', 10)->nullable();
            $table->date('iqama_issue_date')->nullable();
            $table->string('iqama_issue_place')->nullable();
            $table->date('iqama_expiry_date')->nullable();
            $table->string('iqama_document_path')->nullable();

            $table->string('profession')->nullable();
            $table->string('religion')->nullable();
            $table->string('sponsor')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('nationality');
            $table->index('national_id');
            $table->index('iqama_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_personal_info');
    }
};
