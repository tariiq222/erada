<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Organization;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Official organizational structure of Eradah Complex for Mental Health, Riyadh.
 *
 * Source: approved Organizational Chart (CEO: Dr. Ahmad Shandal Al-Anazi).
 * Level mapping follows the chart legend:
 *   Assistant Executive Director  -> LEVEL_EXECUTIVE  (2)
 *   Department Manager            -> LEVEL_DEPARTMENT (3)
 *   Section                       -> LEVEL_SECTION    (4)
 * The complex's Executive Director is the root (LEVEL_TOP_MANAGEMENT). Roles above
 * the complex (cluster CEO / VPs) are intentionally omitted as they sit outside it.
 *
 * Idempotent: re-running upserts by code without duplicating. Run with:
 *   php artisan db:seed --class=Database\\Seeders\\EradahOrgStructureSeeder
 */
class EradahOrgStructureSeeder extends Seeder
{
    private int $organizationId;

    public function run(): void
    {
        $org = Organization::firstOrCreate(
            ['code' => 'ERADA-MH'],
            [
                'name' => 'مجمع إرادة والصحة النفسية بالرياض',
                'description' => 'مجمع متخصص في الصحة النفسية وعلاج وتأهيل الإدمان - التجمع الصحي الرياض الثالث',
                'is_active' => true,
                'settings' => [
                    'system' => [
                        'timezone' => 'Asia/Riyadh',
                        'default_language' => 'ar',
                    ],
                ],
            ]
        );
        $this->organizationId = $org->id;

        foreach ($this->structure() as $order => $node) {
            $this->insert($node, null, $order);
        }
    }

    /**
     * @param  array{name: string, code: string, level: int, children?: array}  $node
     */
    private function insert(array $node, ?int $parentId, int $order): void
    {
        // Self-check: a wrong level mapping here is the only real failure mode.
        if (! Department::isValidHierarchy($parentId, $node['level'])) {
            throw new RuntimeException(
                "Invalid hierarchy for {$node['code']} (level {$node['level']}) under parent #{$parentId}"
            );
        }

        $dept = Department::firstOrCreate(
            ['code' => $node['code']],
            [
                'name' => $node['name'],
                'level' => $node['level'],
                'parent_id' => $parentId,
                'organization_id' => $this->organizationId,
                'sort_order' => $order,
                'is_active' => true,
            ]
        );

        foreach ($node['children'] ?? [] as $childOrder => $child) {
            $this->insert($child, $dept->id, $childOrder);
        }
    }

