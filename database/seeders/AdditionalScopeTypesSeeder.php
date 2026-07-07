<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AdditionalScopeTypesSeeder — registers the scope_types rows for the operational
 * models brought into the scope system in Phase 5: kpi, meeting, survey.
 *
 * Decisions and Recommendations roll up through the meeting scope (no separate
 * scope_type). Idempotent: re-running updates the existing rows in place and
 * preserves created_at.
 */
class AdditionalScopeTypesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $types = [
            'kpi' => [
                'label_ar' => 'مؤشر الأداء',
                'label_en' => 'KPI',
                'model_class' => 'App\\Modules\\Performance\\Models\\Kpi',
            ],
            'meeting' => [
                'label_ar' => 'الاجتماع',
                'label_en' => 'Meeting',
                'model_class' => 'App\\Modules\\Meetings\\Models\\Meeting',
            ],
            'survey' => [
                'label_ar' => 'الاستبيان',
                'label_en' => 'Survey',
                'model_class' => 'App\\Modules\\Surveys\\Models\\Survey',
            ],
        ];

        foreach ($types as $key => $meta) {
            $exists = DB::table('scope_types')->where('key', $key)->exists();

            $attributes = [
                'label_ar' => $meta['label_ar'],
                'label_en' => $meta['label_en'],
                'model_class' => $meta['model_class'],
                'supports_hierarchy' => false,
                'supports_expiry' => false,
                'is_active' => true,
                'sort_order' => 30,
                'updated_at' => $now,
            ];

            // Only stamp created_at on first insert; never reset it on re-run.
            if (! $exists) {
                $attributes['created_at'] = $now;
            }

            DB::table('scope_types')->updateOrInsert(['key' => $key], $attributes);
        }
    }
}
