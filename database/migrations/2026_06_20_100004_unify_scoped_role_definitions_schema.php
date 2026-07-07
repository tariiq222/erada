<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * هجرة (iii) — توحيد تعريفات scoped_role_definitions المتضاربة
 *
 * الجدول أُنشئ في 2026_01_12 بمخطط قديم (name, display_name, scope_type, default_abilities, level)
 * ثم أُعيد تشكيله في 2026_02_09 بإضافة الأعمدة الجديدة (role_key, permissions, can_*, ...).
 * الأعمدة القديمة ما زالت موجودة (مرئية في getColumnListing) لكنها لم تُحذف.
 *
 * هذه الهجرة:
 *  1. تُحدِّث الصفوف التي لا تزال تعتمد على الأعمدة القديمة فقط (role_key=null وname!=null)
 *     بنسخ name → role_key و display_name → label_ar إن لم يكن قد نُسخ سابقاً.
 *  2. تُجهز الصفوف بلا scope_type_id بربطها بالـ scope_type المناسب إن أمكن.
 *  3. تُسجّل نسخة احتياطية من الصفوف القديمة في permission_audits قبل التعديل (للـ down).
 *
 * idempotent: تعمل مشروطاً بوجود قيم قديمة فقط — تتخطّى المُوحَّد مسبقاً.
 */
return new class extends Migration
{
    /** أسماء الأعمدة القديمة للتحقق من وجودها */
    private array $legacyColumns = ['name', 'display_name', 'scope_type', 'default_abilities', 'level'];

    // ===================================================================
    // up
    // ===================================================================

    public function up(): void
    {
        $now = now();

        // جلب الصفوف التي ما زال role_key فيها null لكن name موجود (المخطط القديم)
        $legacyRows = DB::table('scoped_role_definitions')
            ->whereNull('role_key')
            ->whereNotNull('name')
            ->get();

        if ($legacyRows->isEmpty()) {
            // لا يوجد شيء للتوحيد — سجّل ذلك فقط
            DB::table('permission_audits')->insert([
                'event' => 'migration',
                'actor_id' => null,
                'target_user_id' => null,
                'scope_type' => null,
                'scope_id' => null,
                'role' => null,
                'old_value' => json_encode(['legacy_rows_found' => 0]),
                'new_value' => json_encode([
                    'migration' => '2026_06_20_100004_unify_scoped_role_definitions_schema',
                    'action' => 'unify_definitions_iii',
                    'status' => 'no_legacy_rows_found',
                ]),
                'reason' => 'Phase C migration (iii): no legacy scoped_role_definitions rows to unify',
                'ip_address' => null,
                'user_agent' => 'migration',
                'created_at' => $now,
            ]);

            return;
        }

        // نسخة احتياطية من الصفوف القديمة قبل التعديل (تُستخدم في down)
        $backup = [];
        foreach ($legacyRows as $row) {
            $backup[] = (array) $row;
        }

        DB::table('permission_audits')->insert([
            'event' => 'migration',
            'actor_id' => null,
            'target_user_id' => null,
            'scope_type' => null,
            'scope_id' => null,
            'role' => null,
            'old_value' => json_encode($backup),
            'new_value' => json_encode([
                'migration' => '2026_06_20_100004_unify_scoped_role_definitions_schema',
                'action' => 'unify_definitions_iii',
                'legacy_rows_count' => count($backup),
            ]),
            'reason' => 'Phase C migration (iii): backup legacy scoped_role_definitions before unification',
            'ip_address' => null,
            'user_agent' => 'migration',
            'created_at' => $now,
        ]);

        // تحديث كل صف قديم
        foreach ($legacyRows as $row) {
            $updates = [];

            // role_key: من name
            if (empty($row->role_key) && ! empty($row->name)) {
                $updates['role_key'] = $row->name;
            }

            // label_ar: من display_name
            if (empty($row->label_ar)) {
                $displayName = property_exists($row, 'display_name') ? $row->display_name : null;
                if ($displayName) {
                    $updates['label_ar'] = $displayName;
                } elseif (! empty($row->name)) {
                    $updates['label_ar'] = $row->name;
                }
            }

            // permissions: من default_abilities إن كانت null
            if (empty($row->permissions)) {
                $defaultAbilities = property_exists($row, 'default_abilities') ? $row->default_abilities : null;
                if ($defaultAbilities) {
                    // قد تكون JSON أو نص — نحاول تحليلها
                    $decoded = json_decode($defaultAbilities, true);
                    if (is_array($decoded)) {
                        $updates['permissions'] = json_encode($decoded);
                    }
                }
            }

            // scope_type_id: ابحث عن scope_type بالمفتاح القديم
            if (empty($row->scope_type_id)) {
                $legacyScopeType = property_exists($row, 'scope_type') ? $row->scope_type : null;
                if ($legacyScopeType) {
                    $st = DB::table('scope_types')->where('key', $legacyScopeType)->first();
                    if ($st) {
                        $updates['scope_type_id'] = $st->id;
                    }
                }
            }

            if (! empty($updates)) {
                $updates['updated_at'] = $now;
                DB::table('scoped_role_definitions')
                    ->where('id', $row->id)
                    ->update($updates);
            }
        }

        $this->clearCache();
    }

    // ===================================================================
    // down
    // ===================================================================

    public function down(): void
    {
        // نسترجع النسخة الاحتياطية من permission_audits
        $auditRow = DB::table('permission_audits')
            ->where('event', 'migration')
            ->where('user_agent', 'migration')
            ->whereRaw("new_value::jsonb->>'migration' = ?", ['2026_06_20_100004_unify_scoped_role_definitions_schema'])
            ->whereRaw("new_value::jsonb->>'action' = ?", ['unify_definitions_iii'])
            ->whereNotNull('old_value')
            ->whereRaw("old_value::jsonb != 'null'")
            ->latest('created_at')
            ->first();

        if (! $auditRow) {
            return;
        }

        $backup = json_decode($auditRow->old_value, true);
        if (! is_array($backup)) {
            return;
        }

        foreach ($backup as $original) {
            if (empty($original['id'])) {
                continue;
            }

            // استعادة role_key وlabel_ar إلى null إن كانت القيمة القديمة null
            DB::table('scoped_role_definitions')
                ->where('id', $original['id'])
                ->update([
                    'role_key' => $original['role_key'] ?? null,
                    'label_ar' => $original['label_ar'] ?? null,
                    'permissions' => $original['permissions'] ?? null,
                    'scope_type_id' => $original['scope_type_id'] ?? null,
                    'updated_at' => now(),
                ]);
        }

        $this->clearCache();
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    private function clearCache(): void
    {
        Cache::forget('scope_types_active');
        // لا نعرف مسبقاً المفاتيح — نمسح عاماً بحذر
        // نجلب كل المفاتيح الموجودة ونمسح cacheها
        $types = DB::table('scope_types')->get(['key']);
        foreach ($types as $type) {
            Cache::forget("scope_type_{$type->key}");
            Cache::forget("roles_for_type_{$type->key}");
        }
    }
};
