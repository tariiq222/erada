<?php

namespace Database\Seeders;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Models\ScopedRoleDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // إنشاء الصلاحيات
        // Single source of truth: App\Modules\Core\Enums\Permission
        foreach (Permission::cases() as $permission) {
            SpatiePermission::firstOrCreate([
                'name' => $permission->value,
                'guard_name' => 'web',
            ]);
        }

        // إنشاء الأدوار وتعيين الصلاحيات

        // ========== Super Admin - يملك كل الصلاحيات ==========
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->givePermissionTo(SpatiePermission::all());

        // ========== Admin - organization-wide (top of a single organization) ==========
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->givePermissionTo([
            // admin is the organization-wide role (one organization/hospital):
            // it manages everything inside its organization. super_admin sits
            // above all organizations; admin is the top of a single one.
            Permission::VIEW_ORGANIZATIONS->value,
            Permission::VIEW_USERS->value,
            Permission::CREATE_USERS->value,
            Permission::EDIT_USERS->value,
            Permission::VIEW_DASHBOARD->value,
            // مدير الإدارة: الرؤية حسب القسم تُمنح عبر الدور السياقي على القسم
            // (engine) لا عبر صلاحية مسطّحة. الصلاحيات المتبقية على مستوى السلسلة.
            Permission::CREATE_PROJECTS->value,
            Permission::DELETE_PROJECTS->value,
            Permission::CREATE_TASKS->value,
            Permission::DELETE_TASKS->value,
            Permission::VIEW_REPORTS->value,
            Permission::EXPORT_REPORTS->value,
            Permission::VIEW_ROLES->value,
            Permission::ASSIGN_ROLES->value,
            Permission::UPLOAD_ATTACHMENTS->value,
            Permission::DOWNLOAD_ATTACHMENTS->value,
            Permission::DELETE_ATTACHMENTS->value,
            Permission::CREATE_COMMENTS->value,
            Permission::EDIT_COMMENTS->value,
            Permission::DELETE_COMMENTS->value,
            Permission::VIEW_AUDIT_LOGS->value,
            // صلاحيات الموديولات الجديدة
            Permission::VIEW_STRATEGY->value,
            Permission::CREATE_STRATEGY->value,
            Permission::EDIT_STRATEGY->value,
            Permission::VIEW_SURVEY_RESPONSES->value,
            Permission::REVIEW_SURVEY_RESPONSES->value,
            Permission::REVIEW_DATA_IMPORTS->value,
            Permission::VIEW_DEPARTMENTS->value,
            Permission::CREATE_DEPARTMENTS->value,
            Permission::EDIT_DEPARTMENTS->value,
            // صلاحيات OVR
            Permission::OVR_VIEW_ALL->value,
            Permission::OVR_CONFIDENTIAL_VIEW->value,
            Permission::OVR_CREATE->value,
            Permission::OVR_EDIT_ALL->value,
            Permission::OVR_CHANGE_STATUS->value,
            Permission::OVR_ASSIGN->value,
            Permission::OVR_COMMENT->value,
            Permission::OVR_VIEW_INTERNAL_COMMENTS->value,
            Permission::OVR_EXPORT->value,
            Permission::OVR_VIEW_STATISTICS->value,
            Permission::EDIT_ANY_COMMENT->value,
            Permission::DELETE_ANY_COMMENT->value,
            // صلاحيات RiskManagement
            // NOTE: DELETE_RISKS is intentionally NOT granted to admin
            // (only super_admin can delete enterprise risks).
            // صلاحيات الاجتماعات (canonical only; legacy kebab strings were
            // retired in Phase 9. MeetingsPermissionsSeeder grants admin the
            // canonical dotted capabilities for the same role.)
        ]);

        // ملاحظة: أدوار النظام project_manager و member أُلغيت — أدوار المشاريع
        // تُسنَد الآن كأدوار سياقية (scoped roles) على مستوى المشروع.

        // ========== Viewer - مشاهد فقط ==========
        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $viewer->givePermissionTo([
            // view_own_* ladder strings removed in Wave 4 (engine handles).
            Permission::VIEW_DASHBOARD->value,
            Permission::DOWNLOAD_ATTACHMENTS->value,
            Permission::CREATE_COMMENTS->value,
            Permission::VIEW_DEPARTMENTS->value,
            // صلاحيات RiskManagement
        ]);

        // ترحيل المستخدمين من الأدوار الملغاة (project_manager/member) إلى viewer،
        // مع الإبقاء على أدوارهم السياقية على المشاريع كما هي، ثم حذف الأدوار الملغاة.
        foreach (['project_manager', 'member'] as $deprecatedRole) {
            $role = Role::where('name', $deprecatedRole)->where('guard_name', 'web')->first();
            if (! $role) {
                continue;
            }

            foreach ($role->users as $user) {
                if (! $user->hasAnyRole(['super_admin', 'admin', 'viewer'])) {
                    $user->assignRole('viewer');
                }
                $user->removeRole($role);
            }

            $role->delete();
        }

        // تنظيف الصلاحيات اليتيمة: أي صلاحية في قاعدة البيانات لم تعد معرّفة في الـ enum
        SpatiePermission::whereNotIn('name', Permission::values())->delete();

        // أدوار النظام member/project_manager مُلغاة في الإنتاج (وُحّدت لأدوار سياقية،
        // والمستخدمون يُهاجَرون إلى viewer). نعيد إنشاءها في بيئة الاختبار فقط — كأدوار
        // وظيفية بمستوى viewer (Spatie لطبقة الاستعلام + تعريف org سياقي لمحرّك can)
        // — لدعم تجهيزات الاختبارات القديمة دون تغيير دلالتها. لا تُبذر في الإنتاج.
        if (! app()->isProduction()) {
            $this->seedLegacyTestRoles($viewer);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info('تم إنشاء/تحديث الأدوار والصلاحيات بنجاح');
    }

    /**
     * Ensure the admin org-scoped scoped_role_definitions row carries the given
     * engine capability in its `permissions` JSON. Mirrors the dual-write shape
     * used by RoleController::store. Adds the capability if missing; leaves
     * other capabilities untouched.
     */
    protected function syncAdminEngineCapability(string $capability): void
    {
        $orgScopeTypeId = DB::table('scope_types')->where('key', 'organization')->value('id');
        if (! $orgScopeTypeId) {
            return;
        }

        $def = ScopedRoleDefinition::where('scope_type_id', $orgScopeTypeId)
            ->where('role_key', 'admin')
            ->first();

        if (! $def) {
            return;
        }

        $permissions = $def->permissions ?? [];
        if (in_array($capability, $permissions, true)) {
            return;
        }

        $permissions[] = $capability;
        $def->permissions = $permissions;
        $def->save();

        ScopedRoleDefinition::clearCache();
    }

    /**
     * بيئة الاختبار فقط: إعادة إنشاء member/project_manager بمستوى viewer.
     * Spatie role + صلاحيات viewer (لطبقة استعلام الرؤية) + تعريف org سياقي
     * يمنح كل قدرات العرض (view/view_all/view_reports) عبر permissions[]
     * ليعترف به محرّك can عبر الدور الوظيفي.
     *
     * Phase 3 (ADR-UNIFIED-ROLE-ACCESS): the retired can_view_all flag is expressed
     * as the explicit list of view-family capabilities in permissions[], exactly the
     * set the flag used to expand to in the engine.
     */
    protected function seedLegacyTestRoles(Role $viewer): void
    {
        $viewerPermissions = $viewer->permissions->pluck('name')->all();
        $orgScopeTypeId = DB::table('scope_types')
            ->where('key', 'organization')->value('id');

        // can_view_all expanded to capabilities: every capability whose action is
        // view / view_all / view_reports (mirrors the old capabilityMatchesFlags).
        $viewAllCapabilities = array_values(array_filter(
            Capability::all(),
            function (string $capability) {
                $action = str_contains($capability, '.')
                    ? substr($capability, strrpos($capability, '.') + 1)
                    : $capability;

                return in_array($action, ['view', 'view_all', 'view_reports'], true);
            }
        ));

        foreach (['member', 'project_manager'] as $legacyRole) {
            Role::firstOrCreate(['name' => $legacyRole, 'guard_name' => 'web'])
                ->syncPermissions($viewerPermissions);

            if ($orgScopeTypeId) {
                DB::table('scoped_role_definitions')->updateOrInsert(
                    ['scope_type_id' => $orgScopeTypeId, 'role_key' => $legacyRole],
                    [
                        'name' => 'organization.'.$legacyRole,
                        'display_name' => $legacyRole,
                        'scope_type' => 'organization',
                        'label_ar' => $legacyRole,
                        'permissions' => json_encode($viewAllCapabilities),
                        'is_admin_role' => false,
                        'is_active' => true,
                        'sort_order' => 99,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
