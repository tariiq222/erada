<?php

namespace Database\Seeders\Mock;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public array $users = [];

    public function run(array $departments): void
    {
        $organizationId = Organization::query()->orderBy('id')->value('id');

        $usersData = [
            // مدراء عامين
            ['name' => 'محمد بن أحمد العلي', 'email' => 'mohammed.ali@demo.com', 'job_title' => 'الرئيس التنفيذي', 'role' => 'super_admin', 'dept' => 'EXC-001'],
            ['name' => 'عبدالله بن خالد السعيد', 'email' => 'abdullah.saeed@demo.com', 'job_title' => 'نائب الرئيس التنفيذي', 'role' => 'admin', 'dept' => 'EXC-001'],

            // مدراء مشاريع
            ['name' => 'فاطمة بنت سعد الغامدي', 'email' => 'fatima.ghamdi@demo.com', 'job_title' => 'مديرة مشروع أولى', 'role' => 'project_manager', 'dept' => 'PMO-001'],
            ['name' => 'سالم بن عبدالرحمن الحربي', 'email' => 'salem.harbi@demo.com', 'job_title' => 'مدير مشروع أول', 'role' => 'project_manager', 'dept' => 'PMO-001'],
            ['name' => 'نورة بنت محمد الزهراني', 'email' => 'noura.zahrani@demo.com', 'job_title' => 'مديرة مشروع', 'role' => 'project_manager', 'dept' => 'PMO-001'],

            // فريق التقنية
            ['name' => 'سعود بن فهد الدوسري', 'email' => 'saud.dosari@demo.com', 'job_title' => 'مدير تقنية المعلومات', 'role' => 'admin', 'dept' => 'IT-001'],
            ['name' => 'ريم بنت فهد القحطاني', 'email' => 'reem.qahtani@demo.com', 'job_title' => 'مصممة واجهات مستخدم', 'role' => 'member', 'dept' => 'DEV-001'],
            ['name' => 'يوسف بن إبراهيم الشمري', 'email' => 'yousef.shamri@demo.com', 'job_title' => 'مهندس برمجيات أول', 'role' => 'member', 'dept' => 'DEV-001'],
            ['name' => 'هند بنت علي المطيري', 'email' => 'hind.mutairi@demo.com', 'job_title' => 'محللة نظم', 'role' => 'member', 'dept' => 'DEV-001'],
            ['name' => 'خالد بن سليمان العتيبي', 'email' => 'khalid.otaibi@demo.com', 'job_title' => 'مختبر برمجيات', 'role' => 'member', 'dept' => 'DEV-001'],
            ['name' => 'أحمد بن ناصر الشهري', 'email' => 'ahmed.shahri@demo.com', 'job_title' => 'مهندس DevOps', 'role' => 'member', 'dept' => 'INF-001'],
            ['name' => 'منى بنت عبدالله الجهني', 'email' => 'mona.juhani@demo.com', 'job_title' => 'مسؤولة أمن المعلومات', 'role' => 'member', 'dept' => 'SEC-001'],

            // فريق الموارد البشرية
            ['name' => 'سارة بنت أحمد الحربي', 'email' => 'sara.harbi@demo.com', 'job_title' => 'مديرة الموارد البشرية', 'role' => 'admin', 'dept' => 'HR-001'],
            ['name' => 'عمر بن محمد الغامدي', 'email' => 'omar.ghamdi@demo.com', 'job_title' => 'أخصائي توظيف', 'role' => 'member', 'dept' => 'REC-001'],
            ['name' => 'لمى بنت سعد العتيبي', 'email' => 'lama.otaibi@demo.com', 'job_title' => 'منسقة تدريب', 'role' => 'member', 'dept' => 'TRN-001'],

            // فريق المالية
            ['name' => 'فيصل بن عبدالعزيز الراشد', 'email' => 'faisal.rashed@demo.com', 'job_title' => 'المدير المالي', 'role' => 'admin', 'dept' => 'FIN-001'],
            ['name' => 'دانة بنت خالد السويلم', 'email' => 'dana.suwailem@demo.com', 'job_title' => 'محاسبة أولى', 'role' => 'member', 'dept' => 'ACC-001'],

            // فريق العمليات والجودة
            ['name' => 'ماجد بن سعيد القحطاني', 'email' => 'majed.qahtani@demo.com', 'job_title' => 'مدير العمليات', 'role' => 'admin', 'dept' => 'OPS-001'],
            ['name' => 'نوف بنت عبدالرحمن المالكي', 'email' => 'nouf.maliki@demo.com', 'job_title' => 'مسؤولة الجودة', 'role' => 'member', 'dept' => 'QA-001'],
        ];

        $deptMap = [];
        foreach ($departments as $dept) {
            $deptMap[$dept->code] = $dept->id;
        }

        foreach ($usersData as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'job_title' => $data['job_title'],
                    'department_id' => $deptMap[$data['dept']] ?? null,
                    'organization_id' => $organizationId,
                    'is_active' => true,
                ]
            );

            if (! $user->hasRole($data['role'])) {
                $user->assignRole($data['role']);
            }

            $this->users[] = $user;
        }
    }
}
