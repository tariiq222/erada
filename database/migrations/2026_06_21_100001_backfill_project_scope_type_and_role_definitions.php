<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Backfill scope_type=project + تعريفات أدواره (manager/member/viewer) للإنتاج.
 *
 * قبل هذه الهجرة كان الإنتاج يحوي program/portfolio/organization فقط بلا أي
 * تعريف project — فيرجع AccessDecision::can() = false لمديري المشاريع عند
 * اعتماد المحرّك كمسار وحيد في ProjectController. هذه الهجرة تُغلق تلك الفجوة.
 *
 * القيم مطابقة تماماً للـ fixtures الكنسية في
 * tests/Unit/Policies/ProjectPolicyTest::seedProjectScopeDefinitions()
 * (وParity test) — name='project_<role>', role_key=manager/member/viewer،
 * مدير المشروع يعدّل ويدير الأعضاء لكن لا يحذف (الحذف لـ admin/super_admin).
 *
 * idempotent: existence-check على (name, scope_type) — فلا يتعارض مع بذر
 * الاختبارات الذي يستخدم نفس existence-check أو firstOrCreate.
 */
return new class extends Migration
{
    private string $scopeKey = 'project';

    private array $roles = [
        [
            'name' => 'project_manager',
            'role_key' => 'manager',
            'label_ar' => 'مدير المشروع',
            'label_en' => 'Project Manager',
            'is_admin_role' => false,
            'can_manage_members' => true,
            'can_edit' => true,
            'can_delete' => false,
            'can_view_all' => true,
            'sort_order' => 1,
            'permissions' => [
                'projects.view',
                'projects.edit',
                'projects.manage_members',
                'projects.assign_roles',
                'tasks.view',
                'tasks.create',
                'tasks.edit',
                'tasks.delete',
                'tasks.complete',
            ],
        ],
        [
            'name' => 'project_member',
            'role_key' => 'member',
            'label_ar' => 'عضو',
            'label_en' => 'Member',
            'is_admin_role' => false,
            'can_manage_members' => false,
            'can_edit' => false,
            'can_delete' => false,
            'can_view_all' => true,
            'sort_order' => 2,
            'permissions' => ['projects.view', 'tasks.view'],
        ],
        [
            'name' => 'project_viewer',
            'role_key' => 'viewer',
            'label_ar' => 'مشاهد',
            'label_en' => 'Viewer',
            'is_admin_role' => false,
            'can_manage_members' => false,
            'can_edit' => false,
            'can_delete' => false,
            'can_view_all' => true,
            'sort_order' => 3,
            'permissions' => ['projects.view', 'tasks.view'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        $existing = DB::table('scope_types')->where('key', $this->scopeKey)->first();
        if ($existing === null) {
            $scopeTypeId = DB::table('scope_types')->insertGetId([
                'key' => $this->scopeKey,
                'label_ar' => 'مشروع',
                'label_en' => 'Project',
                'model_class' => 'App\\Modules\\Projects\\Models\\Project',
                'icon' => null,
                'color' => 'primary',
                'supports_hierarchy' => true,
                'supports_expiry' => false,
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $scopeTypeId = $existing->id;
        }

        foreach ($this->roles as $i => $role) {
            $exists = DB::table('scoped_role_definitions')
                ->where('scope_type_id', $scopeTypeId)
                ->where('role_key', $role['role_key'])
                ->exists();

            if (! $exists) {
                DB::table('scoped_role_definitions')->insert([
                    'name' => $role['name'],
                    'display_name' => $role['label_en'],
                    'scope_type' => $this->scopeKey,
                    'level' => $i + 1,
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

        $this->clearCache();
    }

    public function down(): void
    {
        $scopeType = DB::table('scope_types')->where('key', $this->scopeKey)->first();
        if (! $scopeType) {
            return;
        }

        foreach ($this->roles as $role) {
            DB::table('scoped_role_definitions')
                ->where('name', $role['name'])
                ->where('scope_type', $this->scopeKey)
                ->delete();
        }

        $remaining = DB::table('scoped_role_definitions')
            ->where('scope_type_id', $scopeType->id)
            ->count();

        if ($remaining === 0) {
            DB::table('scope_types')->where('key', $this->scopeKey)->delete();
        }

        $this->clearCache();
    }

    private function clearCache(): void
    {
        Cache::forget('scope_types_active');
        Cache::forget("scope_type_{$this->scopeKey}");
        Cache::forget("roles_for_type_{$this->scopeKey}");
        foreach ($this->roles as $role) {
            Cache::forget("role_def_{$this->scopeKey}_{$role['role_key']}");
        }
    }
};
