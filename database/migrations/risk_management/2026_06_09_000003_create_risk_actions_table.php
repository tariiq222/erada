<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('title');
            $table->string('type', 20);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();

            $table->index(['risk_id', 'status'], 'risk_actions_risk_status_idx');
            $table->index(['organization_id', 'status'], 'risk_actions_org_status_idx');
            $table->index(['organization_id', 'due_date'], 'risk_actions_org_due_date_idx');
            $table->index(['organization_id', 'owner_id'], 'risk_actions_org_owner_idx');
        });

        DB::statement("ALTER TABLE risk_actions ADD CONSTRAINT risk_actions_type_check CHECK (type IN ('preventive','corrective'))");
        DB::statement("ALTER TABLE risk_actions ADD CONSTRAINT risk_actions_status_check CHECK (status IN ('pending','in_progress','completed','blocked','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_actions');
    }
};
