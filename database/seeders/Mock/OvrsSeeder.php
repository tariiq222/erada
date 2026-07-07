<?php

namespace Database\Seeders\Mock;

use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentParticipant;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Models\ReportComment;
use App\Modules\OVR\Models\StatusHistory;
use Illuminate\Database\Seeder;

class OvrsSeeder extends Seeder
{
    /** @var IncidentReport[] */
    public array $reports = [];

    public function run(array $users, array $departments): void
    {
        $types = $this->ensureTypes($users, $departments);
        $reportsData = $this->buildReportData($users, $departments, $types);

        foreach ($reportsData as $i => $data) {
            $report = IncidentReport::create($data);
            $this->reports[] = $report;

            $this->createParticipants($report, $users);
            $this->createReportComments($report, $users);
            $this->createStatusHistory($report, $users);
        }
    }

    private function ensureTypes(array $users, array $departments): array
    {
        $orgId = $departments[0]->organization_id ?? $users[0]->organization_id ?? null;

        $seeds = [
            ['name' => 'operational_incident', 'name_ar' => 'حادث تشغيلي', 'requires_reportable_type' => false],
            ['name' => 'patient_safety', 'name_ar' => 'سلامة المرضى', 'requires_reportable_type' => true],
            ['name' => 'information_security', 'name_ar' => 'أمن المعلومات', 'requires_reportable_type' => false],
            ['name' => 'workplace_safety', 'name_ar' => 'السلامة في بيئة العمل', 'requires_reportable_type' => false],
            ['name' => 'service_disruption', 'name_ar' => 'تعطل الخدمة', 'requires_reportable_type' => false],
            ['name' => 'compliance_violation', 'name_ar' => 'مخالفة امتثال', 'requires_reportable_type' => true],
            ['name' => 'financial_anomaly', 'name_ar' => 'شذوذ مالي', 'requires_reportable_type' => false],
            ['name' => 'data_breach', 'name_ar' => 'تسرب بيانات', 'requires_reportable_type' => true],
        ];

        $types = [];
        foreach ($seeds as $seed) {
            $types[] = IncidentType::firstOrCreate(
                ['organization_id' => $orgId, 'name' => $seed['name']],
                ['name_ar' => $seed['name_ar'], 'is_active' => true, 'requires_reportable_type' => $seed['requires_reportable_type']]
            );
        }

        return $types;
    }

    private function buildReportData(array $users, array $departments, array $types): array
    {
        $rows = [];
        $samples = [
            ['desc' => 'انقطاع في الوصول إلى نظام إدارة الوثائق لمدة 30 دقيقة بسبب فشل في البنية التحتية', 'type' => 'service_disruption', 'sev' => SeverityLevel::Medium],
            ['desc' => 'تسجيل دخول غير مصرح به من جهاز غير معروف على حساب أحد الموظفين', 'type' => 'information_security', 'sev' => SeverityLevel::High],
            ['desc' => 'تأخر في تسليم شحنة من المورد الخارجي للمستشفى بقيمة 350 ألف ريال', 'type' => 'operational_incident', 'sev' => SeverityLevel::Medium],
            ['desc' => 'تسرب مياه في المبنى الإداري يؤثر على معدات الخادم', 'type' => 'workplace_safety', 'sev' => SeverityLevel::High],
            ['desc' => 'عُثر على تقرير يحوي بيانات شخصية لعملاء في مجلد مشترك بدون صلاحية', 'type' => 'data_breach', 'sev' => SeverityLevel::Critical],
            ['desc' => 'تأخر الموظف عن توقيع محضر تسليم للمواد لأكثر من 5 أيام عمل', 'type' => 'compliance_violation', 'sev' => SeverityLevel::Low],
            ['desc' => 'خطأ في قيد محاسبي نتج عنه عكس قيد يدوي بقيمة 80 ألف ريال', 'type' => 'financial_anomaly', 'sev' => SeverityLevel::Medium],
            ['desc' => 'خطأ في صرف دواء بسبب قراءة غير صحيحة للمريض في النظام', 'type' => 'patient_safety', 'sev' => SeverityLevel::Critical],
            ['desc' => 'فقدان جهاز حاسوب محمول يحتوي على بيانات موظفين', 'type' => 'data_breach', 'sev' => SeverityLevel::High],
            ['desc' => 'خلل في تكامل النظام المحاسبي مع نظام الرواتب', 'type' => 'service_disruption', 'sev' => SeverityLevel::Medium],
            ['desc' => 'وصول مورد جديد إلى قبو المستودع دون إذن مسبق', 'type' => 'workplace_safety', 'sev' => SeverityLevel::Low],
            ['desc' => 'تعطل نظام إدارة المواعيد مما أثر على 200 موعد', 'type' => 'service_disruption', 'sev' => SeverityLevel::High],
        ];

        $statuses = [ReportStatus::Draft, ReportStatus::New, ReportStatus::UnderReview, ReportStatus::InProgress, ReportStatus::Resolved, ReportStatus::Closed];

        foreach ($samples as $i => $sample) {
            $reporter = $users[array_rand($users)];
            $dept = $departments[array_rand($departments)];
            $type = collect($types)->firstWhere('name', $sample['type']);
            $status = $statuses[array_rand($statuses)];
            $isConfidential = $sample['sev'] === SeverityLevel::Critical && rand(0, 1) === 1;
            $incidentAt = now()->subDays(rand(1, 60))->subHours(rand(0, 23));

            $rows[] = [
                'organization_id' => $dept->organization_id ?? $reporter->organization_id,
                'reporter_id' => $reporter->id,
                'reporter_name' => $reporter->name,
                'reporter_email' => $reporter->email,
                'reporter_job_title' => 'موظف إداري',
                'reporter_department_id' => $dept->id,
                'incident_datetime' => $incidentAt,
                'incident_type_id' => $type->id,
                'incident_description' => $sample['desc'],
                'actions_taken' => 'تم عزل الحساب والتواصل مع الفريق التقني لاتخاذ الإجراء اللازم.',
                'severity_level' => $sample['sev']->value,
                'status' => $status->value,
                'assigned_to' => $status === ReportStatus::Draft ? null : $users[array_rand($users)]->id,
                'assigned_at' => $status === ReportStatus::Draft ? null : $incidentAt->copy()->addHours(rand(1, 6)),
                'due_date' => $incidentAt->copy()->addHours($sample['sev']->slaHours()),
                'resolved_at' => in_array($status, [ReportStatus::Resolved, ReportStatus::Closed], true) ? $incidentAt->copy()->addDays(rand(2, 10)) : null,
                'closed_at' => $status === ReportStatus::Closed ? $incidentAt->copy()->addDays(rand(7, 20)) : null,
                'is_confidential' => $isConfidential,
                'contributing_factors' => ['تدريب غير كافٍ', 'غياب توثيق محدّث', 'ضغط عمل مؤقت'],
                'immediate_action_required' => $sample['sev'] === SeverityLevel::Critical,
            ];
        }

        return $rows;
    }

