<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * فك الارتباط الكامل: super_admin هو الدور النظامي الوحيد.
 *
 * تير الإدارة صار مقاداً بصلاحية manage_organization (يحملها أي دور مخصّص)
 * بدل دور admin. هذه الهجرة:
 *   1. تنشئ صلاحية manage_organization وتمنحها لـ super_admin.
 *   2. تحذف دوري admin و viewer (تتحرّر إسناداتهما تلقائياً).
 *
 * المستخدمون الذين كانوا على admin/viewer يصبحون بلا دور، ويُعاد إسناد أدوار
 * مخصّصة لهم من شاشة إدارة الأدوار.
 */
return new class extends Migration
{
    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $manage = SpatiePermission::firstOrCreate([
            'name' => 'manage_organization',
            'guard_name' => 'web',
        ]);

        $superAdmin = Role::where('name', 'super_admin')->where('guard_name', 'web')->first();
        if ($superAdmin) {
            $superAdmin->givePermissionTo($manage);
        }

        foreach (['admin', 'viewer'] as $name) {
            $role = Role::where('name', $name)->where('guard_name', 'web')->first();
            $role?->delete();
        }

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        // أدوار النظام المحذوفة لا تُعاد؛ الهجرة أحادية الاتجاه.
    }
};
