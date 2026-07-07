<?php

namespace App\Modules\Meetings\Console\Commands;

use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Notifications\RecommendationOverdueNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckOverdueRecommendationsCommand extends Command
{
    protected $signature = 'recommendations:check-overdue {--grace-days= : أيام سماح إضافية بعد تاريخ الاستحقاق (يتجاوز config)}';

    protected $description = 'إرسال إشعار للمكلّفين عند تأخر التوصيات عن موعدها';

    public function handle(): int
    {
        $grace = (int) ($this->option('grace-days') ?? config('meetings.recommendation_overdue_grace_days', 0));
        $cutoff = now()->subDays($grace)->toDateString();

        $recommendations = Recommendation::query()
            ->where('status', Recommendation::STATUS_ACCEPTED)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $cutoff)
            ->whereNull('overdue_notified_at')
            ->with('assignee')
            ->get();

        $count = 0;
        foreach ($recommendations as $rec) {
            $assignee = $rec->assignee;
            if (! $assignee instanceof User) {
                continue;
            }
            Notification::send($assignee, new RecommendationOverdueNotification($rec));
            $rec->forceFill(['overdue_notified_at' => now()])->save();
            $count++;
        }

        $this->info("تم إرسال {$count} إشعار تأخر.");

        return self::SUCCESS;
    }
}
