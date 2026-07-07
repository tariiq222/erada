<?php

namespace Database\Seeders\Meetings;

use App\Modules\Core\Enums\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * MeetingsPermissionsSeeder — Phase 9 of master AuthZ unification plan.
 *
 * Phase 5 introduced canonical dotted capabilities (meetings.view/create/
 * edit/delete/record_decisions) and kept the legacy kebab strings
 * (view-meetings / manage-meetings / record-decisions) for the
 * compatibility window. Phase 9 retires the legacy strings: this
 * seeder only creates canonical permissions and grants the admin role
 * the canonical names.
 *
 * RolesAndPermissionsSeeder's orphan-permission sweep deletes any
 * legacy kebab permissions left over from previous installs, so the
 * end state after Phase 9 is canonical-only.
 */
class MeetingsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Canonical dotted capabilities (Phase 5 new-install path;
        //    Phase 9 is the only install path because legacy kebab
        //    cases were retired from Permission.php).
        foreach ([
            Permission::MEETINGS_VIEW->value,
            Permission::MEETINGS_CREATE->value,
            Permission::MEETINGS_EDIT->value,
            Permission::MEETINGS_DELETE->value,
            Permission::MEETINGS_RECORD_DECISIONS->value,
        ] as $name) {
            SpatiePermission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        // 2. super_admin: مغطى تلقائياً بـ SpatiePermission::all() في RolesAndPermissionsSeeder.

        // 3. admin: منح الصلاحيات صراحةً (idempotent عبر givePermissionTo).
        //    Phase 9 drops the legacy kebab grants; admin carries the
        //    canonical dotted names only.
        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($admin) {
            $admin->givePermissionTo([
                Permission::MEETINGS_VIEW->value,
                Permission::MEETINGS_CREATE->value,
                Permission::MEETINGS_EDIT->value,
                Permission::MEETINGS_DELETE->value,
                Permission::MEETINGS_RECORD_DECISIONS->value,
            ]);
        }

        // 4. viewer: لا يحصل على أي صلاحية من المجموعة الجديدة (مطابقاً لـ spec §11.3).

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command?->info('تم إنشاء/تحديث صلاحيات الاجتماعات بنجاح');
    }
}
