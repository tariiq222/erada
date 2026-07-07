<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Task 1.1.1 — `authorization_decision_audits`.
 *
 * Append-only audit log of authorization decisions emitted by the engine. The
 * legacy `permission_audits` table is left untouched; this is additive and
 * lives alongside it during Phase 1.
 *
 * Append-only: `created_at` only — no `updated_at`. The `decision` and `source`
 * columns are VARCHAR + CHECK constraint (NOT native PostgreSQL ENUM types)
 * so future values can be added without an ALTER TYPE migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('authorization_decision_audits')) {
            return;
        }

        Schema::create('authorization_decision_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('authorization_resource_id')->constrained('authorization_resources')->cascadeOnDelete();
            $table->string('action');
            $table->string('decision', 16);
            $table->foreignId('matched_authorization_role_id')->nullable()->constrained('authorization_roles')->nullOnDelete();
            $table->foreignId('matched_authorization_role_assignment_id')->nullable()->constrained('authorization_role_assignments')->nullOnDelete();
            $table->foreignId('matched_authorization_record_rule_id')->nullable()->constrained('authorization_record_rules')->nullOnDelete();
            $table->string('source', 16)->default('engine');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['authorization_resource_id', 'action', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE authorization_decision_audits '
                .'ADD CONSTRAINT authorization_decision_audits_decision_check '
                ."CHECK (decision IN ('allow','deny'))"
            );

            DB::statement(
                'ALTER TABLE authorization_decision_audits '
                .'ADD CONSTRAINT authorization_decision_audits_source_check '
                ."CHECK (source IN ('engine','shadow','legacy'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_decision_audits');
    }
};
