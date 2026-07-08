<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 / Meeting Resolutions Foundation — create `meeting_resolutions`.
 *
 * Direction R (new philosophy): every meeting can produce typed "resolutions".
 * A resolution has a `kind` of either `recommendation` or `decision` — there is
 * no `approve` / `reject` / `adopt` / `deliberate` lifecycle. Status moves
 * forward: open → in_progress → (converted_to_tasks | completed | cancelled),
 * with a `hold` metadata triple that does NOT change status.
 *
 * Per LR-004 (never edit applied migrations) this is a strictly additive
 * table; it does not touch `recommendations`, `meetings`, or any FK that is
 * already serving the legacy Direction B lifecycle. Existing Recommendation
 * rows stay where they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_resolutions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 20)->nullable();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('meeting_id')->constrained('meetings')->restrictOnDelete();
            // Direction R: kind is mandatory at the DB level (NOT nullable) and
            // pinned via CHECK to the two allowed values. Default `recommendation`
            // so accidental inserts without `kind` resolve to a safe choice.
            $table->string('kind', 20)->default('recommendation');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('open');
            $table->string('priority', 20)->default('medium');
            $table->date('due_date')->nullable();
            // Hold metadata — does NOT change status; a held resolution keeps
            // its current status (open / in_progress) until released.
            $table->text('hold_reason')->nullable();
            $table->timestamp('hold_until')->nullable();
            $table->foreignId('hold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hold_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'meeting_resolutions_org_status_idx');
            $table->index(['organization_id', 'kind'], 'meeting_resolutions_org_kind_idx');
            $table->index(['meeting_id', 'status'], 'meeting_resolutions_meeting_status_idx');
            $table->index(['owner_id', 'status'], 'meeting_resolutions_owner_status_idx');
            $table->index('due_date', 'meeting_resolutions_due_date_idx');
        });

        DB::statement(
            'ALTER TABLE meeting_resolutions ADD CONSTRAINT meeting_resolutions_kind_check '
            ."CHECK (kind IN ('recommendation', 'decision'))"
        );
        DB::statement(
            'ALTER TABLE meeting_resolutions ADD CONSTRAINT meeting_resolutions_status_check '
            ."CHECK (status IN ('open', 'in_progress', 'converted_to_tasks', 'completed', 'cancelled'))"
        );
        DB::statement(
            'ALTER TABLE meeting_resolutions ADD CONSTRAINT meeting_resolutions_priority_check '
            ."CHECK (priority IN ('low', 'medium', 'high', 'critical'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_resolutions');
    }
};
