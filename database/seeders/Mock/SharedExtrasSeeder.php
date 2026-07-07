<?php

namespace Database\Seeders\Mock;

use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use Illuminate\Database\Seeder;

class SharedExtrasSeeder extends Seeder
{
    /** @var Attachment[] */
    public array $attachments = [];

    /** @var ActivityLog[] */
    public array $activityLogs = [];

    public function run(array $users, array $projects, array $meetings, array $risks, array $ovrs): void
    {
        $this->createAttachments($users, $projects, $meetings, $risks);
        $this->createActivityLogs($users, $projects, $meetings, $risks, $ovrs);
        $this->createExtraComments($users, $projects, $meetings, $risks, $ovrs);
    }

    private function createAttachments(array $users, array $projects, array $meetings, array $risks): void
    {
        $files = [
            ['name' => 'تقرير_التقدم_الشهري.pdf', 'type' => 'application/pdf', 'size' => rand(800_000, 4_000_000)],
            ['name' => 'محضر_الاجتماع.docx', 'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => rand(120_000, 800_000)],
            ['name' => 'خطة_المشروع.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size' => rand(200_000, 1_500_000)],
            ['name' => 'مخطط_الجدول_الزمني.png', 'type' => 'image/png', 'size' => rand(300_000, 2_000_000)],
            ['name' => 'دليل_المستخدم.pdf', 'type' => 'application/pdf', 'size' => rand(2_000_000, 8_000_000)],
            ['name' => 'متطلبات_الأمان.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size' => rand(150_000, 600_000)],
            ['name' => 'محضر_تسليم.zip', 'type' => 'application/zip', 'size' => rand(3_000_000, 12_000_000)],
            ['name' => 'صورة_الواجهة.fig', 'type' => 'application/octet-stream', 'size' => rand(1_500_000, 5_000_000)],
        ];

        $targets = [];
        foreach (array_slice($projects, 0, 5) as $project) {
            $targets[] = [Project::class, $project->id, 'projects'];
        }
        foreach (array_slice($meetings, 0, 3) as $meeting) {
            $targets[] = [Meeting::class, $meeting->id, 'meetings'];
        }
        foreach (array_slice($risks, 0, 4) as $risk) {
            $targets[] = [Risk::class, $risk->id, 'risks'];
        }

        $count = 0;
        $targetCount = count($targets);
        for ($i = 0; $i < 12 && $i < count($files) * $targetCount; $i++) {
            $target = $targets[$i % $targetCount];
            $file = $files[$i % count($files)];
            $user = $users[array_rand($users)];

            $attachment = Attachment::create([
                'user_id' => $user->id,
                'name' => $file['name'],
                'file_path' => "{$target[2]}/{$target[1]}/".$file['name'],
                'file_type' => $file['type'],
                'file_size' => $file['size'],
                'attachable_type' => $target[0],
                'attachable_id' => $target[1],
            ]);

            $this->attachments[] = $attachment;
            $count++;
        }
    }

    private function createActivityLogs(array $users, array $projects, array $meetings, array $risks, array $ovrs): void
    {
        $crud = [
            ['action' => ActivityLog::ACTION_CREATED, 'loggable' => Project::class, 'item_index' => fn ($items, $i) => array_slice($items, 0, 3)[$i % 3] ?? null],
            ['action' => ActivityLog::ACTION_UPDATED, 'loggable' => Project::class, 'item_index' => fn ($items, $i) => array_slice($items, 3, 3)[$i % 3] ?? null],
            ['action' => ActivityLog::ACTION_CREATED, 'loggable' => Meeting::class, 'item_index' => fn ($items, $i) => $items[$i % count($items)] ?? null],
            ['action' => ActivityLog::ACTION_CREATED, 'loggable' => Risk::class, 'item_index' => fn ($items, $i) => $items[$i % count($items)] ?? null],
            ['action' => ActivityLog::ACTION_UPDATED, 'loggable' => IncidentReport::class, 'item_index' => fn ($items, $i) => $items[$i % count($items)] ?? null],
        ];

        $recordsPool = array_merge($projects, $meetings, $risks, $ovrs);

        $count = 0;
        foreach ($crud as $i => $entry) {
            $loggable = $entry['item_index']($recordsPool, $i);
            if ($loggable === null) {
                continue;
            }
            $actor = $users[array_rand($users)];

            $this->activityLogs[] = ActivityLog::create([
                'user_id' => $actor->id,
                'action' => $entry['action'],
                'description' => match ($entry['action']) {
                    ActivityLog::ACTION_CREATED => 'تم إنشاء السجل',
                    ActivityLog::ACTION_UPDATED => 'تم تحديث السجل',
                    ActivityLog::ACTION_DELETED => 'تم حذف السجل',
                    default => 'حدث على السجل',
                },
                'loggable_type' => $entry['loggable'],
                'loggable_id' => $loggable->id,
                'old_values' => $entry['action'] === ActivityLog::ACTION_UPDATED ? ['status' => 'open'] : null,
                'new_values' => $entry['action'] === ActivityLog::ACTION_UPDATED ? ['status' => 'in_progress'] : null,
                'metadata' => ['source' => 'demo-seeder', 'trace_id' => bin2hex(random_bytes(4))],
                'ip_address' => '10.'.rand(0, 255).'.'.rand(0, 255).'.'.rand(1, 254),
                'user_agent' => 'EradaDemoSeeder/1.0',
            ]);
            $count++;
            if ($count >= 8) {
                break;
            }
        }

        $authEvents = [
            ['action' => ActivityLog::ACTION_LOGIN, 'description' => 'تسجيل دخول المستخدم'],
            ['action' => ActivityLog::ACTION_LOGIN, 'description' => 'تسجيل دخول المستخدم'],
            ['action' => ActivityLog::ACTION_LOGOUT, 'description' => 'تسجيل خروج المستخدم'],
            ['action' => ActivityLog::ACTION_LOGIN_FAILED, 'description' => 'محاولة دخول فاشلة'],
            ['action' => ActivityLog::ACTION_PASSWORD_CHANGED, 'description' => 'تم تغيير كلمة المرور'],
        ];
        foreach ($authEvents as $event) {
            $user = $users[array_rand($users)];
            $this->activityLogs[] = ActivityLog::create([
                'user_id' => $user->id,
                'action' => $event['action'],
                'description' => $event['description'],
                'loggable_type' => User::class,
                'loggable_id' => $user->id,
                'ip_address' => '10.'.rand(0, 255).'.'.rand(0, 255).'.'.rand(1, 254),
                'user_agent' => 'EradaDemoSeeder/1.0',
                'metadata' => ['browser' => 'Chrome'],
            ]);
        }
    }

    private function createExtraComments(array $users, array $projects, array $meetings, array $risks, array $ovrs): void
    {
        $samples = [
            'يُرجى مراجعة هذا البند قبل الاجتماع القادم.',
            'متابعة التحديث بعد اعتماد التغيير.',
            'تمت الموافقة على المقترح من قبل الإدارة.',
            'تحتاج هذه النقطة إلى توضيح إضافي من الفريق.',
            'تم تأكيد الإجراء وتوثيقه.',
            'نوصي بإعادة الجدولة لمناقشة أدق.',
            'وصلتنا تأكيدات من الجهة الخارجية.',
            'فتحنا تذكرة دعم للمتابعة.',
        ];

        $commentCount = 0;

        foreach (array_slice($meetings, 0, 6) as $meeting) {
            $n = rand(1, 3);
            for ($i = 0; $i < $n; $i++) {
                $user = $users[array_rand($users)];
                Comment::create([
                    'user_id' => $user->id,
                    'content' => $samples[array_rand($samples)].' ('.str_replace('اجتماع', 'الاجتماع', $meeting->title).')',
                    'commentable_type' => Meeting::class,
                    'commentable_id' => $meeting->id,
                ]);
                $commentCount++;
            }
        }

        foreach (array_slice($risks, 0, 6) as $risk) {
            $n = rand(1, 3);
            for ($i = 0; $i < $n; $i++) {
                $user = $users[array_rand($users)];
                Comment::create([
                    'user_id' => $user->id,
                    'content' => 'تعليق على المخاطر: '.$samples[array_rand($samples)],
                    'commentable_type' => Risk::class,
                    'commentable_id' => $risk->id,
                ]);
                $commentCount++;
            }
        }

        foreach (array_slice($ovrs, 0, 6) as $ovr) {
            // IncidentReport PK is UUID — comments.commentable_id is bigint, so attach
            // extra comments to a real project instead of the OVR row itself.
            $user = $users[array_rand($users)];
            $project = $projects[array_rand($projects)] ?? null;
            if ($project === null) {
                continue;
            }
            Comment::create([
                'user_id' => $user->id,
                'content' => 'تعليق متابعة للبلاغ '.$ovr->report_number.': '.$samples[array_rand($samples)],
                'commentable_type' => Project::class,
                'commentable_id' => $project->id,
            ]);
            $commentCount++;
        }
    }
}