    private function structure(): array
    {
        $D = Department::LEVEL_DEPARTMENT;
        $S = Department::LEVEL_SECTION;

        return [[
            'name' => 'المدير التنفيذي لمجمع إرادة والصحة النفسية',
            'code' => 'ERADA-CEO',
            'level' => Department::LEVEL_TOP_MANAGEMENT,
            'children' => [
                // First: departments reporting directly to the Executive Director.
                ['name' => 'إدارة المراجعة الداخلية', 'code' => 'INT-AUDIT', 'level' => $D],
                ['name' => 'إدارة الشؤون القانونية', 'code' => 'LEGAL', 'level' => $D],
                ['name' => 'إدارة المدراء المناوبين', 'code' => 'DUTY-MGR', 'level' => $D],
                ['name' => 'إدارة التخطيط والتحول ومكتب إدارة المشاريع', 'code' => 'PLAN-PMO', 'level' => $D],
                ['name' => 'مكتب المدير التنفيذي', 'code' => 'CEO-OFFICE', 'level' => $D],
                ['name' => 'إدارة الصحة الرقمية', 'code' => 'DIGITAL-HEALTH', 'level' => $D],
                ['name' => 'إدارة التواصل المؤسسي والتغيير', 'code' => 'COMM-CHANGE', 'level' => $D],
                ['name' => 'إدارة الشؤون الاكاديمية والتدريب والابحاث', 'code' => 'ACADEMIC', 'level' => $D],

                // Second: assistant executive directorates (each a sub-tree).
                ['name' => 'الإدارة التنفيذية المساعدة للخدمات الطبية', 'code' => 'AED-MED', 'level' => Department::LEVEL_EXECUTIVE, 'children' => [
                    ['name' => 'الصحة النفسية', 'code' => 'MED-MENTAL', 'level' => $D],
                    ['name' => 'خدمات علاج وتأهيل الادمان', 'code' => 'MED-ADDICT', 'level' => $D],
                    ['name' => 'الخدمات الطبية المساندة', 'code' => 'MED-SUPPORT', 'level' => $D],
                    ['name' => 'الصحة العامة ومكافحة العدوى', 'code' => 'MED-PUBLIC', 'level' => $D],
                    ['name' => 'شؤون المرضى', 'code' => 'MED-PATIENT', 'level' => $D],
                    // Per the chart these 6 sections attach directly to the directorate.
                    // ponytail: if review confirms they belong under شؤون المرضى (MED-PATIENT),
                    // move them to its children — the self-check keeps SECTION-under-DEPARTMENT valid.
                    ['name' => 'الطب النفسي الجنائي', 'code' => 'SEC-FORENSIC', 'level' => $S],
                    ['name' => 'الاسعاف والطوارئ', 'code' => 'SEC-EMRG', 'level' => $S],
                    ['name' => 'العيادات الخارجية', 'code' => 'SEC-OPD', 'level' => $S],
                    ['name' => 'الخدمة النفسية', 'code' => 'SEC-PSYCH', 'level' => $S],
                    ['name' => 'الخدمة الاجتماعية', 'code' => 'SEC-SOCIAL', 'level' => $S],
                    ['name' => 'التوعية الدينية والدعم الروحي', 'code' => 'SEC-SPIRIT', 'level' => $S],
                ]],
                ['name' => 'الإدارة التنفيذية المساعدة للرعاية المديدة والتأهيل', 'code' => 'AED-REHAB', 'level' => Department::LEVEL_EXECUTIVE, 'children' => [
                    ['name' => 'التأهيل الطبي', 'code' => 'REHAB-MED', 'level' => $D],
                    ['name' => 'مركز الاخاء', 'code' => 'REHAB-IKHA', 'level' => $D],
                    ['name' => 'التوعية الصحية والاستشارات', 'code' => 'REHAB-AWARE', 'level' => $D],
                    ['name' => 'الرعاية الصحية النفسية المنزلية', 'code' => 'REHAB-HOME', 'level' => $D],
                ]],
                ['name' => 'الإدارة التنفيذية المساعدة لخدمات التمريض', 'code' => 'AED-NURS', 'level' => Department::LEVEL_EXECUTIVE, 'children' => [
                    ['name' => 'الجودة التمريضية', 'code' => 'NURS-QA', 'level' => $D],
                    ['name' => 'التدريب التمريضي', 'code' => 'NURS-TRAIN', 'level' => $D],
                    ['name' => 'التمريض السريري', 'code' => 'NURS-CLIN', 'level' => $D],
                ]],
                ['name' => 'الإدارة التنفيذية المساعدة للجودة والأداء', 'code' => 'AED-QA', 'level' => Department::LEVEL_EXECUTIVE, 'children' => [
                    ['name' => 'الجودة وسلامة المرضى', 'code' => 'QA-SAFETY', 'level' => $D],
                    ['name' => 'المراجعة الاكلينيكية', 'code' => 'QA-CLINREV', 'level' => $D],
                    ['name' => 'تجربة المريض', 'code' => 'QA-PTEXP', 'level' => $D],
                    ['name' => 'الاداء', 'code' => 'QA-PERF', 'level' => $D],
                ]],
                ['name' => 'الإدارة التنفيذية المساعدة للتشغيل', 'code' => 'AED-OPS', 'level' => Department::LEVEL_EXECUTIVE, 'children' => [
                    ['name' => 'الشؤون الهندسية', 'code' => 'OPS-ENG', 'level' => $D],
                    ['name' => 'الامن والسلامة', 'code' => 'OPS-SEC', 'level' => $D],
                    ['name' => 'الخدمات العامة', 'code' => 'OPS-GEN', 'level' => $D],
                    ['name' => 'الصيانة الطبية', 'code' => 'OPS-MEDMAINT', 'level' => $D],
                    ['name' => 'الصيانة العامة', 'code' => 'OPS-GENMAINT', 'level' => $D],
                    ['name' => 'المشتريات', 'code' => 'OPS-PROC', 'level' => $D],
                    ['name' => 'المواد وسلاسل الامداد', 'code' => 'OPS-SUPPLY', 'level' => $D],
                    ['name' => 'التغذية', 'code' => 'OPS-NUTR', 'level' => $D],
                    ['name' => 'الازمات والكوارث', 'code' => 'OPS-CRISIS', 'level' => $D],
                ]],
                ['name' => 'الإدارة التنفيذية المساعدة للموارد المالية والبشرية', 'code' => 'AED-FINHR', 'level' => Department::LEVEL_EXECUTIVE, 'children' => [
                    ['name' => 'إدارة الموارد البشرية', 'code' => 'HR-DIR', 'level' => $D, 'children' => [
                        ['name' => 'مراقبة انتظام الدوام', 'code' => 'HR-ATTEND', 'level' => $S],
                        ['name' => 'الرواتب والاستحقاقات', 'code' => 'HR-PAYROLL', 'level' => $S],
                        ['name' => 'التوظيف', 'code' => 'HR-RECRUIT', 'level' => $S],
                        ['name' => 'المواهب', 'code' => 'HR-TALENT', 'level' => $S],
                        ['name' => 'عمليات الموارد البشرية', 'code' => 'HR-OPS', 'level' => $S],
                    ]],
                    ['name' => 'إدارة الشؤون المالية', 'code' => 'FIN-DIR', 'level' => $D, 'children' => [
                        ['name' => 'تنمية الايرادات', 'code' => 'FIN-REVENUE', 'level' => $S],
                        ['name' => 'مراقبة المخزون', 'code' => 'FIN-INVENTORY', 'level' => $S],
                    ]],
                ]],
            ],
        ]];
    }
}
