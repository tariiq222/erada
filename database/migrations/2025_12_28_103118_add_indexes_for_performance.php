<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Projects indexes
        Schema::table('projects', function (Blueprint $table) {
            $table->index('status');
            $table->index('priority');
            $table->index('manager_id');
        });

        // Tasks indexes
        Schema::table('tasks', function (Blueprint $table) {
            $table->index('status');
            $table->index('priority');
            $table->index('project_id');
            $table->index('assigned_to');
            $table->index('due_date');
            $table->index(['status', 'due_date']);
            $table->index(['project_id', 'status']);
        });

        // Users indexes
        Schema::table('users', function (Blueprint $table) {
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['priority']);
            $table->dropIndex(['manager_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['priority']);
            $table->dropIndex(['project_id']);
            $table->dropIndex(['assigned_to']);
            $table->dropIndex(['due_date']);
            $table->dropIndex(['status', 'due_date']);
            $table->dropIndex(['project_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
        });
    }
};
