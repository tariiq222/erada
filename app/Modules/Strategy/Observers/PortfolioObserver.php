<?php

namespace App\Modules\Strategy\Observers;

use App\Modules\Strategy\Models\Portfolio;
use Illuminate\Support\Facades\Log;

class PortfolioObserver
{
    public function created(Portfolio $portfolio): void
    {
        Log::info('portfolio.created', $this->payload($portfolio));
    }

    public function updated(Portfolio $portfolio): void
    {
        Log::info('portfolio.updated', array_merge(
            $this->payload($portfolio),
            ['changes' => $portfolio->getChanges()]
        ));
    }

    public function deleted(Portfolio $portfolio): void
    {
        Log::info('portfolio.deleted', $this->payload($portfolio));
    }

    public function restored(Portfolio $portfolio): void
    {
        Log::info('portfolio.restored', $this->payload($portfolio));
    }

    private function payload(Portfolio $portfolio): array
    {
        return [
            'id' => $portfolio->id,
            'code' => $portfolio->code,
            'name' => $portfolio->name,
            'status' => $portfolio->status,
            'portfolio_status' => $portfolio->portfolio_status,
            'priority_rank' => $portfolio->priority_rank,
            'weight' => $portfolio->weight,
            'order' => $portfolio->order,
            'start_date' => $portfolio->start_date?->toDateString(),
            'end_date' => $portfolio->end_date?->toDateString(),
            'directive_source' => $portfolio->directive_source,
            'organization_id' => $portfolio->organization_id,
            'created_by' => $portfolio->created_by,
            'updated_at' => $portfolio->updated_at?->toIso8601String(),
        ];
    }
}
