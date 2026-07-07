<?php

namespace App\Modules\Surveys\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataImportRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canReview = $this->canReviewImport($request);
        $includeReviewerDetail = $canReview && ! $request->routeIs('data-imports.index');

        $data = [
            'id' => $this->id,
            'response_id' => $this->response_id,
            'template_id' => $this->template_id,

            'target_table' => $this->target_table,
            'target_table_label' => $this->getTargetTableLabel(),
            'target_id' => $this->target_id,

            'operation' => $this->operation,
            'operation_summary' => $this->getOperationSummary(),
            'has_conflict' => $this->hasConflict(),

            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'status_color' => $this->status?->color(),

            'priority' => $this->priority,
            'requested_at' => $this->requested_at?->toISOString(),

            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'reviewer' => $this->whenLoaded('reviewer', fn () => [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ]),
            'applied_at' => $this->applied_at?->toISOString(),
            'applied_id' => $this->applied_id,

            // إجراءات متاحة
            'can_approve' => $canReview && $this->canApprove(),
            'can_reject' => $canReview && $this->canReject(),
            'can_apply' => $canReview && $this->canApply(),

            // العلاقات
            'response' => $this->whenLoaded('response', fn () => [
                'id' => $this->response->id,
                'respondent_name' => $this->response->respondent_name,
                'submitted_at' => $this->response->submitted_at?->toISOString(),
                'survey' => $this->when($this->response->relationLoaded('survey'), fn () => [
                    'id' => $this->response->survey->id,
                    'code' => $this->response->survey->code,
                    'title' => $this->response->survey->title,
                ]),
            ]),

            'template' => $this->whenLoaded('template', fn () => [
                'id' => $this->template->id,
                'name' => $this->template->name,
            ]),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        if ($includeReviewerDetail) {
            $data += [
                'payload' => $this->payload,
                'diff' => $this->diff,
                'upsert_key_field' => $this->upsert_key_field,
                'upsert_key_value' => $this->upsert_key_value,
                'rejection_reason' => $this->rejection_reason,
                'error_message' => $this->error_message,
            ];
        }

        return $data;
    }

    private function canReviewImport(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user?->isSuperAdmin() || $user?->can('review_data_imports'));
    }
}
