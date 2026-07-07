<?php

namespace Database\Seeders\Mock;

use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Enums\RiskActionType;
use App\Modules\RiskManagement\Enums\RiskResponseType;
use App\Modules\RiskManagement\Enums\RiskStatus;
use App\Modules\RiskManagement\Enums\RiskType;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\RiskManagement\Models\RiskAssessment;
use Illuminate\Database\Seeder;

class RiskManagementSeeder extends Seeder
{
    /** @var Risk[] */
    public array $risks = [];

    public function run(array $users, array $departments): void
    {
        $orgId = $departments[0]->organization_id ?? $users[0]->organization_id ?? null;

        $templates = [
            ['title' => 'تأخر تسليم مشروع التحول الرقمي بسبب نقص الكوادر', 'type' => RiskType::Operational, 'desc' => 'نقص في عدد المطورين المتاحين لتنفيذ المرحلة الثالثة من المشروع.'],
            ['title' => 'تسرب بيانات شخصية لعملاء عبر واجهة قديمة', 'type' => RiskType::Compliance, 'desc' => 'اكتشاف endpoint قديم يعيد PII دون تشفير كافٍ.'],
            ['title' => 'هجوم تصيد احتيالي على البريد الإلكتروني للموظفين', 'type' => RiskType::Technical, 'desc' => 'استلام رسائل مشبوهة من مصادر خارجية تحاكي هوية الإدارة العليا.'],
            ['title' => 'تجاوز الميزانية المخصصة لبرنامج البنية التحتية', 'type' => RiskType::Financial, 'desc' => 'ارتفاع تكاليف التشغيل والاشتراكات السحابية أعلى من التقدير الأولي.'],
            ['title' => 'تأخر اعتماد خطة استمرارية الأعمال', 'type' => RiskType::Reputational, 'desc' => 'عدم جاهزية خطة طوارئ المؤسسة مما يهدد سمعة الجهة في حال أي عطل.'],
            ['title' => 'خطأ إكلينيكي في تحويل بيانات تقرير إلى نظام القرار', 'type' => RiskType::Clinical, 'desc' => 'عدم تطابق الحقول الحرجة عند الترحيل بين نظامين.'],
            ['title' => 'تعطل مقدم خدمة سحابية رئيسي', 'type' => RiskType::Technical, 'desc' => 'احتمال عدم توفر خدمة الاستضافة لمدة تتجاوز SLA.'],
            ['title' => 'تذبذب سعر صرف يؤثر على العقود الدولية', 'type' => RiskType::Financial, 'desc' => 'تقلب سعر الصرف يهدد هوامش ربح العقود طويلة الأجل.'],
            ['title' => 'إهمال صيانة دورية للبنية التحتية لقواعد البيانات', 'type' => RiskType::Operational, 'desc' => 'احتمال توقف الخدمة بسبب إهمال التحديثات الأمنية.'],
            ['title' => 'الإبلاغ عن حالة سلبية في وسائل التواصل الاجتماعي', 'type' => RiskType::Reputational, 'desc' => 'احتمال نشر شكاوى موثقة بطريقة قد تضر بسمعة الجهة.'],
            ['title' => 'فقدان مورد استراتيجي وحيد لمكونات تقنية', 'type' => RiskType::Operational, 'desc' => 'الاعتماد على مورد واحد فقط لقطع غيار حساسة.'],
            ['title' => 'مخالفة ضريبية ناتجة عن قراءة آلية لقواعد الإقرارات', 'type' => RiskType::Compliance, 'desc' => 'احتمال فرض غرامات بسبب تأخر في تقديم إقرار غير دقيق.'],
        ];

        $responseTypes = [RiskResponseType::Mitigate, RiskResponseType::Avoid, RiskResponseType::Transfer, RiskResponseType::Accept];
        $statuses = [RiskStatus::Open, RiskStatus::Treating, RiskStatus::Accepted, RiskStatus::Closed];

        foreach ($templates as $index => $tpl) {
            $dept = $departments[array_rand($departments)];
            $owner = $users[array_rand($users)];
            $creator = $users[array_rand($users)];
            $likelihood = rand(2, 5);
            $impact = rand(2, 5);
            $currentScore = $likelihood * $impact;

            $risk = Risk::create([
                'organization_id' => $orgId,
                'title' => $tpl['title'],
                'description' => $tpl['desc'],
                'consequences' => 'تأخير تسليم المشروع، تجاوز الميزانية، فقدان ثقة stakeholders.',
                'type' => $tpl['type']->value,
                'department_id' => $dept->id,
                'owner_id' => $owner->id,
                'created_by' => $creator->id,
                'discovery_date' => now()->subDays(rand(7, 120)),
                'initial_likelihood' => $likelihood,
                'initial_impact' => $impact,
                'current_likelihood' => $likelihood,
                'current_impact' => $impact,
                'current_score' => $currentScore,
                'current_level' => self::levelFor($currentScore),
                'status' => $statuses[array_rand($statuses)]->value,
                'response_type' => $responseTypes[array_rand($responseTypes)]->value,
                'preventive_measures' => 'تعيين فريق احتياطي، تطبيق سياسات رقابة الوصول، تحديث ضوابط الأمن السيبراني، توثيق الإجراءات والمعايير.',
                'target_close_date' => now()->addDays(rand(30, 180)),
                'stakeholder_ids' => array_slice(array_map(fn ($u) => $u->id, array_slice($users, 0, 5)), 0, 3),
            ]);

            $this->risks[] = $risk;

            $this->createAssessments($risk, $users);
            $this->createActions($risk, $users);
        }
    }

