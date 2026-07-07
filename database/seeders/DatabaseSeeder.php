<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\SystemSettings;
use App\Modules\Core\Models\User;
use Database\Seeders\Meetings\MeetingsPermissionsSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // تشغيل seeder الأدوار والصلاحيات أولاً
        $this->call(RolesAndPermissionsSeeder::class);

        // صلاحيات موديول الاجتماعات (القرارات + الاجتماعات + التوصيات)
        $this->call(MeetingsPermissionsSeeder::class);

        // Department scope-type + capacity/cross-cutting scoped role definitions
        $this->call(ScopedDepartmentRolesSeeder::class);

        // Additional scope_types for operational models (kpi/meeting/survey)
        $this->call(AdditionalScopeTypesSeeder::class);

        // إنشاء إعدادات النظام (idempotent)
        SystemSettings::firstOrCreate(
            ['code' => 'IRADA'],
            [
                'name' => 'منصة إرادة',
                'name_en' => 'Erada System',
                'phone' => '',
                'email' => 'info@iradah.sa',
                'website' => 'https://iradah.sa',
                'settings' => [
                    'system' => [
                        'date_format' => 'DD/MM/YYYY',
                        'time_format' => '24h',
                        'timezone' => 'Asia/Riyadh',
                        'default_language' => 'ar',
                    ],
                    'projects' => [
                        'default_project_status' => 'planning',
                        'enable_milestones' => true,
                        'enable_task_dependencies' => true,
                    ],
                ],
            ]
        );

        $this->seedUser(
            email: 'admin@admin.com',
            name: 'مدير النظام',
            jobTitle: 'مدير النظام',
            role: 'super_admin'
        );

        $this->seedUser(
            email: 'manager@admin.com',
            name: 'مدير الإدارة',
            jobTitle: 'مدير إدارة',
            role: 'admin'
        );

        // The flat project_manager role was retired (project roles are scoped
        // now); deprecated project_manager users migrate to viewer, so seed the
        // demo project-lead account with the surviving viewer role.
        $this->seedUser(
            email: 'pm@admin.com',
            name: 'أحمد المدير',
            jobTitle: 'مدير مشروع',
            role: 'viewer'
        );
    }

    private function seedUser(string $email, string $name, string $jobTitle, string $role): void
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'job_title' => $jobTitle,
                'is_active' => true,
                // Stamp the default organization so the user's data appears in
                // org-scoped listings (Departments / Employees) instead of the
                // null-org fail-closed branch.
                'organization_id' => Organization::query()->orderBy('id')->value('id'),
            ]
        );

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }
}
