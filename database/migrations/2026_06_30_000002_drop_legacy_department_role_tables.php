<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — drop the legacy department-role tables.
 *
 * Run ONLY after the additive backfill (2026_06_30_000001) has been verified in
 * staging via `roles:verify-legacy-migration` and `roles:reconcile`. down()
 * recreates the legacy schema for rollback parity; the legacy *data* is not
 * restored (it lives on the scoped model and is reproducible via reconcile).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('department_role_grants');
        Schema::dropIfExists('department_default_roles');
    }

    public function down(): void
    {
        // Recreate the legacy schema (structure only) for rollback parity.
        Schema::create('department_default_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['department_id', 'role_id']);
        });

        Schema::create('department_role_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });
    }
};
