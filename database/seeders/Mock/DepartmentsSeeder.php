<?php

namespace Database\Seeders\Mock;

use App\Modules\Core\Models\Organization;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentsSeeder extends Seeder
{
    public array $departments = [];

    public function run(): void
    {
        // Demo departments belong to the default organization; without an org
        // the strategy/project chain (programs -> KPIs, all NOT NULL org) fails.
        $organizationId = Organization::query()->orderBy('id')->value('id');

        $departmentsData = [
            // المستوى الأول - الإدارات الرئيسية
            ['name' => 'الإدارة العليا', 'code' => 'EXC-001', 'level' => 1, 'parent' => null],
            ['name' => 'إدارة تقنية المعلومات', 'code' => 'IT-001', 'level' => 1, 'parent' => null],
            ['name' => 'إدارة الموارد البشرية', 'code' => 'HR-001', 'level' => 1, 'parent' => null],
            ['name' => 'الإدارة المالية', 'code' => 'FIN-001', 'level' => 1, 'parent' => null],
            ['name' => 'إدارة التسويق', 'code' => 'MKT-001', 'level' => 1, 'parent' => null],
            ['name' => 'مكتب إدارة المشاريع', 'code' => 'PMO-001', 'level' => 1, 'parent' => null],
            ['name' => 'إدارة العمليات', 'code' => 'OPS-001', 'level' => 1, 'parent' => null],
            ['name' => 'إدارة الجودة', 'code' => 'QA-001', 'level' => 1, 'parent' => null],

            // المستوى الثاني - الأقسام الفرعية
            ['name' => 'قسم تطوير البرمجيات', 'code' => 'DEV-001', 'level' => 2, 'parent' => 'IT-001'],
            ['name' => 'قسم الدعم الفني', 'code' => 'SUP-001', 'level' => 2, 'parent' => 'IT-001'],
            ['name' => 'قسم أمن المعلومات', 'code' => 'SEC-001', 'level' => 2, 'parent' => 'IT-001'],
            ['name' => 'قسم البنية التحتية', 'code' => 'INF-001', 'level' => 2, 'parent' => 'IT-001'],
            ['name' => 'قسم التوظيف', 'code' => 'REC-001', 'level' => 2, 'parent' => 'HR-001'],
            ['name' => 'قسم التدريب والتطوير', 'code' => 'TRN-001', 'level' => 2, 'parent' => 'HR-001'],
            ['name' => 'قسم المحاسبة', 'code' => 'ACC-001', 'level' => 2, 'parent' => 'FIN-001'],
            ['name' => 'قسم الميزانية', 'code' => 'BUD-001', 'level' => 2, 'parent' => 'FIN-001'],
        ];

        $parentMap = [];
        foreach ($departmentsData as $data) {
            $parentId = null;
            if ($data['parent'] && isset($parentMap[$data['parent']])) {
                $parentId = $parentMap[$data['parent']];
            }

            $dept = Department::firstOrCreate(
                ['code' => $data['code']],
                [
                    'organization_id' => $organizationId,
                    'name' => $data['name'],
                    'level' => $data['level'],
                    'parent_id' => $parentId,
                    'description' => 'وصف '.$data['name'],
                    'is_active' => true,
                ]
            );

            $parentMap[$data['code']] = $dept->id;
            $this->departments[] = $dept;
        }
    }
}
