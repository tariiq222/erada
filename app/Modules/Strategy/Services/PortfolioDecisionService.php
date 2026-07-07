<?php

namespace App\Modules\Strategy\Services;

use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\ActivityLogOrganizationResolver;
use App\Modules\Strategy\Models\Portfolio;

class PortfolioDecisionService
{
    /**
     * تسجيل قرار الإغلاق القسري للمحفظة
     */
    public function logForceCloseDecision(
        Portfolio $portfolio,
        User $user,
        ?string $decisionNote = null
    ): void {
        $activePrograms = $portfolio->programs()
            ->whereIn('status', ['draft', 'in_progress'])
            ->get(['id', 'name', 'status']);

        ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'portfolio_force_closed',
            'loggable_type' => Portfolio::class,
            'loggable_id' => $portfolio->id,
            'organization_id' => app(ActivityLogOrganizationResolver::class)
                ->resolveForLoggable(Portfolio::class, $portfolio->id),
            'old_values' => [
                'portfolio_status' => $portfolio->getOriginal('portfolio_status'),
                'active_programs' => $activePrograms->toArray(),
            ],
            'new_values' => [
                'portfolio_status' => 'closed_strategically',
                'decision_note' => $decisionNote,
                'force_closed' => true,
                'closed_at' => now()->toISOString(),
            ],
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * تسجيل قرار تغيير الحالة الاستراتيجية
     */
    public function logStrategicStatusChange(
        Portfolio $portfolio,
        User $user,
        string $oldStatus,
        string $newStatus,
        ?string $decisionNote = null
    ): void {
        ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'portfolio_strategic_status_changed',
            'loggable_type' => Portfolio::class,
            'loggable_id' => $portfolio->id,
            'organization_id' => app(ActivityLogOrganizationResolver::class)
                ->resolveForLoggable(Portfolio::class, $portfolio->id),
            'old_values' => [
                'portfolio_status' => $oldStatus,
            ],
            'new_values' => [
                'portfolio_status' => $newStatus,
                'decision_note' => $decisionNote,
                'changed_at' => now()->toISOString(),
            ],
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * تسجيل قرار تغيير الأولوية
     */
    public function logPriorityChange(
        Portfolio $portfolio,
        User $user,
        array $oldValues,
        array $newValues
    ): void {
        ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'portfolio_priority_changed',
            'loggable_type' => Portfolio::class,
            'loggable_id' => $portfolio->id,
            'organization_id' => app(ActivityLogOrganizationResolver::class)
                ->resolveForLoggable(Portfolio::class, $portfolio->id),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
        ]);
    }
}
