<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إنشاء جداول الأدوار الافتراضية للأقسام:
     * - department_default_roles: ربط القسم بالأدوار التي تُمنح تلقائياً لمستخدميه
     * - department_role_grants: سجل الأدوار الممنوحة تلقائياً (لمعرفة أي قسم منحها)
     */
    public function up(): void
    {
        Schema::create('department_default_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['department_id', 'role_id']);
        });

        Schema::create('department_role_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_role_grants');
        Schema::dropIfExists('department_default_roles');
    }
};
