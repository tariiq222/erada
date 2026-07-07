<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 20)->nullable();
            $table->foreignId('decision_id')->constrained('decisions')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 20)->default('medium');
            $table->string('status', 20)->default('proposed');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id', 'recommendations_org_id_idx');
            $table->index(['organization_id', 'status'], 'recommendations_org_status_idx');
            $table->index(['organization_id', 'priority'], 'recommendations_org_priority_idx');
            $table->index(['organization_id', 'assignee_id'], 'recommendations_org_assignee_idx');
            $table->index('decision_id', 'recommendations_decision_idx');
            $table->index('due_date', 'recommendations_due_date_idx');
        });

        DB::statement("ALTER TABLE recommendations ADD CONSTRAINT recommendations_status_check CHECK (status IN ('proposed','accepted','rejected','deferred','completed'))");
        DB::statement("ALTER TABLE recommendations ADD CONSTRAINT recommendations_priority_check CHECK (priority IN ('low','medium','high','critical'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
