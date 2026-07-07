<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rename the "new" (PMBOK) project type to "development" across stored data:
 *   - projects.type rows: 'new' -> 'development'
 *   - projects.type column default: 'new' -> 'development'
 *   - the project_type_governing_departments JSON setting key: 'new' -> 'development'
 *
 * The label shown to users ("مشاريع تطويرية" / "Development") is rendered from this
 * internal value; the value itself is what changes here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('projects')->where('type', 'new')->update(['type' => 'development']);

        DB::statement("ALTER TABLE projects ALTER COLUMN type SET DEFAULT 'development'");

        $this->remapGoverningKey('new', 'development');
    }

    public function down(): void
    {
        DB::table('projects')->where('type', 'development')->update(['type' => 'new']);

        DB::statement("ALTER TABLE projects ALTER COLUMN type SET DEFAULT 'new'");

        $this->remapGoverningKey('development', 'new');
    }

    /**
     * Move the governing-department mapping from one type key to another, in place.
     */
    private function remapGoverningKey(string $from, string $to): void
    {
        $row = DB::table('project_settings')
            ->where('key', 'project_type_governing_departments')
            ->first();

        if (! $row) {
            return;
        }

        $map = json_decode($row->value, true);
        if (is_array($map) && array_key_exists($from, $map)) {
            $map[$to] = $map[$from];
            unset($map[$from]);

            DB::table('project_settings')
                ->where('key', 'project_type_governing_departments')
                ->update(['value' => json_encode($map)]);
        }

        Cache::forget('project_setting_project_type_governing_departments');
    }
};
