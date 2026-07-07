<?php

namespace Database\Seeders\Mock;

use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingAgendaItem;
use App\Modules\Meetings\Models\MeetingAttendee;
use App\Modules\Meetings\Models\MeetingCategory;
use App\Modules\Meetings\Models\Recommendation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class MeetingsSeeder extends Seeder
{
    /** @var Meeting[] */
    public array $meetings = [];

    /** @var MeetingCategory[] */
    public array $categories = [];

    public function run(array $users, array $departments): void
    {
        $orgId = $departments[0]->organization_id ?? $users[0]->organization_id ?? null;
        $this->ensureCategories($orgId);

        $meetingTemplates = [
            ['title' => 'اجتماع لجنة مراجعة المشاريع', 'category_index' => 0, 'agenda' => ['استعراض حالة المشاريع', 'مراجعة الميزانية', 'الجدول الزمني', 'المخاطر والمعوقات']],
            ['title' => 'اجتماع قيادة التحول الرقمي', 'category_index' => 1, 'agenda' => ['تقرير التقدم الأسبوعي', 'العوائق', 'قرارات تخصيص الموارد']],
            ['title' => 'اجتماع مراجعة استراتيجية الموارد البشرية', 'category_index' => 2, 'agenda' => ['مراجعة مؤشرات الأداء', 'مبادرات التدريب', 'خطة التوظيف']],
            ['title' => 'اجتماع لجنة المخاطر المؤسسية', 'category_index' => 3, 'agenda' => ['تقرير المخاطر المفتوحة', 'مراجعة خطط المعالجة']],
            ['title' => 'اجتماع تحسين تجربة المستفيدين', 'category_index' => 4, 'agenda' => ['نتائج استطلاعات الرضا', 'الشكاوى المتكررة', 'مبادرات التحسين']],
            ['title' => 'اجتماع مراجعة حوكمة البيانات', 'category_index' => 5, 'agenda' => ['حوكمة وحماية البيانات الشخصية', 'تدقيق الصلاحيات', 'تحديث سياسات البيانات']],
            ['title' => 'اجتماع المتابعة الأسبوعي للفريق التقني', 'category_index' => 1, 'agenda' => ['حالة المهام', 'مراجعة السجلات', 'الأعطال المفتوحة']],
            ['title' => 'اجتماع مراجعة العقود والموردين', 'category_index' => 0, 'agenda' => ['العقود المنتهية', 'تجديد العقود', 'تقييم الموردين']],
            ['title' => 'اجتماع لجنة الجودة', 'category_index' => 4, 'agenda' => ['مؤشرات الجودة', 'مراجعة التدقيق', 'الإجراءات التصحيحية']],
            ['title' => 'اجتماع تخطيط الموازنة السنوية', 'category_index' => 2, 'agenda' => ['مراجعة الميزانية الحالية', 'توقعات العام القادم']],
            ['title' => 'اجتماع مراجعة الحوادث الحرجة', 'category_index' => 3, 'agenda' => ['تقرير حوادث الشهر', 'الإجراءات التصحيحية']],
            ['title' => 'اجتماع تخطيط مبادرة التميز المؤسسي', 'category_index' => 4, 'agenda' => ['تقييم الفجوات', 'خطة التقديم']],
        ];

        $statuses = [Meeting::STATUS_SCHEDULED, Meeting::STATUS_IN_PROGRESS, Meeting::STATUS_COMPLETED, Meeting::STATUS_CANCELLED];

        foreach ($meetingTemplates as $mIndex => $template) {
            $organizer = $users[array_rand($users)];
            $dept = $departments[array_rand($departments)];
            $status = $statuses[array_rand($statuses)];

            $meeting = Meeting::create([
                'title' => $template['title'],
                'description' => 'وصف '.str_replace('اجتماع', 'الاجتماع', $template['title']).' ومناقشة بنود جدول الأعمال المحددة.',
                'scheduled_at' => Carbon::now()->subDays(rand(-5, 60))->setTime(rand(8, 16), 0),
                'duration_minutes' => [30, 45, 60, 90, 120][array_rand([30, 45, 60, 90, 120])],
                'location' => 'القاعة '.chr(65 + array_rand([0, 1, 2, 3])).' - '.rand(100, 999).' أو افتراضياً عبر Teams',
                'virtual_link' => 'https://teams.microsoft.com/l/meetup-join/'.bin2hex(random_bytes(4)),
                'agenda' => "خطة {$template['title']}:\n- ".implode("\n- ", $template['agenda']),
                'status' => $status,
                'organizer_id' => $organizer->id,
                'category_id' => $this->categories[$template['category_index'] % count($this->categories)]->id,
                'organization_id' => $orgId,
                'department_id' => $dept->id,
            ]);

            $this->meetings[] = $meeting;

            $this->createAgendaItems($meeting, $template['agenda'], $users, $orgId);
            $this->createAttendees($meeting, $users, $dept);
            $this->maybeCreateActionItems($meeting, $users);
        }
    }

    private function ensureCategories(?int $orgId): void
    {
        $seeds = [
            ['name' => 'لجنة المشاريع', 'sort_order' => 1],
            ['name' => 'قيادة التحول الرقمي', 'sort_order' => 2],
            ['name' => 'الموارد البشرية', 'sort_order' => 3],
            ['name' => 'لجنة المخاطر', 'sort_order' => 4],
            ['name' => 'تجربة المستفيدين', 'sort_order' => 5],
            ['name' => 'حوكمة البيانات', 'sort_order' => 6],
        ];

        foreach ($seeds as $seed) {
            $this->categories[] = MeetingCategory::firstOrCreate(
                ['organization_id' => $orgId, 'name' => $seed['name']],
                ['is_active' => true, 'sort_order' => $seed['sort_order']]
            );
        }
    }

    private function createAgendaItems(Meeting $meeting, array $titles, array $users, ?int $orgId): void
    {
        $positions = range(1, count($titles));
        foreach ($titles as $idx => $title) {
            MeetingAgendaItem::create([
                'meeting_id' => $meeting->id,
                'title' => $title,
                'description' => 'نقاش بشأن: '.$title,
                'proposed_by_id' => $users[array_rand($users)]->id,
                'status' => [MeetingAgendaItem::STATUS_PENDING, MeetingAgendaItem::STATUS_APPROVED, MeetingAgendaItem::STATUS_REJECTED][array_rand([0, 1, 2])],
                'position' => $positions[$idx],
                'organization_id' => $orgId,
            ]);
        }
    }

    private function createAttendees(Meeting $meeting, array $users, $dept): void
    {
        $roles = ['organizer', 'presenter', 'attendee', 'minute_taker'];
        $picks = [];
        for ($i = 0; $i < rand(3, 6); $i++) {
            $user = $users[array_rand($users)];
            if (in_array($user->id, $picks, true)) {
                continue;
            }
            $picks[] = $user->id;

            MeetingAttendee::create([
                'meeting_id' => $meeting->id,
                'user_id' => $user->id,
                'role' => $roles[array_rand($roles)],
                'attended' => $meeting->status === Meeting::STATUS_COMPLETED ? (bool) rand(0, 1) : false,
            ]);
        }
    }

    private function maybeCreateActionItems(Meeting $meeting, array $users): void
    {
        $actionTitles = [
            'مراجعة تقرير المخاطر الشهري',
            'تحديث وثيقة نطاق المشروع',
            'إعداد عرض لتخصيص الميزانية',
            'جدولة ورشة تدريبية للفريق',
            'تنسيق مع المورد الخارجي',
            'تحديث سياسة حوكمة البيانات',
            'إغلاق التذاكر الحرجة',
            'مراجعة طلبات الشراء المعلقة',
        ];

        $count = rand(1, 3);
        for ($i = 0; $i < $count; $i++) {
            $status = [
                Recommendation::STATUS_PROPOSED,
                Recommendation::STATUS_ACCEPTED,
                Recommendation::STATUS_COMPLETED,
                Recommendation::STATUS_DEFERRED,
            ][array_rand([0, 1, 2, 3])];

            Recommendation::create([
                'kind' => Recommendation::KIND_ACTION_ITEM,
                'title' => $actionTitles[array_rand($actionTitles)],
                'description' => 'إجراء مستخرج من '.str_replace('اجتماع', 'الاجتماع', $meeting->title),
                'meeting_id' => $meeting->id,
                'priority' => [
                    Recommendation::PRIORITY_LOW,
                    Recommendation::PRIORITY_MEDIUM,
                    Recommendation::PRIORITY_HIGH,
                ][array_rand([0, 1, 2])],
                'status' => $status,
                'assignee_id' => $users[array_rand($users)]->id,
                'requested_by' => $meeting->organizer_id,
                'due_date' => now()->addDays(rand(7, 30)),
                'completed_at' => $status === Recommendation::STATUS_COMPLETED ? now()->subDays(rand(1, 5)) : null,
                'organization_id' => $meeting->organization_id,
            ]);
        }
    }
}