    private function createParticipants(IncidentReport $report, array $users): void
    {
        $count = rand(1, 3);
        $used = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $users[array_rand($users)];
            if (in_array($user->id, $used, true) || $user->id === $report->reporter_id) {
                continue;
            }
            $used[] = $user->id;

            IncidentParticipant::create([
                'incident_report_id' => $report->id,
                'user_id' => $user->id,
                'invited_by' => $report->reporter_id,
            ]);
        }
    }

    private function createReportComments(IncidentReport $report, array $users): void
    {
        $samples = [
            'تم التحقق من الحادث وأُبلغ الفريق المختص.',
            'يُرجى مراجعة سجل الدخول للمستخدم المعني في الفترة الزمنية المحددة.',
            'الإجراء التصحيحي أُنجز بنجاح، تم تحديث سياسة الوصول.',
            'أقترح إجراء تحقيق داخلي موسّع ومراجعة الصلاحيات.',
            'تم تأكيد تسلسل الأدلة من قبل فريق أمن المعلومات.',
        ];

        $count = rand(2, 4);
        for ($i = 0; $i < $count; $i++) {
            $author = $users[array_rand($users)];
            ReportComment::create([
                'report_id' => $report->id,
                'user_id' => $author->id,
                'author_name' => $author->name,
                'text' => $samples[array_rand($samples)],
                'is_internal' => (bool) rand(0, 1),
            ]);
        }
    }

    private function createStatusHistory(IncidentReport $report, array $users): void
    {
        $transitions = [];
        if ($report->status === ReportStatus::Draft->value) {
            $transitions = [['to' => ReportStatus::Draft->value, 'reason' => 'تم إنشاء المسودة']];
        } elseif (in_array($report->status, [ReportStatus::Resolved->value, ReportStatus::Closed->value], true)) {
            $transitions = [
                ['from' => ReportStatus::Draft->value, 'to' => ReportStatus::New->value, 'reason' => 'إرسال البلاغ للإدارة'],
                ['from' => ReportStatus::New->value, 'to' => ReportStatus::UnderReview->value, 'reason' => 'بدء المراجعة الأولية'],
                ['from' => ReportStatus::UnderReview->value, 'to' => ReportStatus::InProgress->value, 'reason' => 'إحالة للفريق المختص'],
                ['from' => ReportStatus::InProgress->value, 'to' => ReportStatus::Resolved->value, 'reason' => 'تم حل البلاغ'],
            ];
            if ($report->status === ReportStatus::Closed->value) {
                $transitions[] = ['from' => ReportStatus::Resolved->value, 'to' => ReportStatus::Closed->value, 'reason' => 'إغلاق بعد التحقق النهائي'];
            }
        } else {
            $transitions = [
                ['from' => ReportStatus::Draft->value, 'to' => ReportStatus::New->value, 'reason' => 'إرسال للإدارة'],
                ['from' => ReportStatus::New->value, 'to' => $report->status, 'reason' => 'انتقال ضمن دورة المعالجة'],
            ];
        }

        foreach ($transitions as $i => $transition) {
            StatusHistory::create([
                'report_id' => $report->id,
                'from_status' => $transition['from'] ?? null,
                'to_status' => $transition['to'],
                'changed_by' => $users[array_rand($users)]->id,
                'reason' => $transition['reason'],
                'created_at' => $report->incident_datetime->copy()->addHours($i * rand(2, 8)),
            ]);
        }
    }
}
