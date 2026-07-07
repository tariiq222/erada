<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * هجرة (i) — Strategy FK → أدوار عنصر inline في model_has_scoped_roles
 *
 * تُحوِّل:
 *  - programs.program_manager_id  → (role=program_manager, scope_type=program, scope_id=program.id)
 *  - programs.owner_id            → (role=owner,           scope_type=program, scope_id=program.id)
 *  - programs.executive_sponsor_id→ (role=executive_sponsor, scope_type=program, scope_id=program.id)
 *  - portfolios.portfolio_owner_id→ (role=owner,           scope_type=portfolio, scope_id=portfolio.id)
 *
 * الأعمدة FK لا تُحذف هنا (ذلك مرحلة هـ).
 * يجب تشغيل هجرة (iv) أولاً حتى تكون scope_types=program/portfolio موجودة.
 *
 * idempotent: firstOrCreate على القيد الفريد (user_id, role, scope_type, scope_id).
 */
return new class extends Migration
{
    /** خريطة: [جدول, عمود_user_id, scope_type, role_key] */
    private array $mappings = [
        ['table' => 'programs', 'fk_col' => 'owner_id', 'scope_type' => 'program', 'role' => 'owner'],
        ['table' => 'programs', 'fk_col' => 'program_manager_id', 'scope_type' => 'program', 'role' => 'program_manager'],
        ['table' => 'programs', 'fk_col' => 'executive_sponsor_id', 'scope_type' => 'program', 'role' => 'executive_sponsor'],
        ['table' => 'portfolios', 'fk_col' => 'portfolio_owner_id', 'scope_type' => 'portfolio', 'role' => 'owner'],
    ];

    // ===================================================================
    // up
    // ===================================================================

    public function up(): void
    {
        $now = now();
        $inserted = 0;

        foreach ($this->mappings as $map) {
            $rows = DB::table($map['table'])
                ->whereNotNull($map['fk_col'])
                ->get(['id', $map['fk_col']]);

            foreach ($rows as $row) {
                $userId = (int) $row->{$map['fk_col']};
                $scopeId = (int) $row->id;

                // تحقّق أن المستخدم موجود (قد يكون حُذف)
                $userExists = DB::table('users')->where('id', $userId)->exists();
                if (! $userExists) {
                    continue;
                }

                // firstOrCreate على القيد الفريد
                $exists = DB::table('model_has_scoped_roles')
                    ->where('user_id', $userId)
                    ->where('role', $map['role'])
                    ->where('scope_type', $map['scope_type'])
                    ->where('scope_id', $scopeId)
                    ->exists();

                if (! $exists) {
                    DB::table('model_has_scoped_roles')->insert([
                        'user_id' => $userId,
                        'role' => $map['role'],
                        'scope_type' => $map['scope_type'],
                        'scope_id' => $scopeId,
                        'inherit_to_children' => true, // يرث للبرامج/المشاريع التابعة
                        'granted_by' => null,
                        'expires_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $inserted++;
                }
            }
        }

        // سجل التدقيق
        DB::table('permission_audits')->insert([
            'event' => 'migration',
            'actor_id' => null,
            'target_user_id' => null,
            'scope_type' => null,
            'scope_id' => null,
            'role' => null,
            'old_value' => null,
            'new_value' => json_encode([
                'migration' => '2026_06_20_100003_backfill_strategy_fk_to_scoped_roles',
                'action' => 'backfill_strategy_fk_i',
                'mappings_applied' => count($this->mappings),
                'rows_inserted' => $inserted,
            ]),
            'reason' => 'Phase C migration (i): Strategy FK columns → inline scoped roles in model_has_scoped_roles',
            'ip_address' => null,
            'user_agent' => 'migration',
            'created_at' => $now,
        ]);

        $this->clearCache();
    }

    // ===================================================================
    // down
    // ===================================================================

    public function down(): void
    {
        // نحذف الصفوف التي أنشأتها هذه الهجرة تحديداً
        // نشترط scope_type في {program, portfolio} ودور في {owner, program_manager, executive_sponsor}
        // لتجنّب حذف صفوف أنشأها مصدر آخر نتحقق من وجود المستخدم كـ FK في الجدول المصدر
        foreach ($this->mappings as $map) {
            $rows = DB::table($map['table'])
                ->whereNotNull($map['fk_col'])
                ->get(['id', $map['fk_col']]);

            foreach ($rows as $row) {
                $userId = (int) $row->{$map['fk_col']};
                $scopeId = (int) $row->id;

                DB::table('model_has_scoped_roles')
                    ->where('user_id', $userId)
                    ->where('role', $map['role'])
                    ->where('scope_type', $map['scope_type'])
                    ->where('scope_id', $scopeId)
                    ->delete();
            }
        }

        $this->clearCache();
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    private function clearCache(): void
    {
        Cache::forget('scope_types_active');
        foreach (['program', 'portfolio'] as $key) {
            Cache::forget("scope_type_{$key}");
            Cache::forget("roles_for_type_{$key}");
        }
    }
};
