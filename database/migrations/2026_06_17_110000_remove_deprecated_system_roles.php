<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * إزالة أدوار النظام المهجورة project_manager و member.
 *
 * بعد توحيد الأدوار صارت أدوار المشاريع تُسنَد كأدوار سياقية على مستوى المشروع،
 * فلم يعد لـ project_manager/member وجود كأدوار نظام. هذه الهجرة ترحّل أي مستخدم
 * عليه أحد الدورين إلى viewer (مع إبقاء أدواره السياقية كما هي) ثم تحذف الدورين،
 * حتى لا يظهرا في صفحة إدارة الأدوار.
 */
return new class extends Migration
{
    private array $deprecated = ['project_manager', 'member'];

    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach ($this->deprecated as $name) {
            $role = Role::where('name', $name)->where('guard_name', 'web')->first();
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

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        // أدوار مهجورة لا تُعاد؛ الهجرة أحادية الاتجاه.
    }
};
