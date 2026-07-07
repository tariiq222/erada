<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * تحديث Polymorphic Relations:
     * - تغيير Initiative إلى Program في جميع الجداول
     */
    public function up(): void
    {
        $tables = [
            'strategic_kpis' => 'measurable_type',
            'blockers' => 'blockable_type',
            'decisions' => 'decidable_type',
            'reviews' => 'reviewable_type',
        ];

        $oldType = 'App\\Modules\\Strategy\\Models\\Initiative';
        $newType = 'App\\Modules\\Strategy\\Models\\Program';

        foreach ($tables as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)
                    ->where($column, $oldType)
                    ->update([$column => $newType]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'strategic_kpis' => 'measurable_type',
            'blockers' => 'blockable_type',
            'decisions' => 'decidable_type',
            'reviews' => 'reviewable_type',
        ];

        $oldType = 'App\\Modules\\Strategy\\Models\\Program';
        $newType = 'App\\Modules\\Strategy\\Models\\Initiative';

        foreach ($tables as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)
                    ->where($column, $oldType)
                    ->update([$column => $newType]);
            }
        }
    }
};
