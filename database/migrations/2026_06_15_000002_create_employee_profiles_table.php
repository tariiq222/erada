<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('employee_no')->nullable();
            $table->date('hire_date')->nullable();
            $table->string('employment_type')->default('full_time'); // full_time | part_time | contract
            $table->string('employment_status')->default('active');  // active | suspended | terminated
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('employment_status');
            $table->index('employee_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
