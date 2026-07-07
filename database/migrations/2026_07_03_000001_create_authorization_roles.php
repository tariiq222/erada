<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Task 1.1.1 — `authorization_roles`.
 *
 * Roles are the named groupings (admin, viewer, project_manager, ...) that
 * get assigned to users via `authorization_role_assignments`. A role has no
 * direct capability bindings here; those live in `authorization_role_permissions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('authorization_roles')) {
            return;
        }

        Schema::create('authorization_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_roles');
    }
};
