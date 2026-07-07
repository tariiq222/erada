<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Task 1.1.1 — `authorization_role_assignments`.
 *
 * Models `User + Role + Scope`. Scope discriminates WHERE the role applies:
 *
 *   all          — entire system (scope_id NULL; engine grants unconditionally)
 *   organization — exactly one org (scope_id = org.id)
 *   cluster      — org-level cluster (future)
 *   hospital     — hospital subtree (future/conditional; data model not yet shipped)
 *   department   — exactly one department (scope_id = department.id)
 *   team         — exactly one team (scope_id = team.id)
 *   own          — records owned by the user (scope_id NULL; engine filters by owner at query time)
 *
 * The scope_type column is a VARCHAR with a CHECK constraint (NOT a native
 * PostgreSQL ENUM type) so future scope types can be added without an
 * ALTER TYPE migration. `scope_id` is nullable ONLY when `scope_type` is one of
 * the runtime-resolved values `'all'` or `'own'` — both are decided per-query
 * by the engine and do not point at a scope row. For every other scope_type,
 * `scope_id` MUST be NOT NULL and references the row in the corresponding
 * scope table. A CHECK constraint enforces that pairing. Uniqueness over
 * (role, user, scope_type, scope_id) is enforced by TWO partial unique indexes
 * because PostgreSQL treats NULLs as distinct in standard UNIQUE constraints —
 * one partial index covers the NULL-scope case (one row per role+user+type),
 * one partial index covers the NOT-NULL case (one row per scope_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('authorization_role_assignments')) {
            return;
        }

        Schema::create('authorization_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authorization_role_id')->constrained('authorization_roles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope_type', 32);
            $table->unsignedBigInteger('scope_id')->nullable();
            // Denormalized convenience: equals scope_id when scope_type='organization',
            // else null. Lets us add a global per-org index without joining scope.
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->timestamps();

            $table->index('user_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE authorization_role_assignments '
                .'ADD CONSTRAINT authorization_role_assignments_scope_type_check '
                ."CHECK (scope_type IN ('all','organization','cluster','hospital','department','team','own'))"
            );

            DB::statement(
                'ALTER TABLE authorization_role_assignments '
                .'ADD CONSTRAINT authorization_role_assignments_scope_id_allows_null_check '
                ."CHECK ((scope_type IN ('all','own') AND scope_id IS NULL) OR (scope_type NOT IN ('all','own') AND scope_id IS NOT NULL))"
            );

            // Uniqueness on (role, user, scope_type) when scope_id is NULL.
            DB::statement(
                'CREATE UNIQUE INDEX authorization_role_assignments_scope_null_unique '
                .'ON authorization_role_assignments (authorization_role_id, user_id, scope_type) '
                .'WHERE scope_id IS NULL'
            );

            // Uniqueness on (role, user, scope_type, scope_id) when scope_id is NOT NULL.
            DB::statement(
                'CREATE UNIQUE INDEX authorization_role_assignments_scope_not_null_unique '
                .'ON authorization_role_assignments (authorization_role_id, user_id, scope_type, scope_id) '
                .'WHERE scope_id IS NOT NULL'
            );

            DB::statement(
                'CREATE INDEX authorization_role_assignments_organization_id_index '
                .'ON authorization_role_assignments (organization_id) '
                .'WHERE organization_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_role_assignments');
    }
};
