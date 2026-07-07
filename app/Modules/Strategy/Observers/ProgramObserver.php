<?php

namespace App\Modules\Strategy\Observers;

use App\Modules\Strategy\Models\Program;
use Illuminate\Support\Facades\Log;

class ProgramObserver
{
    public function created(Program $program): void
    {
        Log::info('program.created', $this->payload($program));
    }

    public function updated(Program $program): void
    {
        Log::info('program.updated', array_merge(
            $this->payload($program),
            ['changes' => $program->getChanges()]
        ));
    }

    public function deleted(Program $program): void
    {
        Log::info('program.deleted', $this->payload($program));
    }

    public function restored(Program $program): void
    {
        Log::info('program.restored', $this->payload($program));
    }

    private function payload(Program $program): array
    {
        return [
            'id' => $program->id,
            'code' => $program->code,
            'name' => $program->name,
            'portfolio_id' => $program->portfolio_id,
            'department_id' => $program->department_id,
            'status' => $program->status,
            'priority' => $program->priority,
            'progress' => $program->progress,
            'weight' => $program->weight,
            'budget' => $program->budget,
            'spent_amount' => $program->spent_amount,
            'progress_calculation_method' => $program->progress_calculation_method,
            'organization_id' => $program->organization_id,
            'updated_at' => $program->updated_at?->toIso8601String(),
        ];
    }
}
