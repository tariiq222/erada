<?php

use App\Modules\Core\Authorization\Capability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * هجرة (iv) — Backfill scope_types الناقصة (program, portfolio) وتعريفات أدوارها
 *
 * هذه الهجرة تُهيّئ الأنواع والتعريفات بحيث يستطيع المحرّك AccessDecision::can()
 * فحص قدرات Strategy عبر السلسلة عند تفعيله (flag=ON). كل الـ flags ما زالت OFF.
 *
 * ترتيب التشغيل: iv (هذه) → ii → i → iii
 * idempotent: firstOrCreate على القيود الفريدة.
 */
return new class extends Migration
{
    /**
     * السياقات التي تُضاف إن غابت مع بياناتها
     *
     * @var array<string, array{label_ar: string, label_en: string, model_class: string, supports_hierarchy: bool, sort_order: int, roles: array}>
     */
    private array $scopeTypesToAdd = [
        'program' => [
            'label_ar' => 'البرنامج / المبادرة',
            'label_en' => 'Program',
            'model_class' => 'App\\Modules\\Strategy\\Models\\Program',
            'supports_hierarchy' => true,
            'sort_order' => 20,
            'roles' => [
                [
                    'role_key' => 'owner',
                    'label_ar' => 'المالك',
                    'label_en' => 'Owner',
                    'is_admin_role' => true,
                    'can_manage_members' => true,
                    'can_edit' => true,
                    'can_delete' => true,
                    'can_view_all' => true,
                    'sort_order' => 10,
                    'permissions' => [
                        Capability::STRATEGY_VIEW,
                        Capability::STRATEGY_CREATE,
                        Capability::STRATEGY_EDIT,
                        Capability::STRATEGY_DELETE,
                        Capability::STRATEGY_MANAGE_PRIORITY,
                        Capability::STRATEGY_CHANGE_STATUS,
                        Capability::STRATEGY_ASSIGN_OWNER,
                        Capability::STRATEGY_MANAGE_PROJECTS,
                    ],
                ],
                [
                    'role_key' => 'program_manager',
                    'label_ar' => 'مدير البرنامج',
                    'label_en' => 'Program Manager',
                    'is_admin_role' => false,
                    'can_manage_members' => true,
                    'can_edit' => true,
                    'can_delete' => false,
                    'can_view_all' => true,
                    'sort_order' => 20,
                    'permissions' => [
                        Capability::STRATEGY_VIEW,
                        Capability::STRATEGY_EDIT,
                        Capability::STRATEGY_CHANGE_STATUS,
                        Capability::STRATEGY_MANAGE_PROJECTS,
                    ],
                ],
                [
                    'role_key' => 'executive_sponsor',
                    'label_ar' => 'الراعي التنفيذي',
                    'label_en' => 'Executive Sponsor',
                    'is_admin_role' => false,
                    'can_manage_members' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                    'can_view_all' => true,
                    'sort_order' => 30,
                    'permissions' => [
                        Capability::STRATEGY_VIEW,
                        Capability::STRATEGY_MANAGE_PRIORITY,
                        Capability::STRATEGY_CHANGE_STATUS,
                    ],
                ],
            ],
        ],
        'portfolio' => [
            'label_ar' => 'المحفظة / الالتزام التنفيذي',
            'label_en' => 'Portfolio',
            'model_class' => 'App\\Modules\\Strategy\\Models\\Portfolio',
            'supports_hierarchy' => false,
            'sort_order' => 10,
            'roles' => [
                [
                    'role_key' => 'owner',
                    'label_ar' => 'مالك المحفظة',
                    'label_en' => 'Portfolio Owner',
                    'is_admin_role' => true,
                    'can_manage_members' => true,
                    'can_edit' => true,
                    'can_delete' => true,
                    'can_view_all' => true,
                    'sort_order' => 10,
                    'permissions' => [
                        Capability::STRATEGY_VIEW,
                        Capability::STRATEGY_CREATE,
                        Capability::STRATEGY_EDIT,
                        Capability::STRATEGY_DELETE,
                        Capability::STRATEGY_MANAGE_PRIORITY,
                        Capability::STRATEGY_CHANGE_STATUS,
                        Capability::STRATEGY_ASSIGN_OWNER,
                        Capability::STRATEGY_MANAGE_PROJECTS,
                    ],
                ],
            ],
        ],
    ];

    /** المفاتيح التي أضافتها هذه الهجرة فعلاً (للـ down) */
    private array $addedScopeTypeKeys = [];

    // ===================================================================
    // up
    // ===================================================================

    public function up(): void
    {
        $now = now();

        foreach ($this->scopeTypesToAdd as $key => $data) {
            // firstOrCreate على key (القيد الفريد)
            $existing = DB::table('scope_types')->where('key', $key)->first();

            if ($existing === null) {
                $scopeTypeId = DB::table('scope_types')->insertGetId([
                    'key' => $key,
                    'label_ar' => $data['label_ar'],
                    'label_en' => $data['label_en'],
                    'model_class' => $data['model_class'],
                    'icon' => null,
                    'color' => 'primary',
                    'supports_hierarchy' => $data['supports_hierarchy'],
                    'supports_expiry' => false,
                    'is_active' => true,
                    'sort_order' => $data['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->addedScopeTypeKeys[] = $key;
            } else {
                $scopeTypeId = $existing->id;
            }

            // تعريفات الأدوار
            foreach ($data['roles'] as $role) {
                $exists = DB::table('scoped_role_definitions')
                    ->where('scope_type_id', $scopeTypeId)
                    ->where('role_key', $role['role_key'])
                    ->exists();

                if (! $exists) {
                    DB::table('scoped_role_definitions')->insert([
                        // legacy NOT NULL columns (المخطط القديم من 2026_01_12)
                        'name' => $key.'.'.$role['role_key'],
                        'display_name' => $role['label_ar'],
                        'scope_type' => $key,
                        // أعمدة المخطط الحالي
                        'scope_type_id' => $scopeTypeId,
                        'role_key' => $role['role_key'],
                        'label_ar' => $role['label_ar'],
                        'label_en' => $role['label_en'],
                        'description' => null,
                        'color' => 'primary',
                        'permissions' => json_encode($role['permissions']),
                        'is_admin_role' => $role['is_admin_role'],
                        'can_manage_members' => $role['can_manage_members'],
                        'can_edit' => $role['can_edit'],
                        'can_delete' => $role['can_delete'],
                        'can_view_all' => $role['can_view_all'],
                        'is_active' => true,
                        'sort_order' => $role['sort_order'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
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
                'migration' => '2026_06_20_100001_backfill_scope_types_and_role_definitions',
                'action' => 'backfill_scope_types_iv',
                'added_scope_types' => array_keys($this->scopeTypesToAdd),
            ]),
            'reason' => 'Phase C migration (iv): backfill program/portfolio scope_types + role definitions',
            'ip_address' => null,
            'user_agent' => 'migration',
            'created_at' => now(),
        ]);

        // مسح cache محدد
        $this->clearCache();
    }

    // ===================================================================
    // down
    // ===================================================================

    public function down(): void
    {
        foreach ($this->scopeTypesToAdd as $key => $data) {
            $scopeType = DB::table('scope_types')->where('key', $key)->first();
            if (! $scopeType) {
                continue;
            }

            // حذف تعريفات الأدوار المُضافة
            foreach ($data['roles'] as $role) {
                DB::table('scoped_role_definitions')
                    ->where('scope_type_id', $scopeType->id)
                    ->where('role_key', $role['role_key'])
                    ->delete();
            }

            // حذف scope_type نفسه إن لم يكن موجوداً قبل الهجرة
            // (نحذفه فقط إن كانت تعريفاته كلها مُضافة من هنا)
            $remainingDefs = DB::table('scoped_role_definitions')
                ->where('scope_type_id', $scopeType->id)
                ->count();

            if ($remainingDefs === 0) {
                DB::table('scope_types')->where('key', $key)->delete();
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
        foreach (array_keys($this->scopeTypesToAdd) as $key) {
            Cache::forget("scope_type_{$key}");
            Cache::forget("roles_for_type_{$key}");
            // clear role definitions cache
            $roleKeys = array_column($this->scopeTypesToAdd[$key]['roles'], 'role_key');
            foreach ($roleKeys as $rk) {
                Cache::forget("role_def_{$key}_{$rk}");
            }
        }
    }
};
