<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_capacity_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            // 'member'  applies to every user whose department_id = this department
            // 'manager' applies to the user referenced by departments.manager_id
            $table->string('capacity', 10);
            // role_key of a scoped_role_definitions row with scope_type 'department'
            $table->string('role_key', 50);
            $table->timestamps();

            $table->unique(['department_id', 'capacity', 'role_key'], 'unique_dept_capacity_role');
            $table->index(['department_id', 'capacity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_capacity_roles');
    }
};
