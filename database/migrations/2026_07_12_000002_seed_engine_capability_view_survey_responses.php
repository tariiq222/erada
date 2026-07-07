<?php

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\ScopedRoleDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8-D: surface the engine capability for the legacy
 * `view_survey_responses` Spatie permission that gated
 *
 *   - GET /api/surveys/{survey}/export
 *   - the entire /api/surveys/{survey}/responses/* group
 *     (index, show, flag, review)
 *
 * via `permission:view_survey_responses`. Both routes now use
 * `engine_capability:surveys.review_responses`, which resolves through
 * AccessDecision::can(). The target-less call walks
 * `grantedViaOrgFunctionalRole` and reads
 * `scoped_role_definitions.permissions[]` for the user's org-scope
 * scoped role.
 *
 * Migration scope (deliberately narrow):
 *   1. Find every Spatie role whose pivot
 *      (`role_has_permissions` joined to `permissions`) carries
 *      `view_survey_responses` or `review_survey_responses`.
 *   2. For every org-scope `scoped_role_definitions` row whose
 *      `role_key` matches one of those Spatie role names, add
 *      `Capability::SURVEYS_REVIEW_RESPONSES` (`surveys.review_responses`)
 *      to its `permissions` JSON, alongside any existing keys.
 *   3. The legacy Spatie strings stay untouched in the `permissions`
 *      table and in any `scoped_role_definitions.permissions[]` rows
 *      that already reference them (the engine ignores unknown keys).
 *
 * Today this only affects the `admin` Spatie role — the seeder
 * grants it `view_survey_responses` and `review_survey_responses`,
 * and the backfill migration `2026_06_20_100002` provisioned an
 * org-scope scoped_role_definitions row for it (with `is_admin_role=true`
 * so the admin grant is unconditional anyway, but seeding the
 * canonical capability keeps the per-capability list aligned with
 * the route gates for the SHADOW path and the role-management UI).
 *
 * The `viewer` role's scoped_role_definitions does not get this
 * capability because the seeder does not grant it the legacy
 * Spatie string, and the role semantically excludes
 * response-review access.
 */
return new class extends Migration
{
    private const MIGRATION_NAME = '2026_07_12_000002_seed_engine_capability_view_survey_responses';

    private const LEGACY_SPATIE_STRINGS = [
        'view_survey_responses',
        'review_survey_responses',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(self::MIGRATION_NAME.' is PostgreSQL-only.');
        }

        $this->assertRequiredTables();

        $roleKeys = $this->spatieRoleKeysWithLegacyPermissions();
        if ($roleKeys === []) {
            AccessDecision::flushCache();

            return;
        }

        $rows = DB::table('scoped_role_definitions')
            ->select(['id', 'role_key', 'permissions'])
            ->where('scope_type', 'organization')
            ->whereIn('role_key', $roleKeys)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            AccessDecision::flushCache();

            return;
        }

        $canonical = Capability::SURVEYS_REVIEW_RESPONSES;
        $touched = 0;
        DB::transaction(function () use ($rows, $canonical, &$touched) {
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row->permissions, true);
                if (! is_array($decoded)) {
                    continue;
                }
                if (in_array($canonical, $decoded, true)) {
                    continue;
                }

                $merged = array_values(array_unique(array_merge($decoded, [$canonical])));
                DB::table('scoped_role_definitions')
                    ->where('id', (int) $row->id)
                    ->update(['permissions' => json_encode($merged)]);
                $touched++;
            }
        });

        ScopedRoleDefinition::clearCache();
        AccessDecision::flushCache();

        // \Log::info(self::MIGRATION_NAME.': seeded Capability::SURVEYS_REVIEW_RESPONSES into '.$touched.' scoped_role_definitions rows.');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $canonical = Capability::SURVEYS_REVIEW_RESPONSES;

        $rows = DB::table('scoped_role_definitions')
            ->select(['id', 'permissions'])
            ->whereNotNull('permissions')
            ->get();

        if ($rows->isEmpty()) {
            AccessDecision::flushCache();

            return;
        }

        DB::transaction(function () use ($rows, $canonical) {
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row->permissions, true);
                if (! is_array($decoded) || ! in_array($canonical, $decoded, true)) {
                    continue;
                }

                $filtered = array_values(array_filter(
                    $decoded,
                    fn ($p) => is_string($p) && $p !== $canonical
                ));

                DB::table('scoped_role_definitions')
                    ->where('id', (int) $row->id)
                    ->update(['permissions' => json_encode($filtered)]);
            }
        });

        ScopedRoleDefinition::clearCache();
        AccessDecision::flushCache();
    }

    /**
     * @return array<int, string>
     */
    private function spatieRoleKeysWithLegacyPermissions(): array
    {
        $rows = DB::table('role_has_permissions as rhp')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->join('roles as r', 'r.id', '=', 'rhp.role_id')
            ->whereIn('p.name', self::LEGACY_SPATIE_STRINGS)
            ->where('r.guard_name', 'web')
            ->select('r.name')
            ->distinct()
            ->pluck('r.name')
            ->map(fn ($name) => (string) $name)
            ->all();

        return array_values(array_unique($rows));
    }

    private function assertRequiredTables(): void
    {
        foreach (['roles', 'permissions', 'role_has_permissions', 'scoped_role_definitions'] as $table) {
            if (DB::selectOne('SELECT 1 FROM information_schema.tables WHERE table_name = ?', [$table]) === null) {
                throw new RuntimeException(self::MIGRATION_NAME.' requires '.$table.'.');
            }
        }
    }
};
