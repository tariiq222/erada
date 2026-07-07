<?php

namespace App\Console\Commands\OVR;

use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Models\IncidentReport;
use Illuminate\Console\Command;

class NotifyPendingInfoTimeoutCommand extends Command
{
    protected $signature = 'ovr:notify-pending-timeout {--days=7 : عدد الأيام في حالة انتظار المعلومات قبل الإرجاع}';

    protected $description = 'إرجاع التقارير العالقة في حالة انتظار المعلومات إلى حالة جديد بعد انتهاء المهلة';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $reports = IncidentReport::query()
            ->where('status', ReportStatus::PendingInfo)
            ->where('updated_at', '<', now()->subDays($days))
            ->get();

        $count = 0;
        foreach ($reports as $report) {
            if (! $report->canTransitionTo(ReportStatus::New)) {
                continue;
            }

            $oldStatus = $report->status;
            $report->update(['status' => ReportStatus::New]);
            $report->recordStatusChange(
                $oldStatus,
                ReportStatus::New,
                $report->assigned_to ?? $report->reporter_id,
                "إرجاع تلقائي بعد انتهاء مهلة انتظار المعلومات ({$days} يوم)"
            );
            $count++;
        }

        $this->info("تم إرجاع {$count} تقرير من حالة انتظار المعلومات.");

        return self::SUCCESS;
    }
}
