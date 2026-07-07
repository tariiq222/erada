<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_profiles', 'manager_id')) {
            return;
        }

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
        });

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn('manager_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_profiles', 'manager_id')) {
            return;
        }

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }
};
