<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * توحيد النطاق: تحويل دور admin من صلاحيات الكل في المشاريع والمهام
 * إلى صلاحيات نطاق الإدارة المقابلة لها.
 *
 * قبل التوحيد كان admin يملك صلاحية الكل ويُضيَّق نطاقه برمجياً عبر isAdmin.
 * بعد التوحيد صار النطاق مقاداً بالصلاحية، فيجب أن يحمل admin الصلاحية الصريحة
 * لنطاق الإدارة حتى يبقى سلوكه في رؤية وتعديل مشاريع ومهام قسمه كما هو.
 */
return new class extends Migration
{
    private array $swap = [
        'view_projects' => 'view_department_projects',
        'edit_projects' => 'edit_department_projects',
        'view_tasks' => 'view_department_tasks',
        'edit_tasks' => 'edit_department_tasks',
    ];

    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (array_values($this->swap) as $name) {
            SpatiePermission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($admin) {
            foreach ($this->swap as $from => $to) {
                if ($admin->hasPermissionTo($from)) {
                    $admin->revokePermissionTo($from);
                }
                $admin->givePermissionTo($to);
            }
        }

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($admin) {
            foreach ($this->swap as $from => $to) {
                if ($admin->hasPermissionTo($to)) {
                    $admin->revokePermissionTo($to);
                }
                $admin->givePermissionTo($from);
            }
        }

        $registrar->forgetCachedPermissions();
    }
};
