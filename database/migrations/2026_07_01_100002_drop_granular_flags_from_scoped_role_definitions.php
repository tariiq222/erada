<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (ADR-UNIFIED-ROLE-ACCESS): drop the retired granular flag columns.
 * Their grants were merged into permissions[] by the preceding backfill migration
 * (2026_07_01_100001_backfill_granular_flags_into_permissions). is_admin_role is
 * intentionally KEPT — it remains the engine's "grants ALL capabilities" shortcut.
 *
 * Runs after the backfill (lexicographic filename order guarantees it). Idempotent:
 * only drops columns that still exist.
 */
return new class extends Migration
{
    private array $columns = [
        'can_edit',
        'can_delete',
        'can_view_all',
        'can_manage_members',
        'can_view_confidential',
    ];

    public function up(): void
    {
        $present = array_values(array_filter(
            $this->columns,
            fn (string $col) => Schema::hasColumn('scoped_role_definitions', $col)
        ));

        if ($present === []) {
            return;
        }

        Schema::table('scoped_role_definitions', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }

    public function down(): void
    {
        Schema::table('scoped_role_definitions', function (Blueprint $table) {
            if (! Schema::hasColumn('scoped_role_definitions', 'can_manage_members')) {
                $table->boolean('can_manage_members')->default(false);
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'can_edit')) {
                $table->boolean('can_edit')->default(false);
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'can_delete')) {
                $table->boolean('can_delete')->default(false);
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'can_view_all')) {
                $table->boolean('can_view_all')->default(false);
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'can_view_confidential')) {
                $table->boolean('can_view_confidential')->default(false);
            }
        });
    }
};
