<?php

namespace Database\Seeders\Mock;

use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Models\KpiMeasurement;
use App\Modules\Strategy\Models\Blocker;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use Illuminate\Database\Seeder;

class StrategySeeder extends Seeder
{
    public array $portfolios = [];

    public array $programs = [];

    public function run(array $users, array $departments): void
    {
        $this->createPortfolios($users);
        $this->createPrograms($users, $departments);
    }

    private function createPortfolios(array $users): void
    {
        $portfoliosData = [
            [
                'name' => 'التحول الرقمي الشامل',
                'description' => 'محفظة مشاريع التحول الرقمي للمنظمة تشمل أتمتة العمليات وتطوير الأنظمة الإلكترونية',
                'rationale' => 'دعم رؤية المنظمة 2030 وتحسين الكفاءة التشغيلية',
                'directive_source' => 'cluster_3',
                'strategic_plan_link' => 'الخطة الاستراتيجية للتجمع 2024-2026',
                'status' => 'active',
                'portfolio_status' => 'active',
                'weight' => 30,
                'priority_rank' => 1,
            ],
            [
                'name' => 'تطوير رأس المال البشري',
                'description' => 'محفظة مشاريع تطوير الكفاءات والتدريب وتحسين بيئة العمل',
                'rationale' => 'الاستثمار في الموارد البشرية كأصل استراتيجي',
                'directive_source' => 'moh',
                'strategic_plan_link' => 'استراتيجية الموارد البشرية 2025',
                'status' => 'active',
                'portfolio_status' => 'active',
                'weight' => 25,
                'priority_rank' => 2,
            ],
            [
                'name' => 'التميز في الخدمات',
                'description' => 'محفظة مشاريع تحسين جودة الخدمات المقدمة وتجربة المستفيدين',
                'rationale' => 'رفع مستوى رضا المستفيدين وتحقيق معايير التميز',
                'directive_source' => 'holding',
                'strategic_plan_link' => 'خطة تحسين الجودة 2024',
                'status' => 'active',
                'portfolio_status' => 'active',
                'weight' => 25,
                'priority_rank' => 3,
            ],
            [
                'name' => 'البنية التحتية والتشغيل',
                'description' => 'محفظة مشاريع تطوير البنية التحتية التقنية والتشغيلية',
                'rationale' => 'ضمان استمرارية العمل وتحسين الأداء التشغيلي',
                'directive_source' => 'other',
                'directive_source_other' => 'اللجنة التوجيهية للتقنية',
                'status' => 'active',
                'portfolio_status' => 'rebalancing',
                'weight' => 20,
                'priority_rank' => 4,
            ],
        ];

        foreach ($portfoliosData as $index => $data) {
            $portfolio = Portfolio::create([
                ...$data,
                'organization_id' => $users[0]->organization_id,
                'start_date' => now()->subMonths(rand(1, 6)),
                'end_date' => now()->addMonths(rand(12, 24)),
                'portfolio_progress' => rand(15, 65),
                'order' => $index + 1,
                'created_by' => $users[0]->id,
            ]);

            $this->portfolios[] = $portfolio;
        }
    }

    private function createPrograms(array $users, array $departments): void
    {
        $programsData = [
            // برامج التحول الرقمي
            ['name' => 'برنامج أتمتة العمليات الإدارية', 'description' => 'أتمتة العمليات الإدارية الروتينية باستخدام تقنيات RPA والذكاء الاصطناعي', 'portfolio_index' => 0, 'budget' => 2500000, 'status' => 'in_progress', 'priority' => 'high', 'progress' => 45],
            ['name' => 'برنامج تطوير المنصات الرقمية', 'description' => 'تطوير وتحديث المنصات والتطبيقات الرقمية للمنظمة', 'portfolio_index' => 0, 'budget' => 3500000, 'status' => 'in_progress', 'priority' => 'critical', 'progress' => 60],
            ['name' => 'برنامج التحليلات والبيانات الضخمة', 'description' => 'بناء منظومة تحليل البيانات ودعم اتخاذ القرار', 'portfolio_index' => 0, 'budget' => 1800000, 'status' => 'planning', 'priority' => 'medium', 'progress' => 15],

            // برامج تطوير رأس المال البشري
            ['name' => 'برنامج التطوير القيادي', 'description' => 'تأهيل وتطوير القيادات الإدارية الحالية والمستقبلية', 'portfolio_index' => 1, 'budget' => 1200000, 'status' => 'in_progress', 'priority' => 'high', 'progress' => 35],
            ['name' => 'برنامج التحول في ثقافة العمل', 'description' => 'تطوير ثقافة العمل وبيئة العمل المحفزة', 'portfolio_index' => 1, 'budget' => 800000, 'status' => 'in_progress', 'priority' => 'medium', 'progress' => 50],

            // برامج التميز في الخدمات
            ['name' => 'برنامج تجربة المستفيد', 'description' => 'تحسين تجربة المستفيدين عبر جميع قنوات الخدمة', 'portfolio_index' => 2, 'budget' => 1500000, 'status' => 'in_progress', 'priority' => 'critical', 'progress' => 40],
            ['name' => 'برنامج معايير الجودة والتميز', 'description' => 'تطبيق معايير الجودة والتميز المؤسسي', 'portfolio_index' => 2, 'budget' => 600000, 'status' => 'planning', 'priority' => 'high', 'progress' => 20],

            // برامج البنية التحتية
            ['name' => 'برنامج السحابة والبنية التحتية', 'description' => 'ترقية البنية التحتية السحابية وتحسين الأمان', 'portfolio_index' => 3, 'budget' => 4000000, 'status' => 'in_progress', 'priority' => 'high', 'progress' => 55],
        ];

        foreach ($programsData as $index => $data) {
            $dept = $departments[array_rand($departments)];
            $program = Program::create([
                'name' => $data['name'],
                'description' => $data['description'],
                'portfolio_id' => $this->portfolios[$data['portfolio_index']]->id,
                'department_id' => $dept->id,
                'organization_id' => $dept->organization_id,
                'budget' => $data['budget'],
                'spent_amount' => $data['budget'] * ($data['progress'] / 100) * rand(60, 90) / 100,
                'total_program_budget' => $data['budget'] * 1.1,
                'start_date' => now()->subMonths(rand(1, 8)),
                'end_date' => now()->addMonths(rand(6, 18)),
                'progress' => $data['progress'],
                'weight' => rand(15, 40),
                'status' => $data['status'],
                'priority' => $data['priority'],
                'progress_calculation_method' => ['weighted', 'average', 'manual'][rand(0, 2)],
                'created_by' => $users[0]->id,
                'order' => $index + 1,
            ]);

            $this->programs[] = $program;

            $this->createProgramKpis($program, $users);

            if (rand(0, 1)) {
                $this->createBlocker($program, $users);
            }
        }
    }

