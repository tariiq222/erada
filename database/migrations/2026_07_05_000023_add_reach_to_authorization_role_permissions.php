<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.1.3 -- add a per-pivot `reach` JSON column to
 * `authorization_role_permissions`.
 *
 * Background:
 *   The new-path `hasNewPermission` check resolves a
 *   (role, resource, action) tuple into a grant only when the
 *   target falls within the role's reach. Until now the engine
 *   had no per-pivot reach to consult and fell through to the
 *   legacy `scoped_role_definitions.reach` (read once via
 *   `roleReachForCapability` inside `matchViaRoles`). That meant
 *   the SHADOW branch could not reproduce the legacy reach cap on
 *   the new path -- the new path was effectively reach-less and
 *   surfaced every reach=narrowed legacy grant as a shadow
 *   mismatch.
 *
 *   This migration adds the per-pivot `reach` column so the new
 *   path can enforce the cap directly. The companion migration
 *   `2026_07_05_000024_backfill_authorization_role_permissions_reach`
 *   reads the 000021 audit markers, resolves the source legacy
 *   `scoped_role_definitions.reach`, and writes the per-pivot
 *   reach. After that, the new path and the legacy path agree
 *   on reach and SHADOW does not throw on legacy reach=narrowed
 *   grants.
 *
 * Reach semantics (mirrors `scoped_role_definitions.reach`):
 *   - `reach` is a JSON object: `{module: own|department|all}`.
 *   - `NULL` means "no cap on this row" -- the engine falls back
 *     to the legacy definition read. Legacy rows that predate
 *     this column and pre-fix 000024 rows stay NULL, so the
 *     fallback path is the safe default.
 *   - A row with a non-null `reach` enforces the cap for the
 *     capability's module (`reach[module]`). Missing module entry
 *     defaults to 'all' (no cap), matching
 *     `ScopedRoleDefinition::reachForModule`.
 *
 * GIN index:
 *   `reach_module_idx` is a GIN index on the `reach` JSON
 *   column. It supports module-keyed lookups (e.g.
 *   `reach @> '{"projects": "own"}'`) for future query-side
 *   filtering of which roles can perform a given action on a
 *   given module -- not used by the engine today, but it costs
 *   nothing now and avoids a follow-up migration.
 *
 * Safe to run twice: up() uses hasColumn / index-existence guards
 * and DB::statement() for the GIN index, so a second up() is a
 * no-op.
 *
 * down():
 *   Drops the column AND the GIN index in one shot. The 000024
 *   backfill migration's down() does NOT drop this column (it is
 *   owned by 000023), so operators can roll back the backfill
 *   without losing the column or re-running 000023.
 *
 * PostgreSQL only: GIN on JSON is a PostgreSQL feature. The
 * `json` column type also round-trips correctly on the only
 * driver the project supports (per AGENTS.md), and
 * `authorization_role_permissions` is only used by the engine
 * which itself is PostgreSQL-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('authorization_role_permissions')) {
            return;
        }

        if (! Schema::hasColumn('authorization_role_permissions', 'reach')) {
            Schema::table('authorization_role_permissions', function (Blueprint $table) {
                // Nullable JSONB: legacy rows + pre-fix 000024 rows stay
                // NULL, signalling "no cap on this row" so the engine
                // falls back to the legacy definition read. JSONB (not
                // JSON) is required for the GIN index below -- the
                // `json` type has no default GIN operator class on
                // PostgreSQL.
                $table->jsonb('reach')->nullable()->after('action');
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            $exists = DB::selectOne(
                'SELECT 1 FROM pg_indexes '
                ."WHERE tablename = 'authorization_role_permissions' "
                ."AND indexname = 'authorization_role_permissions_reach_module_idx'"
            );

            if ($exists === null) {
                DB::statement(
                    'CREATE INDEX authorization_role_permissions_reach_module_idx '
                    .'ON authorization_role_permissions USING GIN (reach)'
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('authorization_role_permissions')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS authorization_role_permissions_reach_module_idx');
        }

        if (Schema::hasColumn('authorization_role_permissions', 'reach')) {
            Schema::table('authorization_role_permissions', function (Blueprint $table) {
                $table->dropColumn('reach');
            });
        }
    }
};
