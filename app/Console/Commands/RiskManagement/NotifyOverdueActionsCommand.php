<?php

namespace App\Console\Commands\RiskManagement;

use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\RiskManagement\Notifications\RiskActionOverdueNotification;
use Illuminate\Console\Command;

class NotifyOverdueActionsCommand extends Command
{
    protected $signature = 'risks:notify-overdue-actions';

    protected $description = 'إرسال تذكير عند تأخّر إجراءات المخاطر عن موعدها';

    public function handle(): int
    {
        $today = now()->toDateString();

        $actions = RiskAction::query()
            ->whereNotIn('status', [
                RiskActionStatus::Completed->value,
                RiskActionStatus::Cancelled->value,
            ])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->whereNull('overdue_notified_at')
            ->with(['owner', 'risk'])
            ->get();

        $count = 0;
        foreach ($actions as $action) {
            $owner = $action->owner;
            if (! $owner instanceof User) {
                continue;
            }

            $owner->notify(new RiskActionOverdueNotification($action));
            $action->forceFill(['overdue_notified_at' => now()])->save();
            $count++;
        }

        $this->info("تم إرسال {$count} تذكير بإجراءات متأخرة.");

        return self::SUCCESS;
    }
}
