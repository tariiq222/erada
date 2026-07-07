<?php

namespace App\Console\Commands\OVR;

use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Models\IncidentReport;
use Illuminate\Console\Command;

class ArchiveClosedReportsCommand extends Command
{
    protected $signature = 'ovr:archive-closed {--days=30 : عدد الأيام بعد الإغلاق قبل الأرشفة}';

    protected $description = 'أرشفة التقارير المغلقة التي تجاوزت المدة المحددة';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $reports = IncidentReport::where('status', ReportStatus::Closed)
            ->whereNotNull('closed_at')
            ->where('closed_at', '<', now()->subDays($days))
            ->get();

        $count = 0;
        foreach ($reports as $report) {
            if (! $report->canTransitionTo(ReportStatus::Archived)) {
                continue;
            }

            $report->update(['status' => ReportStatus::Archived]);
            $report->recordStatusChange(
                ReportStatus::Closed,
                ReportStatus::Archived,
                $report->closed_by ?? $report->reporter_id,
                "أرشفة تلقائية بعد {$days} يوم من الإغلاق"
            );
            $count++;
        }

        $this->info("تم أرشفة {$count} تقرير مغلق.");

        return self::SUCCESS;
    }
}
