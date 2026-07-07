<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Task 1.1.1 — `authorization_record_rules`.
 *
 * Structured record-level authorization rules. Each row narrows a
 * (resource, action) capability down to rows whose columns satisfy the
 * structured `domain_json` payload.
 *
 * Scoping (NULL role + NULL user) means "applies to everyone who reaches this
 * resource"; setting either column narrows the audience.
 *
 * `domain_json` is a PostgreSQL JSONB document — never raw SQL, never free-form
 * text. Its shape is fixed by the record-rule evaluator (Task 1.1.3):
 *   {"operator": "<allowlisted-op>", "column": "<table.column>", ...args}
 * with the operator allowlist `eq | neq | in | not_in | belongs_to_dept | owned_by`.
 *
 * The two indexes serve the two common access patterns:
 *   (resource_id, action, enabled) — engine hot path
 *   (role_id, resource_id)         — per-role lookup
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('authorization_record_rules')) {
            return;
        }

        Schema::create('authorization_record_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authorization_role_id')->nullable()->constrained('authorization_roles')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('authorization_resource_id')->constrained('authorization_resources')->cascadeOnDelete();
            $table->string('action')->nullable();
            $table->jsonb('domain_json');
            $table->integer('priority')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['authorization_resource_id', 'action', 'enabled'], 'authorization_record_rules_resource_action_enabled_idx');
            $table->index(['authorization_role_id', 'authorization_resource_id'], 'authorization_record_rules_role_resource_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Hot-path index: enabled rules for a (resource, action) pair, ordered by priority DESC.
            DB::statement(
                'CREATE INDEX authorization_record_rules_priority_idx '
                .'ON authorization_record_rules (authorization_resource_id, action, enabled, priority DESC)'
                .' WHERE enabled = true'
            );

            // Per-user lookup index (when rule targets a specific user rather than a role).
            DB::statement(
                'CREATE INDEX authorization_record_rules_user_resource_idx '
                .'ON authorization_record_rules (user_id, authorization_resource_id) '
                .' WHERE user_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_record_rules');
    }
};
