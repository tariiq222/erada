<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Task 1.1.1 — `authorization_role_permissions`.
 *
 * Pure pivot linking a role to (resource, action). No surrogate `id`, no
 * timestamps — the composite primary key IS the row identity. The
 * (authorization_resource_id, action) secondary index makes the engine's
 * "who can do <action> on <resource>" lookup a single index seek.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('authorization_role_permissions')) {
            return;
        }

        Schema::create('authorization_role_permissions', function (Blueprint $table) {
            $table->foreignId('authorization_role_id')->constrained('authorization_roles')->cascadeOnDelete();
            $table->foreignId('authorization_resource_id')->constrained('authorization_resources')->cascadeOnDelete();
            $table->string('action');

            $table->primary(['authorization_role_id', 'authorization_resource_id', 'action']);

            $table->index(['authorization_resource_id', 'action'], 'authorization_role_permissions_resource_action_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX authorization_role_permissions_action_idx '
                .'ON authorization_role_permissions (action)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_role_permissions');
    }
};
