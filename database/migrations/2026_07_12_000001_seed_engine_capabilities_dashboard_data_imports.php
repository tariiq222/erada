<?php

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\ScopedRoleDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8-C: surface the engine capabilities introduced for the two
 * remaining legacy route guards in this phase:
 *
 *   - Capability::DASHBOARD_VIEW            ('dashboard.view')
 *     replaces the legacy Spatie 'view_dashboard' that gated
 *     /api/dashboard/* via `can:view_dashboard`.
 *
 *   - Capability::SURVEYS_REVIEW_DATA_IMPORTS ('surveys.review_data_imports')
 *     replaces the legacy Spatie 'review_data_imports' that gated
 *     /api/data-imports/{id}/(approve|reject|apply|retry|bulk-*) via
 *     `permission:review_data_imports`.
 *
 * Both routes now use `engine_capability:<canonical>`, which resolves
 * through AccessDecision::can(). For target-less calls the only grant
 * surface is the org-functional-role check in whyCan() step 3, which
 * reads `scoped_role_definitions.permissions[]` for the role definition
 * matching the user's Spatie role name (or scoped-role assignment).
 *
 * Migration scope (deliberately narrow):
 *   1. For every `scoped_role_definitions.permissions[]` row that
 *      contains the legacy key, add the canonical capability alongside.
 *      Idempotent — running the migration twice is a no-op (the
 *      in_array check skips rows that already carry the canonical key).
 *   2. The legacy Spatie strings stay in `permissions[]` unchanged.
 *      They are dead weight (no engine code path reads them) but the
 *      engine's `in_array($capability, $permissions, true)` check is
 *      permissive about extra entries, and leaving them avoids losing
 *      access for any consumer that still reads the JSON column
 *      directly. The full strip is out of scope for Phase 8-C.
 *   3. The Spatie `permissions` table is left untouched — the
 *      `permission:` middleware no longer reads it for these routes,
 *      but the SPA, the seeder, and the role editor still surface
 *      these strings for the role catalog.
 *
 * Authorization resources (for the new path's `authorization_role_permissions`
 * table) are intentionally NOT seeded here: both route guards are
 * target-less (`AccessDecision::can($user, $capability)` with $target=null),
 * so the SHADOW slice — which is the only consumer of the new path — does
 * not exercise them. The seeding belongs to a later phase that targets
 * target-bound authz.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_12_000001_seed_engine_capabilities_dashboard_data_imports';

    /**
     * Map: legacy permission key (as it appears in
     * `scoped_role_definitions.permissions` JSON) => canonical Capability
     * to add alongside it.
     *
     * @var array<string, string>
     */
    private const LEGACY_TO_CANONICAL = [
        'view_dashboard' => Capability::DASHBOARD_VIEW,
        'review_data_imports' => Capability::SURVEYS_REVIEW_DATA_IMPORTS,
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // scoped_role_definitions is a Postgres table; SQLite tests are
            // blocked by the CI sqlite-guard, so a non-pg driver here means
            // a misconfigured environment.
            throw new RuntimeException(self::MIGRATION_NAME.' is PostgreSQL-only.');
        }

        $this->assertRequiredTables();

        $rows = DB::table('scoped_role_definitions')
            ->select(['id', 'permissions'])
            ->whereNotNull('permissions')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            AccessDecision::flushCache();

            return;
        }

        $touched = 0;
        DB::transaction(function () use ($rows, &$touched) {
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row->permissions, true);
                if (! is_array($decoded)) {
                    continue;
                }

                $hasLegacy = false;
                $additions = [];
                foreach (self::LEGACY_TO_CANONICAL as $legacy => $canonical) {
                    if (in_array($legacy, $decoded, true)) {
                        $hasLegacy = true;
                        if (! in_array($canonical, $decoded, true)) {
                            $additions[] = $canonical;
                        }
                    }
                }

                if (! $hasLegacy || $additions === []) {
                    continue;
                }

                $merged = array_values(array_unique(array_merge($decoded, $additions)));

                DB::table('scoped_role_definitions')
                    ->where('id', (int) $row->id)
                    ->update(['permissions' => json_encode($merged)]);

                $touched++;
            }
        });

        // Flush the engine + definition caches so the next AccessDecision::can()
        // call sees the updated permissions JSON. ScopedRoleDefinition::clearCache()
        // also clears the (scope_type, role_key) -> row memoization the engine
        // consults for org-functional-role lookups.
        ScopedRoleDefinition::clearCache();
        AccessDecision::flushCache();

        // \Log::info(self::MIGRATION_NAME.': backfilled '.$touched.' scoped_role_definitions rows.');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $rows = DB::table('scoped_role_definitions')
            ->select(['id', 'permissions'])
            ->whereNotNull('permissions')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            AccessDecision::flushCache();

            return;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row->permissions, true);
                if (! is_array($decoded)) {
                    continue;
                }

                $removals = array_values(self::LEGACY_TO_CANONICAL);
                $filtered = array_values(array_filter(
                    $decoded,
                    fn ($p) => is_string($p) && ! in_array($p, $removals, true)
                ));

                if (count($filtered) === count($decoded)) {
                    continue;
                }

                DB::table('scoped_role_definitions')
                    ->where('id', (int) $row->id)
                    ->update(['permissions' => json_encode($filtered)]);
            }
        });

        ScopedRoleDefinition::clearCache();
        AccessDecision::flushCache();
    }

    private function assertRequiredTables(): void
    {
        if (DB::selectOne('SELECT 1 FROM information_schema.tables WHERE table_name = ?', ['scoped_role_definitions']) === null) {
            throw new RuntimeException(self::MIGRATION_NAME.' requires scoped_role_definitions.');
        }
    }
};
