<?php

namespace App\Modules\RiskManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskAssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'risk_id' => $this->risk_id,
            'likelihood' => $this->likelihood,
            'impact' => $this->impact,
            'score' => $this->score,
            'level' => $this->level,
            'residual_likelihood' => $this->residual_likelihood,
            'residual_impact' => $this->residual_impact,
            'residual_score' => $this->residual_score,
            'residual_level' => $this->residual_level,
            'assessor_id' => $this->assessor_id,
            'notes' => $this->notes,
            'next_review_at' => $this->next_review_at?->toDateString(),
            'review_due_notified_at' => $this->review_due_notified_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
            // Relations
            'assessor' => $this->whenLoaded('assessor', fn () => [
                'id' => $this->assessor->id,
                'name' => $this->assessor->name,
            ]),
        ];
    }
}
