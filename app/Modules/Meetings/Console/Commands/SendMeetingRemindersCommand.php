<?php

namespace App\Modules\Meetings\Console\Commands;

use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Notifications\MeetingReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendMeetingRemindersCommand extends Command
{
    protected $signature = 'meetings:send-reminders {--hours= : نافذة التذكير بالساعات (يتجاوز config)}';

    protected $description = 'إرسال تذكير بالاجتماعات المجدولة خلال 24 ساعة لكل مستخدم مدعو';

    public function handle(): int
    {
        $windowHours = (int) ($this->option('hours') ?? config('meetings.meeting_reminder_window_hours', 24));
        $now = now();
        $cutoff = now()->addHours($windowHours);

        $meetings = Meeting::query()
            ->where('status', Meeting::STATUS_SCHEDULED)
            ->whereNull('reminder_sent_at')
            ->whereBetween('scheduled_at', [$now, $cutoff])
            ->with(['attendees', 'organizer'])
            ->get();

        $remindersSent = 0;
        $meetingsTouched = 0;

        foreach ($meetings as $meeting) {
            $recipients = $meeting->attendees;
            if ($meeting->organizer instanceof User && ! $recipients->contains('id', $meeting->organizer_id)) {
                $recipients->push($meeting->organizer);
            }

            Notification::send($recipients, new MeetingReminderNotification($meeting));
            $meeting->forceFill(['reminder_sent_at' => $now])->save();
            $remindersSent += $recipients->count();
            $meetingsTouched++;
        }

        $this->info("تم إرسال {$remindersSent} تذكير لـ {$meetingsTouched} اجتماع.");

        return self::SUCCESS;
    }
}
