<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreReviewRequest - validation + engine-only authz for creating a Review.
 *
 * The previous controller called authorizeStrategy('create') and validated
 * inline. authorize() now resolves strategy.create through
 * AccessDecision::can(); the polymorphic reviewable existence check stays
 * in the controller because it loads the model the controller needs to copy
 * progress from (cannot move into a static rule).
 */
class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_CREATE);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'reviewable_type' => ['required', Rule::in(['objective', 'initiative', 'project'])],
            'reviewable_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(['monthly', 'quarterly', 'annual', 'adhoc'])],
            'pdca_phase' => ['required', Rule::in(['plan', 'do', 'check', 'act'])],
            'review_date' => ['required', 'date'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'overall_status' => ['nullable', Rule::in(['on_track', 'at_risk', 'off_track', 'completed'])],
            'achievements' => ['nullable', 'string'],
            'challenges' => ['nullable', 'string'],
            'lessons_learned' => ['nullable', 'string'],
            'next_steps' => ['nullable', 'string'],
            'recommendations' => ['nullable', 'string'],
            'attendees' => ['nullable', 'array'],
        ];
    }
}