    private function createAssessments(Risk $risk, array $users): void
    {
        $count = rand(1, 3);
        $baseLikelihood = $risk->current_likelihood;
        $baseImpact = $risk->current_impact;

        for ($i = 0; $i < $count; $i++) {
            $residualLikelihood = max(1, $baseLikelihood - rand(0, 1));
            $residualImpact = max(1, $baseImpact - rand(0, 1));
            $residualScore = $residualLikelihood * $residualImpact;

            RiskAssessment::create([
                'risk_id' => $risk->id,
                'organization_id' => $risk->organization_id,
                'likelihood' => $baseLikelihood,
                'impact' => $baseImpact,
                'score' => $risk->current_score,
                'level' => $risk->current_level,
                'residual_likelihood' => $residualLikelihood,
                'residual_impact' => $residualImpact,
                'residual_score' => $residualScore,
                'residual_level' => self::levelFor($residualScore),
                'assessor_id' => $users[array_rand($users)]->id,
                'notes' => 'مراجعة دورية بعد تطبيق خطة المعالجة.',
                'next_review_at' => now()->addDays(rand(30, 90)),
            ]);
        }
    }

    private function createActions(Risk $risk, array $users): void
    {
        $samples = [
            ['title' => 'تعيين فريق احتياطي من قسم تقنية المعلومات', 'type' => RiskActionType::Preventive],
            ['title' => 'إغلاق الـ endpoint القديم والتحقق من سجل الوصول', 'type' => RiskActionType::Corrective],
            ['title' => 'إطلاق حملة توعية بالتصيد الاحتيالي', 'type' => RiskActionType::Preventive],
            ['title' => 'مراجعة العقود السحابية وتحديث هوامش الميزانية', 'type' => RiskActionType::Corrective],
            ['title' => 'اعتماد خطة استمرارية الأعمال من الإدارة العليا', 'type' => RiskActionType::Preventive],
            ['title' => 'تطبيق تحديثات أمنية لقواعد البيانات', 'type' => RiskActionType::Corrective],
            ['title' => 'تدريب الموظفين على سياسة استخدام البيانات', 'type' => RiskActionType::Preventive],
        ];

        $count = rand(1, 3);
        $picks = [];
        for ($i = 0; $i < $count; $i++) {
            $sample = $samples[array_rand($samples)];
            if (in_array($sample['title'], $picks, true)) {
                continue;
            }
            $picks[] = $sample['title'];

            $status = [
                RiskActionStatus::Pending,
                RiskActionStatus::InProgress,
                RiskActionStatus::Completed,
                RiskActionStatus::Blocked,
            ][array_rand([0, 1, 2, 3])];

            RiskAction::create([
                'risk_id' => $risk->id,
                'organization_id' => $risk->organization_id,
                'title' => $sample['title'],
                'type' => $sample['type']->value,
                'description' => 'تنفيذ '.str_replace('تعيين', 'تعيين', $sample['title']),
                'owner_id' => $users[array_rand($users)]->id,
                'status' => $status->value,
                'progress_pct' => $status === RiskActionStatus::Completed ? 100 : rand(10, 80),
                'due_date' => now()->addDays(rand(7, 60)),
                'notes' => 'تم بدء العمل مع الجهة المختصة.',
            ]);
        }
    }

    /**
     * Map score to risk level string. Matches the migration CHECK constraint values
     * (`low`, `medium`, `high`, `critical`).
     */
    private static function levelFor(int $score): string
    {
        return match (true) {
            $score >= 20 => 'critical',
            $score >= 12 => 'high',
            $score >= 6 => 'medium',
            default => 'low',
        };
    }
}
