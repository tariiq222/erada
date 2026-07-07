<?php

namespace App\Console\Commands;

use App\Modules\Surveys\Enums\SurveyStatus;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Console\Command;

class ExpireSurveysCommand extends Command
{
    protected $signature = 'surveys:expire';

    protected $description = 'إغلاق الاستبيانات التي تجاوزت تاريخ الانتهاء';

    public function handle(): int
    {
        $expired = Survey::where('status', SurveyStatus::Published)
            ->where('accepting_responses', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->get();

        $count = 0;
        foreach ($expired as $survey) {
            $survey->update([
                'accepting_responses' => false,
                'closed_at' => now(),
                'close_reason' => 'expired',
            ]);
            $count++;
        }

        $this->info("تم إغلاق {$count} استبيان منتهي.");

        return self::SUCCESS;
    }
}
