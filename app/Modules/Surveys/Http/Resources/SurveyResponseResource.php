<?php

namespace App\Modules\Surveys\Http\Resources;

use App\Modules\Surveys\Enums\SurveyPrivacyMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveyResponseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $privacyMode = $this->survey
            ? $this->survey->privacyMode()
            : SurveyPrivacyMode::Identified;
        $maskIdentity = $privacyMode->masksRespondentIdentity();

        return [
            'id' => $this->id,
            'survey_id' => $this->survey_id,
            'privacy_mode' => $privacyMode->value,

            'respondent_type' => $this->respondent_type,
            'respondent_name' => $maskIdentity ? null : $this->respondent_name,
            'respondent_email' => $maskIdentity ? null : $this->respondent_email,
            'respondent_phone' => $maskIdentity ? null : $this->respondent_phone,
            'respondent_display_name' => $maskIdentity
                ? $privacyMode->respondentDisplayName()
                : $this->getRespondentDisplayName(),

            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'status_color' => $this->status?->color(),

            'completion_time' => $this->completion_time,
            'completion_time_formatted' => $this->formatCompletionTime(),

            'consented_at' => $this->consented_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),

            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'reviewer' => $this->whenLoaded('reviewer', fn () => [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ]),
            'reviewer_notes' => $this->reviewer_notes,

            // الإجابات
            'answers' => $this->whenLoaded('answers', fn () => $this->answers->map(
                fn ($answer) => $this->serializeAnswer($answer, $privacyMode)
            )
            ),

            // طلبات الاستيراد
            'import_requests' => $this->whenLoaded('importRequests', fn () => DataImportRequestResource::collection($this->importRequests)
            ),

            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    protected function serializeAnswer($answer, SurveyPrivacyMode $privacyMode): array
    {
        $serialized = [
            'id' => $answer->id,
            'field_id' => $answer->field_id,
            'field_key' => $answer->field_key,
            'field' => $answer->relationLoaded('field') && $answer->field ? [
                'id' => $answer->field->id,
                'label' => $answer->field->label,
                'type' => $answer->field->type,
            ] : null,
            'display_value' => $answer->getDisplayValue(),
        ];

        if (! $privacyMode->hidesRawAnswerValues()) {
            $serialized['answer_value'] = $answer->answer_value;
            $serialized['answer_text'] = $answer->answer_text;
            $serialized['answer_number'] = $answer->answer_number;
        }

        return $serialized;
    }

    protected function formatCompletionTime(): ?string
    {
        if (! $this->completion_time) {
            return null;
        }

        $minutes = floor($this->completion_time / 60);
        $seconds = $this->completion_time % 60;

        if ($minutes > 0) {
            return "{$minutes} دقيقة و {$seconds} ثانية";
        }

        return "{$seconds} ثانية";
    }
}
