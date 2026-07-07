<?php

namespace App\Console\Commands\RiskManagement;

use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Models\RiskAssessment;
use App\Modules\RiskManagement\Notifications\RiskReviewDueNotification;
use Illuminate\Console\Command;

class CheckDueEvaluationsCommand extends Command
{
    protected $signature = 'risks:check-due-evaluations {--days=0 : نافذة سماح إضافية بالأيام بعد تاريخ الاستحقاق}';

    protected $description = 'إرسال تذكير للمالكين والمسؤولين عند اقتراب موعد إعادة تقييم المخاطر';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->addDays($days)->toDateString();

        // Find the latest assessment per risk that is overdue, idempotent
        // via review_due_notified_at: only notify if we have not already
        // done so for this assessment.
        $assessments = RiskAssessment::query()
            ->whereNotNull('next_review_at')
            ->whereDate('next_review_at', '<=', $cutoff)
            ->whereNull('review_due_notified_at')
            ->with(['risk.owner', 'risk.organization'])
            ->get();

        $count = 0;
        foreach ($assessments as $assessment) {
            $owner = $assessment->risk?->owner;
            if (! $owner instanceof User) {
                continue;
            }

            $owner->notify(new RiskReviewDueNotification($assessment));
            $assessment->forceFill(['review_due_notified_at' => now()])->save();
            $count++;
        }

        $this->info("تم إرسال {$count} تذكير بإعادة التقييم.");

        return self::SUCCESS;
    }
}
