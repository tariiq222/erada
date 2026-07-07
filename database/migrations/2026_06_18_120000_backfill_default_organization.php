<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill a default organization and attach every existing record to it.
 *
 * Production shipped with an empty `organizations` table while the new
 * org-scoped model requires a non-null organization_id on most tables
 * (kpis.organization_id is NOT NULL, etc.). Without a default org the
 * downstream data migrations (migrate_project_kpis_to_performance and the
 * scoped-role backfills) fail mid-deploy and leave the schema half-migrated.
 *
 * This migration also normalizes Arabic-Indic / Persian numerals that leaked
 * into the legacy varchar KPI numeric fields, so the numeric cast performed
 * by migrate_project_kpis_to_performance succeeds.
 *
 * Idempotent: reuses an existing organization if one is present, and only
 * fills organization_id where it is still NULL — safe to run on every
 * environment (dev/test already have a seeded org and are left untouched).
 *
 * Ordered (2026_06_18_120000) to run BEFORE 2026_06_19_000001 which is the
 * first migration that requires a non-null organization_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Ensure a default organization exists.
        $orgId = DB::table('organizations')->orderBy('id')->value('id');

        if ($orgId === null) {
            $orgId = DB::table('organizations')->insertGetId([
                'name' => 'مجمع إرادة والصحة النفسية',
                'code' => 'ERADA',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Attach every table that carries organization_id and still has NULLs.
        $tables = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_name', 'organization_id')
            ->pluck('table_name');

        foreach ($tables as $table) {
            DB::table($table)->whereNull('organization_id')->update(['organization_id' => $orgId]);
        }

        // 3. Normalize Arabic-Indic / Persian numerals in the legacy varchar KPI
        //    fields so the downstream numeric migration can cast them.
        if (Schema::hasTable('project_kpis')) {
            foreach (['baseline', 'target', 'current_value'] as $col) {
                DB::statement(
                    "UPDATE project_kpis SET {$col} = translate({$col}, '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', '01234567890123456789') WHERE {$col} ~ '[٠-٩۰-۹]'"
                );
            }
        }
    }

    public function down(): void
    {
        // Data backfill — not reversible. Populated organization_id values are
        // intentionally left in place.
    }
};
