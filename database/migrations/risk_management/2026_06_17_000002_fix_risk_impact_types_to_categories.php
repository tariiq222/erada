<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_IMPACT_TYPES = [
        'workflow' => 'سير العمل',
        'employees' => 'الموظفون',
        'patients' => 'المرضى',
        'safety' => 'السلامة',
        'quality' => 'الجودة',
        'financial' => 'مالي',
        'reputation' => 'السمعة',
        'compliance' => 'الامتثال',
        'technology' => 'التقنية',
    ];

    private const LEGACY_IMPACT_LABELS = [
        1 => 'منخفض جداً',
        2 => 'منخفض',
        3 => 'متوسط',
        4 => 'مرتفع',
        5 => 'مرتفع جداً',
    ];

    public function up(): void
    {
        if (Schema::hasTable('risk_impact_types')) {
            DB::statement('ALTER TABLE risk_impact_types ALTER COLUMN value TYPE VARCHAR(30) USING value::text');

            DB::table('risk_impact_types')
                ->whereIn('value', array_map('strval', array_keys(self::LEGACY_IMPACT_LABELS)))
                ->delete();

            $now = now();
            $sortOrder = 1;
            foreach (self::DEFAULT_IMPACT_TYPES as $value => $label) {
                DB::table('risk_impact_types')->updateOrInsert(
                    ['value' => $value],
                    [
                        'label' => $label,
                        'is_active' => true,
                        'sort_order' => $sortOrder++,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }

        if (Schema::hasTable('risks') && ! Schema::hasColumn('risks', 'impact_details')) {
            Schema::table('risks', function (Blueprint $table) {
                $table->json('impact_details')->nullable()->after('consequences');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('risks') && Schema::hasColumn('risks', 'impact_details')) {
            Schema::table('risks', function (Blueprint $table) {
                $table->dropColumn('impact_details');
            });
        }

        if (! Schema::hasTable('risk_impact_types')) {
            return;
        }

        DB::table('risk_impact_types')
            ->whereIn('value', array_keys(self::DEFAULT_IMPACT_TYPES))
            ->delete();

        $now = now();
        foreach (self::LEGACY_IMPACT_LABELS as $value => $label) {
            DB::table('risk_impact_types')->updateOrInsert(
                ['value' => (string) $value],
                [
                    'label' => $label,
                    'is_active' => true,
                    'sort_order' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $hasNonNumericValues = DB::table('risk_impact_types')
            ->whereRaw("value !~ '^[0-9]+$'")
            ->exists();

        if (! $hasNonNumericValues) {
            DB::statement('ALTER TABLE risk_impact_types ALTER COLUMN value TYPE SMALLINT USING value::smallint');
        }
    }
};
