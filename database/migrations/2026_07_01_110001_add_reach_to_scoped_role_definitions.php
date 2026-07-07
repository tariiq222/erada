<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 (ADR-UNIFIED-ROLE-ACCESS): per-capability reach on a role definition.
 *
 * `reach` is a per-module cap on what the role grants: {module: own|department|all}.
 * A missing module (or a null column) means 'all' — so existing definitions keep
 * their current org-wide behavior with no backfill. Reach only ever RESTRICTS
 * (least-privilege): the effective reach is the narrower of the assignment scope
 * and this definition reach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scoped_role_definitions', function (Blueprint $table) {
            $table->json('reach')->nullable()->after('permissions');
        });
    }

    public function down(): void
    {
        Schema::table('scoped_role_definitions', function (Blueprint $table) {
            $table->dropColumn('reach');
        });
    }
};
