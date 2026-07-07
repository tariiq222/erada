<?php

use App\Modules\Core\Authorization\Capability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * هجرة (ii) — Spatie flat functional roles → scoped_role_definitions + إسناد مستخدمين
 *
 * تُنشئ تعريفات الأدوار الوظيفية (admin/viewer) على scope=organization
 * في scoped_role_definitions، وتُسند لكل مستخدم يحمل الدور في Spatie
 * صفاً في model_has_scoped_roles على (scope_type='organization', scope_id=org_id).
 *
 * التغطية: كل الموديولات — projects, tasks, risks, departments/hr,
 *           ovr, meetings, surveys, strategy, core/admin.
 *
 * idempotent: firstOrCreate على القيود الفريدة.
 * الـ flags ما زالت OFF.
 */
return new class extends Migration
{
    /**
     * مخطط الأدوار الوظيفية (Spatie role name → تعريف القدرات الموحّدة)
     *
     * المنطق الدلالي (ترجمة الملحق في البريف):
     *  - admin: يملك كل صلاحيات *_all / manage_organization / عموم النظام → is_admin_role أو permissions شاملة
     *  - viewer: يملك صلاحيات *_own / view_dashboard / view الأساسية
     *
     * @var array<string, array{label_ar: string, label_en: string, is_admin_role: bool, can_*: bool, permissions: string[]}>
     */
    private array $functionalRoles = [
        'admin' => [
            'label_ar' => 'مدير إدارة',
            'label_en' => 'Admin',
            'description' => 'مدير الإدارة: رؤية وإدارة نطاق القسم في كل الموديولات',
            'is_admin_role' => true,
            'can_manage_members' => true,
            'can_edit' => true,
            'can_delete' => true,
            'can_view_all' => true,
            'sort_order' => 10,
            'permissions' => [
                // projects
                Capability::PROJECTS_VIEW,
                Capability::PROJECTS_CREATE,
                Capability::PROJECTS_EDIT,
                Capability::PROJECTS_DELETE,
                Capability::PROJECTS_MANAGE_MEMBERS,
                Capability::PROJECTS_ASSIGN_ROLES,
                Capability::PROJECTS_CHANGE_STATUS,
                Capability::PROJECTS_CLOSE,
                // tasks
                Capability::TASKS_VIEW,
                Capability::TASKS_CREATE,
                Capability::TASKS_EDIT,
                Capability::TASKS_DELETE,
                Capability::TASKS_COMPLETE,
                Capability::TASKS_ASSIGN,
                // departments / hr
                Capability::DEPARTMENTS_VIEW,
                Capability::DEPARTMENTS_CREATE,
                Capability::DEPARTMENTS_EDIT,
                Capability::DEPARTMENTS_DELETE,
                Capability::DEPARTMENTS_MANAGE_MEMBERS,
                Capability::DEPARTMENTS_ASSIGN_ROLES,
                Capability::HR_VIEW,
                Capability::HR_CREATE,
                Capability::HR_EDIT,
                Capability::HR_DELETE,
                Capability::HR_MANAGE_PROFILES,
                // strategy
                Capability::STRATEGY_VIEW,
                Capability::STRATEGY_CREATE,
                Capability::STRATEGY_EDIT,
                Capability::STRATEGY_DELETE,
                Capability::STRATEGY_MANAGE_PRIORITY,
                Capability::STRATEGY_CHANGE_STATUS,
                Capability::STRATEGY_ASSIGN_OWNER,
                Capability::STRATEGY_MANAGE_PROJECTS,
                // risks
                Capability::RISKS_VIEW,
                Capability::RISKS_CREATE,
                Capability::RISKS_EDIT,
                Capability::RISKS_DELETE,
                Capability::RISKS_REASSESS,
                Capability::RISKS_CHANGE_STATUS,
                Capability::RISKS_VIEW_REPORTS,
                // ovr (view_all / manage level — confidential يبقى منطقاً خاصاً في Policy)
                Capability::OVR_VIEW,
                Capability::OVR_CREATE,
                Capability::OVR_EDIT,
                Capability::OVR_DELETE,
                Capability::OVR_INVESTIGATE,
                Capability::OVR_CLOSE,
                Capability::OVR_VIEW_ALL,
                // admin core
                Capability::ROLES_VIEW,
                Capability::ROLES_CREATE,
                Capability::ROLES_EDIT,
                Capability::ROLES_ASSIGN,
                Capability::USERS_VIEW,
                Capability::USERS_CREATE,
                Capability::USERS_EDIT,
                Capability::SETTINGS_VIEW,
                Capability::SETTINGS_EDIT,
                Capability::SETTINGS_MANAGE,
            ],
        ],
        'viewer' => [
            'label_ar' => 'مشاهد',
            'label_en' => 'Viewer',
            'description' => 'مشاهد: رؤية المحتوى المرتبط به فقط',
            'is_admin_role' => false,
            'can_manage_members' => false,
            'can_edit' => false,
            'can_delete' => false,
            'can_view_all' => false,
            'sort_order' => 30,
            'permissions' => [
                // projects (own)
                Capability::PROJECTS_VIEW,
                // tasks (own)
                Capability::TASKS_VIEW,
                // departments (read only)
                Capability::DEPARTMENTS_VIEW,
                Capability::HR_VIEW,
                // strategy (read only)
                Capability::STRATEGY_VIEW,
                // risks (read only)
                Capability::RISKS_VIEW,
                // ovr (own level)
                Capability::OVR_VIEW,
                Capability::OVR_CREATE,
            ],
        ],
    ];

    // ===================================================================
    // up
    // ===================================================================

    public function up(): void
    {
        $now = now();

        // نجلب (أو نُنشئ) scope_type=organization
        $orgScopeType = DB::table('scope_types')->where('key', 'organization')->first();

        if ($orgScopeType === null) {
            $orgScopeTypeId = DB::table('scope_types')->insertGetId([
                'key' => 'organization',
                'label_ar' => 'المؤسسة',
                'label_en' => 'Organization',
                'model_class' => 'App\\Modules\\Core\\Models\\Organization',
                'icon' => null,
                'color' => 'primary',
                'supports_hierarchy' => false,
                'supports_expiry' => false,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $orgScopeTypeId = $orgScopeType->id;
        }

        // إنشاء تعريفات الأدوار الوظيفية على scope=organization
        $createdRoles = [];
        foreach ($this->functionalRoles as $roleKey => $roleData) {
            $existing = DB::table('scoped_role_definitions')
                ->where('scope_type_id', $orgScopeTypeId)
                ->where('role_key', $roleKey)
                ->first();

            if ($existing === null) {
                $defId = DB::table('scoped_role_definitions')->insertGetId([
                    // legacy NOT NULL columns (المخطط القديم من 2026_01_12)
                    'name' => 'organization.'.$roleKey,
                    'display_name' => $roleData['label_ar'],
                    'scope_type' => 'organization',
                    // أعمدة المخطط الحالي
                    'scope_type_id' => $orgScopeTypeId,
                    'role_key' => $roleKey,
                    'label_ar' => $roleData['label_ar'],
                    'label_en' => $roleData['label_en'],
                    'description' => $roleData['description'],
                    'color' => 'primary',
                    'permissions' => json_encode($roleData['permissions']),
                    'is_admin_role' => $roleData['is_admin_role'],
                    'can_manage_members' => $roleData['can_manage_members'],
                    'can_edit' => $roleData['can_edit'],
                    'can_delete' => $roleData['can_delete'],
                    'can_view_all' => $roleData['can_view_all'],
                    'is_active' => true,
                    'sort_order' => $roleData['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $createdRoles[] = $roleKey;
            }
        }

        // إسناد المستخدمين: من يملك الدور في Spatie → صف في model_has_scoped_roles
        // نجلب Spatie role→users عبر model_has_roles + roles
        $this->assignUsersFromSpatieRoles($orgScopeTypeId, $now);

        // سجل التدقيق
        DB::table('permission_audits')->insert([
            'event' => 'migration',
            'actor_id' => null,
            'target_user_id' => null,
            'scope_type' => 'organization',
            'scope_id' => null,
            'role' => null,
            'old_value' => null,
            'new_value' => json_encode([
                'migration' => '2026_06_20_100002_backfill_functional_roles_to_scoped_org',
                'action' => 'backfill_functional_roles_ii',
                'scope_type_org_id' => $orgScopeTypeId,
                'created_role_definitions' => $createdRoles,
            ]),
            'reason' => 'Phase C migration (ii): Spatie flat functional roles → scoped org definitions + user assignments',
            'ip_address' => null,
            'user_agent' => 'migration',
            'created_at' => $now,
        ]);

        $this->clearCache($orgScopeTypeId);
    }

    // ===================================================================
    // down
    // ===================================================================

    public function down(): void
    {
        $orgScopeType = DB::table('scope_types')->where('key', 'organization')->first();
        if (! $orgScopeType) {
            return;
        }

        // حذف الإسنادات التي أنشأتها هذه الهجرة (scope_type=organization حصراً)
        // نحذف فقط الأدوار التي عرّفناها (admin/viewer)
        $roleKeys = array_keys($this->functionalRoles);

        DB::table('model_has_scoped_roles')
            ->where('scope_type', 'organization')
            ->whereIn('role', $roleKeys)
            ->delete();

        // حذف تعريفات الأدوار
        DB::table('scoped_role_definitions')
            ->where('scope_type_id', $orgScopeType->id)
            ->whereIn('role_key', $roleKeys)
            ->delete();

        // لا نحذف scope_type=organization لأنه قد يُستخدم بالفعل أو أُنشئ قبلنا

        $this->clearCache($orgScopeType->id);
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    /**
     * إسناد مستخدمي Spatie إلى model_has_scoped_roles على نطاق مؤسستهم
     */
    private function assignUsersFromSpatieRoles(int $orgScopeTypeId, $now): void
    {
        // نجلب الأدوار الوظيفية من Spatie
        foreach ($this->functionalRoles as $roleKey => $roleData) {
            $spatieRole = DB::table('roles')
                ->where('name', $roleKey)
                ->where('guard_name', 'web')
                ->first();

            if (! $spatieRole) {
                continue;
            }

            // المستخدمون الحاملون لهذا الدور
            $userIds = DB::table('model_has_roles')
                ->where('role_id', $spatieRole->id)
                ->where('model_type', 'App\\Modules\\Core\\Models\\User')
                ->pluck('model_id');

            foreach ($userIds as $userId) {
                // organization_id من جدول users
                $user = DB::table('users')->where('id', $userId)->first(['id', 'organization_id']);
                if (! $user || ! $user->organization_id) {
                    continue; // مستخدم بلا مؤسسة — لا نُسند
                }

                $orgId = (int) $user->organization_id;

                // firstOrCreate على القيد الفريد (user_id, role, scope_type, scope_id)
                $exists = DB::table('model_has_scoped_roles')
                    ->where('user_id', $userId)
                    ->where('role', $roleKey)
                    ->where('scope_type', 'organization')
                    ->where('scope_id', $orgId)
                    ->exists();

                if (! $exists) {
                    DB::table('model_has_scoped_roles')->insert([
                        'user_id' => $userId,
                        'role' => $roleKey,
                        'scope_type' => 'organization',
                        'scope_id' => $orgId,
                        'inherit_to_children' => false,
                        'granted_by' => null,
                        'expires_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function clearCache(int $orgScopeTypeId): void
    {
        Cache::forget('scope_types_active');
        Cache::forget('scope_type_organization');

        foreach (array_keys($this->functionalRoles) as $roleKey) {
            Cache::forget("role_def_organization_{$roleKey}");
            Cache::forget('roles_for_type_organization');
        }
    }
};