    private function createProgramKpis(Program $program, array $users): void
    {
        $kpiTemplates = [
            ['name' => 'نسبة إنجاز المشاريع', 'unit' => '%', 'trend' => 'up_good'],
            ['name' => 'مؤشر رضا المستفيدين', 'unit' => '%', 'trend' => 'up_good'],
            ['name' => 'معدل الالتزام بالميزانية', 'unit' => '%', 'trend' => 'stable'],
            ['name' => 'عدد العمليات المؤتمتة', 'unit' => 'عملية', 'trend' => 'up_good'],
            ['name' => 'وقت الاستجابة للطلبات', 'unit' => 'يوم', 'trend' => 'down_good'],
        ];

        $count = rand(2, 3);
        shuffle($kpiTemplates);

        $trendMap = ['up_good' => 'increase', 'down_good' => 'decrease', 'stable' => 'maintain'];

        for ($i = 0; $i < $count; $i++) {
            $template = $kpiTemplates[$i];
            $target = rand(70, 100);
            $current = rand(40, 95);
            $ownerId = $users[rand(2, count($users) - 1)]->id;

            $kpi = (new Kpi)->forceFill([
                'organization_id' => $program->organization_id,
                'name' => $template['name'],
                'description' => $template['name'].' لـ '.$program->name,
                'measurement_method' => 'قياس دوري من خلال النظام',
                'category' => 'strategic',
                'baseline' => rand(20, 40),
                'target' => $target,
                'current_value' => $current,
                'unit' => $template['unit'],
                'frequency' => ['monthly', 'quarterly'][rand(0, 1)],
                'direction' => $trendMap[$template['trend']] ?? 'increase',
                'status' => 'active',
                'owner_id' => $ownerId,
                'created_by' => $ownerId,
                'order' => $i + 1,
            ]);
            $kpi->save();

            (new KpiLink)->forceFill([
                'organization_id' => $program->organization_id,
                'kpi_id' => $kpi->id,
                'linkable_type' => Program::class,
                'linkable_id' => $program->id,
                'relationship_type' => 'primary',
                'weight' => 1,
                'created_by' => $ownerId,
            ])->save();

            $this->createKpiMeasurements($kpi, $program, $users);
        }
    }

    private function createKpiMeasurements(Kpi $kpi, Program $program, array $users): void
    {
        $months = rand(3, 6);
        $baseValue = $kpi->baseline ?? 30;

        for ($i = $months; $i >= 0; $i--) {
            $progress = ($months - $i) / $months;
            $value = $baseValue + ($kpi->current_value - $baseValue) * $progress * (0.8 + rand(0, 40) / 100);

            (new KpiMeasurement)->forceFill([
                'organization_id' => $program->organization_id,
                'kpi_id' => $kpi->id,
                'value' => round($value, 2),
                'measurement_date' => now()->subMonths($i),
                'notes' => $i === 0 ? 'آخر قياس' : null,
                'recorded_by' => $users[rand(0, count($users) - 1)]->id,
            ])->save();
        }
    }

    private function createBlocker($model, array $users): void
    {
        $blockerTemplates = [
            ['title' => 'تأخر في اعتماد الميزانية', 'severity' => 'high'],
            ['title' => 'نقص في الموارد البشرية المتخصصة', 'severity' => 'medium'],
            ['title' => 'تأخر المورد الخارجي', 'severity' => 'high'],
            ['title' => 'تغيير في نطاق العمل', 'severity' => 'medium'],
            ['title' => 'مشكلة في التكامل مع الأنظمة القائمة', 'severity' => 'critical'],
        ];

        $template = $blockerTemplates[array_rand($blockerTemplates)];
        $statuses = ['open', 'in_progress', 'resolved', 'escalated'];
        $status = $statuses[array_rand($statuses)];

        Blocker::create([
            'title' => $template['title'],
            'description' => 'تفاصيل المعوق: '.$template['title']."\n\nالتأثير: تأثير على الجدول الزمني والميزانية\n\nخطة المعالجة: خطة المعالجة المقترحة",
            'blockable_type' => get_class($model),
            'blockable_id' => $model->id,
            'severity' => $template['severity'],
            'status' => $status,
            'identified_date' => now()->subDays(rand(5, 30)),
            'expected_resolution_date' => now()->addDays(rand(5, 20)),
            'resolved_date' => $status === 'resolved' ? now()->subDays(rand(1, 4)) : null,
            'resolution' => $status === 'resolved' ? 'تم حل المعوق بنجاح' : null,
            'reported_by' => $users[rand(2, count($users) - 1)]->id,
            'assigned_to' => $users[rand(0, 4)]->id,
        ]);
    }
}
