<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->nullable()->constrained('risks')->cascadeOnDelete();
            $table->foreignId('risk_action_id')->nullable()->constrained('risk_actions')->cascadeOnDelete();
            $table->foreignId('risk_assessment_id')->nullable()->constrained('risk_assessments')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('type', 40);
            $table->json('payload')->nullable();
            $table->foreignId('sent_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'type'], 'risk_alerts_org_type_idx');
            $table->index(['risk_id', 'type'], 'risk_alerts_risk_type_idx');
            $table->index(['sent_to', 'read_at'], 'risk_alerts_sent_to_read_idx');
        });

        DB::statement("ALTER TABLE risk_alerts ADD CONSTRAINT risk_alerts_type_check CHECK (type IN ('review_due','level_escalated','action_overdue'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_alerts');
    }
};
