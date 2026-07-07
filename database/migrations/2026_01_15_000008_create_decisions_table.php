<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('decisions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('rationale')->nullable();

            // Polymorphic relation (initiative, project)
            $table->morphs('decidable');

            $table->enum('type', [
                'approval',
                'change_request',
                'escalation',
                'resource_allocation',
                'scope_change',
                'budget_change',
                'timeline_change',
                'other',
            ])->default('other');

            $table->enum('status', ['pending', 'approved', 'rejected', 'deferred'])->default('pending');
            $table->date('decision_date');
            $table->date('effective_date')->nullable();
            $table->text('impact')->nullable();

            $table->foreignId('made_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['decidable_type', 'decidable_id', 'status', 'deleted_at'], 'decisions_decidable_status_index');
            $table->index(['status', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('decisions');
    }
};
