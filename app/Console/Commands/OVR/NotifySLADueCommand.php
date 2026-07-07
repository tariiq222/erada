<?php

namespace App\Console\Commands\OVR;

use App\Modules\Core\Models\User;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Notifications\SLADueNotification;
use Illuminate\Console\Command;

class NotifySLADueCommand extends Command
{
    protected $signature = 'ovr:notify-sla-due {--hours=6 : عدد الساعات قبل الاستحقاق لإرسال التذكير}';

    protected $description = 'إرسال تذكير للمعالجين قبل اقتراب موعد استحقاق التقرير (SLA)';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');

        $reports = IncidentReport::query()
            ->whereNotNull('assigned_to')
            ->whereNotNull('due_date')
            ->whereNull('sla_notified_at')
            ->whereNotIn('status', [ReportStatus::Closed, ReportStatus::Archived, ReportStatus::Resolved, ReportStatus::Rejected])
            ->where('due_date', '>', now())
            ->where('due_date', '<=', now()->addHours($hours))
            ->get();

        $count = 0;
        foreach ($reports as $report) {
            $assignee = User::find($report->assigned_to);
            if ($assignee) {
                $assignee->notify(new SLADueNotification($report));
                $report->forceFill(['sla_notified_at' => now()])->save();
                $count++;
            }
        }

        $this->info("تم إرسال {$count} تذكير SLA.");

        return self::SUCCESS;
    }
}
